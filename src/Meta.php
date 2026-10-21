<?php
declare(strict_types=1);

namespace TuWhatsApp;

/**
 * Conversa con la API oficial de WhatsApp (Cloud API de Meta).
 * META_GRAPH_URL (por defecto https://graph.facebook.com) y META_GRAPH_VERSION (por defecto v24.0) en config/.env.
 * Todas las funciones devuelven ['ok' => bool, ...]; nunca lanzan errores por fallas de red.
 */
final class Meta
{
    /** Errores de Meta explicados en palabras simples. */
    private const ERRORES = [
        131047 => 'Pasaron más de 24 horas desde el último mensaje del cliente: Meta solo deja enviar una plantilla aprobada. Usa "Enviar plantilla" o mándalo gratis desde el celular.',
        131026 => 'Ese número no tiene WhatsApp o no puede recibir el mensaje.',
        131030 => 'Número de prueba: en modo de prueba de Meta solo puedes enviar a los números que agregaste como destinatarios.',
        131056 => 'Demasiados mensajes seguidos a la misma persona. Espera un momento.',
        131048 => 'Meta frenó los envíos de este número por muchos reportes o bloqueos. Baja la cantidad de mensajes.',
        131049 => 'Meta no entregó el mensaje de marketing para no saturar al cliente. Intenta otro día.',
        132000 => 'La cantidad de datos no coincide con los espacios {{1}}, {{2}}... de la plantilla.',
        132001 => 'Esa plantilla no existe en ese idioma o todavía no está aprobada.',
        190    => 'El token de Meta venció o está mal. Ve a Números y pega un token nuevo.',
        100    => 'Meta rechazó los datos (revisa el Phone number ID y el token).',
        368    => 'Meta bloqueó temporalmente esta cuenta por incumplir sus reglas.',
        131031 => 'La cuenta de WhatsApp Business está bloqueada o falta verificar el negocio.',
        133010 => 'El número todavía no está registrado en la API de WhatsApp.',
    ];

    public function __construct(private string $phoneNumberId, private string $token, private string $wabaId = '') {}

    public static function deLinea(array $linea): self
    {
        return new self((string) $linea['phone_number_id'], Lineas::token($linea), (string) $linea['waba_id']);
    }

    private static function base(): string
    {
        $url = rtrim((string) (getenv('META_GRAPH_URL') ?: 'https://graph.facebook.com'), '/');
        $ver = (string) (getenv('META_GRAPH_VERSION') ?: 'v24.0');
        return $url . '/' . $ver;
    }

    public function enviarTexto(string $para, string $texto): array
    {
        return $this->enviar([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $texto],
        ]);
    }

    /** @param list<string> $datos lo que va en {{1}}, {{2}}... del cuerpo de la plantilla */
    public function enviarPlantilla(string $para, string $nombre, string $idioma, array $datos): array
    {
        $plantilla = ['name' => $nombre, 'language' => ['code' => $idioma]];
        if ($datos) {
            $plantilla['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($t) => ['type' => 'text', 'text' => (string) $t], array_values($datos)),
            ]];
        }
        return $this->enviar(['messaging_product' => 'whatsapp', 'to' => $para, 'type' => 'template', 'template' => $plantilla]);
    }

    /** Marca como leído (las dos palomitas azules) el mensaje que llegó. */
    public function marcarLeido(string $wamid): array
    {
        return $this->enviar(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid]);
    }

    /** Plantillas aprobadas de la cuenta de WhatsApp Business. */
    public function plantillas(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'Falta el "Identificador de la cuenta de WhatsApp Business" (WABA ID) en Números.'];
        }
        $r = $this->pedir('GET', '/' . $this->wabaId . '/message_templates?fields=name,language,status,category,components&limit=200');
        if (!$r['ok']) {
            return $r;
        }
        $lista = [];
        foreach ((array) ($r['datos']['data'] ?? []) as $p) {
            if (($p['status'] ?? '') !== 'APPROVED') {
                continue;
            }
            $cuerpo = '';
            foreach ((array) ($p['components'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'BODY') {
                    $cuerpo = (string) ($c['text'] ?? '');
                }
            }
            preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $m);
            $lista[] = [
                'nombre' => (string) $p['name'],
                'idioma' => (string) $p['language'],
                'categoria' => Gasto::DE_META[strtolower((string) ($p['category'] ?? ''))] ?? 'marketing',
                'cuerpo' => $cuerpo,
                'espacios' => $m[1] ? max(array_map('intval', $m[1])) : 0,
            ];
        }
        return ['ok' => true, 'plantillas' => $lista];
    }

    /** Prueba que el Phone number ID y el token funcionan. */
    public function probar(): array
    {
        $r = $this->pedir('GET', '/' . $this->phoneNumberId . '?fields=display_phone_number,verified_name,quality_rating');
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'numero' => (string) ($r['datos']['display_phone_number'] ?? ''),
                'nombre' => (string) ($r['datos']['verified_name'] ?? ''), 'calidad' => (string) ($r['datos']['quality_rating'] ?? '')];
    }

    private function enviar(array $cuerpo): array
    {
        $r = $this->pedir('POST', '/' . $this->phoneNumberId . '/messages', $cuerpo);
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'wamid' => (string) ($r['datos']['messages'][0]['id'] ?? '')];
    }

    private function pedir(string $metodo, string $ruta, ?array $cuerpo = null): array
    {
        if ($this->token === '' || $this->phoneNumberId === '') {
            return ['ok' => false, 'error' => 'Este número no tiene token o Phone number ID. Complétalo en Números.'];
        }
        $ch = curl_init(self::base() . $ruta);
        $cab = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $metodo,
        ];
        if ($cuerpo !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $cab[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $cab;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'No se pudo conectar con Meta (' . $falla . '). Revisa el internet del servidor.'];
        }
        $datos = json_decode((string) $resp, true);
        if ($codigo >= 200 && $codigo < 300 && is_array($datos)) {
            return ['ok' => true, 'datos' => $datos];
        }
        return ['ok' => false, 'error' => self::explicar(is_array($datos) ? $datos : [], $codigo), 'codigo' => (int) ($datos['error']['code'] ?? 0)];
    }

    public static function explicar(array $datos, int $http = 0): string
    {
        $err = $datos['error'] ?? $datos;
        $codigo = (int) ($err['code'] ?? 0);
        if (isset(self::ERRORES[$codigo])) {
            return self::ERRORES[$codigo];
        }
        $msg = (string) ($err['error_data']['details'] ?? $err['message'] ?? $err['title'] ?? '');
        return 'Meta respondió con un error' . ($codigo ? " $codigo" : ($http ? " (HTTP $http)" : '')) . ($msg !== '' ? ": $msg" : '.');
    }
}
