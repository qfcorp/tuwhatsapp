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
    // App de Android (Google Play): abre /?r=bandeja&app=play. Se recuerda con una cookie para saber que estamos dentro de la app.
    if (($_GET['app'] ?? '') === 'play') {
        setcookie('tuwa_app', 'play', ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')]);
        $_COOKIE['tuwa_app'] = 'play';
    }
    // App de iPhone (App Store, 13 nov 2026): abre con ?app=ios (u ?origen=ios) y manda «TukanIOS» en el navegador.
    // Se recuerda con la cookie canal=ios (1 año), sin depender de que el modo Google Play esté prendido.
    if ((string) ($_GET['app'] ?? $_GET['origen'] ?? '') === 'ios') {
        setcookie('canal', 'ios', ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')]);
        $_COOKIE['canal'] = 'ios';
    }
}

// ---------------------------------------------------------------------
// Funciones de ayuda
// ---------------------------------------------------------------------

/**
 * ¿Se está usando dentro de la app de Android (Google Play)? Ahí no se muestran precios del programa ni botones o enlaces para
 * pagarlo o pedirlo afuera (WhatsApp de ventas, transferencia, Payphone…), y el programa se llama «Tukán Mensajes» (Google no deja usar
 * la marca WhatsApp como nombre propio). Lo que el negocio cobra a SUS clientes (catálogo, pedidos, Plux) sí se ve. Ver docs/GOOGLE_PLAY.md.
 */
function en_app_android(): bool
{
    return ($_COOKIE['tuwa_app'] ?? '') === 'play';
}

/**
 * ¿Se está usando dentro de la app de iPhone (App Store)? Regla 3.1.1 de Apple (regla SIEMPRE de Dimitry, 12 nov 2026): ahí no se ve
 * ningún precio del plan, del agente con IA ni de nada que venda Tukán, ni botones, enlaces o formularios para pagar, contratar,
 * pedir, mejorar o cancelar el plan, ni textos que digan dónde pagar o a quién pedirlo («escribe a Tukán», WhatsApp de ventas).
 * Se reconoce por «TukanIOS» en el navegador (User-Agent) o por la cookie canal=ios (la deja ?app=ios u ?origen=ios).
 * Lo que el negocio cobra a SUS clientes (catálogo, pedidos, cotizaciones, Plux) y lo que cobra Meta por mensaje sí se ve.
 */
function en_app_ios(): bool
{
    return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'TukanIOS') || ($_COOKIE['canal'] ?? '') === 'ios';
}

/** ¿Dentro de una app de tienda (Google Play o iPhone)? Ahí se esconde todo lo que vende Tukán. Lo propio de Google Play usa en_app_android(). */
function en_app_tienda(): bool
{
    return en_app_android() || en_app_ios();
}

/** Mensaje neutro cuando desde la app de iPhone llega una acción de pago, cancelación o cambio de plan. */
const NO_EN_APP_IOS = 'Esta acción no está disponible en la app.';

/** Nombre del programa en pantalla: «Tukán Mensajes» dentro de las apps (Google Play y iPhone: las tiendas no dejan usar la marca WhatsApp), «WhatsApp Tukán» en la web. */
function nombre_programa(): string
{
    return en_app_tienda() ? 'Tukán Mensajes' : 'WhatsApp Tukán';
}

/**
 * Cobro del plan con Google Play dentro de la app: APAGADO (la app 1.0.0 es solo para entrar con la cuenta que da Tukán).
 * Se prendería con TUWA_GOOGLE_PLAY_COBRO=1 en config/.env y "cobroGooglePlay": true en qfcorp/tukan-apps (apps/tuwhatsapp/app.json),
 * después de programar la pantalla de planes y la verificación de compras (docs/GOOGLE_PLAY.md).
 */
function cobro_google_play(): bool
{
    return getenv('TUWA_GOOGLE_PLAY_COBRO') === '1';
}

/** ¿Es la cuenta de muestra del revisor de Google (deploy/demo_google.php)? Nunca envía nada a Meta ni conecta números. */
function cuenta_muestra(?array $empresa = null): bool
{
    $empresa ??= empresa_actual();
    return !empty($empresa['muestra']);
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= \TuWhatsApp\Db::conectar();
}

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texto escrito por clientes o usuarios (mensajes, nombres, notas): se escapa y NUNCA se traduce con el botón 🌐. */
function u(mixed $v): string
{
    return '<!--notr-->' . e($v) . '<!--/notr-->';
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
    $st = db()->prepare('SELECT id, nombre, correo, empresa_id, rol, super, idioma FROM usuarios WHERE id = ? AND activo');
    $st->execute([$id]);
    return $u = ($st->fetch() ?: null);
}

/** Personal de Tukán: ve el panel de Clientes y puede entrar a cualquier cuenta para dar soporte. */
/** ¿La cuenta que se está mirando tiene encendido este módulo? (ver src/Modulos.php) */
function modulo(string $clave): bool
{
    return \TuWhatsApp\Modulos::activo(empresa_actual(), $clave);
}

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

/** Botón "?" con una explicación corta que se abre al tocarlo (en vez de párrafos fijos arriba de cada pantalla). $html ya viene escapado. */
function ayuda(string $html): string
{
    return '<details class="ayuda"><summary title="Ayuda" aria-label="Ayuda">?</summary><div class="ayuda-texto">' . $html . '</div></details>';
}

/** "sábado 7 de noviembre" */
function fecha_larga(DateTimeImmutable $d): string
{
    $idioma = function_exists('tukan_idioma_actual') ? tukan_idioma_actual() : 'es';
    $n = (int) $d->format('N');
    $m = (int) $d->format('n');
    $j = (int) $d->format('j');
    $l = [
        'en' => [['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']],
        'fr' => [['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'], ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']],
        'pt' => [['segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'], ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']],
        'it' => [['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'], ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre']],
        'es' => [['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'], ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']],
    ];
    [$dias, $meses] = $l[$idioma] ?? $l['es'];
    return match ($idioma) {
        'en' => $dias[$n - 1] . ', ' . $meses[$m - 1] . ' ' . $j,
        'fr', 'it' => $dias[$n - 1] . ' ' . $j . ' ' . $meses[$m - 1],
        default => $dias[$n - 1] . ' ' . $j . ' de ' . $meses[$m - 1],
    };
}

/** Color fijo por contacto (0 a 7) para el círculo con su inicial. */
function color_contacto(int|string $id): int
{
    return abs(crc32((string) $id)) % 8;
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

// ---------------------------------------------------------------------
// Idiomas (pieza compartida Tukán Idiomas): español, inglés, francés, portugués e italiano.
// Botón 🌐 arriba; la página se arma en español y se traduce al final con idiomas/xx.php.
// ---------------------------------------------------------------------
require_once RAIZ . '/src/tukan_idiomas.php';
tukan_idioma_config([
    'dir'       => RAIZ . '/idiomas',
    'usuario'   => fn () => usuario()['idioma'] ?? null,
    'guardar'   => function (string $l): void {
        if (($u = usuario()) && ($u['idioma'] ?? '') !== $l) {
            db()->prepare('UPDATE usuarios SET idioma = ? WHERE id = ?')->execute([$l, $u['id']]);
        }
    },
    'defecto'   => fn () => getenv('TUWA_IDIOMA_PRUEBA') ?: null,      // solo en pruebas (forzar un idioma)
    'faltantes' => getenv('TUWA_IDIOMA_FALTANTES') ?: null,   // solo en pruebas (anota lo que falta traducir)
]);
if (PHP_SAPI !== 'cli' && !defined('TUWA_SIN_IDIOMA')) {
    tukan_idioma_iniciar();
}

/** Igual que ayuda(), pero en dos partes para textos que llevan PHP adentro. */
function ayuda_inicio(): string
{
    return '<details class="ayuda"><summary title="Ayuda" aria-label="Ayuda">?</summary><div class="ayuda-texto">';
}

function ayuda_fin(): string
{
    return '</div></details>';
}


// ---------------------------------------------------------------------
// Identidad gráfica común de Tukán (10 nov 2026): kit public/assets/tukan-ui.css + tukan-ui.js (idénticos en todos los programas)
// ---------------------------------------------------------------------

/** Color de WhatsApp Tukán para el kit común (la única línea que cambia por programa): su naranja tucán, con texto oscuro (contraste AA). */
const TK_COLOR_LINEA = '<style>:root{ --tk-color:#F28C28; --tk-color-claro:#F7A95B; --tk-color-texto:#14213D; }</style>';

/**
 * Todo lo que no está en «Principal», por grupos: [ruta, nombre, para qué sirve, solo administrador]. Lo usan la pantalla «Más» y
 * el menú de la izquierda. Todo el programa viene incluido; solo la IA se cobra aparte.
 */
function menu_mas_grupos(): array
{
    return [
        'Vender' => [
            ['pedidos', 'Pedidos', 'Carritos y pedidos de tus clientes: pendientes de pago, pagados, enviados.', false],
            ['catalogo', 'Catálogo', 'Tus productos o servicios con precio y stock, para mandarlos por el chat.', true],
            ['cobros', 'Cobros y cotizaciones', 'Tarjeta con Plux o Payphone, transferencia, IVA y cotización en PDF.', true],
            ['tufactura', '🧾 Factura electrónica', 'Factura del SRI de cada pedido pagado, con TuFactura.', false],
            ['campanias', 'Campañas', 'Mandar una plantilla a muchos clientes a la vez.', true],
            ['seguimientos', 'Seguimientos', 'Mensajes programados: escribir solo a quien no respondió.', false],
            ['enlaces', 'Enlaces y QR', 'Enlace corto, código QR y botón de WhatsApp para tu página web.', true],
            ['tienda', 'Tienda en línea', 'Shopify o WooCommerce: avisos de pedido y carritos abandonados.', true],
        ],
        'Automático' => [
            ['reglas', 'Respuestas automáticas', 'Menú con botones, horario y palabras clave.', true],
            ['flujos', 'Chatbots', 'Conversaciones por pasos con botones y preguntas, sin programar.', true],
            ['ia', 'Agente con IA', 'Contesta solo las 24 horas con lo que sabe tu negocio y tu catálogo.', true],
            ['asistente', 'Pregúntale a la IA', 'Pide en palabras: quién está listo para comprar, arma una campaña, cómo te fue. Resumen cada mañana.', false],
        ],
        'Equipo' => [
            ['equipo', 'Equipo y reparto', 'Quién atiende qué, reparto automático y vista del supervisor.', true],
            ['reportes', 'Reportes', 'Mensajes, tiempos de respuesta, ventas y de qué anuncio llegó cada cliente.', true],
            ['llamadas', 'Llamadas', 'Contesta y haz llamadas de WhatsApp desde el panel.', false],
            ['app', 'App en el celular', 'Instálalo como app y recibe avisos aunque el navegador esté cerrado.', false],
        ],
        'Ajustes' => [
            ['campos', 'Campos personalizados', 'Datos propios de cada cliente: ciudad, cédula, cumpleaños, talla…', true],
            ['plantillas', 'Plantillas', 'Mensajes aprobados por Meta para escribir pasadas 24 horas.', false],
            ['respuestas', 'Respuestas rápidas', 'Textos guardados para pegar en el chat con un toque.', true],
            ['integraciones', 'Integraciones', 'Excel y avisos a otros programas (Make, Zapier, n8n).', true],
            ['gasto', 'Gasto en Meta', 'Cuánto llevas gastado este mes y el tope.', false],
            ['lineas', 'Números conectados', 'Tus números de WhatsApp y cómo conectarlos.', true],
            ['cuenta', 'Mi cuenta', 'Contraseña, equipo y token de la API.', false],
        ],
    ];
}

/** Emoji de cada opción del menú (el menú de todos los programas Tukán lleva un emoji por opción). */
function icono_menu(string $ruta): string
{
    return [
        'inicio' => '📊', 'bandeja' => '💬', 'pendientes' => '📲', 'pedidos' => '🛒', 'contactos' => '👥', 'embudo' => '🎯', 'mas' => '☰',
        'catalogo' => '📦', 'cobros' => '💳', 'tufactura' => '🧾', 'campanias' => '📣', 'seguimientos' => '⏰', 'enlaces' => '🔗', 'tienda' => '🛍️',
        'reglas' => '🤖', 'flujos' => '🧩', 'ia' => '✨', 'asistente' => '💡', 'equipo' => '🧑‍🤝‍🧑', 'reportes' => '📈', 'llamadas' => '📞', 'app' => '📱',
        'campos' => '🗂️', 'plantillas' => '📄', 'respuestas' => '⚡', 'integraciones' => '🔌', 'gasto' => '💲', 'lineas' => '☎️', 'cuenta' => '⭐',
        'clientes' => '🏢',
    ][$ruta] ?? '•';
}

/** Saludo según la hora de Ecuador: [emoji, texto]. */
function saludo_hora(?DateTimeImmutable $ahora = null): array
{
    $h = (int) ($ahora ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('America/Guayaquil'))->format('G');
    return $h < 12 ? ['☀️', 'Buenos días'] : ($h < 19 ? ['🌤️', 'Buenas tardes'] : ['🌙', 'Buenas noches']);
}
