<?php
declare(strict_types=1);

/**
 * Enlace con TuFactura (factura electrónica del SRI) — pieza común de los programas Tukán que cobran.
 * ORIGINAL: tufactura/docs/enlace/TukanFactura.php. En cada programa hay una COPIA idéntica: no la cambies allá, cámbiala aquí
 * y vuelve a copiarla (docs/ENLACE_PROGRAMAS.md explica cómo). Sin namespace a propósito: sirve igual en todos los programas.
 *
 * Qué hace (9 nov 2026: «todos los programas que cobran deberían tener un enlace para TuFactura que esté
 * desactivado y se pueda activar, para poder también vender TuFactura»):
 *  - Cada negocio pega su clave de TuFactura (tf_…, la crea en TuFactura → Configuración → Conexiones). Se guarda CIFRADA.
 *  - Con un toque (o solo, al cobrar, si encendió «automático») el cobro sale como factura del SRI por la API de TuFactura
 *    (POST /api/v1/facturas, con una referencia que nunca se repite: el mismo cobro nunca se factura dos veces).
 *  - Guarda número, clave de acceso y estado de cada factura; el PDF se baja a través del programa (la clave no sale al navegador).
 *  - Si TuFactura no responde, el cobro NUNCA se cae: la factura queda «por reintentar».
 * El módulo del programa (id «facturacion») viene APAGADO: se enciende desde el Panel Tukán Central cuando el cliente compra TuFactura.
 * Funciona con PostgreSQL, MySQL y SQLite (crea sus dos tablas solo).
 */
final class TukanFactura
{
    public const VERSION = 1;
    public const URL = 'https://factura.tukanec.com';
    public const MODULO = 'facturacion';
    /** Formas de pago del SRI según cómo cobró el programa */
    public const FORMAS = ['efectivo' => '01', 'transferencia' => '20', 'deposito' => '20', 'deuna' => '20', 'payphone' => '19', 'tarjeta' => '19',
                           'tarjeta_debito' => '16', 'plux' => '19', 'cheque' => '20', 'otro' => '20'];
    public const PENDIENTES = ['pendiente', 'error', 'firmado', 'recibida'];

    /** @var callable|null pedidos HTTP (las pruebas lo cambian): fn(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array{0:int,1:string,2:string} */
    public static $http = null;

    private string $motor;

    /**
     * @param PDO    $db     la base del programa
     * @param string $llave  un secreto del programa (de su config/.env) para cifrar las claves tf_ de los negocios
     * @param string $programa nombre corto del programa (sale en TuFactura como origen de las facturas)
     */
    public function __construct(private PDO $db, private string $llave, private string $programa = 'Tukán')
    {
        if (strlen($llave) < 16) throw new RuntimeException('TukanFactura: falta la llave del programa para cifrar las claves.');
        $this->motor = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        self::asegurar($db);
    }

    // ------------------------------------------------------------------ Tablas
    public static function asegurar(PDO $db): void
    {
        static $hecho = [];
        $k = spl_object_id($db);
        if (isset($hecho[$k])) return;
        $m = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = match ($m) { 'pgsql' => 'id SERIAL PRIMARY KEY', 'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY', default => 'id INTEGER PRIMARY KEY AUTOINCREMENT' };
        $txt = $m === 'mysql' ? 'VARCHAR(250)' : 'TEXT';
        $largo = $m === 'mysql' ? 'TEXT' : 'TEXT';
        $extra = $m === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS tukan_factura_conexion (
            negocio_id INTEGER NOT NULL PRIMARY KEY,
            url $txt NOT NULL, clave_cif $largo NOT NULL, prefijo VARCHAR(12) NOT NULL,
            empresa $txt NOT NULL, ruc VARCHAR(13) NOT NULL, punto VARCHAR(10) NOT NULL, ambiente VARCHAR(12) NOT NULL,
            auto SMALLINT NOT NULL DEFAULT 0, precios_con_iva SMALLINT NOT NULL DEFAULT 1, iva SMALLINT NOT NULL DEFAULT 15,
            conectado VARCHAR(25) NOT NULL, ultimo_error $largo
        )$extra");
        $db->exec("CREATE TABLE IF NOT EXISTS tukan_factura_documentos (
            $id,
            negocio_id INTEGER NOT NULL, origen VARCHAR(30) NOT NULL, origen_id VARCHAR(60) NOT NULL, referencia VARCHAR(100) NOT NULL,
            tf_id INTEGER, estado VARCHAR(20) NOT NULL, numero VARCHAR(20), clave_acceso VARCHAR(49), total DECIMAL(12,2) NOT NULL DEFAULT 0,
            ambiente VARCHAR(12), cliente $txt, mensaje $largo, envio $largo, creado VARCHAR(25) NOT NULL, actualizado VARCHAR(25) NOT NULL,
            UNIQUE (negocio_id, referencia)
        )$extra");
        $hecho[$k] = true;
    }

    // ------------------------------------------------------------------ Cifrado de la clave tf_
    private function llaveCifrado(): string { return hash('sha256', 'tukan-factura|' . $this->llave, true); }
    private function cifrar(string $t): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($t, 'aes-256-gcm', $this->llaveCifrado(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $c);
    }
    private function descifrar(string $t): string
    {
        $b = str_starts_with($t, 'v1:') ? base64_decode(substr($t, 3), true) : false;
        if ($b === false || strlen($b) < 29) throw new RuntimeException('La clave de TuFactura guardada no se puede leer. Pégala otra vez.');
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $this->llaveCifrado(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        if ($r === false) throw new RuntimeException('La clave de TuFactura guardada no se puede leer (cambió la llave del programa). Pégala otra vez.');
        return $r;
    }

    // ------------------------------------------------------------------ HTTP
    private static function pedir(string $metodo, string $url, array $cab, ?string $cuerpo = null, int $espera = 40): array
    {
        if (self::$http !== null) return (self::$http)($metodo, $url, $cab, $cuerpo);
        $c = curl_init($url);
        $tipo = '';
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cab, CURLOPT_TIMEOUT => $espera,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => function ($ch, $l) use (&$tipo) { if (stripos($l, 'content-type:') === 0) $tipo = trim(substr($l, 13)); return strlen($l); }]);
        if ($cuerpo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo);
        $r = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);
        if ($r === false) return [0, $err, ''];
        return [$code, (string) $r, $tipo];
    }
    private function api(array $con, string $metodo, string $ruta, ?array $datos = null, ?string $clave = null): array
    {
        $clave ??= $this->descifrar((string) $con['clave_cif']);
        $cab = ['Authorization: Bearer ' . $clave, 'Accept: application/json', 'User-Agent: TukanFactura/' . self::VERSION . ' (' . $this->programa . ')'];
        $cuerpo = null;
        if ($datos !== null) {
            $cab[] = 'Content-Type: application/json';
            $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        [$code, $r] = self::pedir($metodo, rtrim((string) $con['url'], '/') . '/api/v1' . $ruta, $cab, $cuerpo);
        $j = json_decode($r, true);
        return [$code, is_array($j) ? $j : null, $r];
    }
    private static function errorDe(int $code, ?array $j, string $crudo): string
    {
        if ($code === 0) return 'No se pudo hablar con TuFactura (' . mb_substr($crudo, 0, 120) . '). Se reintenta después.';
        if (is_array($j) && is_string($j['error'] ?? null) && $j['error'] !== '') return $j['error'];
        return match (true) {
            $code === 401 => 'La clave de TuFactura no es válida o la desactivaron. Pega una nueva.',
            $code === 403 => 'TuFactura no deja facturar con esta clave (cuenta vencida, suspendida o sin el módulo «Conexión con otros sistemas»).',
            $code >= 500 => 'TuFactura tuvo un problema (error ' . $code . '). Se reintenta después.',
            default => 'TuFactura respondió ' . $code . '.',
        };
    }
    private static function ahora(): string { return date('Y-m-d H:i:s'); }

    // ------------------------------------------------------------------ Conexión de cada negocio
    public function conexion(int $negocio): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tukan_factura_conexion WHERE negocio_id = ?');
        $st->execute([$negocio]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Prueba la clave contra TuFactura (GET /ping) y la guarda cifrada. Devuelve la conexión. */
    public function conectar(int $negocio, string $clave, string $url = self::URL): array
    {
        $clave = strtolower(trim($clave));
        if (!preg_match('/^tf_[a-f0-9]{40}$/', $clave)) throw new RuntimeException('La clave de TuFactura empieza con tf_ y tiene 43 letras y números. Cópiala otra vez desde TuFactura → Configuración → Conexiones.');
        $url = rtrim(trim($url), '/') ?: self::URL;
        if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $url)) throw new RuntimeException('La dirección de TuFactura no es válida (ejemplo: ' . self::URL . ').');
        if (!str_starts_with($url, 'https://') && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?#', $url)) throw new RuntimeException('La dirección de TuFactura debe empezar con https://');
        [$code, $j, $crudo] = $this->api(['url' => $url], 'GET', '/ping', null, $clave);
        if ($code !== 200 || !is_array($j) || !($j['ok'] ?? false)) throw new RuntimeException(self::errorDe($code, $j, $crudo));
        $empresa = (string) ($j['empresa']['razon_social'] ?? $j['empresa'] ?? $j['razon_social'] ?? '');
        $ruc = (string) ($j['empresa']['ruc'] ?? $j['ruc'] ?? '');
        $punto = (string) ($j['punto']['numero'] ?? $j['punto_emision'] ?? $j['punto'] ?? '');
        $amb = (string) ($j['ambiente'] ?? '');
        $antes = $this->conexion($negocio);
        $d = [$url, $this->cifrar($clave), substr($clave, 0, 10), mb_substr($empresa, 0, 250), substr($ruc, 0, 13), substr($punto, 0, 10), substr($amb, 0, 12), self::ahora()];
        if ($antes) {
            $this->db->prepare('UPDATE tukan_factura_conexion SET url = ?, clave_cif = ?, prefijo = ?, empresa = ?, ruc = ?, punto = ?, ambiente = ?, conectado = ?, ultimo_error = NULL WHERE negocio_id = ?')
                ->execute([...$d, $negocio]);
        } else {
            $this->db->prepare('INSERT INTO tukan_factura_conexion (url, clave_cif, prefijo, empresa, ruc, punto, ambiente, conectado, negocio_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$d, $negocio]);
        }
        return $this->conexion($negocio);
    }

    public function desconectar(int $negocio): void
    {
        $this->db->prepare('DELETE FROM tukan_factura_conexion WHERE negocio_id = ?')->execute([$negocio]);
    }

    /** auto (facturar solo al cobrar), precios_con_iva (los precios del programa ya traen IVA) e iva (15, 5 o 0). */
    public function opciones(int $negocio, bool $auto, bool $preciosConIva, int $iva): void
    {
        if (!in_array($iva, [15, 5, 0], true)) throw new RuntimeException('El IVA debe ser 15 %, 5 % o 0 %.');
        $this->db->prepare('UPDATE tukan_factura_conexion SET auto = ?, precios_con_iva = ?, iva = ? WHERE negocio_id = ?')
            ->execute([$auto ? 1 : 0, $preciosConIva ? 1 : 0, $iva, $negocio]);
    }

    // ------------------------------------------------------------------ Facturas
    public function documento(int $negocio, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tukan_factura_documentos WHERE negocio_id = ? AND id = ?');
        $st->execute([$negocio, $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function deOrigen(int $negocio, string $origen, string $origenId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tukan_factura_documentos WHERE negocio_id = ? AND referencia = ?');
        $st->execute([$negocio, self::referencia($origen, $origenId)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    /** @return array<string, array> referencia => documento, de una lista de ids de un mismo origen */
    public function deOrigenes(int $negocio, string $origen, array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn($x) => $x !== ''));
        if (!$ids) return [];
        $refs = array_map(fn($i) => self::referencia($origen, $i), $ids);
        $st = $this->db->prepare('SELECT * FROM tukan_factura_documentos WHERE negocio_id = ? AND referencia IN (' . implode(',', array_fill(0, count($refs), '?')) . ')');
        $st->execute([$negocio, ...$refs]);
        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $r[(string) $d['origen_id']] = $d;
        return $r;
    }
    public function lista(int $negocio, int $limite = 50): array
    {
        $st = $this->db->prepare('SELECT * FROM tukan_factura_documentos WHERE negocio_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limite)));
        $st->execute([$negocio]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function referencia(string $origen, string $origenId): string
    {
        return mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($origen)) . '-' . preg_replace('/[^A-Za-z0-9_.:-]/', '', $origenId), 0, 100);
    }

    /** Cédula (10) o RUC (13) con su dígito verificador: si no, va a consumidor final. Pasaporte: letras y números. */
    public static function identificacionValida(string $id): bool
    {
        if (preg_match('/^\d{13}$/', $id)) {
            if (substr($id, 10) === '000') return false;
            $prov = (int) substr($id, 0, 2);
            if (in_array((int) $id[2], [6, 9], true)) return ($prov >= 1 && $prov <= 24) || $prov === 30;   // sociedades y públicas
            return self::identificacionValida(substr($id, 0, 10));                                              // persona natural
        }
        if (!preg_match('/^\d{10}$/', $id)) return false;
        $p = (int) substr($id, 0, 2);
        if (($p < 1 || $p > 24) && $p !== 30) return false;
        $s = 0;
        for ($i = 0; $i < 9; $i++) { $v = (int) $id[$i] * ($i % 2 === 0 ? 2 : 1); $s += $v > 9 ? $v - 9 : $v; }
        return (10 - $s % 10) % 10 === (int) $id[9];
    }

    /**
     * Arma lo que se manda a TuFactura con los datos del cobro.
     * @param array $cliente  identificacion, nombre, correo, telefono, direccion (sin identificación válida → consumidor final)
     * @param array $items    [[descripcion, cantidad, precio (como lo cobra el programa), codigo?, detalle?, iva? (15/5/0)], …]
     * @param array $extra    forma (efectivo|transferencia|tarjeta…), observacion, info [clave => valor], descuento (en dinero, sobre el total)
     */
    public function armar(array $con, string $origen, string $origenId, array $cliente, array $items, array $extra = []): array
    {
        $conIva = (int) $con['precios_con_iva'] === 1;
        $ivaDef = (int) $con['iva'];
        $lineas = [];
        foreach ($items as $it) {
            $cant = round((float) ($it['cantidad'] ?? 1), 4);
            // precio_sin_iva: el programa ya separa el IVA (ej. cotizaciones con subtotal + IVA): se manda tal cual
            $fijo = isset($it['precio_sin_iva']);
            $precio = (float) ($fijo ? $it['precio_sin_iva'] : ($it['precio'] ?? 0));
            if ($cant <= 0 || $precio < 0) continue;
            $iva = isset($it['iva']) ? (int) $it['iva'] : $ivaDef;
            $lineas[] = array_filter(['codigo' => mb_substr((string) ($it['codigo'] ?? ''), 0, 25), 'descripcion' => mb_substr(trim((string) ($it['descripcion'] ?? 'Servicio')) ?: 'Servicio', 0, 300),
                'detalle' => mb_substr((string) ($it['detalle'] ?? ''), 0, 120), 'cantidad' => $cant, 'precio' => round($precio, 6), 'iva' => $iva, '_fijo' => $fijo ?: ''], fn($v) => $v !== '');
        }
        if (!$lineas) throw new RuntimeException('El cobro no tiene nada que facturar (sin servicios ni productos con valor).');
        if ($conIva) {
            $libres = array_filter($lineas, fn($l) => empty($l['_fijo']));
            $lineas = array_replace($lineas, $libres ? self::quitarIva($libres) : []);
        }
        $lineas = array_values(array_map(function ($l) { unset($l['_fijo']); return $l; }, $lineas));
        $ident = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($cliente['identificacion'] ?? '')));
        $nombre = trim((string) ($cliente['nombre'] ?? ''));
        $valida = $ident !== '' && $nombre !== '' && (self::identificacionValida($ident) || (preg_match('/^[A-Z0-9]{5,20}$/', $ident) && preg_match('/[A-Z]/', $ident)));
        $correo = trim((string) ($cliente['correo'] ?? ''));
        $cli = $valida ? array_filter(['identificacion' => $ident, 'razon_social' => mb_substr($nombre, 0, 300),
            'correos' => filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '', 'telefono' => mb_substr((string) ($cliente['telefono'] ?? ''), 0, 30),
            'direccion' => mb_substr((string) ($cliente['direccion'] ?? ''), 0, 300)], fn($v) => $v !== '') : ['identificacion' => '9999999999999', 'razon_social' => 'CONSUMIDOR FINAL'];
        $info = [];
        foreach ((array) ($extra['info'] ?? []) as $k => $v) if ((string) $v !== '' && count($info) < 5) $info[mb_substr((string) $k, 0, 30)] = mb_substr((string) $v, 0, 280);
        $forma = self::FORMAS[(string) ($extra['forma'] ?? 'otro')] ?? '20';
        return ['referencia' => self::referencia($origen, $origenId), 'cliente' => $cli, 'items' => $lineas, 'forma_pago' => $forma,
                'observacion' => mb_substr((string) ($extra['observacion'] ?? ''), 0, 280), 'info' => $info ?: new stdClass(), 'emitir' => true];
    }

    /**
     * Precios con IVA → precios sin IVA, de modo que el total de la factura (como lo calcula el SRI: base de cada línea a 2 decimales
     * y el IVA sobre la suma de cada tarifa) sea EXACTAMENTE lo que cobró el programa. Cuando la matemática del SRI no lo permite
     * (ej. $10,00 con 15 %: sale $9,99 o $10,01), queda 1 centavo MENOS (nunca más de lo que pagó el cliente).
     */
    public static function quitarIva(array $lineas): array
    {
        $grupos = [];
        foreach ($lineas as $i => $l) $grupos[$l['iva']][] = $i;
        foreach ($grupos as $t => $idx) {
            $f = 1 + $t / 100;
            $meta = 0.0;
            foreach ($idx as $i) $meta += round($lineas[$i]['cantidad'] * $lineas[$i]['precio'], 2);
            $meta = round($meta, 2);
            $mejor = null;
            for ($s = round($meta / $f, 2) - 0.03; $s <= round($meta / $f, 2) + 0.031; $s += 0.01) {
                $s = round($s, 2);
                $tot = round($s + round($s * $t / 100, 2), 2);
                if ($tot <= $meta + 0.0001 && ($mejor === null || $tot > $mejor[1] + 0.0001)) $mejor = [$s, $tot];
            }
            $base = $mejor[0] ?? round($meta / $f, 2);
            $suma = 0.0;
            foreach ($idx as $k => $i) {
                $b = $k === array_key_last($idx) ? round($base - $suma, 2) : round(round($lineas[$i]['cantidad'] * $lineas[$i]['precio'], 2) / $f, 2);
                $suma = round($suma + $b, 2);
                $lineas[$i]['precio'] = round(max(0, $b) / $lineas[$i]['cantidad'], 6);
            }
        }
        return $lineas;
    }

    /**
     * Factura un cobro en TuFactura (o devuelve la que ya existe). Nunca factura dos veces el mismo origen.
     * Lanza RuntimeException con el motivo en palabras sencillas si TuFactura no la pudo hacer (y lo deja anotado).
     */
    public function facturar(int $negocio, string $origen, string $origenId, array $cliente, array $items, array $extra = []): array
    {
        $con = $this->conexion($negocio);
        if (!$con) throw new RuntimeException('Primero conecta tu cuenta de TuFactura (pega la clave).');
        $doc = $this->deOrigen($negocio, $origen, $origenId);
        if ($doc && $doc['estado'] === 'autorizado') return $doc;
        $envio = $this->armar($con, $origen, $origenId, $cliente, $items, $extra);
        $total = 0.0;
        foreach ($envio['items'] as $l) $total += $l['cantidad'] * $l['precio'] * (1 + $l['iva'] / 100);
        $total = round($total, 2);
        $json = json_encode($envio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nombreCli = (string) $envio['cliente']['razon_social'];
        if (!$doc) {
            try {
                $this->db->prepare("INSERT INTO tukan_factura_documentos (negocio_id, origen, origen_id, referencia, estado, total, cliente, envio, creado, actualizado) VALUES (?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?)")
                    ->execute([$negocio, mb_substr($origen, 0, 30), mb_substr($origenId, 0, 60), $envio['referencia'], number_format($total, 2, '.', ''), mb_substr($nombreCli, 0, 250), $json, self::ahora(), self::ahora()]);
            } catch (PDOException) {
                // otro pedido al mismo tiempo la creó: se sigue con esa
            }
            $doc = $this->deOrigen($negocio, $origen, $origenId);
        } else {
            $this->db->prepare('UPDATE tukan_factura_documentos SET envio = ?, total = ?, cliente = ?, actualizado = ? WHERE id = ?')
                ->execute([$json, number_format($total, 2, '.', ''), mb_substr($nombreCli, 0, 250), self::ahora(), $doc['id']]);
        }
        return $this->enviar($negocio, (int) $doc['id'], $con);
    }

    /** Manda (o vuelve a mandar) un documento guardado. */
    public function enviar(int $negocio, int $id, ?array $con = null): array
    {
        $con ??= $this->conexion($negocio);
        $doc = $this->documento($negocio, $id);
        if (!$con || !$doc) throw new RuntimeException('No se encontró la factura o la conexión con TuFactura.');
        if ($doc['tf_id'] && in_array($doc['estado'], ['firmado', 'recibida'], true)) return $this->refrescar($negocio, $id);
        $envio = json_decode((string) $doc['envio'], true);
        try {
            [$code, $j, $crudo] = $this->api($con, 'POST', '/facturas', $envio);
        } catch (RuntimeException $e) {
            $this->anotar($id, 'error', $e->getMessage());
            throw $e;
        }
        if (in_array($code, [200, 201], true) && is_array($j) && ($j['ok'] ?? false)) {
            $this->guardarRespuesta($id, $j);
            $doc = $this->documento($negocio, $id);
            if (in_array($doc['estado'], ['devuelta', 'no_autorizado'], true)) throw new RuntimeException('El SRI no la autorizó: ' . ($doc['mensaje'] ?: 'sin motivo'));
            return $doc;
        }
        $msg = self::errorDe($code, $j, $crudo);
        if (is_array($j) && isset($j['id'])) $this->db->prepare('UPDATE tukan_factura_documentos SET tf_id = ? WHERE id = ?')->execute([(int) $j['id'], $id]);
        $this->anotar($id, 'error', $msg);
        $this->db->prepare('UPDATE tukan_factura_conexion SET ultimo_error = ? WHERE negocio_id = ?')->execute([mb_substr($msg, 0, 500), $negocio]);
        throw new RuntimeException($msg);
    }

    /** Pregunta a TuFactura cómo va una factura (las que esperan al SRI). */
    public function refrescar(int $negocio, int $id): array
    {
        $con = $this->conexion($negocio);
        $doc = $this->documento($negocio, $id);
        if (!$con || !$doc) throw new RuntimeException('No se encontró la factura o la conexión con TuFactura.');
        if (!$doc['tf_id']) return $this->enviar($negocio, $id, $con);
        [$code, $j, $crudo] = $this->api($con, 'GET', '/facturas/' . (int) $doc['tf_id']);
        if ($code === 200 && is_array($j)) {
            $this->guardarRespuesta($id, $j);
        } elseif ($code === 404) {
            $this->anotar($id, 'error', 'TuFactura ya no tiene esta factura (¿se borraron las pruebas?). Vuelve a facturar.');
            $this->db->prepare('UPDATE tukan_factura_documentos SET tf_id = NULL WHERE id = ?')->execute([$id]);
        } else {
            $this->anotar($id, $doc['estado'], self::errorDe($code, $j, $crudo));
        }
        return $this->documento($negocio, $id);
    }

    /** Reintenta las que quedaron pendientes o con error (botón «Reintentar» o tarea automática). */
    public function reintentar(int $negocio, int $max = 20): array
    {
        $st = $this->db->prepare("SELECT id FROM tukan_factura_documentos WHERE negocio_id = ? AND estado IN ('pendiente', 'error', 'firmado', 'recibida') ORDER BY id LIMIT " . max(1, min(100, $max)));
        $st->execute([$negocio]);
        $r = ['bien' => 0, 'mal' => 0];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try { $d = $this->refrescar($negocio, (int) $id); $r[$d['estado'] === 'autorizado' ? 'bien' : 'mal']++; }
            catch (RuntimeException) { $r['mal']++; }
        }
        return $r;
    }

    private function guardarRespuesta(int $id, array $j): void
    {
        $estado = in_array($j['estado'] ?? '', ['autorizado', 'firmado', 'recibida', 'devuelta', 'no_autorizado', 'anulado', 'borrador'], true) ? $j['estado'] : 'error';
        $this->db->prepare('UPDATE tukan_factura_documentos SET tf_id = ?, estado = ?, numero = ?, clave_acceso = ?, total = ?, ambiente = ?, mensaje = ?, actualizado = ? WHERE id = ?')
            ->execute([(int) ($j['id'] ?? 0) ?: null, $estado, isset($j['numero']) ? mb_substr((string) $j['numero'], 0, 20) : null, isset($j['clave_acceso']) ? substr((string) $j['clave_acceso'], 0, 49) : null,
                number_format((float) ($j['total'] ?? 0), 2, '.', ''), mb_substr((string) ($j['ambiente'] ?? ''), 0, 12), mb_substr((string) ($j['mensajes'] ?? ''), 0, 1000), self::ahora(), $id]);
    }
    private function anotar(int $id, string $estado, string $msg): void
    {
        $this->db->prepare('UPDATE tukan_factura_documentos SET estado = ?, mensaje = ?, actualizado = ? WHERE id = ?')->execute([$estado, mb_substr($msg, 0, 1000), self::ahora(), $id]);
    }

    /** El PDF (RIDE) de una factura autorizada, bajado con la clave del negocio. */
    public function pdf(int $negocio, int $id): string
    {
        $con = $this->conexion($negocio);
        $doc = $this->documento($negocio, $id);
        if (!$con || !$doc || !$doc['tf_id'] || $doc['estado'] !== 'autorizado') throw new RuntimeException('La factura todavía no está autorizada.');
        $clave = $this->descifrar((string) $con['clave_cif']);
        [$code, $r, $tipo] = self::pedir('GET', rtrim((string) $con['url'], '/') . '/api/v1/facturas/' . (int) $doc['tf_id'] . '/pdf', ['Authorization: Bearer ' . $clave]);
        if ($code !== 200 || !str_starts_with($r, '%PDF')) throw new RuntimeException('TuFactura no entregó el PDF (' . $code . ').');
        return $r;
    }

    /**
     * Para el final de cada cobro: si el módulo está encendido y el negocio eligió «automático», factura sin hacer esperar
     * a la persona. Nunca lanza errores: el cobro ya está hecho.
     *  - Si el cobro todavía está dentro de una transacción (o con PHP-FPM), se hace al TERMINAR el pedido: así solo se factura
     *    lo que de verdad quedó guardado ($datos se lee en ese momento: si devuelve null, no se factura nada).
     * @param callable $datos fn(): ?array{0: array $cliente, 1: array $items, 2?: array $extra}
     */
    public function alCobrar(int $negocio, bool $moduloActivo, string $origen, string $origenId, callable $datos): bool
    {
        try {
            if (!$moduloActivo) return false;
            $con = $this->conexion($negocio);
            if (!$con || (int) $con['auto'] !== 1) return false;
            $hacer = function () use ($negocio, $origen, $origenId, $datos) {
                try {
                    $d = $datos();
                    if (!is_array($d)) return;
                    [$cliente, $items, $extra] = $d + [2 => []];
                    $this->facturar($negocio, $origen, $origenId, $cliente, $items, $extra);
                } catch (Throwable $e) {
                    error_log('TukanFactura (' . $this->programa . ', negocio ' . $negocio . ', ' . $origen . ' ' . $origenId . '): ' . $e->getMessage());
                }
            };
            $fpm = PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request') && getenv('TUKAN_FACTURA_AL_MOMENTO') !== '1';
            if ($fpm || $this->db->inTransaction()) {
                register_shutdown_function(function () use ($hacer, $fpm) { if ($fpm) @fastcgi_finish_request(); $hacer(); });
            } else {
                $hacer();
            }
            return true;
        } catch (Throwable $e) {
            error_log('TukanFactura alCobrar: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ Pantalla (la misma en todos los programas)
    public static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    public static function nombreEstado(string $e): string
    {
        return ['autorizado' => 'Autorizada', 'pendiente' => 'Enviando…', 'firmado' => 'Esperando al SRI', 'recibida' => 'Esperando al SRI', 'devuelta' => 'El SRI la devolvió',
                'no_autorizado' => 'No autorizada', 'error' => 'Por reintentar', 'anulado' => 'Anulada', 'borrador' => 'Borrador en TuFactura'][$e] ?? $e;
    }

    /**
     * Atiende el formulario de la pantalla (POST). Devuelve [tipo, texto] para el aviso, o null si no hubo nada.
     * Campos: tf_accion = conectar | opciones | desconectar | reintentar | refrescar | facturar_origen
     * @param callable|null $datosDe fn(string $origenId): ?array{0: array $cliente, 1: array $items, 2?: array $extra}  (para «Facturar» un cobro de la lista)
     */
    public function atender(int $negocio, array $post, ?callable $datosDe = null, string $origen = 'cobro'): ?array
    {
        $acc = (string) ($post['tf_accion'] ?? '');
        try {
            switch ($acc) {
                case 'conectar':
                    $c = $this->conectar($negocio, (string) ($post['clave'] ?? ''), (string) ($post['url'] ?? '') ?: self::URL);
                    return ['ok', 'Conectado con TuFactura: ' . $c['empresa'] . ' (punto ' . $c['punto'] . ', ' . ($c['ambiente'] === 'produccion' ? 'facturas reales' : 'ambiente de pruebas') . ').'];
                case 'opciones':
                    $this->opciones($negocio, !empty($post['auto']), !empty($post['precios_con_iva']), (int) ($post['iva'] ?? 15));
                    return ['ok', 'Guardado.'];
                case 'desconectar':
                    $this->desconectar($negocio);
                    return ['ok', 'Se quitó la conexión con TuFactura. Las facturas ya hechas siguen en TuFactura.'];
                case 'reintentar':
                    $r = $this->reintentar($negocio);
                    return [$r['mal'] ? 'error' : 'ok', 'Reintento: ' . $r['bien'] . ' autorizada(s)' . ($r['mal'] ? ', ' . $r['mal'] . ' todavía con problemas (mira el motivo en la lista).' : '.')];
                case 'refrescar':
                    $d = $this->refrescar($negocio, (int) ($post['id'] ?? 0));
                    return [$d['estado'] === 'autorizado' ? 'ok' : 'error', 'Factura ' . ($d['numero'] ?: '') . ': ' . self::nombreEstado($d['estado']) . ($d['mensaje'] ? ' — ' . $d['mensaje'] : '')];
                case 'facturar_origen':
                    $oid = (string) ($post['origen_id'] ?? '');
                    $datos = $datosDe ? $datosDe($oid) : null;
                    if (!$datos) throw new RuntimeException('No se encontró ese cobro.');
                    [$cli, $items, $extra] = $datos + [2 => []];
                    $d = $this->facturar($negocio, (string) ($post['origen'] ?? $origen), $oid, $cli, $items, $extra);
                    return ['ok', 'Factura ' . $d['numero'] . ': ' . self::nombreEstado($d['estado']) . '.'];
            }
        } catch (RuntimeException $e) {
            return ['error', $e->getMessage()];
        }
        return null;
    }

    /**
     * La pantalla «Factura electrónica (TuFactura)».
     * @param array $p sin_compras (bool: dentro de la app de Google Play, sin enlaces para comprar), modulo (bool: módulo encendido), csrf (HTML del campo oculto), accion (URL del formulario), pdf (fn(int $id): string URL del PDF),
     *                 pedir (URL para pedir el módulo o null), cobros (lista [origen_id, fecha, cliente, total, texto] para facturar a mano), origen,
     *                 programa (nombre que ve la persona), es_admin (bool: puede conectar y cambiar opciones)
     */
    public function pantalla(int $negocio, array $p): string
    {
        $e = [self::class, 'e'];
        $prog = (string) ($p['programa'] ?? $this->programa);
        ob_start();
        echo '<style>.tf-tabla{width:100%;border-collapse:collapse;font-size:14px}.tf-tabla th,.tf-tabla td{text-align:left;padding:8px 6px;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:top}'
           . '.tf-num{text-align:right;white-space:nowrap}.tf-estado{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12.5px;font-weight:700;background:rgba(0,0,0,.06)}'
           . '.tf-autorizado{background:#E3F4EA;color:#1D7A46}.tf-error,.tf-devuelta,.tf-no_autorizado{background:#FBE7E6;color:#B3261E}.tf-firmado,.tf-recibida,.tf-pendiente{background:#E7EEFB;color:#2C55A8}'
           . '.tf-fila{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.tf-fila form{margin:0}.tf-caja{overflow-x:auto}.tf-check{display:flex;gap:10px;align-items:center;margin:8px 0}</style>';
        if (empty($p['modulo'])) {
            echo '<section class="bloque tarjeta"><h2>🧾 Factura electrónica del SRI con TuFactura</h2>'
               . '<p>Cada cobro de ' . $e($prog) . ' puede salir como <strong>factura electrónica autorizada por el SRI</strong>, con un toque o sola al cobrar. Le llega al cliente por correo con el PDF y el XML.</p>'
               . '<ul><li>Sin escribir dos veces: los datos del cliente y lo que cobraste pasan solos.</li><li>Tus facturas, notas de crédito y el paquete para el contador quedan en TuFactura.</li>'
               . '<li>Puedes empezar en <strong>ambiente de pruebas</strong> (no vale ante el SRI) y pasar a facturas reales cuando estés listo.</li></ul>'
               . '<p class="suave">Necesitas tu firma electrónica (.p12) y una cuenta de TuFactura de Tukán Software.</p>';
            if (!empty($p['sin_compras'])) {   // app de Google Play: sin enlaces para comprar afuera
                echo '<p class="suave">Pregunta a Tukán Software cómo activarla.</p></section>';
                return (string) ob_get_clean();
            }
            if (!empty($p['pedir'])) echo '<p><a class="boton" href="' . $e($p['pedir']) . '">Quiero facturar con TuFactura</a></p>';
            echo '<p class="suave">También puedes escribir a Tukán Software por <a href="https://wa.me/593983662181?text=' . rawurlencode('Hola, quiero facturar con TuFactura desde ' . $prog) . '">WhatsApp 098 366 2181</a>.</p></section>';
            return (string) ob_get_clean();
        }
        $con = $this->conexion($negocio);
        $admin = $p['es_admin'] ?? true;
        $csrf = (string) ($p['csrf'] ?? '');
        $acc = $e($p['accion'] ?? '');
        if (!$con) {
            echo '<section class="bloque tarjeta"><h2>🧾 Conectar con TuFactura</h2>';
            if ($admin) {
                echo '<ol><li>Entra a <a href="' . self::URL . '" target="_blank" rel="noopener">TuFactura</a> → <strong>Configuración → Conexiones con otros sistemas</strong>.</li>'
                   . '<li>Escribe «' . $e($prog) . '», elige el punto de emisión y toca <strong>Crear clave</strong>.</li><li>Copia la clave (empieza con <code>tf_</code>) y pégala aquí.</li></ol>'
                   . '<form method="post" action="' . $acc . '">' . $csrf . '<input type="hidden" name="tf_accion" value="conectar">'
                   . '<div class="campo"><label for="tf-clave">Clave de TuFactura</label><input id="tf-clave" name="clave" required autocomplete="off" spellcheck="false" placeholder="tf_…" maxlength="60"></div>'
                   . '<details><summary class="suave">Otra dirección de TuFactura (casi nunca hace falta)</summary><div class="campo"><label for="tf-url">Dirección</label><input id="tf-url" name="url" value="' . self::URL . '"></div></details>'
                   . '<p><button class="boton" type="submit">Conectar y probar</button></p></form>';
            } else {
                echo '<p>El administrador del negocio debe conectar la cuenta de TuFactura.</p>';
            }
            echo '</section>';
            return (string) ob_get_clean();
        }
        $real = $con['ambiente'] === 'produccion';
        echo '<section class="bloque tarjeta"><h2>🧾 Conectado con TuFactura</h2>'
           . '<p><strong>' . $e($con['empresa']) . '</strong> · RUC ' . $e($con['ruc']) . ' · punto ' . $e($con['punto']) . ' · '
           . ($real ? '<span class="tf-estado tf-autorizado">Facturas reales</span>' : '<span class="tf-estado tf-pendiente">Ambiente de pruebas (no valen ante el SRI)</span>') . '</p>';
        if ($con['ultimo_error']) echo '<p class="aviso aviso-error">Último problema: ' . $e($con['ultimo_error']) . '</p>';
        if ($admin) {
            echo '<form method="post" action="' . $acc . '">' . $csrf . '<input type="hidden" name="tf_accion" value="opciones">'
               . '<label class="tf-check"><input type="checkbox" name="auto" value="1"' . ((int) $con['auto'] === 1 ? ' checked' : '') . '> Facturar <strong>solo</strong> cada cobro (sin tocar nada)</label>'
               . '<label class="tf-check"><input type="checkbox" name="precios_con_iva" value="1"' . ((int) $con['precios_con_iva'] === 1 ? ' checked' : '') . '> Mis precios ya incluyen el IVA</label>'
               . '<div class="campo"><label for="tf-iva">IVA de lo que vendo</label><select id="tf-iva" name="iva">';
            foreach ([15 => '15 %', 5 => '5 %', 0 => '0 % (no grava IVA)'] as $v => $t) echo '<option value="' . $v . '"' . ((int) $con['iva'] === $v ? ' selected' : '') . '>' . $t . '</option>';
            echo '</select></div><p class="tf-fila"><button class="boton boton-chico" type="submit">Guardar</button></p></form>'
               . '<form method="post" action="' . $acc . '" onsubmit="return confirm(\'¿Quitar la conexión con TuFactura? Las facturas hechas siguen en TuFactura.\')">' . $csrf
               . '<input type="hidden" name="tf_accion" value="desconectar"><button class="boton boton-claro boton-chico" type="submit">Quitar la conexión</button></form>';
        }
        echo '</section>';

        $cobros = (array) ($p['cobros'] ?? []);
        if ($cobros) {
            $docs = $this->deOrigenes($negocio, (string) ($p['origen'] ?? 'cobro'), array_column($cobros, 0));
            echo '<section class="bloque tarjeta"><h2>Cobros recientes</h2><div class="tf-caja"><table class="tf-tabla"><thead><tr><th>Fecha</th><th>Cliente</th><th>Detalle</th><th class="tf-num">Total</th><th>Factura</th></tr></thead><tbody>';
            foreach ($cobros as [$oid, $fecha, $cli, $total, $texto]) {
                $d = $docs[(string) $oid] ?? null;
                echo '<tr><td>' . $e(substr((string) $fecha, 0, 10)) . '</td><td>' . $e($cli ?: 'Consumidor final') . '</td><td class="suave">' . $e(mb_substr((string) $texto, 0, 80)) . '</td><td class="tf-num">$' . number_format((float) $total, 2) . '</td><td>';
                if ($d && $d['estado'] === 'autorizado') {
                    echo '<span class="tf-estado tf-autorizado">' . $e($d['numero']) . '</span>';
                    if (!empty($p['pdf'])) echo ' <a href="' . $e(($p['pdf'])((int) $d['id'])) . '">PDF</a>';
                } else {
                    if ($d) echo '<span class="tf-estado tf-' . $e($d['estado']) . '">' . $e(self::nombreEstado($d['estado'])) . '</span> ';
                    echo '<form method="post" action="' . $acc . '" style="display:inline">' . $csrf . '<input type="hidden" name="tf_accion" value="facturar_origen"><input type="hidden" name="origen_id" value="' . $e($oid) . '">'
                       . '<button class="boton boton-chico" type="submit">' . ($d ? 'Reintentar' : 'Facturar') . '</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }

        $lista = $this->lista($negocio, 30);
        echo '<section class="bloque tarjeta"><div class="tf-fila" style="justify-content:space-between"><h2>Facturas hechas desde ' . $e($prog) . '</h2>';
        if (array_filter($lista, fn($d) => in_array($d['estado'], self::PENDIENTES, true))) {
            echo '<form method="post" action="' . $acc . '">' . $csrf . '<input type="hidden" name="tf_accion" value="reintentar"><button class="boton boton-claro boton-chico" type="submit">Reintentar las pendientes</button></form>';
        }
        echo '</div>';
        if (!$lista) {
            echo '<p class="suave">Todavía no hay facturas. ' . ((int) $con['auto'] === 1 ? 'Saldrán solas con el próximo cobro.' : 'Toca «Facturar» en un cobro.') . '</p>';
        } else {
            echo '<div class="tf-caja"><table class="tf-tabla"><thead><tr><th>Fecha</th><th>Número</th><th>Cliente</th><th class="tf-num">Total</th><th>Estado</th><th></th></tr></thead><tbody>';
            foreach ($lista as $d) {
                echo '<tr><td>' . $e(substr((string) $d['creado'], 0, 10)) . '</td><td>' . $e($d['numero'] ?: '—') . '</td><td>' . $e($d['cliente']) . '</td><td class="tf-num">$' . number_format((float) $d['total'], 2) . '</td>'
                   . '<td><span class="tf-estado tf-' . $e($d['estado']) . '">' . $e(self::nombreEstado($d['estado'])) . '</span>' . ($d['mensaje'] && $d['estado'] !== 'autorizado' ? '<br><span class="suave">' . $e(mb_substr($d['mensaje'], 0, 200)) . '</span>' : '') . '</td><td>';
                if ($d['estado'] === 'autorizado' && !empty($p['pdf'])) echo '<a href="' . $e(($p['pdf'])((int) $d['id'])) . '">PDF</a>';
                elseif (in_array($d['estado'], self::PENDIENTES, true)) echo '<form method="post" action="' . $acc . '">' . $csrf . '<input type="hidden" name="tf_accion" value="refrescar"><input type="hidden" name="id" value="' . (int) $d['id'] . '"><button class="boton boton-claro boton-chico" type="submit">Revisar</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
        return (string) ob_get_clean();
    }

    /** Cajita para la pantalla de un cobro: el estado de su factura o el botón «Facturar». */
    public function cajita(int $negocio, bool $modulo, string $origen, string $origenId, array $p): string
    {
        $e = [self::class, 'e'];
        if (!$modulo) {
            return !empty($p['pantalla']) ? '<p class="suave">🧾 ¿Factura electrónica del SRI? <a href="' . $e($p['pantalla']) . '">Conoce TuFactura</a></p>' : '';
        }
        if (!$this->conexion($negocio)) {
            return !empty($p['pantalla']) ? '<p class="suave">🧾 <a href="' . $e($p['pantalla']) . '">Conecta tu cuenta de TuFactura</a> para facturar este cobro.</p>' : '';
        }
        $d = $this->deOrigen($negocio, $origen, $origenId);
        if ($d && $d['estado'] === 'autorizado') {
            return '<p>🧾 Factura <strong>' . $e($d['numero']) . '</strong> autorizada' . (!empty($p['pdf']) ? ' · <a href="' . $e(($p['pdf'])((int) $d['id'])) . '">PDF</a>' : '') . '</p>';
        }
        return '<form method="post" action="' . $e($p['accion'] ?? '') . '" class="tf-fila">' . ($p['csrf'] ?? '') . '<input type="hidden" name="tf_accion" value="facturar_origen">'
             . '<input type="hidden" name="origen" value="' . $e($origen) . '"><input type="hidden" name="origen_id" value="' . $e($origenId) . '">'
             . ($d ? '<span class="suave">🧾 ' . $e(self::nombreEstado($d['estado'])) . ($d['mensaje'] ? ': ' . $e(mb_substr($d['mensaje'], 0, 160)) : '') . '</span> ' : '')
             . '<button class="boton boton-chico" type="submit">🧾 ' . ($d ? 'Reintentar la factura' : 'Facturar') . '</button></form>';
    }
}
