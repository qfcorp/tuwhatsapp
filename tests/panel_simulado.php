<?php
declare(strict_types=1);

/**
 * PANEL TUKÁN CENTRAL SIMULADO para las pruebas de «pagar el plan solo con tarjeta» (src/PagosCentral.php).
 * Hace de /plux_api.php del Panel: revisa el token v2 «plux» (firma, programa y hash del cuerpo) y contesta como
 * PagosPlux::atenderPrograma() del Panel de verdad: estado, crear, cancelar y cancelar_plan. Nunca llama a Plux ni cobra.
 *
 * Servidor:  PANEL_ESTADO=archivo.json PANEL_LOG=archivo PANEL_SECRETO=... PANEL_BASE=http://127.0.0.1:8095 php -S 127.0.0.1:8095 tests/panel_simulado.php
 * Desde las pruebas (cambia el archivo de estado):
 *   php tests/panel_simulado.php modo activo|apagado|caido     php tests/panel_simulado.php pruebas si|no
 *   php tests/panel_simulado.php aprobar   (el pago pendiente pasa a suscripción activa)      php tests/panel_simulado.php limpiar
 */
$archivo = (string) getenv('PANEL_ESTADO');
$leer = function () use ($archivo): array {
    $j = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
    return (is_array($j) ? $j : []) + ['modo' => 'apagado', 'pruebas' => true, 'cobros' => [], 'sig' => 1];
};
$guardar = fn (array $e) => file_put_contents($archivo, json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

if (PHP_SAPI === 'cli') {
    $e = $leer();
    [$que, $valor] = [$argv[1] ?? '', $argv[2] ?? ''];
    if ($que === 'modo') {
        $e['modo'] = $valor;
    } elseif ($que === 'pruebas') {
        $e['pruebas'] = $valor === 'si';
    } elseif ($que === 'aprobar') {
        foreach ($e['cobros'] as &$c) {
            if ($c['estado'] === 'pendiente') {
                $c = array_merge($c, ['estado' => 'activa', 'estado_texto' => 'Activa', 'cobra_sola' => true, 'url_pago' => null,
                                      'pagado_hasta' => date('Y-m-d', strtotime('+1 month')), 'cuotas' => 1]);
            }
        }
        unset($c);
    } elseif ($que === 'limpiar') {
        $e['cobros'] = [];
    } else {
        fwrite(STDERR, "Uso: modo activo|apagado|caido · pruebas si|no · aprobar · limpiar\n");
        exit(1);
    }
    $guardar($e);
    exit(0);
}

$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$e = $leer();
if (preg_match('#^/pagar/(\d+)$#', $ruta, $m)) {   // la «página de pago» a donde se manda al cliente
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>PAGINA DE PAGO SIMULADA</h1><p>cobro ' . (int) $m[1] . '</p>';
    return;
}
$responder = function (int $http, array $j): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
if ($ruta !== '/plux_api.php') {
    $responder(404, ['ok' => false, 'error' => 'No existe.']);
    return;
}
if ($e['modo'] === 'caido') {   // el Panel con problemas
    http_response_code(500);
    echo 'Error interno';
    return;
}
$cuerpo = (string) file_get_contents('php://input');
$b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$partes = explode('.', (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? ''));
$p = count($partes) === 2 ? json_decode((string) base64_decode(strtr($partes[0], '-_', '+/')), true) : null;
$buena = is_array($p) && hash_equals($b64(hash_hmac('sha256', $partes[0], (string) getenv('PANEL_SECRETO'), true)), $partes[1])
    && ($p['v'] ?? 0) === 2 && ($p['tipo'] ?? '') === 'plux' && ($p['inst'] ?? '') === 'tuwhatsapp' && ($p['exp'] ?? 0) >= time()
    && ($p['bh'] ?? '') === $b64(hash('sha256', $cuerpo, true)) && filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$buena) {
    $responder(401, ['ok' => false, 'codigo' => 'firma', 'error' => 'Token no válido.']);
    return;
}
$b = json_decode($cuerpo, true);
if (!is_array($b) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', (string) ($b['cliente_id'] ?? ''))) {
    $responder(422, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Falta el cliente_id.']);
    return;
}
if (($log = (string) getenv('PANEL_LOG')) !== '') {
    file_put_contents($log, json_encode($b + ['_email' => $p['email']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
$accion = (string) ($b['accion'] ?? '');
$cid = (string) $b['cliente_id'];
$activo = $e['modo'] === 'activo';
$mios = array_values(array_filter($e['cobros'], fn ($c) => $c['cliente_id'] === $cid));
$viva = fn (array $c) => $c['tipo'] === 'suscripcion' && in_array($c['estado'], ['pendiente', 'rechazado', 'activa', 'en_gracia'], true);
$publico = function (array $c): array {
    unset($c['cliente_id']);
    return $c;
};

if ($accion === 'cancelar_plan') {
    if (!empty($b['deshacer'])) {
        $responder(200, ['ok' => true, 'deshecha' => true]);
        return;
    }
    $n = 0;
    foreach ($e['cobros'] as &$c) {
        if ($c['cliente_id'] === $cid && $viva($c)) {
            $c['estado'] = 'cancelada';
            $c['estado_texto'] = 'Cancelada';
            $c['url_pago'] = null;
            $n++;
        }
    }
    unset($c);
    $guardar($e);
    $responder(200, ['ok' => true, 'canceladas' => $n, 'programadas' => 0, 'errores' => []]);
    return;
}
if ($accion === 'estado') {
    $responder(200, ['ok' => true, 'activo' => $activo, 'iva' => 15.0, 'pruebas' => (bool) $e['pruebas'], 'tope_consumidor' => 50.0,
                     'cobros' => $activo ? array_map($publico, array_reverse($mios)) : []]);
    return;
}
if (!$activo) {
    $responder(409, ['ok' => false, 'codigo' => 'apagado', 'error' => 'El pago con tarjeta no está disponible por ahora. Pague por transferencia y envíe el comprobante.']);
    return;
}
if ($accion === 'cancelar') {
    foreach ($e['cobros'] as &$c) {
        if ($c['cliente_id'] === $cid && $c['id'] === (int) ($b['id'] ?? 0)) {
            $c['estado'] = 'cancelada';
            $c['estado_texto'] = 'Cancelada';
            $c['url_pago'] = null;
            $guardar($e);
            $responder(200, ['ok' => true, 'programada' => false, 'cobro' => $publico($c)]);
            return;
        }
    }
    unset($c);
    $responder(404, ['ok' => false, 'codigo' => 'no_encontrado', 'error' => 'Esa suscripción no existe.']);
    return;
}
if ($accion !== 'crear') {
    $responder(400, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Acción no válida (crear, estado, cancelar o cambiar).']);
    return;
}
$tipo = (string) ($b['tipo'] ?? 'cobro');
foreach ($mios as $c) {
    if ($tipo === 'suscripcion' && $viva($c)) {
        $responder(409, ['ok' => false, 'codigo' => 'ya_tiene', 'error' => 'Ya tiene una suscripción con tarjeta (' . $c['estado_texto'] . ').', 'cobro' => $publico($c)]);
        return;
    }
}
$subtotal = round((float) ($b['subtotal'] ?? 0), 2);
if ($subtotal < 0.5 || ($tipo === 'suscripcion' && !in_array($b['periodo'] ?? null, ['mensual', 'trimestral', 'semestral', 'anual'], true))) {
    $responder(422, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Datos del cobro no válidos.']);
    return;
}
$id = (int) $e['sig'];
$e['sig'] = $id + 1;
$nuevo = ['id' => $id, 'cliente_id' => $cid, 'tipo' => $tipo, 'estado' => 'pendiente', 'estado_texto' => 'Esperando el pago',
          'descripcion' => (string) ($b['descripcion'] ?? ''), 'plan' => $b['plan'] ?? null, 'periodo' => $b['periodo'] ?? null, 'meses' => (int) ($b['meses'] ?? 1),
          'subtotal' => $subtotal, 'total' => round($subtotal * 1.15, 2), 'url_pago' => rtrim((string) getenv('PANEL_BASE'), '/') . '/pagar/' . $id,
          'cobra_sola' => false, 'pagado_hasta' => null, 'cuotas' => 0, 'pruebas' => (bool) $e['pruebas'], 'creado' => date('c'),
          'cancelar_hasta' => null, 'cancelacion_programada' => null];
$e['cobros'][] = $nuevo;
$guardar($e);
$responder(201, ['ok' => true, 'cobro' => $publico($nuevo)]);
