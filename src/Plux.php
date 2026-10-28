<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Cobro con tarjeta por Pago Plux (API v1.3 credentials/paymentCardResource), con las credenciales DEL NEGOCIO.
 * Es la misma integración que TuPass, pero cada número de WhatsApp tiene su propia cuenta de Plux.
 *
 * - Cada pedido tiene su página de pago: https://whatsapp.tukanec.com/pagar.php?p=<token> (muestra el valor exacto).
 * - Los datos de la tarjeta se cifran EN EL NAVEGADOR del comprador (AES-256-ECB con CryptoJS; la clave al azar
 *   se cifra con la llave pública RSA de Plux con JSEncrypt). Este servidor solo los reenvía: NUNCA los guarda ni los anota.
 * - Authorization: Basic base64(id_cliente:clave_secreta). Cabecera simetricKey = la clave cifrada.
 * - Códigos de Plux: 0 aprobado, 1 error, 2 rechazado, 100 pide OTP, 102 OTP incorrecto, 103 pide validación 3DS.
 * - urlRetorno3ds = https://whatsapp.tukanec.com/pagar.php SIN parámetros (Plux agrega los suyos): el pedido se reconoce
 *   por una cookie de la página de pago.
 * - Aviso de pago (webhook): POST JSON a https://whatsapp.tukanec.com/plux.php con Basic Auth; estado PAGADO o RECHAZADO.
 * - Plux NO suma IVA: se manda el valor final (con IVA en baseAmount12; sin IVA en baseAmount0).
 * - Mientras Plux no certifique la instalación del negocio, el número queda en "pruebas" (sandbox).
 */
final class Plux
{
    public const URL_SANDBOX = 'https://apipre.pagoplux.com/intv1/';
    public const URL_PRODUCCION = 'https://api.pagoplux.com/intv1/';
    public const RUTA_PAGO = 'credentials/paymentCardResource';
    public const RUTA_3DS = 'integrations/dataTransactionThreeDsResource';
    public const MAX_INTENTOS = 12;   // por pedido y por hora

    public static function base(): string
    {
        return rtrim((string) (getenv('TUWA_URL') ?: 'https://whatsapp.tukanec.com'), '/');
    }

    public static function ambiente(array $linea): string
    {
        return ($linea['plux_ambiente'] ?? '') === 'produccion' ? 'produccion' : 'sandbox';
    }

    public static function urlApi(array $linea): string
    {
        $o = (string) (getenv('PLUX_API_BASE') ?: '');   // solo pruebas (Plux falso local)
        if ($o !== '' && preg_match('#^https?://\S+/$#', $o)) {
            return $o;
        }
        return self::ambiente($linea) === 'produccion' ? self::URL_PRODUCCION : self::URL_SANDBOX;
    }

    public static function configurado(array $linea): bool
    {
        foreach (['plux_establecimiento', 'plux_cliente', 'plux_clave', 'plux_llave'] as $k) {
            if (trim((string) ($linea[$k] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** Dirección donde Plux avisa los pagos, y el usuario y la clave con que firma (se copian en el panel de Plux). */
    public static function aviso(PDO $db, array $linea): array
    {
        $clave = (string) ($linea['plux_aviso'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $clave)) {
            $clave = bin2hex(random_bytes(16));
            $db->prepare('UPDATE lineas SET plux_aviso = ? WHERE id = ?')->execute([$clave, (int) $linea['id']]);
        }
        return ['url' => self::base() . '/plux.php', 'usuario' => 'tukan' . (int) $linea['id'], 'clave' => $clave,
                'retorno3ds' => self::base() . '/pagar.php', 'pagina' => self::base() . '/pagar.php?p=…'];
    }

    /** La llave pública en formato PEM (para JSEncrypt en el navegador). Acepta la llave en una línea o con BEGIN/END. */
    public static function llavePem(string $k): string
    {
        $k = trim(str_replace('\n', "\n", $k));
        if (str_contains($k, 'BEGIN')) {
            return $k;
        }
        return "-----BEGIN PUBLIC KEY-----\n" . trim(chunk_split((string) preg_replace('/\s+/', '', $k), 64, "\n")) . "\n-----END PUBLIC KEY-----";
    }

    /** Revisa y guarda las credenciales que el negocio pegó en Cobros. Lo que viene vacío se queda como estaba. */
    public static function guardar(PDO $db, array $linea, array $d): void
    {
        $t = fn (string $k, int $n) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $n);
        if (!empty($d['quitar_plux'])) {
            $db->prepare("UPDATE lineas SET plux_establecimiento = '', plux_cliente = '', plux_clave = '', plux_llave = '', plux_ambiente = 'sandbox',
                          cobro_tarjeta = CASE WHEN cobro_tarjeta = 'plux' THEN '' ELSE cobro_tarjeta END WHERE id = ?")->execute([(int) $linea['id']]);
            return;
        }
        $est = $t('plux_establecimiento', 100);
        $cli = $t('plux_cliente', 200);
        $cla = $t('plux_clave', 500);
        $llave = $t('plux_llave', 4000);
        foreach ([[$est, 'el id de establecimiento'], [$cli, 'el id de cliente']] as [$v, $que]) {
            if ($v !== '' && !preg_match('/^[A-Za-z0-9._\-=+\/]{1,200}$/', $v)) {
                throw new RuntimeException('Revisa ' . $que . ' de Plux: tiene letras o espacios que no van.');
            }
        }
        if ($llave !== '') {
            $pem = self::llavePem($llave);
            if (openssl_pkey_get_public($pem) === false) {
                throw new RuntimeException('La llave pública de Plux no es válida. Cópiala completa, tal como te la mandó Plux.');
            }
        }
        $amb = ($d['plux_ambiente'] ?? '') === 'produccion' ? 'produccion' : 'sandbox';
        $db->prepare("UPDATE lineas SET plux_establecimiento = COALESCE(NULLIF(?, ''), plux_establecimiento), plux_cliente = COALESCE(NULLIF(?, ''), plux_cliente),
                      plux_llave = COALESCE(NULLIF(?, ''), plux_llave), plux_ambiente = ?, plux_error = '' WHERE id = ?")
           ->execute([$est, $cli, $llave, $amb, (int) $linea['id']]);
        if ($cla !== '') {
            $db->prepare('UPDATE lineas SET plux_clave = ? WHERE id = ?')->execute([Cifrado::cifrar($cla), (int) $linea['id']]);
        }
        self::aviso($db, $linea);
    }

    /** Token de la página de pago del pedido (se crea la primera vez). */
    public static function tokenPedido(PDO $db, array $pedido): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($pedido['pago_token'] ?? ''))) {
            return (string) $pedido['pago_token'];
        }
        $t = bin2hex(random_bytes(16));
        $db->prepare('UPDATE pedidos SET pago_token = COALESCE(pago_token, ?) WHERE id = ?')->execute([$t, (int) $pedido['id']]);
        $st = $db->prepare('SELECT pago_token FROM pedidos WHERE id = ?');
        $st->execute([(int) $pedido['id']]);
        return (string) $st->fetchColumn();
    }

    public static function urlPago(PDO $db, array $pedido): string
    {
        return self::base() . '/pagar.php?p=' . self::tokenPedido($db, $pedido);
    }

    /** Pedido (con su número) por el token de la página de pago. */
    public static function pedidoPorToken(PDO $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $db->prepare('SELECT * FROM pedidos WHERE pago_token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /** Llama a Plux. Devuelve el JSON; en error de red: code -1. */
    public static function llamar(array $linea, string $ruta, array $cuerpo, ?string $claveCifrada = null): array
    {
        $cab = ['Content-Type: application/json', 'Accept: application/json',
                'Authorization: Basic ' . base64_encode((string) $linea['plux_cliente'] . ':' . Cifrado::descifrar((string) $linea['plux_clave']))];
        if ($claveCifrada !== null) {
            $cab[] = 'simetricKey: ' . $claveCifrada;
        }
        $ch = curl_init(self::urlApi($linea) . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $cab, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch);
        if ($resp === false) {
            return ['code' => -1, 'description' => 'No se pudo conectar con Plux (' . $err . ').'];
        }
        $j = json_decode((string) $resp, true);
        return is_array($j) ? $j : ['code' => -1, 'description' => 'Plux respondió algo que no se entiende.'];
    }

    /** Con IVA el total va en baseAmount12; sin IVA en baseAmount0. Siempre el valor FINAL. */
    public static function montos(array $pedido): array
    {
        $t = round((float) $pedido['total'], 2);
        return (float) $pedido['iva'] > 0 ? ['baseAmount0' => 0.0, 'baseAmount12' => $t] : ['baseAmount0' => $t, 'baseAmount12' => 0.0];
    }

    /** Referencia que va en la descripción (para reconocer el aviso de pago). */
    public static function referencia(array $pedido): string
    {
        return 'WT-' . (int) $pedido['id'];
    }

    /** Arma el pedido para Plux con la tarjeta YA cifrada en el navegador. Lanza RuntimeException con el texto para el comprador. */
    public static function cuerpo(array $linea, array $pedido, array $contacto, array $in, string $ip): array
    {
        $card = is_array($in['card'] ?? null) ? $in['card'] : [];
        foreach (['number', 'expirationYear', 'expirationMonth', 'cvv'] as $k) {
            if (!isset($card[$k]) || !is_string($card[$k]) || !preg_match('#^[A-Za-z0-9+/=]{8,400}$#', $card[$k])) {
                throw new RuntimeException('Los datos de la tarjeta llegaron incompletos. Vuelve a escribirlos.');
            }
        }
        $buyer = is_array($in['buyer'] ?? null) ? $in['buyer'] : [];
        $t = fn ($v, int $n = 60) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $n);
        $doc = preg_replace('/\D/', '', $t($buyer['documentNumber'] ?? '', 13)) ?? '';
        if (!preg_match('/^\d{10}(\d{3})?$/', $doc)) {
            throw new RuntimeException('Escribe la cédula (10 números) o el RUC (13) del titular de la tarjeta.');
        }
        $nombre = $t($buyer['firstName'] ?? '');
        $apellido = $t($buyer['lastName'] ?? '');
        if ($nombre === '' || $apellido === '') {
            throw new RuntimeException('Escribe nombres y apellidos del titular de la tarjeta.');
        }
        $correo = $t($buyer['email'] ?? '', 120);
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Escribe un correo válido del titular de la tarjeta.');
        }
        $tel = preg_replace('/[^\d+]/', '', $t($buyer['phone'] ?? '', 20)) ?: (string) ($contacto['telefono'] ?? '');
        $c = [
            'card' => ['number' => $card['number'], 'expirationYear' => $card['expirationYear'], 'expirationMonth' => $card['expirationMonth'], 'cvv' => $card['cvv']],
            'buyer' => ['documentNumber' => $doc, 'firstName' => $nombre, 'lastName' => $apellido, 'phone' => $tel, 'email' => $correo],
            'shippingAddress' => ['country' => 'Ecuador', 'city' => $t($buyer['city'] ?? '') ?: 'Quito', 'street' => $t($buyer['street'] ?? '', 120) ?: 'S/N', 'number' => 'S/N'],
            'currency' => 'USD',
            'installments' => '0',
            'interests' => '0',
            'description' => mb_substr(self::referencia($pedido) . ' Pedido #' . $pedido['numero'] . ' ' . $linea['nombre'], 0, 100),
            'clientIp' => $ip,
            'idEstablecimiento' => base64_encode((string) $linea['plux_establecimiento']),
            'urlRetorno3ds' => self::base() . '/pagar.php',   // SIN parámetros
        ] + self::montos($pedido);
        $otp = $in['paramsOtp'] ?? null;
        if (is_array($otp)) {
            $c['paramsOtp'] = [];
            foreach (['idTransaction', 'sessionId', 'tkn', 'tknky', 'tkniv', 'otpCode'] as $k) {
                if (isset($otp[$k]) && is_scalar($otp[$k])) {
                    $c['paramsOtp'][$k] = mb_substr((string) $otp[$k], 0, 2000);
                }
            }
        }
        return $c;
    }

    /** Quita todo lo que pueda ser de la tarjeta antes de guardar una respuesta. */
    public static function limpiar(mixed $v): mixed
    {
        if (is_array($v)) {
            $o = [];
            foreach ($v as $k => $x) {
                if (is_string($k) && preg_match('/card|tarjeta|cvv|cvc|pan|expir|number|numero|bin$/i', $k)) {
                    continue;
                }
                $o[$k] = self::limpiar($x);
            }
            return $o;
        }
        if (is_string($v)) {
            return preg_replace('/\d[\d \-]{10,22}\d/', '[oculto]', $v);
        }
        return $v;
    }

    public static function texto(array $r): string
    {
        return mb_substr((string) json_encode(self::limpiar($r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3000);
    }

    /** Número de transacción que manda Plux (para relacionar el aviso de pago). */
    public static function transaccion(array $r): string
    {
        $d = is_array($r['detail'] ?? null) ? $r['detail'] : $r;
        foreach (['id_transaccion', 'idTransaccion', 'idTransaction', 'transactionId', 'id_transaction', 'token', 'voucher'] as $k) {
            if (isset($d[$k]) && is_scalar($d[$k]) && (string) $d[$k] !== '') {
                return mb_substr((string) $d[$k], 0, 100);
            }
        }
        return '';
    }

    /** ¿Quedan intentos? (12 por pedido y por hora; cuenta este intento) */
    public static function intento(PDO $db, array $pedido): bool
    {
        $st = $db->prepare("UPDATE pedidos SET plux_intentos = CASE WHEN plux_intento_en IS NULL OR plux_intento_en < now() - interval '1 hour' THEN 1 ELSE plux_intentos + 1 END,
                            plux_intento_en = CASE WHEN plux_intento_en IS NULL OR plux_intento_en < now() - interval '1 hour' THEN now() ELSE plux_intento_en END
                            WHERE id = ? RETURNING plux_intentos");
        $st->execute([(int) $pedido['id']]);
        return (int) $st->fetchColumn() <= self::MAX_INTENTOS;
    }

    /**
     * Respuesta de Plux -> qué le decimos al navegador. Si fue aprobado, el pedido pasa a Pagado.
     * Devuelve ['status' => success|otp_required|otp_incorrecto|3ds_required|failed, ...].
     */
    public static function procesar(PDO $db, array $linea, array $pedido, array $r, string $origen): array
    {
        $code = (int) ($r['code'] ?? -1);
        $txt = self::texto($r);
        $tr = self::transaccion($r);
        if ($code === 0) {
            self::aprobar($db, $linea, $pedido, $tr, $txt, $origen);
            return ['status' => 'success'];
        }
        $anotar = function (string $paso) use ($db, $pedido, $txt, $tr): void {
            $db->prepare("UPDATE pedidos SET plux_paso = ?, plux_respuesta = ?, plux_transaccion = COALESCE(NULLIF(?, ''), plux_transaccion), actualizado = now()
                          WHERE id = ? AND estado IN ('carrito', 'pendiente')")->execute([$paso, $txt, $tr, (int) $pedido['id']]);
        };
        $detalle = is_array($r['detail'] ?? null) ? self::limpiar($r['detail']) : null;
        if ($code === 100) {
            $anotar('otp');
            return ['status' => 'otp_required', 'detail' => $detalle];
        }
        if ($code === 102) {
            $anotar('otp');
            return ['status' => 'otp_incorrecto', 'message' => 'El código no es correcto. Revisa el mensaje de tu banco e intenta otra vez.', 'detail' => $detalle];
        }
        if ($code === 103) {
            $anotar('3ds');
            $d = is_array($r['detail'] ?? null) ? $r['detail'] : [];
            $url = is_string($d['url'] ?? null) && preg_match('#^https?://#', $d['url']) ? $d['url'] : '';
            if ($url === '') {
                return ['status' => 'failed', 'message' => 'El banco pidió una validación pero no llegó la dirección. Intenta otra vez.'];
            }
            $params = [];
            foreach ((array) ($d['parameters'] ?? []) as $p) {
                if (is_array($p) && isset($p['name'], $p['value'])) {
                    $params[] = ['name' => (string) $p['name'], 'value' => (string) $p['value']];
                }
            }
            return ['status' => '3ds_required', 'detail' => ['url' => $url, 'parameters' => $params, 'customFormChallenge' => !empty($d['customFormChallenge'])]];
        }
        $anotar('fallido');
        if ($code === 401 || $code === -1) {
            $db->prepare('UPDATE lineas SET plux_error = ? WHERE id = ?')
               ->execute([mb_substr('Plux no aceptó el cobro (' . date('d/m H:i') . '): ' . (string) ($r['description'] ?? 'error') . '. Revisa las credenciales y si estás en pruebas o en producción.', 0, 300), (int) $linea['id']]);
        }
        $msg = is_string($r['detail'] ?? null) && $r['detail'] !== '' ? $r['detail'] : (string) ($r['description'] ?? '');
        if ($code === 2) {
            $msg = 'Tu banco no aprobó el pago' . ($msg !== '' ? ': ' . $msg : '') . '.';
        } elseif ($msg === '') {
            $msg = 'El pago no se pudo procesar.';
        }
        if ($code !== 2) {   // un rechazo del banco es normal; lo demás se anota (solo el código, nunca el pedido)
            error_log('tuwhatsapp Plux: pedido ' . $pedido['id'] . ' respondió ' . $code);
        }
        return ['status' => 'failed', 'code' => $code, 'message' => mb_substr((string) self::limpiar($msg), 0, 300)];
    }

    /**
     * Pago aprobado: el pedido pasa a Pagado (si el stock no alcanza, queda anotado para revisar, pero el pago se registra),
     * se avisa al equipo (app del celular) y se le confirma al cliente por WhatsApp si su ventana de 24 h está abierta.
     * Devuelve true si recién se marcó como pagado.
     */
    public static function aprobar(PDO $db, array $linea, array $pedido, string $transaccion, string $respuesta, string $origen): bool
    {
        $st = $db->prepare('SELECT * FROM pedidos WHERE id = ?');
        $st->execute([(int) $pedido['id']]);
        $pedido = $st->fetch() ?: $pedido;
        $db->prepare("UPDATE pedidos SET metodo = 'plux', plux_paso = 'pagado', plux_respuesta = ?, plux_transaccion = COALESCE(NULLIF(?, ''), plux_transaccion) WHERE id = ?")
           ->execute([$respuesta, $transaccion, (int) $pedido['id']]);
        if (!in_array($pedido['estado'], ['carrito', 'pendiente'], true)) {
            return false;   // ya estaba pagado (el aviso de pago y la página pueden llegar los dos)
        }
        try {
            Pedidos::cambiarEstado($db, $linea, (int) $pedido['id'], 'pagado');
        } catch (RuntimeException $e) {
            $db->prepare("UPDATE pedidos SET estado = 'pagado', pagado_en = now(), actualizado = now(), notas = trim(notas || ' ' || ?) WHERE id = ?")
               ->execute(['[Pagado con Plux, revisar: ' . $e->getMessage() . ']', (int) $pedido['id']]);
        }
        $pedido = Pedidos::buscar($db, (int) $linea['id'], (int) $pedido['id']) ?? $pedido;
        $total = '$' . number_format((float) $pedido['total'], 2, ',', '.');
        $contacto = null;
        if ($pedido['contacto_id'] !== null) {
            $st = $db->prepare('SELECT * FROM contactos WHERE id = ?');
            $st->execute([(int) $pedido['contacto_id']]);
            $contacto = $st->fetch() ?: null;
        }
        try {
            $usuarios = [];
            if ($contacto && !empty($contacto['asignado_a'])) {
                $usuarios = [(int) $contacto['asignado_a']];
            } else {
                $st = $db->prepare('SELECT id FROM usuarios WHERE empresa_id = ? AND activo AND recibe_chats');
                $st->execute([(int) $linea['empresa_id']]);
                $usuarios = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            Push::enviar($db, $usuarios, ['titulo' => 'Pago con tarjeta: ' . $total, 'texto' => 'Pedido #' . $pedido['numero'] . ' · ' . Pedidos::nombreCliente($pedido),
                                          'url' => '/?r=pedido&id=' . (int) $pedido['id'], 'tag' => 'tuwa-pago-' . (int) $pedido['id']]);
        } catch (\Throwable $e) {
            error_log('tuwhatsapp Plux aviso al equipo: ' . $e->getMessage());
        }
        if ($contacto) {
            try {
                (new Mensajeria($db))->texto($linea, $contacto, '✅ Recibimos tu pago de ' . $total . ' del pedido #' . $pedido['numero'] . '. ¡Gracias por tu compra!', 'auto');
            } catch (\Throwable $e) {
                error_log('tuwhatsapp Plux confirmación: ' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Aviso de pago de Plux (webhook). Basic Auth: "tukan<id del número>" + la clave del aviso, o el id de cliente
     * y la clave secreta de Plux de ese número. Devuelve [código HTTP, respuesta].
     */
    public static function webhook(PDO $db, string $usuario, string $clave, string $cuerpo): array
    {
        $linea = null;
        if (preg_match('/^tukan(\d{1,9})$/', $usuario, $m)) {
            $st = $db->prepare('SELECT * FROM lineas WHERE id = ?');
            $st->execute([(int) $m[1]]);
            $l = $st->fetch();
            if ($l && preg_match('/^[a-f0-9]{32}$/', (string) $l['plux_aviso']) && hash_equals((string) $l['plux_aviso'], $clave)) {
                $linea = $l;
            }
        } elseif ($usuario !== '') {
            $st = $db->prepare("SELECT * FROM lineas WHERE plux_cliente = ? AND plux_cliente <> ''");
            $st->execute([$usuario]);
            foreach ($st->fetchAll() as $l) {
                if (hash_equals(Cifrado::descifrar((string) $l['plux_clave']), $clave)) {
                    $linea = $l;
                    break;
                }
            }
        }
        if ($linea === null) {
            return [401, ['ok' => false, 'error' => 'No autorizado.']];
        }
        $in = json_decode($cuerpo, true);
        if (!is_array($in)) {
            return [400, ['ok' => false, 'error' => 'El aviso no es JSON.']];
        }
        $plano = [];
        array_walk_recursive($in, function ($v, $k) use (&$plano) {
            if (is_scalar($v)) {
                $plano[strtolower((string) $k)][] = (string) $v;
            }
        });
        $primero = function (array $claves) use ($plano): string {
            foreach ($claves as $k) {
                if (!empty($plano[$k][0])) {
                    return $plano[$k][0];
                }
            }
            return '';
        };
        $estado = strtoupper($primero(['state', 'estado', 'status']));
        if (!in_array($estado, ['PAGADO', 'RECHAZADO'], true)) {
            return [422, ['ok' => false, 'error' => 'Estado desconocido (se espera PAGADO o RECHAZADO).']];
        }
        // ¿De cuál pedido? 1) el número de transacción de Plux  2) la referencia "WT-<id>" de la descripción
        $p = null;
        $tr = $primero(['id_transaccion', 'idtransaccion', 'idtransaction', 'transactionid', 'id_transaction', 'token', 'voucher']);
        if ($tr !== '') {
            $st = $db->prepare("SELECT * FROM pedidos WHERE linea_id = ? AND plux_transaccion = ? AND plux_transaccion <> ''");
            $st->execute([(int) $linea['id'], $tr]);
            $p = $st->fetch() ?: null;
        }
        if ($p === null) {
            foreach ($plano as $vals) {
                foreach ($vals as $v) {
                    if (preg_match('/\bWT-(\d{1,10})\b/', $v, $mm)) {
                        $st = $db->prepare('SELECT * FROM pedidos WHERE id = ? AND linea_id = ?');
                        $st->execute([(int) $mm[1], (int) $linea['id']]);
                        $p = $st->fetch() ?: null;
                        break 2;
                    }
                }
            }
        }
        if ($p === null) {
            $db->prepare('INSERT INTO webhook_log (cuerpo, error) VALUES (?, ?)')->execute([mb_substr('plux ' . self::texto($in), 0, 2000), 'Aviso de Plux sin pedido']);
            return [404, ['ok' => false, 'error' => 'Pedido no encontrado.']];
        }
        $monto = $primero(['amount', 'monto', 'total', 'valor']);
        if ($monto !== '' && is_numeric($monto) && abs((float) $monto - (float) $p['total']) > 0.009) {
            $db->prepare('INSERT INTO webhook_log (cuerpo, error) VALUES (?, ?)')
               ->execute([mb_substr('plux ' . self::texto($in), 0, 2000), 'Aviso de Plux del pedido ' . $p['id'] . ' con otro valor (' . $monto . ' en vez de ' . $p['total'] . '): no se aplicó']);
            return [409, ['ok' => false, 'error' => 'El valor no coincide con el pedido.']];
        }
        $txt = self::texto($in);
        if ($estado === 'PAGADO') {
            $nuevo = self::aprobar($db, $linea, $p, $tr, $txt, 'aviso de pago');
            return [200, ['ok' => true, 'pedido' => (int) $p['numero'], 'resultado' => $nuevo ? 'pagado' : 'ya_estaba_' . $p['estado']]];
        }
        if (in_array($p['estado'], ['carrito', 'pendiente'], true)) {
            $db->prepare("UPDATE pedidos SET plux_paso = 'rechazado', plux_respuesta = ? WHERE id = ?")->execute([$txt, (int) $p['id']]);
            return [200, ['ok' => true, 'pedido' => (int) $p['numero'], 'resultado' => 'rechazado']];
        }
        $db->prepare('INSERT INTO webhook_log (cuerpo, error) VALUES (?, ?)')
           ->execute([mb_substr('plux ' . $txt, 0, 2000), 'Plux avisó RECHAZADO del pedido ' . $p['id'] . ' que ya estaba ' . $p['estado'] . ': revisar con Plux']);
        return [200, ['ok' => true, 'pedido' => (int) $p['numero'], 'resultado' => 'revisar']];
    }
}
