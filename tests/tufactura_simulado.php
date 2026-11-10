<?php
declare(strict_types=1);

/**
 * TuFactura SIMULADO para las pruebas de los programas (php -S 127.0.0.1:PUERTO tufactura_simulado.php).
 * ORIGINAL: tufactura/docs/enlace/tufactura_simulado.php (cada programa tiene una copia en tests/).
 * Responde como la API de verdad (docs/API.md): GET /api/v1/ping, POST /api/v1/facturas, GET /api/v1/facturas/{id} y /pdf.
 *  - Clave válida: tf_ + 40 letras a-f/números. Si tiene «dead» → 401. Si tiene «bad0» → 403 (cuenta suspendida).
 *  - Cliente con razón social «RECHAZAR» → el SRI la devuelve. «ESPERAR» → queda «recibida» y se autoriza al consultarla.
 *  - Guarda todo en TF_SIM_DIR (carpeta temporal): las pruebas leen ahí lo que llegó.
 */
$dir = getenv('TF_SIM_DIR') ?: sys_get_temp_dir() . '/tf_sim';
@mkdir($dir, 0777, true);
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$json = function (int $c, array $d): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer (tf_[a-f0-9]{40})$/', $auth, $m)) $json(401, ['ok' => false, 'error' => 'Falta la clave de la API (Authorization: Bearer tf_…).']);
$clave = $m[1];
if (str_contains($clave, 'dead')) $json(401, ['ok' => false, 'error' => 'Clave de la API no válida o desactivada.']);
if (str_contains($clave, 'bad0')) $json(403, ['ok' => false, 'error' => 'La cuenta de TuFactura está suspendida. Escribe a Tukán Software para seguir facturando.']);
$k = substr(hash('sha256', $clave), 0, 12);
$archivo = "$dir/facturas_$k.json";
$todas = is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: []) : [];
$guardar = function () use (&$todas, $archivo) { file_put_contents($archivo, json_encode($todas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); };
$resp = function (array $f) { return ['ok' => true, 'id' => $f['id'], 'referencia' => $f['referencia'], 'estado' => $f['estado'], 'autorizada' => $f['estado'] === 'autorizado',
    'numero' => $f['numero'], 'clave_acceso' => $f['clave'], 'ambiente' => 'pruebas', 'subtotal' => $f['subtotal'], 'iva' => $f['iva'], 'total' => $f['total'],
    'mensajes' => $f['mensajes'], 'pdf' => '/api/v1/facturas/' . $f['id'] . '/pdf']; };

if ($ruta === '/api/v1/ping') $json(200, ['ok' => true, 'sistema' => 'Programa', 'empresa' => 'EMPRESA DE PRUEBA S.A.', 'ruc' => '1790012345001', 'punto' => '001-002', 'local' => 'Matriz', 'ambiente' => 'pruebas']);
if ($ruta === '/api/v1/facturas' && $metodo === 'POST') {
    $d = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($d) || trim((string) ($d['referencia'] ?? '')) === '') $json(422, ['ok' => false, 'error' => 'Falta "referencia".']);
    if (empty($d['items'])) $json(422, ['ok' => false, 'error' => 'Falta "items".']);
    foreach ($todas as $i => $f) {
        if ($f['referencia'] === $d['referencia']) {
            if ($f['estado'] === 'devuelta' && ($d['cliente']['razon_social'] ?? '') !== 'RECHAZAR') { $todas[$i]['estado'] = 'autorizado'; $todas[$i]['mensajes'] = ''; $guardar(); }
            $json(200, ['repetida' => true] + $resp($todas[$i]));
        }
    }
    // Igual que el SRI: base de cada línea a 2 decimales y el IVA sobre la suma de cada tarifa
    $sub = 0.0; $iva = 0.0; $grupos = [];
    foreach ($d['items'] as $it) { $b = round((float) $it['cantidad'] * (float) $it['precio'], 2); $sub += $b; $t = (string) (float) ($it['iva'] ?? 15); $grupos[$t] = ($grupos[$t] ?? 0) + $b; }
    foreach ($grupos as $t => $b) $iva += round($b * (float) $t / 100, 2);
    $n = count($todas) + 1;
    $rs = (string) ($d['cliente']['razon_social'] ?? '');
    $estado = $rs === 'RECHAZAR' ? 'devuelta' : ($rs === 'ESPERAR' ? 'recibida' : 'autorizado');
    $f = ['id' => 500 + $n, 'referencia' => $d['referencia'], 'estado' => $estado, 'numero' => '001-002-' . str_pad((string) $n, 9, '0', STR_PAD_LEFT),
          'clave' => str_pad((string) (1000 + $n), 49, '7', STR_PAD_LEFT), 'subtotal' => round($sub, 2), 'iva' => round($iva, 2), 'total' => round($sub + $iva, 2),
          'mensajes' => $estado === 'devuelta' ? 'ERROR 65 FECHA EMISION EXTEMPORANEA' : '', 'datos' => $d];
    $todas[] = $f;
    $guardar();
    $json(201, $resp($f));
}
if (preg_match('#^/api/v1/facturas/(\d+)(/pdf)?$#', $ruta, $mm) && $metodo === 'GET') {
    foreach ($todas as $i => $f) {
        if ($f['id'] === (int) $mm[1]) {
            if (!empty($mm[2])) { header('Content-Type: application/pdf'); echo "%PDF-1.4\n% factura " . $f['numero'] . "\n%%EOF"; exit; }
            if ($f['estado'] === 'recibida') { $todas[$i]['estado'] = 'autorizado'; $guardar(); }
            $json(200, $resp($todas[$i]));
        }
    }
    $json(404, ['ok' => false, 'error' => 'No existe.']);
}
$json(404, ['ok' => false, 'error' => 'No existe.']);
