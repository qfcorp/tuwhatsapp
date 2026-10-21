<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica con Meta y Telegram simulados (tests/meta_simulado.php).
 * Uso: bash tests/correr.sh   (crea la base tuwhatsapp_prueba, levanta el simulador y corre esto)
 */

$sim = getenv('SIM_URL') ?: 'http://127.0.0.1:8097';
foreach ([
    'TUWA_LLAVE' => str_repeat('ab', 32),
    'META_GRAPH_URL' => $sim,
    'META_APP_SECRET' => 'secreto_de_prueba',
    'META_VERIFY_TOKEN' => 'verifica123',
    'TELEGRAM_BOT_TOKEN' => '123:abc',
    'TELEGRAM_CHAT_ID' => '999',
    'TELEGRAM_API_URL' => $sim,
] as $k => $v) {
    putenv("$k=$v");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\{Avisos, Cifrado, Contactos, Gasto, Lineas, Mensajeria, Pendientes, Registro, Reglas, Telefono, Webhook};

$bien = 0;
$mal = 0;
function ok(bool $c, string $que): void
{
    global $bien, $mal;
    if ($c) {
        $bien++;
    } else {
        $mal++;
        echo "  FALLA: $que\n";
    }
}
function igual(mixed $a, mixed $b, string $que): void
{
    ok($a === $b, $que . ' (esperaba ' . var_export($b, true) . ', salió ' . var_export($a, true) . ')');
}
$logSim = getenv('SIM_LOG');
function sim(): array
{
    global $logSim;
    $r = [];
    foreach (file($logSim) ?: [] as $l) {
        $r[] = json_decode($l, true);
    }
    return $r;
}
function limpiarSim(): void
{
    global $logSim;
    file_put_contents($logSim, '');
}
function cuerpoMeta(array $value, string $campo = 'messages'): string
{
    return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '200', 'changes' => [['field' => $campo, 'value' => $value]]]]]);
}
function entrante(string $de, string $texto, string $wamid, int $ts, string $nombre = 'Cliente', array $extra = []): string
{
    return cuerpoMeta([
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '593996408397', 'phone_number_id' => '100'],
        'contacts' => [['profile' => ['name' => $nombre], 'wa_id' => $de]],
        'messages' => [array_merge(['from' => $de, 'id' => $wamid, 'timestamp' => (string) $ts, 'type' => 'text', 'text' => ['body' => $texto]], $extra)],
    ]);
}

$db = db();

echo "== Teléfonos\n";
igual(Telefono::normalizar('0991234567'), '593991234567', '09…');
igual(Telefono::normalizar('+593 99 123 4567'), '593991234567', '+593 con espacios');
igual(Telefono::normalizar('991234567'), '593991234567', 'sin 0');
igual(Telefono::normalizar('12'), null, 'muy corto');
igual(Telefono::normalizar('+1 305 555 1234'), '13055551234', 'otro país');
igual(Telefono::bonito('593991234567'), '099 123 4567', 'bonito');
igual(Telefono::enlace('0991234567', 'Hola Ana, ¿cómo estás?'), 'https://wa.me/593991234567?text=Hola%20Ana%2C%20%C2%BFc%C3%B3mo%20est%C3%A1s%3F', 'enlace wa.me');

echo "== Línea\n";
$lid = Lineas::guardarConexion($db, null, ['nombre' => 'Tukán ventas', 'telefono' => '0996408397', 'phone_number_id' => '100', 'waba_id' => '200', 'token' => 'EAAGtokenDePrueba1234567890']);
$linea = Lineas::buscar($db, $lid);
igual(Lineas::token($linea), 'EAAGtokenDePrueba1234567890', 'token cifrado y descifrado');
ok(!str_contains($linea['token_cifrado'], 'EAAG'), 'el token no se guarda a la vista');
igual($linea['telefono'], '593996408397', 'teléfono normalizado');
try {
    Lineas::guardarConexion($db, null, ['nombre' => 'Otra', 'phone_number_id' => '100', 'token' => 'EAAGtokenDePrueba1234567890']);
    ok(false, 'no deja repetir el phone number id');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'ya está'), 'no deja repetir el phone number id');
}
Lineas::guardarConexion($db, $lid, ['nombre' => 'Tukán ventas', 'telefono' => '0996408397', 'phone_number_id' => '100', 'waba_id' => '200', 'token' => '']);
igual(Lineas::token(Lineas::buscar($db, $lid)), 'EAAGtokenDePrueba1234567890', 'token vacío no lo borra');
igual(count(Lineas::opciones($linea)), 3, '3 opciones de fábrica');
igual(Lineas::horario($linea)[7], null, 'domingo no atiende');
igual(Lineas::horario($linea)[1], ['08:00', '18:00'], 'lunes 8 a 18');

echo "== Reglas (decisión)\n";
$martes20 = new DateTimeImmutable('2026-10-20 20:00:00');   // martes, fuera de horario
$martes10 = new DateTimeImmutable('2026-10-20 10:00:00');   // martes, en horario
$L = array_merge($linea, ['auto_activo' => true]);
$cli = ['tipo' => 'cliente'];
$d = Reglas::decidir(array_merge($linea, ['auto_activo' => false]), $cli, 'precio', $martes20, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'apagadas'), 'apagado no contesta');
$d = Reglas::decidir($L, ['tipo' => 'personal'], 'precio', $martes20, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'personal'), 'personal nunca');
$d = Reglas::decidir($L, ['tipo' => 'desconocido'], 'precio', $martes20, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'No es cliente'), 'solo clientes');
$d = Reglas::decidir(array_merge($L, ['regla_solo_clientes' => false]), ['tipo' => 'desconocido'], 'precio', $martes20, 0);
ok($d['responder'], 'sin la regla de clientes, un desconocido sí');
$d = Reglas::decidir($L, $cli, '¿Cuál es el PRECIO?', $martes10, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'horario'), 'en horario contestas tú');
$d = Reglas::decidir($L, $cli, 'hola como estas', $martes20, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'negocio'), 'sin palabras de negocio no');
$d = Reglas::decidir($L, $cli, '¿Cuánto cuesta? Quiero una cotización', $martes20, 0);
ok($d['responder'] && str_contains((string) $d['texto'], '1. Precios y planes'), 'cotización (con tilde) -> menú');
$d = Reglas::decidir($L, $cli, '2', $martes20, 1);
ok($d['responder'] && str_contains((string) $d['texto'], 'demo') && !$d['humano'], 'elige opción 2');
$d = Reglas::decidir($L, $cli, ' 3. ', $martes20, 1);
ok($d['responder'] && $d['humano'], 'opción 3 pasa a persona');
$d = Reglas::decidir($L, $cli, 'precio otra vez', $martes20, 1);
ok(!$d['responder'] && str_contains($d['motivo'], 'menú'), 'después del menú no se repite');
$d = Reglas::decidir($L, $cli, '1', $martes20, 3);
ok(!$d['responder'] && str_contains($d['motivo'], '3 veces'), 'máximo de respuestas');
$d = Reglas::decidir($L, $cli, '9', $martes20, 1);
ok(!$d['responder'], 'opción que no existe');
$d = Reglas::decidir($L, $cli, 'precio', new DateTimeImmutable('2026-10-25 10:00'), 0);   // domingo
ok($d['responder'], 'domingo (sin horario) contesta');
$d = Reglas::decidir($L, array_merge($cli, ['silencio_hasta' => '2026-10-20 22:00:00']), 'precio', $martes20, 0);
ok(!$d['responder'] && str_contains($d['motivo'], 'persona'), 'en silencio no contesta');
ok(Reglas::tienePalabra('Necesito INFORMACIÓN', ['informacion']), 'tildes y mayúsculas');
ok(!Reglas::tienePalabra('planeta', ['plan']), 'palabra completa (plan ≠ planeta)');
igual(Reglas::listaPalabras("Precio, precio,\nDemo ;  "), ['precio', 'demo'], 'lista de palabras sin repetir');

echo "== Guardar reglas\n";
$form = [
    'auto_activo' => '1', 'regla_solo_clientes' => '1', 'regla_palabras' => '1', 'regla_horario' => '1', 'regla_una_vez' => '1',
    'palabras' => 'precio, demo, cotización, info', 'horas_silencio' => '12', 'max_auto' => '3', 'mensaje_menu' => 'Hola, soy Tukán:',
    'trabaja' => [1 => '1', 2 => '1', 3 => '1', 4 => '1', 5 => '1'], 'abre' => array_fill(1, 7, '08:00'), 'cierra' => array_fill(1, 7, '18:00'),
    'op_titulo' => ['Precios', 'Demo', 'Persona', ''], 'op_respuesta' => ['Te cotizamos.', 'Te armamos la demo.', 'Ya te atiende alguien.', ''], 'op_humano' => [2 => '1'],
];
Lineas::guardarReglas($db, $lid, $form);
$linea = Lineas::buscar($db, $lid);
ok($linea['auto_activo'] === true, 'automático encendido');
igual(Lineas::horario($linea)[6], null, 'sábado sin marcar');
igual(count(Lineas::opciones($linea)), 3, 'opciones guardadas');
ok(Lineas::opciones($linea)[2]['humano'], 'opción 3 es persona');
try {
    Lineas::guardarReglas($db, $lid, array_merge($form, ['cierra' => array_fill(1, 7, '07:00')]));
    ok(false, 'horario al revés');
} catch (RuntimeException $e) {
    ok(true, 'horario al revés');
}
try {
    Lineas::guardarReglas($db, $lid, array_merge($form, ['op_respuesta' => ['', 'x', 'y']]));
    ok(false, 'opción sin respuesta');
} catch (RuntimeException $e) {
    ok(true, 'opción sin respuesta');
}

echo "== Webhook: verificación y firma\n";
igual(Webhook::verificar(['hub_mode' => 'subscribe', 'hub_verify_token' => 'verifica123', 'hub_challenge' => '12345']), '12345', 'verifica');
igual(Webhook::verificar(['hub_mode' => 'subscribe', 'hub_verify_token' => 'otro', 'hub_challenge' => '12345']), null, 'token malo');
$c = '{"a":1}';
ok(Webhook::firmaValida($c, 'sha256=' . hash_hmac('sha256', $c, 'secreto_de_prueba')), 'firma buena');
ok(!Webhook::firmaValida($c, 'sha256=' . hash_hmac('sha256', $c, 'otro')), 'firma mala');
ok(!Webhook::firmaValida($c, ''), 'sin firma');

echo "== Webhook: cliente escribe fuera de horario -> menú pagado\n";
Contactos::guardar($db, $lid, '0991111111', 'Ana Salón', 'cliente');
limpiarSim();
$wh = new Webhook($db, $martes20);
$wh->procesar(entrante('593991111111', 'Hola, quiero el precio', 'wamid.E1', $martes20->getTimestamp() - 5, 'Ana'));
$ana = Contactos::porTelefono($db, $lid, '593991111111');
$msgs = $db->query("SELECT * FROM mensajes WHERE contacto_id = {$ana['id']} ORDER BY id")->fetchAll();
igual(count($msgs), 2, 'entra 1 y sale 1');
igual($msgs[1]['origen'], 'auto', 'respuesta automática');
igual($msgs[1]['estado'], 'enviado', 'enviada');
igual((float) $msgs[1]['costo'], 0.0113, 'cuesta lo de servicio');
ok(str_contains($msgs[0]['motivo'], 'Contestado solo'), 'motivo en el mensaje que entró');
ok($msgs[0]['leido'] === false, 'queda sin leer');
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/100/messages'));
igual(count($env), 1, 'Meta recibió 1 envío');
igual($env[0]['cuerpo']['to'] ?? null, '593991111111', 'a Ana');
ok(str_contains($env[0]['cuerpo']['text']['body'] ?? '', '1. Precios'), 'con el menú');
igual($env[0]['auth'], 'Bearer EAAGtokenDePrueba1234567890', 'con el token');
igual($ana['nombre_whatsapp'], 'Ana', 'nombre de WhatsApp guardado');

echo "== Repetido: Meta manda el mismo mensaje dos veces\n";
limpiarSim();
$wh->procesar(entrante('593991111111', 'Hola, quiero el precio', 'wamid.E1', $martes20->getTimestamp() - 5));
igual((int) $db->query("SELECT count(*) FROM mensajes WHERE contacto_id = {$ana['id']}")->fetchColumn(), 2, 'no se duplica');
igual(count(sim()), 0, 'no se contesta dos veces');

echo "== Elige la opción 3 -> respuesta y silencio\n";
$wh->procesar(entrante('593991111111', '3', 'wamid.E2', $martes20->getTimestamp()));
$ana = Contactos::porTelefono($db, $lid, '593991111111');
ok(!empty($ana['silencio_hasta']), 'queda en silencio');
$wh->procesar(entrante('593991111111', 'precio de nuevo', 'wamid.E3', $martes20->getTimestamp()));
$ult = $db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.E3'")->fetchColumn();
ok(str_contains((string) $ult, 'persona'), 'ya no contesta solo: ' . $ult);

echo "== Personal y desconocido\n";
Contactos::guardar($db, $lid, '0992222222', 'Mamá', 'personal');
limpiarSim();
$wh->procesar(entrante('593992222222', 'hijo cuál es el precio del pan', 'wamid.P1', $martes20->getTimestamp()));
igual(count(sim()), 0, 'a Mamá no se le contesta');
igual($db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.P1'")->fetchColumn(), 'Contacto personal: no se contesta solo', 'motivo personal');
$wh->procesar(entrante('593993333333', 'precio por favor', 'wamid.D1', $martes20->getTimestamp(), 'Nuevo'));
igual(count(sim()), 0, 'a un desconocido no (solo clientes)');
$nuevo = Contactos::porTelefono($db, $lid, '593993333333');
igual($nuevo['tipo'], 'desconocido', 'se crea como desconocido');

echo "== Foto: no se contesta\n";
$wh->procesar(cuerpoMeta(['metadata' => ['phone_number_id' => '100'], 'messages' => [['from' => '593991111112', 'id' => 'wamid.F1', 'timestamp' => (string) $martes20->getTimestamp(), 'type' => 'image', 'image' => ['caption' => 'mi logo']]]]));
igual($db->query("SELECT texto FROM mensajes WHERE wamid = 'wamid.F1'")->fetchColumn(), '[Imagen] mi logo', 'texto de la imagen');

echo "== Anuncio: 72 horas gratis\n";
Contactos::guardar($db, $lid, '0994444444', 'Luis', 'cliente');
limpiarSim();
$wh->procesar(entrante('593994444444', 'Quiero info del anuncio', 'wamid.A1', $martes20->getTimestamp(), 'Luis', ['referral' => ['source_type' => 'ad', 'source_id' => '1']]));
$costo = $db->query("SELECT m.costo FROM mensajes m JOIN contactos c ON c.id = m.contacto_id WHERE c.telefono = '593994444444' AND m.direccion = 'sale'")->fetchColumn();
igual((float) $costo, 0.0, 'respuesta gratis por venir de anuncio');

echo "== Estados y lo que Meta cobró\n";
$w = $db->query("SELECT wamid FROM mensajes WHERE origen = 'auto' AND contacto_id = {$ana['id']} ORDER BY id LIMIT 1")->fetchColumn();
$estado = fn (string $s, ?array $pricing = null, array $extra = []) => cuerpoMeta(['metadata' => ['phone_number_id' => '100'],
    'statuses' => [array_merge(['id' => $w, 'status' => $s, 'timestamp' => (string) time(), 'recipient_id' => '593991111111'], $pricing ? ['pricing' => $pricing] : [], $extra)]]);
$wh->procesar($estado('delivered', ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'service']));
igual($db->query("SELECT estado FROM mensajes WHERE wamid = '$w'")->fetchColumn(), 'entregado', 'entregado');
$wh->procesar($estado('read'));
$wh->procesar($estado('sent'));
igual($db->query("SELECT estado FROM mensajes WHERE wamid = '$w'")->fetchColumn(), 'leido', 'no retrocede de leído a enviado');
$wh->procesar($estado('delivered', ['billable' => false, 'pricing_model' => 'PMP', 'category' => 'service', 'type' => 'free_customer_service']));
igual((float) $db->query("SELECT costo FROM mensajes WHERE wamid = '$w'")->fetchColumn(), 0.0, 'Meta dice que no se cobra -> 0');

echo "== Envío desde el panel y ventana de 24 horas\n";
$m = new Mensajeria($db, $martes20);
$ana = Contactos::porTelefono($db, $lid, '593991111111');
$r = $m->texto($linea, $ana, 'Hola Ana, te escribo yo', 'panel', null);
ok($r['ok'], 'se envía');
$viejo = Contactos::guardar($db, $lid, '0995555555', 'Viejo', 'cliente');
$r = $m->texto($linea, $viejo, 'Hola', 'panel');
ok(!$r['ok'] && !empty($r['ventana']), 'sin mensaje en 24 h pide plantilla');
$r = $m->texto($linea, $ana, '   ', 'panel');
ok(!$r['ok'], 'mensaje vacío');

echo "== Plantillas\n";
$p = \TuWhatsApp\Meta::deLinea($linea)->plantillas();
ok($p['ok'], 'lista de plantillas');
igual(count($p['plantillas']), 2, 'solo las aprobadas');
igual($p['plantillas'][0]['espacios'], 2, '{{1}} y {{2}}');
igual($p['plantillas'][0]['categoria'], 'utilidad', 'utilidad');
limpiarSim();
$r = $m->plantilla($linea, $viejo, $p['plantillas'][0], ['Pedro', '30/10'], 'panel');
ok($r['ok'], 'plantilla enviada');
igual($r['costo'], 0.0113, 'utilidad fuera de la ventana cuesta');
$env = sim()[0]['cuerpo'];
igual($env['template']['components'][0]['parameters'][1]['text'] ?? null, '30/10', 'datos en la plantilla');
igual($db->query("SELECT texto FROM mensajes WHERE id = {$r['id']}")->fetchColumn(), 'Hola Pedro, tu plan vence el 30/10.', 'se guarda el texto armado');
$r = $m->plantilla($linea, $ana, $p['plantillas'][0], ['Ana', '1/11'], 'panel');
igual($r['costo'], 0.0, 'utilidad con la ventana abierta es gratis');
$r = $m->plantilla($linea, $viejo, $p['plantillas'][1], [], 'panel');
igual($r['costo'], 0.074, 'marketing');
$r = $m->plantilla($linea, $viejo, $p['plantillas'][0], ['Pedro', ''], 'panel');
ok(!$r['ok'], 'falta un dato');

echo "== Crear plantillas\n";
use TuWhatsApp\Plantillas;
igual(Plantillas::nombre('Recordatorio de Pagó  #1'), 'recordatorio_de_pago_1', 'nombre de plantilla');
$base = ['nombre' => 'Recordatorio pago nuevo', 'categoria' => 'UTILITY', 'idioma' => 'es', 'cuerpo' => "Hola {{ 1 }}, tu plan vence el {{2}}.\nGracias.",
         'ejemplo' => [1 => 'Ana', 2 => '30 de octubre'], 'encabezado' => 'Aviso', 'pie' => 'Tukán Software',
         'boton_tipo' => ['URL', 'QUICK_REPLY', 'NINGUNO'], 'boton_texto' => ['Pagar', 'Ya pagué', ''], 'boton_destino' => ['https://tukanec.com/pagar', '', '']];
$d = Plantillas::armar($base);
igual($d['name'], 'recordatorio_pago_nuevo', 'nombre normalizado');
igual($d['components'][1]['text'], "Hola {{1}}, tu plan vence el {{2}}.\nGracias.", 'variables arregladas ({{ 1 }} -> {{1}})');
igual($d['components'][1]['example']['body_text'], [['Ana', '30 de octubre']], 'ejemplos');
igual(array_column($d['components'], 'type'), ['HEADER', 'BODY', 'FOOTER', 'BUTTONS'], 'partes en orden');
igual(array_column($d['components'][3]['buttons'], 'type'), ['QUICK_REPLY', 'URL'], 'botones de respuesta primero');
$debeFallar = function (array $cambio, string $palabra, string $que) use ($base) {
    try {
        Plantillas::armar(array_merge($base, $cambio));
        ok(false, $que);
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), $palabra), $que . ': ' . $e->getMessage());
    }
};
$debeFallar(['cuerpo' => '{{1}}, hola'], 'principio', 'variable al inicio');
$debeFallar(['cuerpo' => 'Hola {{1}}.'], 'final', 'variable al final');
$debeFallar(['cuerpo' => 'Hola {{1}} y {{3}} gracias'], 'orden', 'variables saltadas');
$debeFallar(['ejemplo' => [1 => 'Ana']], 'ejemplo', 'falta un ejemplo');
$debeFallar(['categoria' => 'OTRA'], 'Marketing', 'categoría mala');
$debeFallar(['encabezado' => 'Hola {{1}}'], 'encabezado', 'variable en el encabezado');
$debeFallar(['boton_destino' => ['tukanec.com', '', '']], 'https', 'enlace sin https');
$debeFallar(['boton_tipo' => ['QUICK_REPLY', 'QUICK_REPLY', 'QUICK_REPLY', 'QUICK_REPLY'], 'boton_texto' => ['a', 'b', 'c', 'd']], 'Máximo', 'demasiados botones');
$debeFallar(['cuerpo' => str_repeat('a', 1025)], '1024', 'texto muy largo');
$debeFallar(['cuerpo' => "Hola\n\n\nchao"], 'blanco', 'muchas líneas en blanco');
$debeFallar(['nombre' => '¡¡!!'], 'nombre', 'nombre vacío');
$meta = \TuWhatsApp\Meta::deLinea($linea);
$t = $meta->todasLasPlantillas();
ok($t['ok'] && count($t['plantillas']) === 4, 'todas las plantillas (con pendientes y rechazadas)');
$rech = array_values(array_filter($t['plantillas'], fn ($x) => $x['estado'] === 'REJECTED'))[0] ?? [];
igual($rech['motivo'] ?? null, 'PROMOTIONAL', 'motivo del rechazo');
$promo = array_values(array_filter($t['plantillas'], fn ($x) => $x['nombre'] === 'promo_octubre'))[0];
igual([$promo['encabezado'], $promo['pie'], $promo['botones'][0]['texto'] ?? null], ['Oferta', 'Tukán', 'Me interesa'], 'encabezado, pie y botón leídos');
$c = $meta->crearPlantilla($d);
ok($c['ok'] && $c['estado'] === 'PENDING', 'plantilla creada en revisión');
$c = $meta->crearPlantilla($d);
ok(!$c['ok'] && str_contains($c['error'], 'Ya existe'), 'nombre repetido explicado: ' . ($c['error'] ?? ''));
igual(count($meta->todasLasPlantillas()['plantillas']), 5, 'aparece en la lista');
igual(count($meta->plantillas()['plantillas']), 2, 'para enviar solo salen aprobadas');
ok($meta->borrarPlantilla('recordatorio_pago_nuevo')['ok'], 'borrar');
igual(count($meta->todasLasPlantillas()['plantillas']), 4, 'ya no aparece');
ok(!$meta->borrarPlantilla('no_existe')['ok'], 'borrar una que no existe');

echo "== Errores de Meta\n";
$sin = Contactos::guardar($db, $lid, '0990000000', 'Sin WhatsApp', 'cliente');
$db->prepare('UPDATE contactos SET ultimo_entrante = ? WHERE id = ?')->execute([$martes20->format('c'), $sin['id']]);
$sin = Contactos::buscar($db, $lid, (int) $sin['id']);
$antes = Gasto::delMes($db, $lid, $martes20);
$r = $m->texto($linea, $sin, 'Hola', 'panel');
ok(!$r['ok'] && str_contains($r['error'], 'no tiene WhatsApp'), 'error explicado: ' . ($r['error'] ?? ''));
igual(Gasto::delMes($db, $lid, $martes20), $antes, 'un envío fallido no suma gasto');
$malo = Lineas::guardarConexion($db, null, ['nombre' => 'Malo', 'phone_number_id' => '101', 'token' => 'token_malo_0000000000000000000']);
$pr = \TuWhatsApp\Meta::deLinea(Lineas::buscar($db, $malo))->probar();
ok(!$pr['ok'] && str_contains($pr['error'], 'token'), 'token vencido explicado');
$pr = \TuWhatsApp\Meta::deLinea($linea)->probar();
ok($pr['ok'] && $pr['nombre'] === 'Tukán Software', 'probar conexión');

echo "== Tope del mes\n";
$gastado = Gasto::delMes($db, $lid, $martes20);
$tope = round($gastado + 0.005, 2);
Lineas::guardarTope($db, $lid, number_format($tope, 2, ',', ''), '80');
$linea = Lineas::buscar($db, $lid);
ok($tope - $gastado < 0.0113, "gastado cerca del tope: $gastado de $tope");
limpiarSim();
$r = $m->texto($linea, $ana, 'uno más', 'panel');
ok(!$r['ok'] && !empty($r['bloqueado']), 'bloqueado por el tope');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/messages'))), 0, 'no llegó nada a Meta');
igual(Gasto::delMes($db, $lid, $martes20), $gastado, 'el bloqueado no suma');
$tg = array_values(array_filter(sim(), fn ($x) => str_contains($x['ruta'], 'sendMessage')));
igual(count($tg), 0, 'sin avisos nuevos en este intento');
// el aviso del 80 % y del 100 % se mandan una sola vez
$db->exec('DELETE FROM avisos_gasto');
limpiarSim();
$niv = Avisos::revisar($db, $linea, $martes20);
igual($niv, [80], 'aviso del 80 %');
igual(count(array_filter(sim(), fn ($x) => str_contains($x['ruta'], 'sendMessage'))), 1, 'Telegram avisado');
igual(Avisos::revisar($db, $linea, $martes20), [], 'no se repite');
// automática bloqueada
Contactos::guardar($db, $lid, '0996666666', 'Rosa', 'cliente');
limpiarSim();
$wh->procesar(entrante('593996666666', 'precio', 'wamid.T1', $martes20->getTimestamp()));
igual($db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.T1'")->fetchColumn(), 'No se contestó: llegaste al tope del mes', 'automática respeta el tope');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/messages'))), 0, 'nada a Meta');
// el próximo mes vuelve a cero
igual(Gasto::delMes($db, $lid, new DateTimeImmutable('2026-11-02 10:00')), 0.0, 'mes nuevo empieza en 0');
$res = Gasto::resumen($db, $linea, $martes20);
igual($res['pct'], (int) floor($gastado * 100 / $tope), 'porcentaje');
ok($res['bloqueados'] >= 2, 'cuenta los bloqueados');
Lineas::guardarTope($db, $lid, '12', '80');
$linea = Lineas::buscar($db, $lid);

echo "== Pendientes y eco del celular\n";
$pid = Pendientes::agregar($db, $lid, '0997777777', 'Carla', "Hola Carla,\n tu demo está lista");
igual(Pendientes::contar($db, $lid), 1, '1 pendiente');
$eco = cuerpoMeta(['metadata' => ['phone_number_id' => '100'], 'message_echoes' => [[
    'from' => '593996408397', 'to' => '593997777777', 'id' => 'wamid.ECO1', 'timestamp' => (string) $martes20->getTimestamp(),
    'type' => 'text', 'text' => ['body' => 'Hola Carla, tu demo está lista'],
]]], 'smb_message_echoes');
$wh->procesar($eco);
igual(Pendientes::contar($db, $lid), 0, 'enviado desde el celular -> se marca solo');
$fila = $db->query("SELECT origen, costo FROM mensajes WHERE wamid = 'wamid.ECO1'")->fetch();
igual([$fila['origen'], (float) $fila['costo']], ['celular', 0.0], 'guardado como del celular, gratis');
Pendientes::marcar($db, $lid, $pid, 'pendiente');
igual(Pendientes::contar($db, $lid), 1, 'volver a pendiente');
try {
    Pendientes::agregar($db, $lid, 'abc', '', 'x');
    ok(false, 'número malo');
} catch (RuntimeException $e) {
    ok(true, 'número malo');
}

echo "== Contactos\n";
$r = Contactos::importar($db, $lid, "0991111111, Ana\n0998888888, Primo\nhola\n\n", 'personal');
igual([$r['nuevos'], $r['cambiados'], $r['malos']], [1, 1, ['hola']], 'importar lista');
igual(Contactos::porTelefono($db, $lid, '593991111111')['tipo'], 'personal', 'cambió a personal');
$b = Contactos::bandeja($db, $lid);
ok(count($b) >= 5, 'bandeja con conversaciones');
igual(Contactos::bandeja($db, $lid, 'personal')[0]['tipo'] ?? null, 'personal', 'filtro personal');
ok(count(Contactos::bandeja($db, $lid, 'sin_leer')) > 0, 'filtro sin leer');
igual(count(Contactos::bandeja($db, $lid, 'todos', '99222')), 1, 'buscar por número');

echo "== Contactos desde archivos del celular\n";
use TuWhatsApp\LectorContactos;
$dir = __DIR__ . '/datos/';
$a = LectorContactos::leer(file_get_contents($dir . 'android.vcf'), 'Contactos.vcf');
igual($a, [['099 123 4567', 'María Pérez Mamá'], ['02 234 5678', 'María Pérez Mamá'], ['+593987654321', 'Papá'], ['0987654321', 'Papá']], 'Android .vcf (tildes, varios números, foto, sin número)');
$i = LectorContactos::leer(file_get_contents($dir . 'iphone.vcf'), 'Todos.vcf');
igual($i, [['+593 99 888 7766', 'Pedro Gómez'], ['+1 (305) 555-1234', 'Pedro Gómez'], ['0991112233', 'Ferretería El Clavo']], 'iPhone .vcf (item1, sin nombre usa la empresa)');
$g = LectorContactos::leer(file_get_contents($dir . 'google.csv'), 'contacts.csv');
igual($g, [['099 555 1111', 'Tía Rosa'], ['099 555 2222', 'Tía Rosa'], ['02 600 7000', 'Tía Rosa'], ['+593 96 000 1111', 'Juan, el primo']], 'CSV de Google (::: y comas)');
igual(LectorContactos::leer("0991234567, Ana\n\n0987654321"), [['0991234567', 'Ana'], ['0987654321', '']], 'texto pegado');
igual(LectorContactos::leer(mb_convert_encoding("BEGIN:VCARD\nFN:José\nTEL:0991230000\nEND:VCARD\n", 'Windows-1252', 'UTF-8'), 'x.vcf'), [['0991230000', 'José']], 'archivo en otra codificación');
$r = Contactos::importar($db, $lid, $a, 'personal', true);
igual([$r['nuevos'], $r['cambiados'], $r['iguales'], count($r['malos'])], [2, 0, 0, 1], 'importa Android: 2 celulares, el fijo se salta, Papá una sola vez');
Contactos::guardar($db, $lid, '0998887766', 'Pedro cliente', 'cliente');
$r = Contactos::importar($db, $lid, $i, 'personal', true);
igual([$r['nuevos'], $r['iguales']], [2, 1], 'con "no cambiar": Pedro sigue');
igual(Contactos::porTelefono($db, $lid, '593998887766')['tipo'], 'cliente', 'Pedro sigue siendo cliente');
$r = Contactos::importar($db, $lid, $i, 'personal', false);
igual(Contactos::porTelefono($db, $lid, '593998887766')['tipo'], 'personal', 'sin "no cambiar" sí lo cambia');
igual(Contactos::porTelefono($db, $lid, '593991234567')['nombre'], 'María Pérez Mamá', 'nombre con tildes');

echo "== Número desconocido en el webhook\n";
$antes = (int) $db->query('SELECT count(*) FROM mensajes')->fetchColumn();
$wh->procesar(str_replace('"100"', '"999"', entrante('593991111111', 'precio', 'wamid.X9', $martes20->getTimestamp())));
igual((int) $db->query('SELECT count(*) FROM mensajes')->fetchColumn(), $antes, 'no guarda nada');
ok(str_contains((string) $db->query('SELECT error FROM webhook_log ORDER BY id DESC LIMIT 1')->fetchColumn(), 'Número desconocido'), 'queda el error en el registro');

echo "== Conectar con código QR (Embedded Signup)\n";
putenv('META_APP_ID=');
putenv('META_ES_CONFIG_ID=');
ok(!Registro::listo(), 'sin App ID no está listo');
$r = Registro::completar($db, ['code' => 'codigo_bueno_1234567', 'evento' => 'FINISH', 'waba_id' => '300', 'phone_number_id' => '301']);
ok(!$r['ok'] && str_contains($r['error'], 'Falta configurar'), 'sin configurar avisa');
putenv('META_APP_ID=2592670637899782');
putenv('META_ES_CONFIG_ID=1112223334445');
ok(Registro::listo(), 'con App ID, Config ID y clave está listo');
$r = Registro::completar($db, ['code' => 'codigo_bueno_1234567', 'evento' => 'CANCEL', 'waba_id' => '300', 'phone_number_id' => '301']);
ok(!$r['ok'] && str_contains($r['error'], 'no se terminó'), 'cancelado en Meta');
$r = Registro::completar($db, ['code' => '', 'evento' => 'FINISH', 'waba_id' => '300', 'phone_number_id' => '301']);
ok(!$r['ok'] && str_contains($r['error'], 'código'), 'sin código');
limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_vencido_123456', 'evento' => 'FINISH', 'waba_id' => '300', 'phone_number_id' => '301']);
ok(!$r['ok'] && str_contains($r['error'], '30 segundos'), 'código vencido');
igual((int) $db->query("SELECT count(*) FROM lineas WHERE phone_number_id = '301'")->fetchColumn(), 0, 'código vencido no guarda nada');

limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_bueno_1234567', 'evento' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
                               'waba_id' => '300', 'phone_number_id' => '301', 'nombre' => 'QF Producciones']);
ok($r['ok'] && $r['coexistencia'] === true && $r['pin'] === '' && $r['avisos'] === [], 'coexistencia conectada sin avisos');
$llamadas = array_map(fn ($x) => $x['metodo'] . ' ' . preg_replace('#^/v[\d.]+#', '', $x['ruta']), sim());
igual($llamadas, ['GET /oauth/access_token', 'GET /301', 'POST /300/subscribed_apps', 'POST /301/smb_app_data', 'POST /301/smb_app_data'], 'pasos en orden y SIN registrar el número');
$s = sim();
igual([$s[3]['cuerpo']['sync_type'], $s[4]['cuerpo']['sync_type']], ['smb_app_state_sync', 'history'], 'primero contactos y después historial');
ok(str_starts_with($s[2]['auth'], 'Bearer EAAGnegocio'), 'se suscribe con el token del negocio');
$q = Lineas::buscar($db, $r['linea_id']);
igual([$q['nombre'], $q['conectado_por'], $q['coexistencia'], $q['waba_id'], $q['telefono']], ['QF Producciones', 'qr', true, '300', '593996408397'], 'línea guardada');
ok(str_starts_with(Lineas::token($q), 'EAAGnegocio') && !str_contains($q['token_cifrado'], 'EAAG'), 'token guardado cifrado');
ok(str_starts_with($q['sync_contactos'], 'ok:REQ') && str_starts_with($q['sync_historial'], 'ok:REQ') && $q['historial_estado'] === 'recibiendo', 'sincronización pedida');
$x = Registro::sincronizar($db, $q, 'historial');
ok(!$x['ok'] && str_contains($x['error'], 'una vez'), 'el historial solo se pide una vez');
$qrId = (int) $q['id'];

limpiarSim();
$r2 = Registro::completar($db, ['code' => 'codigo_otro_99999999', 'evento' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'waba_id' => '300', 'phone_number_id' => '301']);
igual([$r2['ok'], $r2['linea_id']], [true, $qrId], 'conectar otra vez el mismo número no lo duplica');
igual((int) $db->query("SELECT count(*) FROM lineas WHERE phone_number_id = '301'")->fetchColumn(), 1, 'una sola línea');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/smb_app_data'))), 0, 'no vuelve a pedir lo que ya pidió');

limpiarSim();
$r3 = Registro::completar($db, ['code' => 'codigo_nuevo_1234567', 'evento' => 'FINISH', 'waba_id' => '400', 'phone_number_id' => '401', 'nombre' => 'Chip nuevo']);
ok($r3['ok'] && $r3['coexistencia'] === false && preg_match('/^\d{6}$/', $r3['pin']) === 1, 'número nuevo: PIN de 6 números');
$reg = array_values(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/401/register')));
igual($reg[0]['cuerpo']['pin'] ?? null, $r3['pin'], 'número nuevo: se registra con ese PIN');
igual(Cifrado::descifrar((string) Lineas::buscar($db, $r3['linea_id'])['pin_cifrado']), $r3['pin'], 'PIN guardado cifrado');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/smb_app_data'))), 0, 'número nuevo: no pide historial');
$x = Registro::sincronizar($db, Lineas::buscar($db, $r3['linea_id']), 'contactos');
ok(!$x['ok'] && str_contains($x['error'], 'Solo se puede'), 'sin coexistencia no se sincroniza');

$db->prepare("UPDATE lineas SET phone_number_id = '666', sync_contactos = '' WHERE id = ?")->execute([$r3['linea_id']]);
$db->prepare("UPDATE lineas SET coexistencia = true WHERE id = ?")->execute([$r3['linea_id']]);
$x = Registro::sincronizar($db, Lineas::buscar($db, $r3['linea_id']), 'contactos');
ok(!$x['ok'] && str_starts_with((string) Lineas::buscar($db, $r3['linea_id'])['sync_contactos'], 'error:'), 'si Meta rechaza queda el error');
igual(Registro::estadoSync('ok:REQ1'), 'pedido a Meta', 'estado en palabras');

echo "== QR: Meta no manda el número (coexistencia real)\n";
limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_coex_sin_numero1', 'evento' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'waba_id' => '500', 'phone_number_id' => '']);
ok($r['ok'] && $r['coexistencia'] === true && $r['pin'] === '', 'sin phone_number_id: lo busca en la cuenta');
igual(Lineas::buscar($db, $r['linea_id'])['phone_number_id'], '501', 'elige el número que sigue en la app del celular');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/register'))), 0, 'no registra (coexistencia)');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/smb_app_data'))), 2, 'pide contactos e historial');

limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_sin_datos_12345', 'evento' => '', 'waba_id' => '', 'phone_number_id' => '']);
ok($r['ok'] && $r['coexistencia'] === true, 'sin ningún dato de la ventana: lo averigua con el token');
igual(Lineas::buscar($db, $r['linea_id'])['waba_id'], '600', 'cuenta sacada del permiso del token');
igual(Lineas::buscar($db, $r['linea_id'])['phone_number_id'], '601', 'número sacado de la cuenta');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/debug_token'))), 1, 'usa debug_token');

limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_sin_evento_123', 'evento' => '', 'waba_id' => '700', 'phone_number_id' => '']);
ok($r['ok'] && $r['coexistencia'] === false && $r['pin'] === '', 'sin evento y número fuera de la app: no inventa');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/register'))), 0, 'sin confirmación de Meta NUNCA registra a ciegas');
ok(str_contains(implode(' ', $r['avisos']), 'No se registró'), 'avisa que no registró');

limpiarSim();
$r = Registro::completar($db, ['code' => 'codigo_cuenta_vacia1', 'evento' => 'FINISH', 'waba_id' => '800', 'phone_number_id' => '']);
ok(!$r['ok'] && str_contains($r['error'], 'no tiene ningún número'), 'cuenta sin números: explica');

echo "== Historial y contactos del celular (webhook)\n";
$meta301 = ['display_phone_number' => '593996408397', 'phone_number_id' => '301'];
$t0 = $martes20->getTimestamp() - 86400 * 3;
$hist = fn (int $progreso, array $mensajes) => cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => $meta301,
    'history' => [['metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => $progreso], 'threads' => [['id' => '593981112222', 'messages' => $mensajes]]]]], 'history');
limpiarSim();
$wh->procesar($hist(55, [
    ['from' => '593981112222', 'id' => 'wamid.H1', 'timestamp' => (string) $t0, 'type' => 'text', 'text' => ['body' => 'Hola, quiero precios'], 'history_context' => ['status' => 'READ']],
    ['from' => '593996408397', 'to' => '593981112222', 'id' => 'wamid.H2', 'timestamp' => (string) ($t0 + 60), 'type' => 'text', 'text' => ['body' => 'Claro, te cuento'], 'history_context' => ['status' => 'READ']],
    ['from' => '593981112222', 'id' => 'wamid.H3', 'timestamp' => (string) ($t0 + 120), 'type' => 'media_placeholder', 'history_context' => ['status' => 'READ']],
]));
$hm = $db->query("SELECT wamid, direccion, origen, estado, leido, texto, motivo FROM mensajes WHERE wamid LIKE 'wamid.H%' ORDER BY wamid")->fetchAll();
igual(count($hm), 3, 'guarda los 3 mensajes del historial');
igual([$hm[0]['direccion'], $hm[0]['origen'], $hm[0]['leido'], $hm[0]['motivo']], ['entra', 'cliente', true, 'Del historial del celular'], 'mensaje del cliente: entra y ya leído');
igual([$hm[1]['direccion'], $hm[1]['origen'], $hm[1]['estado']], ['sale', 'celular', 'leido'], 'respuesta desde el celular: sale y leído');
igual($hm[2]['texto'], '[Archivo del historial]', 'foto del historial');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/messages'))), 0, 'nunca contesta solo al historial');
$q = Lineas::buscar($db, $qrId);
igual([$q['historial_progreso'], $q['historial_estado']], [55, 'recibiendo'], 'progreso 55 %');
$wh->procesar($hist(100, [['from' => '593981112222', 'id' => 'wamid.H1', 'timestamp' => (string) $t0, 'type' => 'text', 'text' => ['body' => 'Hola, quiero precios']]]));
igual((int) $db->query("SELECT count(*) FROM mensajes WHERE wamid LIKE 'wamid.H%'")->fetchColumn(), 3, 'repetido no se duplica');
igual(Lineas::buscar($db, $qrId)['historial_estado'], 'completo', 'historial completo al 100 %');
$wh->procesar(cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => $meta301, 'history' => [['errors' => [['code' => 2593109, 'title' => 'No compartido']]]]], 'history'));
igual(Lineas::buscar($db, $qrId)['historial_estado'], 'rechazado', 'si no se comparte el historial queda anotado');

$sync = fn (array $items) => cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => $meta301, 'state_sync' => $items], 'smb_app_state_sync');
Contactos::guardar($db, $qrId, '0981113333', 'Cliente Ya Guardado', 'cliente');
$wh->procesar($sync([
    ['type' => 'contact', 'contact' => ['full_name' => 'Ana Pérez', 'first_name' => 'Ana', 'phone_number' => '+593 98 111 4444'], 'action' => 'add', 'metadata' => ['timestamp' => (string) $t0]],
    ['type' => 'contact', 'contact' => ['full_name' => 'Otro Nombre', 'phone_number' => '+593981113333'], 'action' => 'add', 'metadata' => ['timestamp' => (string) $t0]],
    ['type' => 'contact', 'contact' => ['phone_number' => '+593981115555'], 'action' => 'remove', 'metadata' => ['timestamp' => (string) $t0]],
]));
$ana = Contactos::porTelefono($db, $qrId, '593981114444');
igual([$ana['nombre'] ?? null, $ana['tipo'] ?? null], ['Ana Pérez', 'desconocido'], 'contacto nuevo del celular con su nombre');
$ya = Contactos::porTelefono($db, $qrId, '593981113333');
igual([$ya['nombre'], $ya['tipo']], ['Cliente Ya Guardado', 'cliente'], 'el que ya existía no cambia');
igual(Contactos::porTelefono($db, $qrId, '593981115555'), null, 'quitar del celular no crea nada');
$wh->procesar(json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '300', 'changes' => [['field' => 'account_update', 'value' => ['event' => 'PARTNER_ADDED', 'waba_info' => ['waba_id' => '300']]]]]]]));
igual($db->query('SELECT error FROM webhook_log ORDER BY id DESC LIMIT 1')->fetchColumn(), null, 'aviso de la cuenta se acepta sin error');

echo "== Precios\n";
Gasto::guardarPrecios($db, ['servicio' => '0,02', 'utilidad' => '0.0113', 'marketing' => '0.074', 'autenticacion' => '0.0113']);
igual(Gasto::precios($db)['servicio'], 0.02, 'precio cambiado');
try {
    Gasto::guardarPrecios($db, ['servicio' => '5', 'utilidad' => '0', 'marketing' => '0', 'autenticacion' => '0']);
    ok(false, 'precio absurdo');
} catch (RuntimeException $e) {
    ok(true, 'precio absurdo');
}


echo "== Cuentas por cliente (empresas)\n";
use TuWhatsApp\Empresas;
$hoy = new DateTimeImmutable('2026-10-21');
ok(Empresas::alDia(['estado' => 'activa', 'vence' => null], $hoy), 'activa sin fecha: al día');
ok(Empresas::alDia(['estado' => 'activa', 'vence' => '2026-10-21'], $hoy), 'vence hoy: todavía al día');
ok(!Empresas::alDia(['estado' => 'activa', 'vence' => '2026-10-20'], $hoy), 'vencida ayer');
ok(Empresas::alDia(['estado' => 'demo', 'vence' => '2026-10-28'], $hoy), 'demo vigente');
ok(!Empresas::alDia(['estado' => 'demo', 'vence' => null], $hoy), 'demo sin fecha no vale');
ok(!Empresas::alDia(['estado' => 'suspendida', 'vence' => '2030-01-01'], $hoy), 'suspendida');
igual(Empresas::motivoBloqueo(['estado' => 'suspendida', 'motivo_suspension' => 'Falta el pago', 'vence' => null]), 'Falta el pago', 'motivo de suspensión');
ok(preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', Empresas::claveNueva()) === 1, 'contraseña fácil de dictar');

$nueva = Empresas::crear($db, ['nombre' => 'Salón Bella', 'estado' => 'demo', 'vence' => (new DateTimeImmutable('+7 days'))->format('Y-m-d'),
    'max_numeros' => '1', 'max_usuarios' => '2', 'contacto' => 'Bella', 'telefono' => '0991112222', 'admin_correo' => 'Bella@Salon.com', 'admin_nombre' => 'Bella']);
$e2 = (int) $nueva['empresa_id'];
ok($e2 > 1 && strlen($nueva['clave']) === 14, 'empresa creada con su primer usuario');
$u = $db->query("SELECT * FROM usuarios WHERE correo = 'bella@salon.com'")->fetch();
ok($u && (int) $u['empresa_id'] === $e2 && $u['rol'] === 'admin' && !$u['super'] && password_verify($nueva['clave'], $u['clave']), 'usuario admin de su empresa (correo en minúsculas)');
try {
    Empresas::crear($db, ['nombre' => 'Otro', 'estado' => 'demo', 'vence' => '2030-01-01', 'admin_correo' => 'bella@salon.com']);
    ok(false, 'correo repetido');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'Ya existe'), 'correo repetido');
}
igual((int) $db->query("SELECT count(*) FROM empresas WHERE nombre = 'Otro'")->fetchColumn(), 0, 'si falla el usuario no queda la empresa a medias');
try {
    Empresas::crear($db, ['nombre' => 'Sin fecha', 'estado' => 'demo', 'admin_correo' => 'x@y.com']);
    ok(false, 'demo sin fecha');
} catch (RuntimeException $e) {
    ok(true, 'demo sin fecha');
}
Empresas::agregarUsuario($db, $e2, 'agente@salon.com', 'Agente', 'agente');
try {
    Empresas::agregarUsuario($db, $e2, 'tercero@salon.com', 'Tercero', 'agente');
    ok(false, 'límite de usuarios');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), '2 usuarios'), 'límite de usuarios');
}
$agente = (int) $db->query("SELECT id FROM usuarios WHERE correo = 'agente@salon.com'")->fetchColumn();
try {
    Empresas::cambiarUsuario($db, 1, $agente, 'desactivar', 999);
    ok(false, 'no se toca un usuario de otra empresa');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no es de tu cuenta'), 'no se toca un usuario de otra empresa');
}
Empresas::cambiarUsuario($db, $e2, $agente, 'desactivar', (int) $u['id']);
Empresas::agregarUsuario($db, $e2, 'tercero@salon.com', 'Tercero', 'agente');
ok(true, 'desactivado deja cupo');
try {
    Empresas::cambiarUsuario($db, $e2, (int) $u['id'], 'agente', (int) $u['id']);
    ok(false, 'no se quita el admin a sí mismo');
} catch (RuntimeException $e) {
    ok(true, 'no se quita el admin a sí mismo');
}

// Números separados por empresa
try {
    Lineas::guardarConexion($db, null, ['nombre' => 'Robado', 'phone_number_id' => '100', 'token' => 'EAAGtokenDePrueba1234567890'], $e2);
    ok(false, 'no puede usar el número de otra empresa');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'otra cuenta'), 'no puede usar el número de otra empresa');
}
$l2 = Lineas::guardarConexion($db, null, ['nombre' => 'Bella', 'phone_number_id' => '900', 'waba_id' => '901', 'token' => 'EAAGtokenBella1234567890123'], $e2);
try {
    Lineas::guardarConexion($db, null, ['nombre' => 'Segundo', 'phone_number_id' => '902', 'token' => 'EAAGtokenBella1234567890123'], $e2);
    ok(false, 'límite de números');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no permite más números'), 'límite de números');
}
igual(array_column(Lineas::todas($db, $e2), 'id'), [$l2], 'la empresa ve solo su número');
igual(Lineas::buscar($db, $lid, $e2), null, 'no puede abrir el número de Tukán');
try {
    Lineas::guardarConexion($db, $lid, ['nombre' => 'X', 'phone_number_id' => '100', 'token' => ''], $e2);
    ok(false, 'no puede editar el número de otra empresa');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no es de tu cuenta'), 'no puede editar el número de otra empresa');
}
$r = Registro::completar($db, ['code' => 'codigo_bueno_1234567', 'evento' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'waba_id' => '300', 'phone_number_id' => '301'], null, $e2);
ok(!$r['ok'] && str_contains($r['error'], 'otra cuenta'), 'QR: número de otra empresa rechazado: ' . ($r['error'] ?? ''));

// Webhook y envíos según el estado de la cuenta
$linea2 = Lineas::buscar($db, $l2);
Lineas::guardarReglas($db, $l2, array_merge($form, ['regla_solo_clientes' => '0', 'regla_horario' => '0']));
$linea2 = Lineas::buscar($db, $l2);
limpiarSim();
$wh->procesar(str_replace('"100"', '"900"', entrante('593991231231', 'precio por favor', 'wamid.B1', $martes20->getTimestamp())));
ok(str_contains((string) $db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.B1'")->fetchColumn(), 'Contestado solo'), 'cuenta en demo contesta sola');
igual(count(array_filter(sim(), fn ($x) => $x['ruta'] === '/v24.0/900/messages')), 1, 'sale por el número de Bella');
$db->prepare("UPDATE empresas SET estado = 'suspendida', motivo_suspension = 'Falta el pago' WHERE id = ?")->execute([$e2]);
limpiarSim();
$wh->procesar(str_replace('"100"', '"900"', entrante('593991231232', 'precio por favor', 'wamid.B2', $martes20->getTimestamp())));
igual($db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.B2'")->fetchColumn(), 'La cuenta no está al día: no se contesta solo', 'suspendida: guarda pero no contesta');
igual(count(sim()), 0, 'nada a Meta');
$cb = Contactos::porTelefono($db, $l2, '593991231232');
$r = (new Mensajeria($db, $martes20))->texto($linea2, $cb, 'hola', 'panel');
ok(!$r['ok'] && str_contains($r['error'], 'no está al día'), 'suspendida: no envía desde el panel');
$db->prepare("UPDATE empresas SET estado = 'activa' WHERE id = ?")->execute([$e2]);

// Token de API por empresa
$tok = Empresas::nuevoTokenApi($db, $e2);
igual((int) (Empresas::porTokenApi($db, $tok)['id'] ?? 0), $e2, 'token de API de la empresa');
igual(Empresas::porTokenApi($db, 'tw_2_' . str_repeat('0', 48)), null, 'token falso');
$tok2 = Empresas::nuevoTokenApi($db, $e2);
igual(Empresas::porTokenApi($db, $tok), null, 'el token anterior deja de servir');
ok(Empresas::porTokenApi($db, $tok2) !== null, 'el nuevo sirve');
$lista = Empresas::lista($db);
igual((int) $lista[0]['id'], 1, 'Tukán primero en la lista');
$fila = array_values(array_filter($lista, fn ($x) => (int) $x['id'] === $e2))[0];
igual([(int) $fila['numeros'], (int) $fila['mensajes_mes'] > 0], [1, true], 'cifras del cliente');


echo "== Campañas masivas\n";
use TuWhatsApp\Campanias;
ok(Campanias::pideBaja('BAJA'), 'BAJA es pedido de baja');
ok(Campanias::pideBaja('  Stop!! '), 'Stop!! también');
ok(Campanias::pideBaja('No más'), 'No más');
ok(!Campanias::pideBaja('quiero dar de baja mi pedido de ayer'), 'una frase larga no es baja');
ok(!Campanias::pideBaja('hola'), 'hola no es baja');
igual(Campanias::valores([['tipo' => 'nombre', 'valor' => 'amigo'], ['tipo' => 'fijo', 'valor' => '20 %']], ['nombre' => 'ANA maría López', 'nombre_whatsapp' => '']),
      ['Ana', '20 %'], 'primer nombre bonito y texto fijo');
igual(Campanias::valores([['tipo' => 'nombre', 'valor' => 'amigo']], ['nombre' => '', 'nombre_whatsapp' => '']), ['amigo'], 'sin nombre usa el texto de respaldo');
igual(Campanias::valores([['tipo' => 'nombre', 'valor' => 'amigo']], ['nombre' => '', 'nombre_whatsapp' => '+593 99']), ['amigo'], 'un número no sirve de nombre');

$lc = Lineas::guardarConexion($db, null, ['nombre' => 'Campañas', 'phone_number_id' => '777', 'waba_id' => '778', 'token' => 'EAAGtokenCampania123456789'], 1);
$lineaC = Lineas::buscar($db, $lc);
$mk = function (string $tel, string $nombre, string $tipo, string $mkt, string $etq = '') use ($db, $lc) {
    $c = Contactos::guardar($db, $lc, $tel, $nombre, $tipo);
    Campanias::marcarMarketing($db, $lc, (int) $c['id'], $mkt);
    Contactos::guardarEtiquetas($db, $lc, (int) $c['id'], $etq);
    return (int) $c['id'];
};
$mk('0981000001', 'Ana López', 'cliente', 'acepta', 'VIP, Quito');
$mk('0981000002', 'Beto', 'cliente', 'acepta', 'quito');
$mk('0981000003', '', 'cliente', 'sin_dato');
$mk('0981000004', 'Mamá', 'personal', 'acepta');
$mk('0981000005', 'Carla', 'cliente', 'baja', 'vip');
$mk('0981000006', 'Nuevo', 'desconocido', 'acepta');
igual(Campanias::contar($db, $lc, 'cliente', '', true), 2, 'clientes que aceptaron');
igual(Campanias::contar($db, $lc, 'cliente', '', false), 3, 'todos los clientes menos la baja');
igual(Campanias::contar($db, $lc, 'cliente_desconocido', '', true), 3, 'con desconocidos que aceptaron');
igual(Campanias::contar($db, $lc, 'cliente', 'vip', false), 1, 'etiqueta vip (sin la baja)');
igual(Campanias::contar($db, $lc, 'cliente', 'QUITO', true), 2, 'etiqueta sin importar mayúsculas');
igual(array_keys(Contactos::etiquetasUsadas($db, $lc)), ['quito', 'vip'], 'etiquetas usadas');

$aprob = \TuWhatsApp\Meta::deLinea($lineaC)->plantillas()['plantillas'];
try {
    Campanias::preparar($db, $lineaC, ['plantilla' => 'recordatorio_pago|es', 'dato_tipo' => [1 => 'nombre', 2 => 'fijo'], 'dato_valor' => [1 => '', 2 => '']], $aprob);
    ok(false, 'falta el texto fijo');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), '{{2}}'), 'falta el texto fijo');
}
$prep = Campanias::preparar($db, $lineaC, ['nombre' => 'Recordatorio', 'plantilla' => 'recordatorio_pago|es', 'dato_tipo' => [1 => 'nombre', 2 => 'fijo'],
    'dato_valor' => [1 => 'amigo', 2 => '30 de noviembre'], 'filtro_tipo' => 'cliente_desconocido', 'solo_acepta' => '1', 'por_minuto' => '2'], $aprob);
igual([$prep['total'], $prep['minutos'], $prep['costo']], [3, 2, 0.0339], 'revisión: 3 contactos, 2 minutos, costo');
igual($prep['ejemplo'], 'Hola Ana, tu plan vence el 30 de noviembre.', 'vista previa con el nombre');
$idc = Campanias::crear($db, $lineaC, $prep, null);
// Después de crearla, Beto pide la baja: no le debe llegar
$beto = Contactos::porTelefono($db, $lc, '593981000002');
limpiarSim();
$wh->procesar(str_replace('"100"', '"777"', entrante('593981000002', 'Baja', 'wamid.BAJA1', $martes20->getTimestamp())));
igual(Contactos::porTelefono($db, $lc, '593981000002')['marketing'], 'baja', 'responder BAJA lo saca de las promociones');
ok(str_contains((string) $db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.BAJA1'")->fetchColumn(), 'no recibir'), 'motivo de la baja');
igual(count(sim()), 0, 'a la baja no se le contesta');
limpiarSim();
$r = Campanias::procesar($db, $martes20);
igual($r['enviados'], 1, 'primer minuto: 1 enviado (Beto se omite, 2 por minuto)');
$r = Campanias::procesar($db, $martes20);
igual($r['enviados'], 1, 'segundo minuto: el último');
$envios = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v24.0/777/messages'));
igual(count($envios), 2, 'Meta recibió 2 plantillas');
igual($envios[0]['cuerpo']['template']['components'][0]['parameters'][0]['text'] ?? null, 'Ana', 'con el nombre de Ana');
igual($envios[1]['cuerpo']['template']['components'][0]['parameters'][0]['text'] ?? null, 'Nuevo', 'y el del desconocido');
$k = Campanias::lista($db, $lc)[0];
igual([$k['estado'], (int) $k['enviados'], (int) $k['omitidos'], (int) $k['pendientes']], ['terminada', 2, 1, 0], 'campaña terminada con 1 omitido');
igual($db->query("SELECT count(*) FROM mensajes WHERE linea_id = $lc AND origen = 'campania'")->fetchColumn(), 2, 'mensajes guardados como campaña');
// Respuesta después de la campaña
(new Webhook($db, $martes20->modify('+1 hour')))->procesar(str_replace('"100"', '"777"', entrante('593981000001', 'me interesa', 'wamid.RESP1', $martes20->getTimestamp() + 600)));
igual((int) Campanias::lista($db, $lc)[0]['respondieron'], 1, 'cuenta quién respondió');

// Tope: la campaña se pausa sola y se reanuda
Contactos::guardar($db, $lc, '0981000007', 'Dani', 'cliente');
Campanias::marcarMarketing($db, $lc, (int) Contactos::porTelefono($db, $lc, '593981000007')['id'], 'acepta');
Contactos::guardar($db, $lc, '0981000008', 'Eva', 'cliente');
Campanias::marcarMarketing($db, $lc, (int) Contactos::porTelefono($db, $lc, '593981000008')['id'], 'acepta');
$gastado = Gasto::delMes($db, $lc, $martes20);
Lineas::guardarTope($db, $lc, number_format($gastado + 0.08, 2, '.', ''), '80');
$lineaC = Lineas::buscar($db, $lc);
$prep = Campanias::preparar($db, $lineaC, ['plantilla' => 'promo_octubre|es', 'filtro_tipo' => 'cliente', 'solo_acepta' => '1', 'por_minuto' => '10'], $aprob);
igual([$prep['total'], $prep['alcanza'] < $prep['total'] || $prep['alcanza'] >= 1], [3, true], 'promo a 3 clientes que aceptaron');
$idp = Campanias::crear($db, $lineaC, $prep, null);
$r = Campanias::procesar($db, $martes20);
$k = array_values(array_filter(Campanias::lista($db, $lc), fn ($x) => (int) $x['id'] === $idp))[0];
igual([$r['enviados'], $r['pausadas'], $k['estado']], [1, 1, 'pausada'], 'se pausa al llegar al tope');
ok(str_contains($k['motivo_pausa'], 'tope'), 'motivo de la pausa');
igual((int) $k['pendientes'], 2, 'lo que faltaba queda pendiente');
igual((int) $db->query("SELECT count(*) FROM mensajes WHERE linea_id = $lc AND estado = 'bloqueado'")->fetchColumn(), 0, 'no deja mensajes bloqueados sueltos');
Lineas::guardarTope($db, $lc, '50', '80');
Campanias::cambiar($db, $lc, $idp, 'reanudar');
$r = Campanias::procesar($db, $martes20);
igual($r['enviados'], 2, 'reanudada termina');
try {
    Campanias::cambiar($db, $lc, $idp, 'pausar');
    ok(false, 'no se pausa una terminada');
} catch (RuntimeException $e) {
    ok(true, 'no se pausa una terminada');
}
try {
    Campanias::cambiar($db, $lid, $idp, 'cancelar');
    ok(false, 'no se toca la campaña de otro número');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no existe'), 'no se toca la campaña de otro número');
}
$idx = Campanias::crear($db, Lineas::buscar($db, $lc), Campanias::preparar($db, Lineas::buscar($db, $lc), ['plantilla' => 'promo_octubre|es', 'filtro_tipo' => 'cliente', 'solo_acepta' => '1'], $aprob), null);
Campanias::cambiar($db, $lc, $idx, 'cancelar');
igual(Campanias::procesar($db, $martes20)['enviados'], 0, 'cancelada no envía');
// Importar con permiso de promociones
$r = Contactos::importar($db, $lc, "0981000010, Gaby\n0981000011, Hugo", 'cliente', true, true);
igual(Campanias::contar($db, $lc, 'cliente', '', true), 5, 'importados con permiso de promociones');


echo "== Varios agentes y respuestas rápidas\n";
use TuWhatsApp\Respuestas;
$clAg = Empresas::agregarUsuario($db, 1, 'agente1@tukan.com', 'Luis Agente', 'agente');
$ag1 = (int) $db->query("SELECT id FROM usuarios WHERE correo = 'agente1@tukan.com'")->fetchColumn();
$bellaAdmin = (int) $db->query("SELECT id FROM usuarios WHERE correo = 'bella@salon.com'")->fetchColumn();
$ana = Contactos::porTelefono($db, $lc, '593981000001');
try {
    Contactos::asignar($db, $lc, (int) $ana['id'], $bellaAdmin, 1);
    ok(false, 'no se asigna a alguien de otra cuenta');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no es de tu equipo'), 'no se asigna a alguien de otra cuenta');
}
Contactos::asignar($db, $lc, (int) $ana['id'], $ag1, 1);
$mios = Contactos::bandeja($db, $lc, 'mios', '', 100, $ag1);
igual(array_column($mios, 'telefono'), ['593981000001'], 'Míos: solo la de Luis');
igual($mios[0]['asignado_nombre'], 'Luis Agente', 'nombre de quien atiende');
ok(!in_array('593981000001', array_column(Contactos::bandeja($db, $lc, 'sin_asignar'), 'telefono'), true), 'ya no sale en Sin asignar');
$nuevoC = Contactos::porTelefono($db, $lc, '593981000006');
Contactos::tomarSiLibre($db, (int) $nuevoC['id'], $ag1);
Contactos::tomarSiLibre($db, (int) $ana['id'], 999999);
igual((int) Contactos::porTelefono($db, $lc, '593981000001')['asignado_a'], $ag1, 'si ya tiene dueño, no se lo quita');
igual((int) Contactos::porTelefono($db, $lc, '593981000006')['asignado_a'], $ag1, 'si estaba libre, lo toma quien responde');
Contactos::asignar($db, $lc, (int) $ana['id'], null, 1);
ok(in_array('593981000001', array_column(Contactos::bandeja($db, $lc, 'sin_asignar'), 'telefono'), true), 'quitar asignación');
Respuestas::guardar($db, $lc, '/Precios ', 'Nuestros planes empiezan en $10.');
igual(Respuestas::lista($db, $lc)[0]['atajo'], 'precios', 'atajo limpio');
try {
    Respuestas::guardar($db, $lc, 'precios', 'otro');
    ok(false, 'atajo repetido');
} catch (RuntimeException $e) {
    ok(true, 'atajo repetido');
}
Respuestas::borrar($db, $lid, (int) Respuestas::lista($db, $lc)[0]['id']);
igual(count(Respuestas::lista($db, $lc)), 1, 'no se borra la de otro número');


echo "== Panel Tukán Central (protocolo v1)\n";
use TuWhatsApp\TukanCentral;
putenv('TUKAN_CENTRAL_ID=');
igual(TukanCentral::config(), null, 'sin configurar está apagado');
$sec = str_repeat('a1', 32);
putenv('TUKAN_CENTRAL_ID=tuwhatsapp');
putenv('TUKAN_CENTRAL_SECRET=' . $sec);
$tc = new TukanCentral($db);
$t0 = time();
$tok = fn (array $cambio = []) => TukanCentral::firmar(array_merge(['v' => 1, 'tipo' => 'estado', 'inst' => 'tuwhatsapp', 'email' => 'Dimi@Tukanec.com',
    'nombre' => 'Dimitry', 'iat' => $t0, 'exp' => $t0 + 60, 'nonce' => bin2hex(random_bytes(16))], $cambio), $sec);
$p = $tc->validar($tok(), 'estado', $t0);
igual($p['email'], 'dimi@tukanec.com', 'token bueno (correo en minúsculas)');
$rechazo = function (string $t, string $accion, string $palabra, string $que, ?int $ahora = null) use ($tc, $t0) {
    try {
        $tc->validar($t, $accion, $ahora ?? $t0);
        ok(false, $que);
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), $palabra) && $e->getCode() === 401, $que . ': ' . $e->getMessage());
    }
};
$bueno = $tok();
$rechazo(substr($bueno, 0, -2) . 'xx', 'estado', 'firma', 'firma mala');
$rechazo(TukanCentral::firmar(['v' => 1, 'tipo' => 'estado', 'inst' => 'tuwhatsapp', 'email' => 'a@b.com', 'iat' => $t0, 'exp' => $t0 + 60, 'nonce' => bin2hex(random_bytes(16))], 'otro_secreto_de_32_letras_o_mas_xx'), 'estado', 'firma', 'otro secreto');
$rechazo($tok(['inst' => 'tusalon']), 'estado', 'otra instalación', 'otro inst');
$rechazo($tok(['tipo' => 'sso']), 'estado', 'acción', 'tipo cambiado');
$rechazo($tok(['v' => 2]), 'estado', 'Versión', 'otra versión');
$rechazo($tok(['iat' => $t0 - 200, 'exp' => $t0 - 150]), 'estado', 'venció', 'vencido');
$rechazo($tok(['iat' => $t0 + 200, 'exp' => $t0 + 250]), 'estado', 'futura', 'fecha futura');
$rechazo($tok(['exp' => $t0 + 600]), 'estado', 'dura más', 'dura demasiado');
$rechazo($tok(['email' => 'no-es-correo']), 'estado', 'correo', 'correo inválido');
$rechazo($tok(['nonce' => 'abc']), 'estado', 'nonce', 'nonce mal formado');
$repetido = $tok();
$tc->validar($repetido, 'estado', $t0);
$rechazo($repetido, 'estado', 'ya se usó', 'un enlace sirve una sola vez');
$e = $tc->estado(new DateTimeImmutable('today'));
ok($e['ok'] && $e['protocolo'] === 1 && $e['producto'] === 'tuwhatsapp', 'estado: cabecera');
ok(is_array($e['negocios']) && $e['negocios']['total'] >= 1, 'estado: negocios (sin contar a Tukán)');
ok(array_key_exists('Números conectados', $e['extra']) && count($e['extra']) <= 8, 'estado: extra');
$uid = $tc->superUsuario(['email' => 'nuevo.staff@tukanec.com', 'nombre' => 'Staff Nuevo']);
$fila = $db->query("SELECT * FROM usuarios WHERE id = $uid")->fetch();
ok($fila['super'] && $fila['creado_por_central'] && (int) $fila['empresa_id'] === 1, 'SSO crea al personal de Tukán la primera vez');
igual($tc->superUsuario(['email' => 'nuevo.staff@tukanec.com']), $uid, 'la segunda vez usa el mismo');
try {
    $tc->superUsuario(['email' => 'bella@salon.com']);
    ok(false, 'el correo de un cliente no entra como personal de Tukán');
} catch (RuntimeException $x) {
    ok($x->getCode() === 403, 'el correo de un cliente no entra como personal de Tukán');
}
$okLim = true;
for ($i = 0; $i < 30; $i++) { $okLim = $okLim && $tc->dentroDelLimite('9.9.9.9', $t0); }
ok($okLim && !$tc->dentroDelLimite('9.9.9.9', $t0), 'límite de 30 por minuto');
putenv('TUKAN_CENTRAL_ID=');
putenv('TUKAN_CENTRAL_SECRET=');


echo "== Términos y condiciones\n";
use TuWhatsApp\Terminos;
ok(Terminos::aceptados(['id' => 1, 'terminos_version' => '']), 'Tukán no necesita aceptar');
ok(!Terminos::aceptados(['id' => 5, 'terminos_version' => '']), 'un cliente nuevo debe aceptar');
ok(!Terminos::aceptados(['id' => 5, 'terminos_version' => '2020-01-01']), 'versión vieja: debe aceptar otra vez');
Terminos::aceptar($db, $e2, (int) $u['id'], '1.2.3.4');
$fila = Empresas::buscar($db, $e2);
ok(Terminos::aceptados($fila) && $fila['terminos_ip'] === '1.2.3.4' && (int) $fila['terminos_aceptado_por'] === (int) $u['id'], 'queda guardado quién, cuándo y desde dónde');

echo "\nResultado: $bien bien, $mal mal\n";
echo $mal === 0 ? "TODO BIEN\n" : "HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
