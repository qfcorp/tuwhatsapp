<?php
declare(strict_types=1);

/**
 * Arranque de WhatsApp Tukán: configuración, sesión, conexión y funciones de ayuda.
 * La configuración vive en config/.env (fuera de la carpeta pública y fuera de GitHub).
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');

$envFile = getenv('TUWA_ENV') ?: RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuWhatsApp\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 11) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }
    }
});

if (!defined('TUWA_SIN_SESION') && PHP_SAPI !== 'cli') {
    session_name('tuwhatsapp');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

// ---------------------------------------------------------------------
// Funciones de ayuda
// ---------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= \TuWhatsApp\Db::conectar();
}

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $ruta, array $params = []): string
{
    return '/?' . http_build_query(array_merge(['r' => $ruta], $params));
}

function redirigir(string $ruta, array $params = []): never
{
    header('Location: ' . url($ruta, $params));
    exit;
}

/** Dinero con 2 decimales; con 4 si es menos de un dólar y no es redondo (precios por mensaje). */
function dinero(float|string|null $v, bool $fino = false): string
{
    $v = (float) $v;
    $dec = $fino ? 4 : 2;
    return '$' . number_format($v, $dec, ',', '.');
}

function aviso(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['avisos'][] = [$tipo, $texto];
}

function tomar_avisos(): array
{
    $a = $_SESSION['avisos'] ?? [];
    unset($_SESSION['avisos']);
    return $a;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">';
}

function csrf_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals(csrf(), $_POST['csrf']);
}

function usuario(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) {
        return $u = null;
    }
    $st = db()->prepare('SELECT id, nombre, correo, empresa_id, rol, super FROM usuarios WHERE id = ? AND activo');
    $st->execute([$id]);
    return $u = ($st->fetch() ?: null);
}

/** Personal de Tukán: ve el panel de Clientes y puede entrar a cualquier cuenta para dar soporte. */
function es_super(): bool
{
    return (bool) (usuario()['super'] ?? false);
}

/** Administrador de su cuenta (o personal de Tukán): cambia reglas, gasto, números y equipo. Los agentes solo atienden. */
function es_admin(): bool
{
    return es_super() || (usuario()['rol'] ?? '') === 'admin';
}

/** La empresa que se está mirando: la del usuario o, si es de Tukán y entró a dar soporte, la del cliente. */
function empresa_actual(): ?array
{
    static $cache = [];
    $u = usuario();
    if (!$u) {
        return null;
    }
    $id = (int) $u['empresa_id'];
    if (es_super() && !empty($_SESSION['empresa_vista'])) {
        $id = (int) $_SESSION['empresa_vista'];
    }
    return $cache[$id] ??= \TuWhatsApp\Empresas::buscar(db(), $id);
}

function viendo_otra_empresa(): bool
{
    $e = empresa_actual();
    return $e !== null && (int) $e['id'] !== (int) (usuario()['empresa_id'] ?? 0);
}

/** La línea (número de WhatsApp) que se está mirando en el panel. */
function linea_actual(): ?array
{
    $e = empresa_actual();
    if (!$e) {
        return null;
    }
    $lineas = \TuWhatsApp\Lineas::todas(db(), (int) $e['id']);
    if (!$lineas) {
        return null;
    }
    $id = (int) ($_SESSION['linea_id'] ?? 0);
    foreach ($lineas as $l) {
        if ((int) $l['id'] === $id) {
            return $l;
        }
    }
    $_SESSION['linea_id'] = (int) $lineas[0]['id'];
    return $lineas[0];
}

function hora_corta(?string $ts): string
{
    if (!$ts) {
        return '';
    }
    $d = new DateTimeImmutable($ts);
    $hoy = new DateTimeImmutable('today');
    if ($d >= $hoy) {
        return $d->format('H:i');
    }
    if ($d >= $hoy->modify('-1 day')) {
        return 'ayer ' . $d->format('H:i');
    }
    return $d->format('j/n H:i');
}

function tipo_contacto(string $tipo): string
{
    return ['personal' => 'Personal', 'cliente' => 'Cliente', 'desconocido' => 'Desconocido'][$tipo] ?? $tipo;
}

function ip_cliente(): string
{
    return (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}
