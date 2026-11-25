<?php
declare(strict_types=1);

/**
 * Súper usuario en el login (Anexo C del protocolo v2): correo + clave + código de 2 pasos del Panel Tukán, contra un Panel de mentira
 * (tests/panel_super_simulado.php) y una copia del sitio (php -S) que le habla. Con PostgreSQL real.
 * Parte 1: TukanCentral::verificarSuper. Parte 2: la pantalla de entrar. Parte 3: con TUKAN_SOLO_CENTRAL=1.
 * Clave del súper usuario de mentira: ClaveBuena1 · código: 123456.   Uso: bash tests/correr.sh
 */
$SECRETO = str_repeat('cd34', 16);
foreach (['TUWA_LLAVE' => str_repeat('ab', 32), 'TUWA_URL' => 'https://whatsapp.ejemplo.com', 'TUKAN_IA_ENV' => '/no/existe',
          'TUKAN_CENTRAL_ID' => 'tuwhatsapp_super', 'TUKAN_CENTRAL_SECRET' => $SECRETO, 'TUKAN_CENTRAL_URL' => 'http://127.0.0.1:8284'] as $k => $v) {
    putenv("$k=$v");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\TukanCentral as TC;

$bien = 0;
$mal = 0;
function ok(bool $c, string $que): void
{
    global $bien, $mal;
    if ($c) {
        $bien++;
        echo "  OK    $que\n";
    } else {
        $mal++;
        echo "  FALLA $que\n";
    }
}
function igual(mixed $real, mixed $esperado, string $que): void
{
    ok($real === $esperado, $que . ($real === $esperado ? '' : ' — esperado ' . json_encode($esperado, JSON_UNESCAPED_UNICODE) . ', salió ' . json_encode($real, JSON_UNESCAPED_UNICODE)));
}
function arrancar(array $cmd, array $extra, string $log): mixed
{
    $env = array_merge(getenv(), $extra);
    return proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $tt, null, $env);
}
function esperar_puerto(int $p): bool
{
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $p)) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

$P_PANEL = 8284; $P_WEB = 8285; $P_WEB2 = 8286;
$dir = sys_get_temp_dir() . '/tuwa_super_' . getmypid();
@mkdir($dir, 0777, true);
$procs = [];
register_shutdown_function(function () use (&$procs, $dir) {
    foreach ($procs as $p) {
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    exec('rm -rf ' . escapeshellarg($dir));
});
foreach ([$P_PANEL, $P_WEB, $P_WEB2] as $p) {
    if (@fsockopen('127.0.0.1', $p)) {
        fwrite(STDERR, "El puerto de prueba $p está ocupado\n");
        exit(1);
    }
}
$procs[] = arrancar(['php', '-S', "127.0.0.1:$P_PANEL", dirname(__DIR__) . '/tests/panel_super_simulado.php'],
    ['PANEL_SIM_DIR' => $dir, 'TUKAN_CENTRAL_ID' => 'tuwhatsapp_super', 'TUKAN_CENTRAL_SECRET' => $SECRETO], "$dir/panel.log");
$base = ['TUKAN_CENTRAL_ID' => 'tuwhatsapp_super', 'TUKAN_CENTRAL_SECRET' => $SECRETO, 'TUKAN_CENTRAL_URL' => "http://127.0.0.1:$P_PANEL", 'TUKAN_SOLO_CENTRAL' => '0',
         'TUWA_LLAVE' => str_repeat('ab', 32), 'TUWA_URL' => 'https://whatsapp.ejemplo.com', 'TUKAN_IA_ENV' => '/no/existe'];
$procs[] = arrancar(['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/web.err", '-S', "127.0.0.1:$P_WEB", '-t', dirname(__DIR__) . '/public'], $base, "$dir/web.log");
$procs[] = arrancar(['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/web2.err", '-S', "127.0.0.1:$P_WEB2", '-t', dirname(__DIR__) . '/public'], ['TUKAN_SOLO_CENTRAL' => '1'] + $base, "$dir/web2.log");
if (!esperar_puerto($P_PANEL) || !esperar_puerto($P_WEB) || !esperar_puerto($P_WEB2)) {
    fwrite(STDERR, "No arrancaron los servidores de prueba\n");
    exit(1);
}
$log = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$dir/super.log"))));

echo "== 1. TukanCentral::verificarSuper contra el panel de mentira\n";
$r = TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
igual([$r['ok'], $r['codigo'], $r['email'] ?? null, $r['nombre'] ?? null], [true, 'ok', 'super@tukan.ec', 'Super Panel'], 'clave y código buenos: ok con correo y nombre del panel');
$t = $log(); $u = end($t);
igual([$u['tipo'], $u['vida'] <= 60, $u['email'], $u['codigo'], $u['ip']], ['super', true, 'super@tukan.ec', '123456', '203.0.113.9'], 'el panel recibió token tipo super (vida ≤ 60 s), correo, código e IP');
$r = TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '', '203.0.113.9');
igual([$r['ok'], $r['codigo']], [false, 'codigo'], 'sin código: el panel lo pide');
$r = TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '000000', '203.0.113.9');
igual([$r['ok'], $r['codigo'], $r['error']], [false, 'codigo', 'El código de 2 pasos no es correcto.'], 'código malo: texto del panel');
$r = TC::verificarSuper('super@tukan.ec', 'mala', '', '203.0.113.9');
igual([$r['ok'], $r['codigo']], [false, 'credenciales'], 'clave mala: credenciales');
$r = TC::verificarSuper('bloq@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
igual($r['codigo'], 'bloqueado', 'bloqueado por intentos');
$r = TC::verificarSuper('sin2@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
igual($r['codigo'], 'sin_2fa', 'sin 2 pasos activado en el panel');
touch("$dir/roto");
igual(TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9')['codigo'], 'red', 'respuesta que no es JSON: red');
unlink("$dir/roto");
touch("$dir/caido");
igual(TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9')['codigo'], 'red', 'panel en mantenimiento (503): red');
unlink("$dir/caido");
putenv('TUKAN_CENTRAL_SECRET=' . str_repeat('ef56', 16));
igual(TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9')['codigo'], 'firma', 'secreto distinto: el panel rechaza la firma');
putenv('TUKAN_CENTRAL_SECRET=' . $SECRETO);
putenv('TUKAN_CENTRAL_URL=http://127.0.0.1:1');
$r = TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
igual([$r['codigo'], $r['error']], ['red', 'No se pudo hablar con el Panel Tukán. Intenta de nuevo.'], 'panel apagado: red con texto en español');
putenv("TUKAN_CENTRAL_URL=http://127.0.0.1:$P_PANEL");
putenv('TUKAN_CENTRAL_ID');
$n = count($log());
igual([TC::verificarSuper('super@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9')['codigo'], count($log())], ['red', $n], 'conector sin configurar: red y no se llama al panel');
putenv('TUKAN_CENTRAL_ID=tuwhatsapp_super');

echo "== 2. La pantalla de entrar\n";
// Una cuenta de cliente (no es personal de Tukán) con su usuario
db()->exec("INSERT INTO empresas (id, nombre) VALUES (2, 'Negocio de Prueba') ON CONFLICT DO NOTHING");
db()->prepare("INSERT INTO usuarios (correo, nombre, clave, empresa_id, rol, super, activo) VALUES ('cliente@negocio.example', 'Cliente', ?, 2, 'admin', false, true)
               ON CONFLICT (correo) DO UPDATE SET clave = EXCLUDED.clave")->execute([password_hash('claveCliente1', PASSWORD_DEFAULT)]);
db()->prepare("INSERT INTO usuarios (correo, nombre, clave, empresa_id, rol, super, activo) VALUES ('viejo@tukan.ec', 'Personal viejo', ?, 1, 'admin', true, true)
               ON CONFLICT (correo) DO UPDATE SET clave = EXCLUDED.clave")->execute([password_hash('claveVieja12', PASSWORD_DEFAULT)]);
final class Nav
{
    public string $cuerpo = ''; public int $http = 0; public string $lugar = '';
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'tuwa_jar_'); }
    public function __destruct() { @unlink($this->jar); }
    public function pedir(string $metodo, string $ruta, ?array $datos = null): int
    {
        $ch = curl_init($this->base . $ruta); $cab = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$cab) { $p = explode(':', $h, 2); if (count($p) === 2) { $cab[strtolower(trim($p[0]))] = trim($p[1]); } return strlen($h); }]);
        if ($datos !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos)); }
        $this->cuerpo = (string) curl_exec($ch);
        $this->http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lugar = (string) ($cab['location'] ?? '');
        return $this->http;
    }
    public function csrf(): string { return preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->cuerpo, $m) ? $m[1] : ''; }
    public function tiene(string $t): bool { return str_contains($this->cuerpo, $t); }
}
$entrar = function (string $base, string $correo, string $clave, string $codigo = '') {
    $n = new Nav($base);
    $n->pedir('GET', '/?r=login');
    $d = ['csrf' => $n->csrf(), 'correo' => $correo, 'clave' => $clave];
    if ($codigo !== '') { $d['codigo'] = $codigo; }
    $n->pedir('POST', '/?r=login', $d);
    return $n;
};
$W = "http://127.0.0.1:$P_WEB";
db()->exec('DELETE FROM intentos_login');
@unlink("$dir/super.log");

$n = $entrar($W, 'cliente@negocio.example', 'claveCliente1');
ok($n->http === 302 && str_contains($n->lugar, 'inicio'), 'un cliente que entra bien va a su inicio');
igual(count($log()), 0, '…y no toca el panel');

$n = $entrar($W, 'super@tukan.ec', 'ClaveBuena1');
ok($n->http === 200 && $n->tiene('name="codigo"') && $n->tiene('Escribe también el código de 2 pasos'), 'súper usuario sin código: se queda y pide el código');
$n->pedir('GET', '/?r=clientes');
ok($n->http === 302 && str_contains($n->lugar, 'login'), 'todavía no hay sesión');
igual((int) db()->query("SELECT count(*) FROM usuarios WHERE lower(correo) = 'super@tukan.ec'")->fetchColumn(), 0, 'no se creó el usuario todavía');
igual((int) db()->query('SELECT count(*) FROM intentos_login')->fetchColumn(), 0, 'pedir el código no cuenta como intento fallido');

$n = $entrar($W, 'super@tukan.ec', 'ClaveBuena1', '000000');
ok($n->http === 200 && $n->tiene('El código de 2 pasos no es correcto.') && $n->tiene('name="codigo"'), 'código malo: texto del panel y sigue pidiendo el código');

$n = $entrar($W, 'super@tukan.ec', 'mala', '123456');
ok($n->http === 200 && $n->tiene('Correo o contraseña incorrectos.') && !$n->tiene('name="codigo"'), 'clave mala: mensaje de siempre y sin campo de código');
igual((int) db()->query('SELECT count(*) FROM intentos_login')->fetchColumn(), 1, '…y cuenta como intento fallido');

$antes = count($log());
$entrar($W, 'sinarroba', 'ClaveBuena1', '123456');
igual(count($log()), $antes, 'un correo inválido nunca llama al panel');

$n = $entrar($W, 'super@tukan.ec', 'ClaveBuena1', '123456');
ok($n->http === 302 && str_contains($n->lugar, 'clientes'), 'clave y código buenos: entra y abre Clientes de Tukán');
$n->pedir('GET', '/?r=clientes');
ok($n->http === 200 && $n->tiene('Panel Tukán'), 'ya tiene sesión (con el vínculo al Panel Tukán)');
$fila = db()->query("SELECT super, empresa_id, creado_por_central, activo FROM usuarios WHERE lower(correo) = 'super@tukan.ec'")->fetch();
igual([$fila['super'], (int) $fila['empresa_id'], $fila['creado_por_central'], $fila['activo']], [true, 1, true, true], 'se creó personal de Tukán marcado como creado por el Panel');
$entrar($W, 'super@tukan.ec', 'ClaveBuena1', '123456');
igual((int) db()->query("SELECT count(*) FROM usuarios WHERE lower(correo) = 'super@tukan.ec'")->fetchColumn(), 1, 'la segunda vez reutiliza el mismo usuario');

$n = $entrar($W, 'bloq@tukan.ec', 'ClaveBuena1', '123456');
ok($n->tiene('Demasiados intentos equivocados'), 'bloqueado: texto del panel');
$n = $entrar($W, 'sin2@tukan.ec', 'ClaveBuena1', '123456');
ok($n->tiene('Primero activa la verificación en 2 pasos'), 'sin 2 pasos: texto del panel');

// Un correo de un CLIENTE que el panel dice que es del personal: no entra como personal de Tukán
$n = $entrar($W, 'cliente@negocio.example', 'ClaveBuena1', '123456');
ok($n->http === 200 && $n->tiene('Ese correo es de un cliente') && !str_contains($n->lugar, 'clientes'), 'el correo de un cliente no se vuelve personal de Tukán');
igual((int) db()->query("SELECT count(*) FROM usuarios WHERE lower(correo) = 'cliente@negocio.example' AND super")->fetchColumn(), 0, '…ni se marca como súper');

touch("$dir/caido");
$n = $entrar($W, 'super@tukan.ec', 'ClaveBuena1', '123456');
ok($n->http === 200 && $n->tiene('Correo o contraseña incorrectos.'), 'panel caído: mensaje normal, el login no se rompe');
$n = $entrar($W, 'cliente@negocio.example', 'claveCliente1');
ok($n->http === 302 && str_contains($n->lugar, 'inicio'), '…y un cliente sigue entrando');
unlink("$dir/caido");

echo "== 3. Con TUKAN_SOLO_CENTRAL=1 (el personal de Tukán no entra con la clave guardada aquí)\n";
$W2 = "http://127.0.0.1:$P_WEB2";
db()->exec('DELETE FROM intentos_login');
$n = $entrar($W2, 'viejo@tukan.ec', 'claveVieja12');
ok($n->http === 200 && $n->tiene('entra con la clave y el código de 2 pasos del Panel Tukán Central'), 'el personal con clave guardada aquí NO entra con ella');
$n = $entrar($W2, 'super@tukan.ec', 'ClaveBuena1', '123456');
ok($n->http === 302 && str_contains($n->lugar, 'clientes'), 'pero sí con la clave y el código del Panel Tukán');
$n = $entrar($W2, 'cliente@negocio.example', 'claveCliente1');
ok($n->http === 302 && str_contains($n->lugar, 'inicio'), 'los clientes entran igual que siempre');

$errores = is_file("$dir/web.err") ? trim((string) file_get_contents("$dir/web.err")) : '';
$errores .= is_file("$dir/web2.err") ? trim((string) file_get_contents("$dir/web2.err")) : '';
igual($errores, '', 'sin avisos de PHP en el sitio');

echo "\nSúper usuario en el login — correctas: $bien · Fallas: $mal\n";
exit($mal ? 1 : 0);
