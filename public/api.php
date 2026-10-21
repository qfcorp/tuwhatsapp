<?php
declare(strict_types=1);

/**
 * API para que otros programas (TuSalón, el Panel Tukán Central...) usen este WhatsApp.
 * Cabecera: Authorization: Bearer <TUWA_API_TOKEN de config/.env>. Cuerpo JSON. Respuesta JSON.
 *   POST /api.php?accion=pendiente  {"telefono":"0991234567","nombre":"Ana","texto":"Hola...","linea":1}
 *        -> lo agrega a Pendientes (para enviarlo gratis desde el celular)
 *   POST /api.php?accion=contacto   {"telefono":"0991234567","nombre":"Ana","tipo":"cliente","linea":1}
 *        -> crea o actualiza un contacto (tipo: cliente, personal o desconocido)
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

$token = trim((string) getenv('TUWA_API_TOKEN'));
$cab = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (strlen($token) < 32 || !str_starts_with($cab, 'Bearer ') || !hash_equals($token, substr($cab, 7))) {
    responder(401, ['ok' => false, 'error' => 'Token no válido']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Usa POST']);
}
$d = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($d)) {
    responder(400, ['ok' => false, 'error' => 'El cuerpo debe ser JSON']);
}
$linea = isset($d['linea']) ? \TuWhatsApp\Lineas::buscar(db(), (int) $d['linea']) : (\TuWhatsApp\Lineas::todas(db())[0] ?? null);
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
