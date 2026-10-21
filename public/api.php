<?php
declare(strict_types=1);

/**
 * API para que otros programas (TuSalón, el Panel Tukán Central...) usen este WhatsApp.
 * Cabecera: Authorization: Bearer <token de la empresa (Mi cuenta) o TUWA_API_TOKEN de config/.env para Tukán>. Cuerpo JSON. Respuesta JSON.
 *   POST /api.php?accion=pendiente  {"telefono":"0991234567","nombre":"Ana","texto":"Hola...","linea":1}
 *        -> lo agrega a Pendientes (para enviarlo gratis desde el celular)
 *   POST /api.php?accion=contacto   {"telefono":"0991234567","nombre":"Ana","tipo":"cliente","linea":1}
 *        -> crea o actualiza un contacto (tipo: cliente, personal o desconocido; opcional "promociones": true|false y "etiquetas": "vip, quito")
 * "linea" es opcional (por defecto el primer número).
 */
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Token: el de cada empresa (Mi cuenta → Crear token) o, para Tukán, TUWA_API_TOKEN de config/.env
$cab = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$enviado = str_starts_with($cab, 'Bearer ') ? substr($cab, 7) : '';
$global = trim((string) getenv('TUWA_API_TOKEN'));
$empresa = null;
if ($enviado !== '' && strlen($global) >= 32 && hash_equals($global, $enviado)) {
    $empresa = \TuWhatsApp\Empresas::buscar(db(), \TuWhatsApp\Empresas::TUKAN);
} elseif ($enviado !== '') {
    $empresa = \TuWhatsApp\Empresas::porTokenApi(db(), $enviado);
}
if (!$empresa) {
    responder(401, ['ok' => false, 'error' => 'Token no válido']);
}
if (!\TuWhatsApp\Empresas::alDia($empresa)) {
    responder(403, ['ok' => false, 'error' => 'La cuenta no está al día']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Usa POST']);
}
$d = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($d)) {
    responder(400, ['ok' => false, 'error' => 'El cuerpo debe ser JSON']);
}
$linea = isset($d['linea']) ? \TuWhatsApp\Lineas::buscar(db(), (int) $d['linea'], (int) $empresa['id'])
                            : (\TuWhatsApp\Lineas::todas(db(), (int) $empresa['id'])[0] ?? null);
if (!$linea) {
    responder(404, ['ok' => false, 'error' => 'No existe ese número']);
}
try {
    switch ($_GET['accion'] ?? '') {
        case 'pendiente':
            $id = \TuWhatsApp\Pendientes::agregar(db(), (int) $linea['id'], (string) ($d['telefono'] ?? ''), (string) ($d['nombre'] ?? ''),
                (string) ($d['texto'] ?? ''), mb_substr((string) ($d['origen'] ?? 'api'), 0, 40));
            responder(200, ['ok' => true, 'id' => $id]);
        case 'contacto':
            $c = \TuWhatsApp\Contactos::guardar(db(), (int) $linea['id'], (string) ($d['telefono'] ?? ''), (string) ($d['nombre'] ?? ''),
                (string) ($d['tipo'] ?? 'cliente'));
            if (isset($d['promociones'])) {
                \TuWhatsApp\Campanias::marcarMarketing(db(), (int) $linea['id'], (int) $c['id'], $d['promociones'] ? 'acepta' : 'baja');
            }
            if (isset($d['etiquetas'])) {
                \TuWhatsApp\Contactos::guardarEtiquetas(db(), (int) $linea['id'], (int) $c['id'], (string) $d['etiquetas']);
            }
            responder(200, ['ok' => true, 'id' => (int) $c['id'], 'telefono' => $c['telefono'], 'tipo' => $c['tipo']]);
        default:
            responder(400, ['ok' => false, 'error' => 'Acción no válida (usa pendiente o contacto)']);
    }
} catch (\PDOException $e) {
    error_log('tuwhatsapp api: ' . $e);
    responder(500, ['ok' => false, 'error' => 'Error del servidor']);
} catch (\RuntimeException $e) {
    responder(422, ['ok' => false, 'error' => $e->getMessage()]);
}
