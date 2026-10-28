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
        2388023 => 'Meta todavía está borrando una plantilla con ese nombre: usa otro nombre o espera unas semanas.',
        2388024 => 'Ya existe una plantilla con ese nombre e idioma.',
        2388299 => 'Una variable {{1}} no puede ir al principio ni al final del texto: agrega palabras antes o después.',
        368    => 'Meta bloqueó temporalmente esta cuenta por incumplir sus reglas.',
        131031 => 'La cuenta de WhatsApp Business está bloqueada o falta verificar el negocio.',
        133010 => 'El número todavía no está registrado en la API de WhatsApp.',
        138006 => 'Este cliente no te ha dado permiso para llamarlo. Pídele permiso primero (botón "Pedir permiso para llamar").',
    ];

    /** Números de la cuenta de muestra del revisor de Google (deploy/demo_google.php): no son de verdad y nunca llaman a Meta. */
    public const PREFIJO_MUESTRA = 'muestra-';
    public const AVISO_MUESTRA = 'Cuenta de demostración: no se envía nada a WhatsApp (Meta).';

    public function __construct(private string $phoneNumberId, private string $token, private string $wabaId = '') {}

    private function esMuestra(): bool
    {
        return str_starts_with($this->phoneNumberId, self::PREFIJO_MUESTRA);
    }

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

    /**
     * Foto, audio, video o documento ya subido a Meta (media id).
     * @param string $tipo imagen | audio | video | documento
     */
    public function enviarMedio(string $para, string $tipo, string $mediaId, string $pie = '', string $nombreArchivo = ''): array
    {
        $t = Archivos::A_META[$tipo] ?? 'document';
        $obj = ['id' => $mediaId];
        if ($pie !== '' && in_array($t, ['image', 'video', 'document'], true)) {
            $obj['caption'] = mb_substr($pie, 0, 1024);
        }
        if ($t === 'document' && $nombreArchivo !== '') {
            $obj['filename'] = $nombreArchivo;
        }
        return $this->enviar(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => $t, $t => $obj]);
    }

    /**
     * Mensaje con botones (hasta 3) o con lista (hasta 10 opciones). El cliente toca y llega como "interactive".
     * Con $forzarLista siempre va como lista (el catálogo: muestra el precio debajo de cada producto).
     * @param list<array{id:string,titulo:string,descripcion?:string}> $opciones
     */
    public function enviarBotones(string $para, string $texto, array $opciones, string $textoBotonLista = 'Ver opciones', bool $forzarLista = false): array
    {
        $opciones = array_slice(array_values($opciones), 0, 10);
        if (count($opciones) <= 3 && !$forzarLista) {
            $interactivo = ['type' => 'button', 'body' => ['text' => mb_substr($texto, 0, 1024)], 'action' => ['buttons' => array_map(
                fn ($o) => ['type' => 'reply', 'reply' => ['id' => mb_substr($o['id'], 0, 256), 'title' => mb_substr($o['titulo'], 0, 20)]], $opciones)]];
        } else {
            $interactivo = ['type' => 'list', 'body' => ['text' => mb_substr($texto, 0, 1024)], 'action' => [
                'button' => mb_substr($textoBotonLista, 0, 20),
                'sections' => [['title' => 'Opciones', 'rows' => array_map(
                    fn ($o) => ['id' => mb_substr($o['id'], 0, 200), 'title' => mb_substr($o['titulo'], 0, 24)]
                               + (isset($o['descripcion']) && $o['descripcion'] !== '' ? ['description' => mb_substr((string) $o['descripcion'], 0, 72)] : []), $opciones)]],
            ]];
        }
        return $this->enviar(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'interactive', 'interactive' => $interactivo]);
    }

    /** Sube un archivo a Meta y devuelve su id (sirve 30 días para enviarlo). */
    public function subirMedio(string $rutaCompleta, string $mime, string $nombre): array
    {
        if ($this->esMuestra()) {
            return ['ok' => false, 'error' => self::AVISO_MUESTRA];
        }
        if ($this->token === '' || $this->phoneNumberId === '') {
            return ['ok' => false, 'error' => 'Este número no tiene token o Phone number ID. Complétalo en Números.'];
        }
        $ch = curl_init(self::base() . '/' . $this->phoneNumberId . '/media');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json'],
            CURLOPT_POSTFIELDS => ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => new \CURLFile($rutaCompleta, $mime, $nombre)],
        ]);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'No se pudo subir el archivo a Meta (' . $falla . ').'];
        }
        $d = json_decode((string) $resp, true);
        if ($codigo >= 200 && $codigo < 300 && !empty($d['id'])) {
            return ['ok' => true, 'id' => (string) $d['id']];
        }
        return ['ok' => false, 'error' => self::explicar(is_array($d) ? $d : [], $codigo)];
    }

    /** Baja un archivo que llegó: primero se pide su dirección (dura 5 minutos) y luego los bytes, siempre con el token. */
    public function bajarMedio(string $mediaId): array
    {
        $r = $this->pedir('GET', '/' . rawurlencode($mediaId));
        if (!$r['ok']) {
            return $r;
        }
        $url = (string) ($r['datos']['url'] ?? '');
        if (!preg_match('#^https://#', $url) && !str_starts_with($url, rtrim((string) getenv('META_GRAPH_URL'), '/'))) {
            return ['ok' => false, 'error' => 'Meta no devolvió la dirección del archivo.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'User-Agent: TukanWhatsApp/1.0'],
        ]);
        $bytes = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($bytes) || $codigo < 200 || $codigo >= 300 || $bytes === '') {
            return ['ok' => false, 'error' => 'Meta no entregó el archivo (HTTP ' . $codigo . ').'];
        }
        if (strlen($bytes) > 100 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'El archivo es demasiado grande.'];
        }
        return ['ok' => true, 'bytes' => $bytes, 'mime' => (string) ($r['datos']['mime_type'] ?? 'application/octet-stream')];
    }

    /** Marca como leído (las dos palomitas azules) el mensaje que llegó. */
    public function marcarLeido(string $wamid): array
    {
        return $this->enviar(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid]);
    }

    public const ESTADOS_PLANTILLA = ['APPROVED' => 'Aprobada', 'PENDING' => 'En revisión', 'REJECTED' => 'Rechazada', 'PAUSED' => 'Pausada',
                                      'DISABLED' => 'Desactivada', 'IN_APPEAL' => 'En apelación', 'PENDING_DELETION' => 'Borrándose'];

    /** Plantillas aprobadas (las que se pueden enviar). */
    public function plantillas(): array
    {
        $r = $this->todasLasPlantillas();
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'plantillas' => array_values(array_filter($r['plantillas'], fn ($p) => $p['estado'] === 'APPROVED'))];
    }

    /** Todas las plantillas de la cuenta, con su estado y el motivo si Meta la rechazó. */
    public function todasLasPlantillas(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'Falta el "Identificador de la cuenta de WhatsApp Business" (WABA ID) en Números.'];
        }
        $r = $this->pedir('GET', '/' . $this->wabaId . '/message_templates?fields=id,name,language,status,category,components,rejected_reason&limit=200');
        if (!$r['ok']) {
            return $r;
        }
        $lista = [];
        foreach ((array) ($r['datos']['data'] ?? []) as $p) {
            $cuerpo = $encabezado = $pie = '';
            $botones = [];
            foreach ((array) ($p['components'] ?? []) as $c) {
                $tipo = strtoupper((string) ($c['type'] ?? ''));
                if ($tipo === 'BODY') {
                    $cuerpo = (string) ($c['text'] ?? '');
                } elseif ($tipo === 'HEADER' && strtoupper((string) ($c['format'] ?? 'TEXT')) === 'TEXT') {
                    $encabezado = (string) ($c['text'] ?? '');
                } elseif ($tipo === 'FOOTER') {
                    $pie = (string) ($c['text'] ?? '');
                } elseif ($tipo === 'BUTTONS') {
                    foreach ((array) ($c['buttons'] ?? []) as $b) {
                        $botones[] = ['tipo' => strtoupper((string) ($b['type'] ?? '')), 'texto' => (string) ($b['text'] ?? ''),
                                      'destino' => (string) ($b['url'] ?? $b['phone_number'] ?? '')];
                    }
                }
            }
            preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $m);
            $motivo = (string) ($p['rejected_reason'] ?? '');
            $lista[] = [
                'id' => (string) ($p['id'] ?? ''),
                'nombre' => (string) $p['name'],
                'idioma' => (string) $p['language'],
                'categoria' => Gasto::DE_META[strtolower((string) ($p['category'] ?? ''))] ?? 'marketing',
                'estado' => strtoupper((string) ($p['status'] ?? '')),
                'motivo' => in_array($motivo, ['', 'NONE'], true) ? '' : $motivo,
                'encabezado' => $encabezado,
                'cuerpo' => $cuerpo,
                'pie' => $pie,
                'botones' => $botones,
                'espacios' => $m[1] ? max(array_map('intval', $m[1])) : 0,
            ];
        }
        return ['ok' => true, 'plantillas' => $lista];
    }

    /** Manda una plantilla nueva a Meta para que la revise. $datos viene de Plantillas::armar(). */
    public function crearPlantilla(array $datos): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'Falta el "Identificador de la cuenta de WhatsApp Business" (WABA ID) en Números.'];
        }
        $r = $this->pedir('POST', '/' . $this->wabaId . '/message_templates', $datos);
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'id' => (string) ($r['datos']['id'] ?? ''), 'estado' => strtoupper((string) ($r['datos']['status'] ?? 'PENDING'))];
    }

    /** Borra una plantilla (todas sus versiones de idioma con ese nombre). */
    public function borrarPlantilla(string $nombre): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'Falta el WABA ID en Números.'];
        }
        return $this->pedir('DELETE', '/' . $this->wabaId . '/message_templates?name=' . rawurlencode($nombre));
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

    /** Suscribe esta app a la cuenta de WhatsApp (WABA): sin esto Meta NO manda los mensajes que llegan. */
    public function suscribirApp(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'Falta el identificador de la cuenta (WABA ID): sin él Meta no avisa los mensajes que llegan.'];
        }
        return $this->pedir('POST', '/' . $this->wabaId . '/subscribed_apps', []);
    }

    // ---------------------------------------------------------------- Llamadas (Calling API)

    /** Contestar, rechazar o colgar una llamada: pre_accept y accept llevan el SDP de respuesta del navegador. */
    public function llamadaAccion(string $callId, string $accion, string $sdp = ''): array
    {
        $c = ['messaging_product' => 'whatsapp', 'call_id' => $callId, 'action' => $accion];
        if ($sdp !== '') {
            $c['session'] = ['sdp_type' => 'answer', 'sdp' => $sdp];
        }
        return $this->pedir('POST', '/' . $this->phoneNumberId . '/calls', $c);
    }

    /** Llamar al cliente (necesita su permiso). Devuelve call_id. */
    public function llamar(string $para, string $sdpOferta, string $referencia): array
    {
        $r = $this->pedir('POST', '/' . $this->phoneNumberId . '/calls', ['messaging_product' => 'whatsapp', 'to' => $para, 'action' => 'connect',
            'session' => ['sdp_type' => 'offer', 'sdp' => $sdpOferta], 'biz_opaque_callback_data' => mb_substr($referencia, 0, 512)]);
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'call_id' => (string) ($r['datos']['calls'][0]['id'] ?? '')];
    }

    /** Mensaje con el botón de Meta para que el cliente dé permiso de llamarlo. */
    public function pedirPermisoLlamada(string $para, string $texto): array
    {
        return $this->enviar(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'interactive',
            'interactive' => ['type' => 'call_permission_request', 'action' => ['name' => 'call_permission_request'], 'body' => ['text' => mb_substr($texto, 0, 1024)]]]);
    }

    /** Encender o apagar las llamadas del número (y el horario de atención de llamadas). */
    public function ajustesLlamadas(array $calling): array
    {
        return $this->pedir('POST', '/' . $this->phoneNumberId . '/settings', ['calling' => $calling]);
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
        if ($this->esMuestra()) {
            return ['ok' => false, 'error' => self::AVISO_MUESTRA];
        }
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
        $sub = (int) ($err['error_subcode'] ?? 0);
        if ($sub && isset(self::ERRORES[$sub])) {
            return self::ERRORES[$sub];
        }
        if (isset(self::ERRORES[$codigo]) && !($codigo === 100 && !empty($err['error_user_msg']))) {
            return self::ERRORES[$codigo];
        }
        $msg = (string) ($err['error_user_msg'] ?? $err['error_data']['details'] ?? $err['message'] ?? $err['title'] ?? '');
        return 'Meta respondió con un error' . ($codigo ? " $codigo" : ($http ? " (HTTP $http)" : '')) . ($msg !== '' ? ": $msg" : '.');
    }
}
