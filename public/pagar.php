<?php
declare(strict_types=1);

// Página de pago con tarjeta (Pago Plux) de un pedido: /pagar.php?p=<token>. No usa la sesión del panel.
//  GET ?p=…                 muestra el pedido con el valor exacto y el formulario de la tarjeta
//  POST (JSON)              cobra con la tarjeta ya cifrada en el navegador (y el código OTP si el banco lo pide)
//  GET ?pti=…&pcc=…&ptk=…   regreso del banco después de la validación 3D Secure (Plux agrega esos datos; nosotros no ponemos ninguno)
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\Plux;
use TuWhatsApp\Pedidos;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$seguro = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$cookie = fn (string $n, string $v, int $seg) => setcookie($n, $v, ['expires' => time() + $seg, 'path' => '/pagar.php', 'secure' => $seguro, 'httponly' => true, 'samesite' => 'Lax']);
$db = db();
$lineaDe = function (array $p) use ($db): ?array {
    $st = $db->prepare('SELECT * FROM lineas WHERE id = ?');
    $st->execute([(int) $p['linea_id']]);
    return $st->fetch() ?: null;
};
$json = function (int $http, array $d): never {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$ir = fn (string $tok, string $r = '', string $m = '') => header('Location: /pagar.php?' . http_build_query(array_filter(['p' => $tok, 'r' => $r, 'm' => $m])), true, 303);

// ---------------------------------------------------------------- cobrar (POST JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in)) {
        $json(400, ['status' => 'failed', 'message' => 'Pedido mal formado.']);
    }
    $p = Plux::pedidoPorToken($db, (string) ($in['p'] ?? ''));
    if ($p === null) {
        $json(404, ['status' => 'failed', 'message' => 'Pedido no encontrado.']);
    }
    if (in_array($p['estado'], ['pagado', 'enviado', 'entregado'], true)) {   // ya pagado (otra pestaña, o el aviso de Plux llegó antes)
        $json(200, ['status' => 'success', 'ir' => '/pagar.php?p=' . $p['pago_token'] . '&r=ok']);
    }
    $csrf = (string) ($_COOKIE['tuwa_pago_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string) ($in['csrf'] ?? ''))) {
        $json(400, ['status' => 'failed', 'message' => 'La página estuvo abierta mucho tiempo. Recárgala e intenta de nuevo.']);
    }
    $linea = $lineaDe($p);
    if ($p['estado'] !== 'carrito' && $p['estado'] !== 'pendiente') {
        $json(409, ['status' => 'failed', 'message' => 'Este pedido ya no se puede pagar. Escríbenos por WhatsApp.']);
    }
    if ((float) $p['total'] <= 0) {
        $json(409, ['status' => 'failed', 'message' => 'Este pedido no tiene valor a pagar.']);
    }
    if (!$linea || !Plux::configurado($linea)) {
        $json(503, ['status' => 'failed', 'message' => 'El pago con tarjeta no está disponible. Escríbenos por WhatsApp.']);
    }
    if (!Plux::intento($db, $p)) {
        $json(429, ['status' => 'failed', 'message' => 'Demasiados intentos. Espera una hora o paga por transferencia.']);
    }
    $clave = (string) ($in['simetricKeyCifrada'] ?? '');
    if (!preg_match('#^[A-Za-z0-9+/=]{40,1200}$#', $clave)) {
        $json(422, ['status' => 'failed', 'message' => 'No se pudo preparar el pago. Recarga la página.']);
    }
    $contacto = [];
    if ($p['contacto_id'] !== null) {
        $st = $db->prepare('SELECT * FROM contactos WHERE id = ?');
        $st->execute([(int) $p['contacto_id']]);
        $contacto = $st->fetch() ?: [];
    }
    try {
        $cuerpo = Plux::cuerpo($linea, $p, $contacto, $in, ip_cliente());
    } catch (RuntimeException $e) {
        $json(422, ['status' => 'failed', 'message' => $e->getMessage()]);
    }
    $cookie('tuwa_pago', (string) $p['pago_token'], 7200);   // para reconocer el pedido al volver del banco (3DS)
    $conOtp = isset($cuerpo['paramsOtp']);
    $r = Plux::llamar($linea, Plux::RUTA_PAGO, $cuerpo, $clave);
    unset($cuerpo, $in);   // la tarjeta (cifrada) no se guarda en ningún lado
    $res = Plux::procesar($db, $linea, $p, $r, $conOtp ? 'tarjeta con código OTP' : 'tarjeta');
    if ($res['status'] === 'success') {
        $res['ir'] = '/pagar.php?p=' . $p['pago_token'] . '&r=ok';
    }
    $json(200, $res);
}

// ---------------------------------------------------------------- regreso del banco (3D Secure)
if (!isset($_GET['p']) && (isset($_GET['pti']) || isset($_GET['ptk']))) {
    $g = fn (string $k, int $n) => mb_substr(trim(is_string($_GET[$k] ?? null) ? $_GET[$k] : ''), 0, $n);
    $pti = $g('pti', 500);
    $pcc = $g('pcc', 500);
    $ptk = $g('ptk', 2000);
    $p = Plux::pedidoPorToken($db, (string) ($_COOKIE['tuwa_pago'] ?? ''));
    $linea = $p ? $lineaDe($p) : null;
    if (!$p || !$linea || !Plux::configurado($linea)) {
        http_response_code(400);
        $titulo = 'Pago no completado';
        $aviso = ['error', 'No pudimos reconocer tu pedido al volver del banco. Si tu banco te cobró, envíanos una captura por WhatsApp y lo revisamos.'];
    } elseif ($pti === '' || $pcc === '' || $ptk === '') {
        $ir((string) $p['pago_token'], 'no', 'La respuesta del banco llegó incompleta. Intenta otra vez o usa otra tarjeta.');
        exit;
    } elseif (in_array($p['estado'], ['pagado', 'enviado', 'entregado'], true)) {
        $ir((string) $p['pago_token'], 'ok');
        exit;
    } else {
        $r = Plux::llamar($linea, Plux::RUTA_3DS, ['pti' => $pti, 'pcc' => $pcc, 'ptk' => $ptk]);
        $res = Plux::procesar($db, $linea, $p, $r, 'validación 3DS');
        $ir((string) $p['pago_token'], $res['status'] === 'success' ? 'ok' : 'no', $res['status'] === 'success' ? '' : (string) ($res['message'] ?? 'El banco no aprobó la transacción.'));
        exit;
    }
}

// ---------------------------------------------------------------- la página
$p = isset($_GET['p']) ? Plux::pedidoPorToken($db, (string) $_GET['p']) : null;
$linea = $p ? $lineaDe($p) : null;
$items = $p ? Pedidos::items($db, (int) $p['id']) : [];
$d = fn (float $v) => '$' . number_format($v, 2, ',', '.');
$titulo ??= $p ? 'Pagar pedido #' . $p['numero'] : 'Pedido no encontrado';
$aviso ??= null;
$csrf = bin2hex(random_bytes(16));
if ($p && $linea) {
    $cookie('tuwa_pago_csrf', $csrf, 7200);
    $cookie('tuwa_pago', (string) $p['pago_token'], 7200);
}
$pagado = $p && in_array($p['estado'], ['pagado', 'enviado', 'entregado'], true);
$abierto = $p && in_array($p['estado'], ['carrito', 'pendiente'], true) && (float) $p['total'] > 0;
if (!$p && $aviso === null) {
    http_response_code(404);
    $aviso = ['error', 'Este enlace de pago no existe. Pide uno nuevo por WhatsApp.'];
} elseif (($_GET['r'] ?? '') === 'no' && $abierto) {
    $aviso = ['error', '❌ ' . mb_substr(trim((string) ($_GET['m'] ?? 'El pago no fue aprobado.')), 0, 300) . ' Puedes intentar de nuevo o con otra tarjeta.'];
}
$anio = (int) date('Y');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#14211F">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?><?= $linea ? ' · ' . e($linea['nombre']) : '' ?></title>
<link rel="stylesheet" href="/assets/app.css?v=15">
</head>
<body class="sin-sesion publica pagina-pago">
<main class="contenido documento pago">
<?php if ($linea): ?><p class="pago-negocio"><?= e($linea['nombre']) ?></p><?php endif; ?>
<h1><?= e($titulo) ?></h1>
<?php if ($aviso): ?><div class="aviso aviso-<?= e($aviso[0]) ?>" role="alert"><?= e($aviso[1]) ?></div><?php endif; ?>
<?php if ($p && $linea): ?>
    <?php if ($pagado): ?>
    <div class="aviso aviso-ok" role="status">✅ Este pedido ya está pagado. ¡Gracias! Te escribiremos por WhatsApp.</div>
    <?php elseif (!$abierto): ?>
    <div class="aviso aviso-error" role="alert">Este pedido ya no se puede pagar. Escríbenos por WhatsApp.</div>
    <?php endif; ?>
    <section class="tarjeta">
        <table class="tabla-pedido">
            <?php foreach ($items as $i): ?>
            <tr><td><?= (int) $i['cantidad'] ?> × <?= e($i['nombre']) ?></td><td class="derecha"><?= e($d((float) $i['precio'] * (int) $i['cantidad'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ((float) $p['iva'] > 0): ?>
            <tr class="suave"><td>Subtotal</td><td class="derecha"><?= e($d((float) $p['subtotal'])) ?></td></tr>
            <tr class="suave"><td>IVA</td><td class="derecha"><?= e($d((float) $p['iva'])) ?></td></tr>
            <?php endif; ?>
            <tr class="total"><td>Total a pagar</td><td class="derecha"><?= e($d((float) $p['total'])) ?></td></tr>
        </table>
    </section>
    <?php if ($abierto && Plux::configurado($linea)): ?>
    <?php if (Plux::ambiente($linea) === 'sandbox'): ?><p class="aviso aviso-info pequeno">Modo de pruebas de Plux: no se cobra de verdad.</p><?php endif; ?>
    <section class="tarjeta formulario" id="plux-caja" data-p="<?= e($p['pago_token']) ?>" data-csrf="<?= e($csrf) ?>" data-llave="<?= e(Plux::llavePem((string) $linea['plux_llave'])) ?>">
        <div id="plux-paso-datos">
            <h2>Paga con tarjeta</h2>
            <label>Número de la tarjeta<input id="plux-numero" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000"></label>
            <div class="fila-triple">
                <label>Mes<select id="plux-mes" autocomplete="cc-exp-month"><option value="">Mes</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option><?php endfor; ?></select></label>
                <label>Año<select id="plux-anio" autocomplete="cc-exp-year"><option value="">Año</option><?php for ($a = $anio; $a <= $anio + 12; $a++): ?><option value="<?= $a ?>"><?= $a ?></option><?php endfor; ?></select></label>
                <label>CVV<input id="plux-cvv" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4"></label>
            </div>
            <div class="fila-doble">
                <label>Nombres del titular<input id="plux-nombres" maxlength="60" autocomplete="cc-given-name"></label>
                <label>Apellidos del titular<input id="plux-apellidos" maxlength="60" autocomplete="cc-family-name"></label>
            </div>
            <div class="fila-doble">
                <label>Cédula o RUC del titular<input id="plux-cedula" inputmode="numeric" maxlength="13"></label>
                <label>Correo<input id="plux-email" type="email" maxlength="120" autocomplete="email"></label>
            </div>
            <label>Celular<input id="plux-telefono" type="tel" maxlength="16" autocomplete="tel"></label>
            <button type="button" class="boton boton-grande" id="plux-pagar">🔒 Pagar <?= e($d((float) $p['total'])) ?></button>
        </div>
        <div id="plux-paso-otp" hidden>
            <h2>Código de tu banco</h2>
            <p>Tu banco te envió un código por SMS o correo. Escríbelo aquí:</p>
            <input id="plux-otp" inputmode="numeric" maxlength="10" autocomplete="one-time-code" aria-label="Código del banco">
            <button type="button" class="boton boton-grande" id="plux-otp-btn">Confirmar código</button>
        </div>
        <div id="plux-mensaje" aria-live="polite"></div>
        <p class="suave pequeno">🔒 Pago seguro con <b>Pago Plux</b>. Los datos de tu tarjeta se cifran en tu teléfono y van directo a Plux: no se guardan en ningún lado.</p>
    </section>
    <script src="/assets/vendor/crypto-js.min.js"></script>
    <script src="/assets/vendor/jsencrypt.min.js"></script>
    <script src="/assets/plux.js?v=1"></script>
    <?php elseif ($abierto && trim((string) $linea['pago_transferencia']) !== ''): ?>
    <section class="tarjeta"><h2>Paga por transferencia</h2><p><?= nl2br(e(trim((string) $linea['pago_transferencia']))) ?></p></section>
    <?php endif; ?>
<?php endif; ?>
<p class="suave pequeno">Página de pago de <a href="https://tukansoftware.com">WhatsApp Tukán</a>.</p>
</main>
</body>
</html>
