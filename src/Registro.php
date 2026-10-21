<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Conectar un número con el botón "Conectar con WhatsApp Business (código QR)" (Embedded Signup de Meta, versión 5).
 *
 * Si el número ya está en la app WhatsApp Business del celular (coexistencia), sigue funcionando en el celular
 * y además en este programa. Pasos después de que la persona termina en la ventana de Meta:
 *  1. Cambiar el código (dura 30 segundos) por el token del negocio.
 *  2. Suscribir la app a la cuenta de WhatsApp Business (para que lleguen los mensajes al webhook).
 *  3. Solo número NUEVO (no está en la app): registrarlo con un PIN de 6 números. Con coexistencia NO se registra.
 *  4. Guardar el número aquí.
 *  5. Coexistencia: pedir a Meta los contactos y el historial (solo se puede 1 vez y dentro de 24 horas).
 *
 * Necesita en config/.env: META_APP_ID, META_ES_CONFIG_ID (configuración de "Inicio de sesión con Facebook para empresas")
 * y META_APP_SECRET.
 */
final class Registro
{
    public const EVENTOS_OK = ['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'];

    public static function listo(): bool
    {
        return self::appId() !== '' && self::configId() !== '' && (string) getenv('META_APP_SECRET') !== '';
    }

    public static function appId(): string
    {
        return preg_replace('/\D+/', '', (string) getenv('META_APP_ID'));
    }

    public static function configId(): string
    {
        return preg_replace('/\D+/', '', (string) getenv('META_ES_CONFIG_ID'));
    }

    public static function version(): string
    {
        return (string) (getenv('META_GRAPH_VERSION') ?: 'v24.0');
    }

    /**
     * Hace todos los pasos. $d viene del navegador: code, evento, waba_id, phone_number_id, nombre.
     * @return array{ok:bool, linea_id?:int, coexistencia?:bool, numero?:string, pin?:string, avisos?:list<string>, error?:string}
     */
    public static function completar(PDO $db, array $d, ?DateTimeImmutable $ahora = null): array
    {
        if (!self::listo()) {
            return ['ok' => false, 'error' => 'Falta configurar en el servidor META_APP_ID, META_ES_CONFIG_ID y la clave secreta de Meta (bash /var/www/tuwhatsapp/deploy/clave_meta.sh).'];
        }
        $code = trim((string) ($d['code'] ?? ''));
        $evento = (string) ($d['evento'] ?? '');
        $waba = preg_replace('/\D+/', '', (string) ($d['waba_id'] ?? ''));
        $pnid = preg_replace('/\D+/', '', (string) ($d['phone_number_id'] ?? ''));
        if ($code === '' || !preg_match('/^[A-Za-z0-9_\-#.]{10,2000}$/', $code)) {
            return ['ok' => false, 'error' => 'Meta no devolvió el código de autorización. Vuelve a tocar el botón.'];
        }
        // Evento vacío = la ventana de Meta no mandó sus datos (pasa a veces); se sigue y se averigua con el token.
        if ($evento !== '' && !in_array($evento, self::EVENTOS_OK, true)) {
            return ['ok' => false, 'error' => 'La conexión en Meta no se terminó (' . $evento . '). Vuelve a intentarlo y llega hasta "Finalizar".'];
        }

        // 1. Código -> token del negocio
        $r = self::pedir('GET', '/oauth/access_token?' . http_build_query([
            'client_id' => self::appId(), 'client_secret' => (string) getenv('META_APP_SECRET'), 'code' => $code,
        ]));
        $token = (string) ($r['datos']['access_token'] ?? '');
        if (!$r['ok'] || $token === '') {
            return ['ok' => false, 'error' => 'No se pudo cambiar el código por el token: ' . ($r['error'] ?? 'Meta no devolvió el token') . '. El código dura 30 segundos: vuelve a intentarlo.'];
        }

        // 1b. Si la ventana no mandó la cuenta o el número (con coexistencia Meta a veces solo manda la cuenta), se buscan con el token
        if ($waba === '') {
            $waba = self::wabaDelToken($token);
        }
        if ($waba === '') {
            return ['ok' => false, 'error' => 'Meta no indicó qué cuenta de WhatsApp Business se conectó. Vuelve a intentarlo y elige tu cuenta en la ventana de Meta.'];
        }
        $infoNum = null;
        if ($pnid === '') {
            $infoNum = self::numeroDeLaCuenta($waba, $token);
            $pnid = (string) ($infoNum['id'] ?? '');
        }
        if ($pnid === '') {
            return ['ok' => false, 'error' => 'La cuenta de WhatsApp Business ' . $waba . ' no tiene ningún número. Vuelve a intentarlo y escribe tu número en la ventana de Meta.'];
        }
        $info = self::pedir('GET', '/' . $pnid . '?fields=display_phone_number,verified_name,is_on_biz_app,platform_type', null, $token);
        $enApp = ($info['datos']['is_on_biz_app'] ?? $infoNum['is_on_biz_app'] ?? null) === true;
        // Coexistencia si Meta lo dijo en el evento o si el número sigue en la app del celular
        $coex = $evento === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' || $enApp;

        // 2. Suscribir la app a la cuenta (sin esto no llegan mensajes)
        $avisos = [];
        $s = self::pedir('POST', '/' . $waba . '/subscribed_apps', [], $token);
        if (!$s['ok']) {
            $avisos[] = 'No se pudo suscribir el webhook: ' . $s['error'] . ' (sin esto no llegan los mensajes; vuelve a conectar).';
        }

        // 3. Número nuevo: registrarlo con PIN. Con coexistencia NO (ya está registrado por la app).
        //    Por seguridad solo se registra si Meta confirmó "FINISH" (número nuevo): nunca a ciegas.
        $pin = '';
        if (!$coex && $evento === '') {
            $avisos[] = 'No se registró el número en la API porque Meta no confirmó el tipo de conexión. Si es un número nuevo, vuelve a conectarlo.';
        } elseif (!$coex) {
            $pin = (string) random_int(100000, 999999);
            $g = self::pedir('POST', '/' . $pnid . '/register', ['messaging_product' => 'whatsapp', 'pin' => $pin], $token);
            if (!$g['ok']) {
                $avisos[] = 'No se pudo registrar el número en la API: ' . $g['error'];
            }
        }

        // 4. Datos del número y guardar
        $numero = (string) ($info['datos']['display_phone_number'] ?? $infoNum['display_phone_number'] ?? '');
        $nombreMeta = (string) ($info['datos']['verified_name'] ?? $infoNum['verified_name'] ?? '');
        $nombre = trim((string) ($d['nombre'] ?? '')) ?: ($nombreMeta !== '' ? $nombreMeta : 'WhatsApp');
        $tel = Telefono::normalizar($numero) ?? preg_replace('/\D+/', '', $numero);
        $ahora ??= new DateTimeImmutable();

        $existe = Lineas::porPhoneNumberId($db, $pnid);
        if ($existe) {
            $id = (int) $existe['id'];
            $db->prepare("UPDATE lineas SET waba_id = ?, token_cifrado = ?, telefono = CASE WHEN ? <> '' THEN ? ELSE telefono END,
                          conectado_por = 'qr', coexistencia = ?, conectado_en = ?, pin_cifrado = CASE WHEN ? <> '' THEN ? ELSE pin_cifrado END WHERE id = ?")
               ->execute([$waba, Cifrado::cifrar($token), $tel, $tel, $coex ? 't' : 'f', $ahora->format('c'), $pin, $pin !== '' ? Cifrado::cifrar($pin) : '', $id]);
        } else {
            $st = $db->prepare("INSERT INTO lineas (nombre, telefono, phone_number_id, waba_id, token_cifrado, conectado_por, coexistencia, conectado_en, pin_cifrado)
                                VALUES (?, ?, ?, ?, ?, 'qr', ?, ?, ?) RETURNING id");
            $st->execute([mb_substr($nombre, 0, 60), $tel, $pnid, $waba, Cifrado::cifrar($token), $coex ? 't' : 'f', $ahora->format('c'), $pin !== '' ? Cifrado::cifrar($pin) : '']);
            $id = (int) $st->fetchColumn();
        }

        // 5. Coexistencia: traer contactos y luego historial (solo 1 vez cada uno, dentro de 24 horas)
        if ($coex) {
            foreach (['smb_app_state_sync' => 'contactos', 'history' => 'historial'] as $tipo => $cual) {
                $x = self::sincronizar($db, Lineas::buscar($db, $id), $cual);
                if (!$x['ok']) {
                    $avisos[] = 'No se pudo pedir ' . $cual . ': ' . $x['error'];
                }
            }
        }
        return ['ok' => true, 'linea_id' => $id, 'coexistencia' => $coex, 'numero' => $numero, 'pin' => $pin, 'avisos' => $avisos];
    }

    /**
     * Pide a Meta que mande los contactos o el historial de la app del celular (llegan por el webhook).
     * Meta lo permite una sola vez por tipo y dentro de las 24 horas después de conectar.
     */
    public static function sincronizar(PDO $db, ?array $linea, string $cual): array
    {
        $col = ['contactos' => 'sync_contactos', 'historial' => 'sync_historial'][$cual] ?? null;
        $tipo = ['contactos' => 'smb_app_state_sync', 'historial' => 'history'][$cual] ?? null;
        if ($linea === null || $col === null) {
            return ['ok' => false, 'error' => 'Número o tipo no válido.'];
        }
        if (!$linea['coexistencia']) {
            return ['ok' => false, 'error' => 'Solo se puede con números conectados con código QR que siguen en la app del celular.'];
        }
        if (str_starts_with((string) $linea[$col], 'ok:')) {
            return ['ok' => false, 'error' => 'Ya se pidió antes: Meta solo lo permite una vez.'];
        }
        $r = self::pedir('POST', '/' . $linea['phone_number_id'] . '/smb_app_data', ['messaging_product' => 'whatsapp', 'sync_type' => $tipo], Lineas::token($linea));
        $valor = $r['ok'] ? 'ok:' . (string) ($r['datos']['request_id'] ?? '') : 'error:' . mb_substr((string) $r['error'], 0, 300);
        $db->prepare("UPDATE lineas SET $col = ?" . ($cual === 'historial' && $r['ok'] ? ", historial_estado = 'recibiendo'" : '') . ' WHERE id = ?')
           ->execute([$valor, $linea['id']]);
        return $r['ok'] ? ['ok' => true] : ['ok' => false, 'error' => (string) $r['error']];
    }

    /** La cuenta de WhatsApp Business a la que el negocio dio permiso (según el token). */
    public static function wabaDelToken(string $token): string
    {
        $r = self::pedir('GET', '/debug_token?' . http_build_query([
            'input_token' => $token, 'access_token' => self::appId() . '|' . (string) getenv('META_APP_SECRET'),
        ]));
        foreach ((array) ($r['datos']['data']['granular_scopes'] ?? []) as $g) {
            if (in_array($g['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                $ids = array_values(array_filter((array) ($g['target_ids'] ?? []), fn ($x) => preg_match('/^\d+$/', (string) $x)));
                if ($ids) {
                    return (string) end($ids);   // la última es la recién conectada
                }
            }
        }
        return '';
    }

    /**
     * El número de la cuenta. Si hay varios, prefiere el que sigue en la app del celular (coexistencia).
     * @return ?array{id:string, display_phone_number?:string, verified_name?:string, is_on_biz_app?:bool}
     */
    public static function numeroDeLaCuenta(string $waba, string $token): ?array
    {
        $r = self::pedir('GET', '/' . $waba . '/phone_numbers?fields=id,display_phone_number,verified_name,is_on_biz_app,platform_type', null, $token);
        $lista = array_values(array_filter((array) ($r['datos']['data'] ?? []), fn ($n) => preg_match('/^\d+$/', (string) ($n['id'] ?? ''))));
        if (!$lista) {
            return null;
        }
        foreach ($lista as $n) {
            if (($n['is_on_biz_app'] ?? false) === true) {
                return $n;
            }
        }
        return $lista[0];
    }

    /** Estado en palabras para la pantalla del número. */
    public static function estadoSync(string $valor): string
    {
        if ($valor === '') {
            return 'sin pedir';
        }
        return str_starts_with($valor, 'ok:') ? 'pedido a Meta' : 'falló (' . substr($valor, 6) . ')';
    }

    private static function pedir(string $metodo, string $ruta, ?array $cuerpo = null, string $token = ''): array
    {
        $url = rtrim((string) (getenv('META_GRAPH_URL') ?: 'https://graph.facebook.com'), '/') . '/' . self::version() . $ruta;
        $ch = curl_init($url);
        $cab = ['Accept: application/json'];
        if ($token !== '') {
            $cab[] = 'Authorization: Bearer ' . $token;
        }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $metodo];
        if ($metodo === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo ?? [], JSON_UNESCAPED_UNICODE);
            $cab[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $cab;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'No se pudo conectar con Meta (' . $falla . ')'];
        }
        $datos = json_decode((string) $resp, true);
        if ($codigo >= 200 && $codigo < 300 && is_array($datos)) {
            return ['ok' => true, 'datos' => $datos];
        }
        return ['ok' => false, 'error' => Meta::explicar(is_array($datos) ? $datos : [], $codigo)];
    }
}
