<?php
declare(strict_types=1);

/**
 * Servidor de correo (SMTP) FALSO para las pruebas: nunca manda nada de verdad. Guarda cada correo en SMTP_LOG (una línea JSON).
 * Uso: SMTP_LOG=/tmp/correos.log php tests/smtp_falso.php 8099
 * Acepta AUTH PLAIN y AUTH LOGIN con el usuario avisos@prueba.ec y la clave clave-buena (cualquier otra → 535).
 * SMTP_ESPERA=1.5 → espera esos segundos antes de saludar (servidor lento). SMTP_LOG_AUTH=archivo → anota cada AUTH que recibe.
 */
$puerto = (int) ($argv[1] ?? 8099);
$log = (string) getenv('SMTP_LOG');
$srv = stream_socket_server("tcp://127.0.0.1:$puerto", $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "No se pudo abrir el puerto $puerto: $errstr\n");
    exit(1);
}
while ($c = @stream_socket_accept($srv, -1)) {
    stream_set_timeout($c, 10);
    $di = fn (string $l) => fwrite($c, $l . "\r\n");
    if ((float) getenv('SMTP_ESPERA') > 0) {
        usleep((int) ((float) getenv('SMTP_ESPERA') * 1000000));
    }
    $di('220 smtp.prueba.ec ESMTP falso');
    $correo = ['de' => '', 'para' => [], 'usuario' => '', 'datos' => ''];
    $usuarioLogin = null;
    $esperando = '';
    while (($l = fgets($c, 4096)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($esperando === 'login_usuario') {
            $usuarioLogin = base64_decode($l);
            $esperando = 'login_clave';
            $di('334 UGFzc3dvcmQ6');
            continue;
        }
        if ($esperando === 'login_clave') {
            $esperando = '';
            if ($usuarioLogin === 'avisos@prueba.ec' && base64_decode($l) === 'clave-buena') {
                $correo['usuario'] = $usuarioLogin;
                $di('235 Autenticado');
            } else {
                $di('535 Clave mala');
            }
            continue;
        }
        $orden = strtoupper(substr($l, 0, 4));
        if ($orden === 'AUTH' && getenv('SMTP_LOG_AUTH')) {
            file_put_contents((string) getenv('SMTP_LOG_AUTH'), "auth\n", FILE_APPEND | LOCK_EX);
        }
        if ($orden === 'EHLO' || $orden === 'HELO') {
            fwrite($c, "250-smtp.prueba.ec\r\n250-AUTH " . (getenv('SMTP_SOLO_LOGIN') === '1' ? 'LOGIN' : 'PLAIN LOGIN') . "\r\n250 8BITMIME\r\n");
        } elseif (str_starts_with(strtoupper($l), 'AUTH PLAIN ')) {
            $p = explode("\0", (string) base64_decode(substr($l, 11)));
            if (($p[1] ?? '') === 'avisos@prueba.ec' && ($p[2] ?? '') === 'clave-buena') {
                $correo['usuario'] = $p[1];
                $di('235 Autenticado');
            } else {
                $di('535 Clave mala');
            }
        } elseif (strtoupper($l) === 'AUTH LOGIN') {
            $esperando = 'login_usuario';
            $di('334 VXNlcm5hbWU6');
        } elseif ($orden === 'MAIL') {
            $correo['de'] = preg_replace('/^MAIL FROM:<(.*)>.*$/i', '$1', $l);
            $di('250 OK');
        } elseif ($orden === 'RCPT') {
            $correo['para'][] = preg_replace('/^RCPT TO:<(.*)>.*$/i', '$1', $l);
            $di('250 OK');
        } elseif ($orden === 'DATA') {
            $di('354 Adelante');
            $datos = '';
            while (($d = fgets($c, 8192)) !== false && $d !== ".\r\n") {
                $datos .= $d;
            }
            $correo['datos'] = preg_replace('/^\.\./m', '.', $datos);
            if ($log !== '') {
                file_put_contents($log, json_encode($correo, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            }
            $correo = ['de' => '', 'para' => [], 'usuario' => $correo['usuario'], 'datos' => ''];
            $di('250 Recibido');
        } elseif ($orden === 'RSET' || $orden === 'NOOP') {
            $di('250 OK');
        } elseif ($orden === 'QUIT') {
            $di('221 Chao');
            break;
        } else {
            $di('502 No entiendo');
        }
    }
    fclose($c);
}
