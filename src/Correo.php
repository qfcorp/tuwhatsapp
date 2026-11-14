<?php
declare(strict_types=1);

namespace TuWhatsApp;

use RuntimeException;

/**
 * Correos que manda el programa (respaldo de los avisos de mensajes nuevos). Cliente SMTP propio y chico, sin librerías:
 *  - config/.env: SMTP_HOST, SMTP_PORT (465 = SSL, 587 = STARTTLS, 25 = sin cifrar), SMTP_USUARIO, SMTP_CLAVE, SMTP_DE, SMTP_DE_NOMBRE.
 *  - Sin SMTP_HOST no manda nada (el panel lo dice). mail() de PHP / sendmail solo si config/.env dice SMTP_MODO=sendmail
 *    (si no, los correos podrían salir sin SPF/DKIM y perderse sin que nadie se entere).
 *  - Sin SSL directo (465) siempre se cifra con STARTTLS; si el servidor no lo ofrece no se manda la clave en claro
 *    (solo con SMTP_SEGURIDAD=ninguna, para un servidor de correo en la misma máquina).
 *  - SMTP_SIN_VERIFICAR=1 acepta certificados propios (servidor de correo con certificado autofirmado); no se recomienda.
 */
final class Correo
{
    /** ¿Hay forma de mandar correos? */
    public static function configurado(): bool
    {
        return self::host() !== '' || self::haySendmail();
    }

    /** Cómo se manda (para mostrarlo en el panel). */
    public static function modo(): string
    {
        return self::host() !== '' ? 'smtp' : (self::haySendmail() ? 'sendmail' : 'ninguno');
    }

    private static function host(): string
    {
        return trim((string) getenv('SMTP_HOST'));
    }

    private static function haySendmail(): bool
    {
        if (getenv('TUWA_SIN_SENDMAIL') === '1' || strtolower(trim((string) getenv('SMTP_MODO'))) !== 'sendmail') {
            return false;
        }
        $p = (string) ini_get('sendmail_path');
        $bin = $p !== '' ? strtok($p, ' ') : '/usr/sbin/sendmail';
        return is_string($bin) && is_file($bin) && is_executable($bin);
    }

    public static function valido(string $correo): bool
    {
        return strlen($correo) <= 200 && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $correo);
    }

    /**
     * Manda un correo de texto + HTML. Devuelve true si el servidor lo aceptó.
     * @throws RuntimeException con el motivo si no se pudo (sin configurar, clave mala, servidor caído…)
     */
    public static function enviar(string $para, string $asunto, string $texto, string $html = ''): bool
    {
        if (!self::valido($para)) {
            throw new RuntimeException('Correo de destino no válido.');
        }
        $de = trim((string) (getenv('SMTP_DE') ?: getenv('SMTP_USUARIO') ?: ''));
        if (!self::valido($de)) {
            $de = 'avisos@tukansoftware.com';
        }
        $nombre = trim((string) (getenv('SMTP_DE_NOMBRE') ?: 'Tukán Mensajes'));
        $mensaje = self::armar($de, $nombre, $para, $asunto, $texto, $html);
        if (self::host() !== '') {
            self::smtp($de, $para, $mensaje);
            return true;
        }
        if (!self::haySendmail()) {
            throw new RuntimeException('El servidor no tiene configurado el correo (SMTP).');
        }
        [$cab, $cuerpo] = explode("\r\n\r\n", $mensaje, 2);
        $cab = preg_replace('/^(To|Subject):.*\r\n/mi', '', $cab . "\r\n");
        return mail($para, self::cabeceraUtf8($asunto), $cuerpo, rtrim((string) $cab), '-f' . $de);
    }

    /** Arma el correo completo (cabeceras + cuerpo multipart). Público para las pruebas. */
    public static function armar(string $de, string $nombre, string $para, string $asunto, string $texto, string $html = ''): string
    {
        $dominio = substr((string) strrchr($de, '@'), 1) ?: 'tukansoftware.com';
        $cab = [
            'Date: ' . date('r'),
            'From: ' . self::cabeceraUtf8($nombre) . ' <' . $de . '>',
            'To: <' . $para . '>',
            'Subject: ' . self::cabeceraUtf8($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        if ($html === '') {
            $cab[] = 'Content-Type: text/plain; charset=UTF-8';
            $cab[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $cab) . "\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
        }
        $b = 'tuwa' . bin2hex(random_bytes(8));
        $cab[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        return implode("\r\n", $cab) . "\r\n\r\n"
            . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n")
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
            . "--$b--\r\n";
    }

    /** Asunto o nombre con tildes, eñes o emojis (RFC 2047). */
    public static function cabeceraUtf8(string $t): string
    {
        $t = str_replace(["\r", "\n"], ' ', $t);
        return preg_match('/[^\x20-\x7E]/', $t) ? '=?UTF-8?B?' . base64_encode($t) . '?=' : $t;
    }

    /** Conversación SMTP: EHLO, STARTTLS (587), AUTH, MAIL FROM, RCPT TO, DATA. */
    private static function smtp(string $de, string $para, string $mensaje): void
    {
        $host = self::host();
        $puerto = (int) (getenv('SMTP_PORT') ?: 587);
        $ssl = $puerto === 465 || getenv('SMTP_SEGURIDAD') === 'ssl';
        $verificar = getenv('SMTP_SIN_VERIFICAR') !== '1';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verificar, 'verify_peer_name' => $verificar, 'allow_self_signed' => !$verificar, 'peer_name' => $host]]);
        $s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $puerto, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException("No se pudo conectar al servidor de correo ($host:$puerto): $errstr");
        }
        stream_set_timeout($s, 15);
        try {
            self::leer($s, 220);
            $yo = gethostname() ?: 'localhost';
            $ehlo = self::orden($s, "EHLO $yo", 250);
            // Sin SSL directo: se cifra con STARTTLS. Si el servidor no lo ofrece, nunca se manda la clave en claro:
            // solo se sigue sin cifrar en el puerto 25 sin usuario (relevo local) o con SMTP_SEGURIDAD=ninguna.
            $sinCifrar = getenv('SMTP_SEGURIDAD') === 'ninguna';
            if (!$ssl && !$sinCifrar) {
                if (stripos($ehlo, 'STARTTLS') !== false) {
                    self::orden($s, 'STARTTLS', 220);
                    if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                        throw new RuntimeException('No se pudo cifrar la conexión con el servidor de correo.');
                    }
                    $ehlo = self::orden($s, "EHLO $yo", 250);
                } elseif ($puerto !== 25 || (string) getenv('SMTP_USUARIO') !== '') {
                    throw new RuntimeException('El servidor de correo no ofrece conexión cifrada (STARTTLS): no se manda la clave sin cifrar. Usa el puerto 465 o 587.');
                }
            }
            $usuario = (string) getenv('SMTP_USUARIO');
            if ($usuario !== '') {
                $clave = (string) getenv('SMTP_CLAVE');
                if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $ehlo)) {
                    self::orden($s, 'AUTH PLAIN ' . base64_encode("\0" . $usuario . "\0" . $clave), 235, true);
                } else {
                    self::orden($s, 'AUTH LOGIN', 334);
                    self::orden($s, base64_encode($usuario), 334, true);
                    self::orden($s, base64_encode($clave), 235, true);
                }
            }
            self::orden($s, "MAIL FROM:<$de>", 250);
            self::orden($s, "RCPT TO:<$para>", [250, 251]);
            self::orden($s, 'DATA', 354);
            // Puntos al inicio de línea se duplican (RFC 5321 4.5.2)
            $datos = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $mensaje));
            fwrite($s, rtrim($datos, "\r\n") . "\r\n.\r\n");
            self::leer($s, 250);
            fwrite($s, "QUIT\r\n");
        } finally {
            fclose($s);
        }
    }

    /** @param int|list<int> $esperado */
    private static function orden($s, string $linea, int|array $esperado, bool $secreta = false): string
    {
        fwrite($s, $linea . "\r\n");
        try {
            return self::leer($s, $esperado);
        } catch (RuntimeException $e) {
            if ($secreta) {
                throw new RuntimeException('El servidor de correo no aceptó el usuario o la clave.');
            }
            throw $e;
        }
    }

    /** @param int|list<int> $esperado */
    private static function leer($s, int|array $esperado): string
    {
        $todo = '';
        while (($l = fgets($s, 2048)) !== false) {
            $todo .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $codigo = (int) substr($todo, 0, 3);
        if (!in_array($codigo, (array) $esperado, true)) {
            throw new RuntimeException('El servidor de correo respondió: ' . trim(mb_substr($todo, 0, 200)));
        }
        return $todo;
    }
}
