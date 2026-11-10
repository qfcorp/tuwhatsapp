<?php
declare(strict_types=1);

namespace TuWhatsApp;

use RuntimeException;

/**
 * (10 nov 2026) EL CLIENTE PAGA SU PLAN SOLO, CON TARJETA, desde «Mi cuenta» (pedido de Dimitry: que el cliente, en demo o ya
 * pagando, elija su plan y pague sin que Tukán tenga que mandarle el enlace).
 *
 * WhatsApp Tukán NO habla con Plux para esto ni guarda tarjetas: le pide el cobro al Panel Tukán Central
 * (POST {TUKAN_CENTRAL_URL}/plux_api.php, token v2 de tipo «plux» firmado con TUKAN_CENTRAL_SECRET, el mismo de conectar_central).
 * El Panel lo crea en Tukán Pagos y devuelve la página de pago. Cuando Plux aprueba, el Panel registra el pago aquí con
 * «registrar_pago» (src/TukanCentralV2.php) y emite la factura: la cuenta queda activa sola, también si era una demo.
 *   - Es una suscripción mensual: Plux cobra sola cada mes. El precio de los planes es SIN IVA (el IVA lo suma Tukán Pagos).
 *   - Si el Panel no contesta o Dimitry no ha encendido el pago con tarjeta para WhatsApp Tukán, «Mi cuenta» queda como antes.
 *   - Dentro de la app de Android (Google Play) no se muestra nada de esto (regla de Google).
 * (El Plux de src/Plux.php es otra cosa: lo que el NEGOCIO cobra a SUS clientes.)
 */
final class PagosCentral
{
    /** Estados de una suscripción que sigue viva (no se puede crear otra: el Panel contesta 409). */
    public const VIVA = ['pendiente', 'rechazado', 'activa', 'en_gracia'];

    /** Solo para las pruebas: function (string $url, array $cabeceras, string $cuerpo, int $tiempo): array{0:int, 1:string} */
    public static $transporte = null;

    /** @var array<int, ?array> lo que contestó el Panel en esta página (una sola consulta por cuenta) */
    private static array $visto = [];

    /** Pide algo al Panel. Devuelve [http, json|null] (http 0 = no conecta o el programa no está conectado al Panel). */
    public static function llamar(string $accion, array $datos, array $quien = [], int $tiempo = 15): array
    {
        $c = TukanCentral::config();
        if ($c === null) {
            return [0, null];
        }
        $cuerpo = json_encode(['accion' => $accion] + $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $correo = filter_var($quien['correo'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $quien['correo'] : 'sistema@tukanec.com';
        $ahora = time();
        $token = TukanCentral::firmar([
            'v' => 2, 'tipo' => 'plux', 'inst' => $c['id'], 'email' => $correo, 'nombre' => mb_substr((string) ($quien['nombre'] ?? TukanCentral::NOMBRE), 0, 80),
            'rol' => 'dueno', 'iat' => $ahora, 'exp' => $ahora + 60, 'nonce' => bin2hex(random_bytes(16)), 'bh' => TukanCentral::b64(hash('sha256', $cuerpo, true)),
        ], $c['secreto']);
        $url = TukanCentral::urlCentral() . '/plux_api.php';
        $cab = ['Content-Type: application/json', 'Accept: application/json', 'X-Tukan-Token: ' . $token];
        if (self::$transporte !== null) {
            [$http, $r] = (self::$transporte)($url, $cab, $cuerpo, $tiempo);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
                                    CURLOPT_TIMEOUT => $tiempo, CURLOPT_HTTPHEADER => $cab]);
            $r = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            if ($r === false) {
                return [0, null];
            }
        }
        $j = json_decode((string) $r, true);
        return [(int) $http, is_array($j) ? $j : null];
    }

    /** Lo que dice el Panel de los pagos con tarjeta de esta cuenta. null = el Panel no contesta o el programa no está conectado. */
    public static function estado(array $empresa): ?array
    {
        $id = (int) $empresa['id'];
        if (!array_key_exists($id, self::$visto)) {
            [$http, $j] = self::llamar('estado', ['cliente_id' => (string) $id], [], 6);
            self::$visto[$id] = $http === 200 && is_array($j) && !empty($j['ok']) ? $j : null;
        }
        return self::$visto[$id];
    }

    /** Olvida lo consultado (después de crear o cancelar, y en las pruebas). */
    public static function olvidar(): void
    {
        self::$visto = [];
    }

    /** ¿Esta cuenta puede pagar su plan con tarjeta por el Panel? */
    public static function activo(array $empresa): bool
    {
        $e = self::estado($empresa);
        return $e !== null && !empty($e['activo']);
    }

    /** La suscripción con tarjeta viva de la cuenta (si tiene). */
    public static function suscripcion(array $empresa): ?array
    {
        foreach ((self::estado($empresa)['cobros'] ?? []) as $c) {
            if (is_array($c) && ($c['tipo'] ?? '') === 'suscripcion' && in_array($c['estado'] ?? '', self::VIVA, true)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Los planes que se pueden pagar (los de src/Modulos.php, precio mensual sin IVA). Solo mes a mes.
     * @return array<string, array{nombre:string, precio:float, incluye:string}>
     */
    public static function planes(): array
    {
        $r = [];
        foreach (Modulos::PLANES as $k => $p) {
            $extras = array_map(fn ($m) => Modulos::LISTA[$m][0], $p[4]);
            $r[$k] = ['nombre' => (string) $p[0], 'precio' => round((float) $p[1], 2),
                      'incluye' => 'Todo el programa' . ($extras ? ' y además: ' . implode(', ', $extras) : ' (sin la inteligencia artificial)') . '.'];
        }
        return $r;
    }

    /** El plan que se marca al abrir: con IA si hoy la tiene encendida; si no, el plan sin IA. */
    public static function planSugerido(array $empresa): string
    {
        $conIa = Modulos::activo($empresa, 'ia');
        foreach (Modulos::PLANES as $k => $p) {
            if (in_array('ia', $p[4], true) === $conIa) {
                return $k;
            }
        }
        return Modulos::planDe($empresa);
    }

    /**
     * Lo que «Mi cuenta» (o la pantalla de cuenta vencida) muestra para pagar con tarjeta. null = no se muestra nada (el Panel
     * no contesta, el pago con tarjeta está apagado para WhatsApp Tukán, o la cuenta es la de Tukán o está suspendida).
     * @return ?array{pruebas:bool, iva:float, suscripcion:?array, cobros:list<array>, planes:array, elegido:string}
     */
    public static function pantalla(array $empresa): ?array
    {
        if ((int) $empresa['id'] === Empresas::TUKAN || ($empresa['estado'] ?? '') === 'suspendida' || !empty($empresa['muestra'])) {
            return null;
        }
        $e = self::estado($empresa);
        if ($e === null || empty($e['activo'])) {
            return null;
        }
        $vivos = array_values(array_filter(is_array($e['cobros'] ?? null) ? $e['cobros'] : [],
            fn ($c) => is_array($c) && in_array($c['estado'] ?? '', ['pendiente', 'rechazado', 'activa', 'en_gracia', 'vencida'], true)));
        return ['pruebas' => !empty($e['pruebas']), 'iva' => (float) ($e['iva'] ?? 15), 'suscripcion' => self::suscripcion($empresa), 'cobros' => $vivos,
                'planes' => self::planes(), 'elegido' => self::planSugerido($empresa)];
    }

    /**
     * Pide al Panel la suscripción mensual del plan elegido. Devuelve el cobro (con url_pago, la página donde se pone la tarjeta)
     * o lanza el error para mostrarlo.
     */
    public static function crear(array $empresa, array $yo, string $plan): array
    {
        $planes = self::planes();
        if (!isset($planes[$plan])) {
            throw new RuntimeException('Elige un plan.');
        }
        $p = $planes[$plan];
        [$http, $j] = self::llamar('crear', [
            'tipo' => 'suscripcion', 'cliente_id' => (string) $empresa['id'], 'nombre' => (string) $empresa['nombre'],
            'plan' => $plan, 'plan_nombre' => $p['nombre'], 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => $p['precio'],
            'descripcion' => mb_substr('Plan ' . $p['nombre'] . ' · suscripción mensual · ' . $empresa['nombre'], 0, 90),
            'comprador' => ['nombre' => (string) ($yo['nombre'] ?? ''), 'correo' => (string) ($yo['correo'] ?? ''), 'telefono' => (string) ($empresa['telefono'] ?? '')],
            'fiscal' => [],
            'volver' => TukanCentralV2::urlBase() . '/?r=cuenta',
        ], $yo, 30);
        self::olvidar();
        if ($http === 0 || $j === null) {
            throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intenta en unos minutos.');
        }
        if (empty($j['ok'])) {
            throw new RuntimeException((string) ($j['error'] ?? 'No se pudo crear el pago.'));
        }
        $c = is_array($j['cobro'] ?? null) ? $j['cobro'] : [];
        if (!is_string($c['url_pago'] ?? null) || !preg_match('#^https?://#', $c['url_pago'])) {
            throw new RuntimeException('El sistema de pagos no devolvió el enlace. Intenta otra vez.');
        }
        return $c;
    }

    /** Cancela un cobro con tarjeta que todavía no se paga (o la suscripción): conserva lo ya pagado. */
    public static function cancelar(array $empresa, int $id, array $yo): array
    {
        [$http, $j] = self::llamar('cancelar', ['cliente_id' => (string) $empresa['id'], 'id' => $id], $yo, 20);
        self::olvidar();
        if ($http === 0 || $j === null) {
            throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intenta en unos minutos.');
        }
        if (empty($j['ok'])) {
            throw new RuntimeException((string) ($j['error'] ?? 'No se pudo cancelar.'));
        }
        return (is_array($j['cobro'] ?? null) ? $j['cobro'] : []) + ['programada' => !empty($j['programada'])];
    }

    /**
     * «Cancelar mi plan» con una suscripción con tarjeta viva: el Panel la cancela (para que Plux no cobre el mes siguiente) y
     * lo anota. Si no se logra, lanza el error: NO se debe decir «cancelado» mientras la tarjeta se siga cobrando.
     */
    public static function cancelarPlan(array $empresa, ?string $hasta, string $motivo, array $yo): void
    {
        [$http, $j] = self::llamar('cancelar_plan', [
            'cliente_id' => (string) $empresa['id'], 'nombre' => (string) $empresa['nombre'], 'hasta' => $hasta, 'motivo' => mb_substr(trim($motivo), 0, 500),
            'quien' => trim(($yo['nombre'] ?? '') . ' <' . ($yo['correo'] ?? '') . '>'),
        ], $yo, 25);
        self::olvidar();
        if ($http !== 200 || empty($j['ok']) || !empty($j['errores'])) {
            throw new RuntimeException('No se pudo cancelar el cobro con tarjeta. Intenta en unos minutos o escribe a Tukán: mientras tanto tu plan sigue igual.');
        }
    }

    /** Texto para avisar al volver de la página de pago (?plux=aprobado u otro valor). [tipo de aviso, texto] o null. */
    public static function avisoVuelta(string $vuelta): ?array
    {
        if ($vuelta === '') {
            return null;
        }
        return $vuelta === 'aprobado'
            ? ['ok', '¡Pago recibido! Tu plan se activa en unos segundos (recarga la página). La factura te llega al correo.']
            : ['info', 'El pago no se completó. Puedes intentarlo otra vez con otra tarjeta.'];
    }
}
