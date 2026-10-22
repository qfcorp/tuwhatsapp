<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Fotos, audios, videos y documentos de los chats.
 * Se guardan fuera de la carpeta pública (config TUWA_ARCHIVOS, por defecto /var/www/tuwhatsapp/archivos) y solo se ven
 * desde el panel con sesión (ruta ?r=archivo&id=…), revisando que el mensaje sea de la cuenta de quien lo pide.
 *
 * Lo que llega: Meta manda un "media id"; se baja apenas llega el aviso (la dirección de Meta dura 5 minutos,
 * el id 30 días) y si falla se reintenta al abrirlo.
 * Lo que sale: se sube a Meta (/{phone_number_id}/media) y se manda con ese id.
 */
final class Archivos
{
    /** tipo de mensaje de WhatsApp => [tipos MIME aceptados, máximo de bytes que acepta Meta, extensión por defecto] */
    public const TIPOS = [
        'imagen'    => [['image/jpeg' => 'jpg', 'image/png' => 'png'], 5 * 1024 * 1024],
        'audio'     => [['audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr'], 16 * 1024 * 1024],
        'video'     => [['video/mp4' => 'mp4', 'video/3gpp' => '3gp'], 16 * 1024 * 1024],
        'documento' => [[
            'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
            'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip',
        ], 30 * 1024 * 1024],
        'sticker'   => [['image/webp' => 'webp'], 1024 * 1024],
    ];

    /** Nombre de WhatsApp => nuestro tipo */
    public const DE_META = ['image' => 'imagen', 'audio' => 'audio', 'voice' => 'audio', 'video' => 'video', 'document' => 'documento', 'sticker' => 'sticker'];
    public const A_META = ['imagen' => 'image', 'audio' => 'audio', 'video' => 'video', 'documento' => 'document', 'sticker' => 'sticker'];

    public static function carpeta(): string
    {
        $c = rtrim((string) (getenv('TUWA_ARCHIVOS') ?: RAIZ . '/archivos'), '/');
        if (!is_dir($c) && !@mkdir($c, 0770, true) && !is_dir($c)) {
            throw new RuntimeException('No se puede crear la carpeta de archivos (' . $c . ').');
        }
        return $c;
    }

    /** Ruta completa a partir de la ruta guardada (siempre dentro de la carpeta; nunca "..") */
    public static function ruta(string $relativa): ?string
    {
        if ($relativa === '' || str_contains($relativa, '..') || !preg_match('#^[a-z0-9/_\-.]+$#i', $relativa)) {
            return null;
        }
        $p = self::carpeta() . '/' . $relativa;
        return is_file($p) ? $p : null;
    }

    public static function tipoDeMime(string $mime): ?string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        foreach (self::TIPOS as $tipo => [$mimes]) {
            if (isset($mimes[$mime])) {
                return $tipo;
            }
        }
        return null;
    }

    /** Guarda bytes en la carpeta y devuelve la ruta relativa (cuenta/año-mes/azar.ext). */
    public static function guardarBytes(string $bytes, string $mime, int $empresaId): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $ext = 'bin';
        foreach (self::TIPOS as [$mimes]) {
            if (isset($mimes[$mime])) {
                $ext = $mimes[$mime];
            }
        }
        $sub = $empresaId . '/' . date('Y-m');
        $dir = self::carpeta() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de archivos.');
        }
        $nombre = bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $nombre, $bytes) === false) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        return $sub . '/' . $nombre;
    }

    /**
     * Revisa un archivo subido desde el panel (o grabado en el navegador) y lo deja listo para enviar.
     * Las notas de voz del navegador (webm/opus) se pasan a ogg/opus con ffmpeg, que es lo que pide WhatsApp.
     * @return array{tipo:string, mime:string, nombre:string, bytes:int, ruta:string}
     */
    public static function deSubida(array $f, int $empresaId): array
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            $codigo = (int) ($f['error'] ?? 0);
            throw new RuntimeException(in_array($codigo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo es muy grande.' : 'No llegó el archivo. Vuelve a elegirlo.');
        }
        $tmp = (string) $f['tmp_name'];
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $nombre = self::nombreLimpio((string) ($f['name'] ?? 'archivo'));
        if (in_array($mime, ['video/webm', 'audio/webm'], true) || ($mime === 'application/octet-stream' && str_ends_with($nombre, '.webm'))) {
            $ogg = self::aOgg($tmp);
            if ($ogg === null) {
                throw new RuntimeException('No se pudo preparar la nota de voz (falta ffmpeg en el servidor). Sube un audio .mp3 o .ogg.');
            }
            $bytes = $ogg;
            $mime = 'audio/ogg';
            $nombre = 'nota-de-voz.ogg';
        } else {
            $bytes = (string) file_get_contents($tmp);
        }
        if ($mime === 'application/zip' && preg_match('/\.(docx|xlsx|pptx)$/i', $nombre, $m)) {   // Office moderno se ve como zip
            $mime = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                     'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'][strtolower($m[1])];
        }
        $tipo = self::tipoDeMime($mime);
        if ($tipo === null || $tipo === 'sticker') {
            throw new RuntimeException('Ese tipo de archivo no se puede enviar por WhatsApp (' . $mime . '). Usa foto JPG/PNG, audio, video MP4 o documento PDF/Word/Excel.');
        }
        $max = self::TIPOS[$tipo][1];
        if (strlen($bytes) > $max) {
            throw new RuntimeException('El archivo pesa ' . self::tamanio(strlen($bytes)) . ': WhatsApp acepta hasta ' . self::tamanio($max) . ' para ' . $tipo . '.');
        }
        return ['tipo' => $tipo, 'mime' => $mime, 'nombre' => $nombre, 'bytes' => strlen($bytes), 'ruta' => self::guardarBytes($bytes, $mime, $empresaId)];
    }

    /** Baja el archivo de un mensaje que llegó (si todavía no está) y guarda dónde quedó. */
    public static function bajarDeMensaje(PDO $db, array $msg, array $linea): ?string
    {
        if (!empty($msg['media_ruta']) && self::ruta((string) $msg['media_ruta'])) {
            return (string) $msg['media_ruta'];
        }
        if (empty($msg['media_id'])) {
            return null;
        }
        $r = Meta::deLinea($linea)->bajarMedio((string) $msg['media_id']);
        if (!$r['ok']) {
            $db->prepare('UPDATE mensajes SET error = COALESCE(error, ?) WHERE id = ?')->execute(['No se pudo bajar el archivo: ' . $r['error'], $msg['id']]);
            return null;
        }
        $mime = (string) ($msg['media_mime'] ?: $r['mime']);
        $ruta = self::guardarBytes($r['bytes'], $mime, (int) $linea['empresa_id']);
        $db->prepare('UPDATE mensajes SET media_ruta = ?, media_bytes = ?, media_mime = ? WHERE id = ?')->execute([$ruta, strlen($r['bytes']), $mime, $msg['id']]);
        return $ruta;
    }

    public static function nombreLimpio(string $n): string
    {
        $n = preg_replace('/[^\p{L}\p{N} ._\-()]+/u', '', basename($n)) ?? '';
        $n = trim(mb_substr($n, 0, 120));
        return $n !== '' ? $n : 'archivo';
    }

    public static function tamanio(int $b): string
    {
        return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
    }

    /** webm/opus del navegador -> ogg/opus (sin volver a comprimir). null si no hay ffmpeg. */
    private static function aOgg(string $origen): ?string
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '') {
            return null;
        }
        $destino = tempnam(sys_get_temp_dir(), 'tuwa') . '.ogg';
        $cmd = escapeshellarg($ffmpeg) . ' -nostdin -loglevel error -y -i ' . escapeshellarg($origen) . ' -vn -c:a libopus -b:a 32k ' . escapeshellarg($destino) . ' 2>&1';
        exec($cmd, $salida, $codigo);
        $bytes = $codigo === 0 && is_file($destino) ? (string) file_get_contents($destino) : null;
        @unlink($destino);
        return $bytes !== '' ? $bytes : null;
    }
}
