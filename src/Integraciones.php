<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Módulo "Integraciones":
 *  - Aviso a otro programa (webhook de salida): cada mensaje que llega se manda por POST (JSON) a la dirección
 *    que ponga el cliente (Make, Zapier, n8n, su propio sistema), firmado con X-Tukan-Firma: sha256=HMAC(secreto, cuerpo).
 *  - Exportar contactos y mensajes a un archivo que abre Excel (CSV con ; y tildes bien).
 */
final class Integraciones
{
    /** Guarda la dirección (vacía = apagado). Devuelve el secreto (se crea uno si no había). */
    public static function guardarAviso(PDO $db, int $lineaId, string $url, bool $secretoNuevo = false): string
    {
        $url = trim($url);
        if ($url !== '') {
            self::revisarUrl($url);
        }
        $st = $db->prepare('SELECT aviso_secreto FROM lineas WHERE id = ?');
        $st->execute([$lineaId]);
        $secreto = (string) $st->fetchColumn();
        if ($secreto === '' || $secretoNuevo) {
            $secreto = bin2hex(random_bytes(20));
        }
        $db->prepare("UPDATE lineas SET aviso_url = ?, aviso_secreto = ?, aviso_error = '' WHERE id = ?")->execute([$url, $secreto, $lineaId]);
        return $secreto;
    }

    /** Solo https a direcciones públicas (nunca a la red interna del servidor). */
    public static function revisarUrl(string $url): array
    {
        $p = parse_url($url);
        $local = getenv('TUWA_AVISO_PERMITIR_LOCAL') === '1';   // solo para las pruebas (servidor simulado sin https)
        $esquema = (string) ($p['scheme'] ?? '');
        if (!$p || !($esquema === 'https' || ($local && $esquema === 'http')) || empty($p['host']) || isset($p['user']) || strlen($url) > 500) {
            throw new RuntimeException('La dirección debe empezar con https:// (por ejemplo https://hook.make.com/...).');
        }
        $host = (string) $p['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('No se encontró el servidor "' . $host . '". Revisa la dirección.');
        }
        if (!$local && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Esa dirección es de una red interna: no se permite.');
        }
        return ['host' => $host, 'ip' => $ip, 'puerto' => (int) ($p['port'] ?? ($esquema === 'http' ? 80 : 443))];
    }

    /** Lo llama el webhook al llegar un mensaje. Nunca detiene el webhook: si falla, guarda el error. */
    public static function avisar(PDO $db, array $linea, array $contacto, array $mensaje): void
    {
        $url = (string) ($linea['aviso_url'] ?? '');
        if ($url === '' || !Modulos::activo(Empresas::buscar($db, (int) $linea['empresa_id']), 'integraciones')) {
            return;
        }
        $cuerpo = json_encode([
            'evento' => 'mensaje_recibido',
            'numero' => ['id' => (int) $linea['id'], 'nombre' => $linea['nombre'], 'telefono' => $linea['telefono']],
            'contacto' => [
                'id' => (int) $contacto['id'], 'telefono' => $contacto['telefono'], 'nombre' => Contactos::nombre($contacto),
                'tipo' => $contacto['tipo'], 'etiquetas' => (string) ($contacto['etiquetas'] ?? ''), 'etapa' => $contacto['etapa'] ?? null,
            ],
            'mensaje' => $mensaje,
            'enviado_en' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $error = self::post($url, (string) $cuerpo, (string) $linea['aviso_secreto']);
        if ($error !== (string) ($linea['aviso_error'] ?? '')) {
            $db->prepare('UPDATE lineas SET aviso_error = ? WHERE id = ?')->execute([$error === '' ? '' : date('d/m H:i') . ' ' . $error, $linea['id']]);
        }
    }

    /** Manda el aviso; devuelve '' si salió bien o el error. */
    public static function post(string $url, string $cuerpo, string $secreto): string
    {
        try {
            $d = self::revisarUrl($url);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => getenv('TUWA_AVISO_PERMITIR_LOCAL') === '1' ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$d['host'] . ':' . $d['puerto'] . ':' . $d['ip']],   // la IP que se revisó (evita trucos de DNS)
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: WhatsAppTukan/1',
                                   'X-Tukan-Firma: sha256=' . hash_hmac('sha256', $cuerpo, $secreto)],
        ]);
        if (getenv('TUWA_AVISO_PERMITIR_LOCAL') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);   // pruebas con servidor local
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $ok = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            return 'No respondió: ' . $falla;
        }
        return $codigo >= 200 && $codigo < 300 ? '' : 'Respondió con error ' . $codigo;
    }

    /** Aviso de prueba desde el panel. */
    public static function probar(array $linea): string
    {
        if ((string) $linea['aviso_url'] === '') {
            return 'Primero guarda la dirección.';
        }
        $cuerpo = json_encode(['evento' => 'prueba', 'numero' => ['id' => (int) $linea['id'], 'telefono' => $linea['telefono']], 'enviado_en' => date('c')], JSON_UNESCAPED_SLASHES);
        return self::post((string) $linea['aviso_url'], (string) $cuerpo, (string) $linea['aviso_secreto']);
    }

    /**
     * Filas para exportar. $que: contactos | mensajes. Mensajes: de los últimos $dias días.
     * @return list<list<string>>
     */
    public static function filas(PDO $db, int $lineaId, string $que, int $dias = 30): array
    {
        if ($que === 'contactos') {
            $campos = Campos::lista($db, $lineaId);
            $r = [array_merge(['Teléfono', 'Nombre', 'Nombre en WhatsApp', 'Tipo', 'Etiquetas', 'Etapa', 'Valor', 'Promociones', 'Notas', 'Creado', 'Último mensaje', 'Llegó del anuncio'],
                              array_column($campos, 'nombre'))];
            $st = $db->prepare('SELECT * FROM contactos WHERE linea_id = ? ORDER BY id');
            $st->execute([$lineaId]);
            foreach ($st as $c) {
                $datos = Campos::de($c);
                $r[] = array_merge([$c['telefono'], $c['nombre'], $c['nombre_whatsapp'], $c['tipo'], (string) $c['etiquetas'], (string) ($c['etapa'] ?? ''),
                        number_format((float) $c['valor'], 2, ',', ''), (string) $c['marketing'], $c['notas'], self::fecha($c['creado']), self::fecha($c['ultimo_entrante']),
                        (string) ($c['origen_titulo'] ?? $c['origen_id'] ?? '')],
                        array_map(fn ($cp) => Campos::mostrar($cp, $datos[$cp['clave']] ?? ''), $campos));
            }
            return $r;
        }
        $r = [['Fecha', 'Teléfono', 'Nombre', 'Dirección', 'Quién', 'Tipo', 'Texto', 'Estado', 'Costo']];
        $st = $db->prepare("SELECT m.*, c.telefono, c.nombre, c.nombre_whatsapp, u.nombre AS usuario FROM mensajes m JOIN contactos c ON c.id = m.contacto_id
                            LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.linea_id = ? AND m.creado >= now() - make_interval(days => ?) ORDER BY m.creado, m.id");
        $st->execute([$lineaId, max(1, min(366, $dias))]);
        foreach ($st as $m) {
            $quien = ['cliente' => 'Cliente', 'panel' => 'Equipo (panel)', 'auto' => 'Automático', 'bot' => 'Agente con IA', 'flujo' => 'Chatbot', 'tienda' => 'Tienda en línea', 'campania' => 'Campaña', 'celular' => 'Celular', 'pendiente' => 'Pendiente'][$m['origen']] ?? $m['origen'];
            $r[] = [self::fecha($m['creado']), $m['telefono'], $m['nombre'] !== '' ? $m['nombre'] : $m['nombre_whatsapp'], $m['direccion'] === 'entra' ? 'Recibido' : 'Enviado',
                    $m['usuario'] ? $quien . ': ' . $m['usuario'] : $quien, $m['tipo'], (string) $m['texto'], $m['estado'], number_format((float) $m['costo'], 4, ',', '')];
        }
        return $r;
    }

    /** CSV para Excel en español: BOM, separador ";" y sin fórmulas peligrosas. */
    public static function csv(array $filas): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($filas as $fila) {
            fputcsv($f, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $fila), ';', '"', '');
        }
        rewind($f);
        $s = (string) stream_get_contents($f);
        fclose($f);
        return $s;
    }

    private static function fecha(?string $ts): string
    {
        return $ts ? (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone('America/Guayaquil'))->format('Y-m-d H:i') : '';
    }
}
