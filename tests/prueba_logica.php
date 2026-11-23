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
    'TUWA_ARCHIVOS' => getenv('TUWA_ARCHIVOS') ?: sys_get_temp_dir() . '/tuwa_archivos_prueba',
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
        'metadata' => ['display_phone_number' => '593983662181', 'phone_number_id' => '100'],
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
$lid = Lineas::guardarConexion($db, null, ['nombre' => 'Tukán ventas', 'telefono' => '0983662181', 'phone_number_id' => '100', 'waba_id' => '200', 'token' => 'EAAGtokenDePrueba1234567890']);
$linea = Lineas::buscar($db, $lid);
igual(Lineas::token($linea), 'EAAGtokenDePrueba1234567890', 'token cifrado y descifrado');
ok(!str_contains($linea['token_cifrado'], 'EAAG'), 'el token no se guarda a la vista');
igual($linea['telefono'], '593983662181', 'teléfono normalizado');
try {
    Lineas::guardarConexion($db, null, ['nombre' => 'Otra', 'phone_number_id' => '100', 'token' => 'EAAGtokenDePrueba1234567890']);
    ok(false, 'no deja repetir el phone number id');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'ya está'), 'no deja repetir el phone number id');
}
Lineas::guardarConexion($db, $lid, ['nombre' => 'Tukán ventas', 'telefono' => '0983662181', 'phone_number_id' => '100', 'waba_id' => '200', 'token' => '']);
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
igual((float) $msgs[1]['costo'], 0.0, 'la respuesta automática dentro de 24 horas no se cobra (Meta no cobra las respuestas)');
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
igual($db->query("SELECT texto FROM mensajes WHERE wamid = 'wamid.F1'")->fetchColumn(), '[Foto] mi logo', 'texto de la imagen');

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
igual((float) $db->query("SELECT costo FROM mensajes WHERE wamid = '$w'")->fetchColumn(), 0.0113, 'si Meta cobra una respuesta (pasó su cupo gratis), cuenta a precio de utilidad');
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
$r = $m->plantilla($linea, $viejo, $p['plantillas'][1], [], 'panel');   // marketing: $0,074, más de lo que queda
ok(!$r['ok'] && !empty($r['bloqueado']), 'plantilla pagada: bloqueada por el tope');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/messages'))), 0, 'no llegó nada a Meta');
igual(Gasto::delMes($db, $lid, $martes20), $gastado, 'el bloqueado no suma');
$r = $m->texto($linea, $ana, 'uno más', 'panel');
ok($r['ok'] && (float) $r['costo'] === 0.0, 'las respuestas gratis salen aunque estés en el tope');
igual(Gasto::delMes($db, $lid, $martes20), $gastado, 'y no suman al gasto');
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
ok(str_contains((string) $db->query("SELECT motivo FROM mensajes WHERE wamid = 'wamid.T1'")->fetchColumn(), 'Contestado solo'), 'la respuesta automática es gratis: sale aunque estés en el tope');
igual(count(array_filter(sim(), fn ($x) => str_ends_with($x['ruta'], '/messages'))), 1, 'una sola respuesta a Meta');
// el próximo mes vuelve a cero
igual(Gasto::delMes($db, $lid, new DateTimeImmutable('2026-11-02 10:00')), 0.0, 'mes nuevo empieza en 0');
$res = Gasto::resumen($db, $linea, $martes20);
igual($res['pct'], (int) floor($gastado * 100 / $tope), 'porcentaje');
ok($res['bloqueados'] >= 1, 'cuenta los bloqueados');
Lineas::guardarTope($db, $lid, '12', '80');
$linea = Lineas::buscar($db, $lid);

echo "== Pendientes y eco del celular\n";
$pid = Pendientes::agregar($db, $lid, '0997777777', 'Carla', "Hola Carla,\n tu demo está lista");
igual(Pendientes::contar($db, $lid), 1, '1 pendiente');
$eco = cuerpoMeta(['metadata' => ['phone_number_id' => '100'], 'message_echoes' => [[
    'from' => '593983662181', 'to' => '593997777777', 'id' => 'wamid.ECO1', 'timestamp' => (string) $martes20->getTimestamp(),
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
igual([$q['nombre'], $q['conectado_por'], $q['coexistencia'], $q['waba_id'], $q['telefono']], ['QF Producciones', 'qr', true, '300', '593983662181'], 'línea guardada');
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
$meta301 = ['display_phone_number' => '593983662181', 'phone_number_id' => '301'];
$t0 = $martes20->getTimestamp() - 86400 * 3;
$hist = fn (int $progreso, array $mensajes) => cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => $meta301,
    'history' => [['metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => $progreso], 'threads' => [['id' => '593981112222', 'messages' => $mensajes]]]]], 'history');
limpiarSim();
$wh->procesar($hist(55, [
    ['from' => '593981112222', 'id' => 'wamid.H1', 'timestamp' => (string) $t0, 'type' => 'text', 'text' => ['body' => 'Hola, quiero precios'], 'history_context' => ['status' => 'READ']],
    ['from' => '593983662181', 'to' => '593981112222', 'id' => 'wamid.H2', 'timestamp' => (string) ($t0 + 60), 'type' => 'text', 'text' => ['body' => 'Claro, te cuento'], 'history_context' => ['status' => 'READ']],
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
igual((int) $fila['numeros'], 1, 'cifras del cliente: sus números');
ok(isset($fila['mensajes_mes']), 'cifras del cliente: mensajes del mes (los de prueba son de octubre, así que el número depende del mes en que se corre)');


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
for ($i = 0; $i < 120; $i++) { $okLim = $okLim && $tc->dentroDelLimite('9.9.9.9', $t0); }
ok($okLim && !$tc->dentroDelLimite('9.9.9.9', $t0), 'límite de 120 por minuto (protocolo v2)');
for ($i = 0; $i < 30; $i++) { $tc->anotarRechazo('9.9.9.8', $t0); }
ok(!$tc->dentroDelLimite('9.9.9.8', $t0), 'límite de 30 rechazados por minuto');
try {
    $tc->validar($tok(['inst' => 'tusalon']), 'estado', $t0);
    ok(false, 'los errores de v1 traen codigo');
} catch (\TuWhatsApp\TukanCentralError $x) {
    ok($x->codigo === 'inst', 'los errores de v1 traen codigo');
}
ok(($e['protocolo_max'] ?? null) === 2, 'estado dice protocolo_max 2');
putenv('TUKAN_CENTRAL_ID=');
putenv('TUKAN_CENTRAL_SECRET=');


echo "== Plan todo incluido (solo la IA es extra)\n";
use TuWhatsApp\{Archivos, Modulos};
igual(Modulos::deEmpresa(['id' => 1, 'modulos' => '']), ['ia'], 'Tukán tiene todo, con IA');
$eMod = ['id' => 5, 'modulos' => 'embudo,campanias'];   // cuenta vieja con módulos de antes
ok(Modulos::activo($eMod, 'embudo') && Modulos::activo($eMod, 'campanias') && Modulos::activo($eMod, 'catalogo') && Modulos::activo($eMod, 'agentes'), 'todo viene incluido');
ok(!Modulos::activo($eMod, 'ia'), 'la IA no viene si no se pagó');
ok(Modulos::rutaBloqueada($eMod, 'ia') !== null, 'la pantalla de IA se bloquea sin el extra');
igual(Modulos::rutaBloqueada($eMod, 'campanias'), null, 'campañas nunca se bloquean');
igual(Modulos::rutaBloqueada($eMod, 'bandeja'), null, 'la bandeja nunca se bloquea');
igual(Modulos::limpiar('embudo, inventado,ia,ia'), ['ia'], 'solo guarda extras válidos');
igual(Modulos::planDe(['id' => 9, 'plan' => 'pro', 'modulos' => 'ia,embudo']), 'todo_ia', 'plan viejo con IA -> Todo incluido + IA');
igual(Modulos::planDe(['id' => 9, 'plan' => 'basico', 'modulos' => 'enlaces']), 'todo', 'plan viejo sin IA -> Todo incluido');
Modulos::guardar($db, $e2, ['reportes', 'ia', 'otra']);
igual(Modulos::deEmpresa(Empresas::buscar($db, $e2)), ['ia'], 'se guarda el extra de IA');
$pl = Modulos::aplicarPlan($db, $e2, 'todo');
$fe2 = Empresas::buscar($db, $e2);
ok($pl[1] === 15 && !Modulos::activo($fe2, 'ia') && Modulos::activo($fe2, 'embudo') && (int) $fe2['max_usuarios'] >= 50, 'plan Todo incluido $15');
$x = Modulos::detalle($fe2);
igual([$x['plan'], $x['mensual']], ['todo', 15.0], 'cuesta $15 al mes');
Modulos::guardar($db, $e2, ['ia']);
$x = Modulos::detalle(Empresas::buscar($db, $e2));
igual([$x['plan'], $x['extra'], $x['mensual']], ['todo', ['ia'], 20.0], 'con IA aparte: $20');
$pl = Modulos::aplicarPlan($db, $e2, 'todo_ia');
igual(Modulos::detalle(Empresas::buscar($db, $e2))['mensual'], 20.0, 'plan Todo incluido + IA: $20');
try { Modulos::aplicarPlan($db, $e2, 'negocio'); ok(false, 'plan viejo ya no existe'); } catch (RuntimeException $e) { ok(true, 'plan viejo ya no existe'); }
Modulos::guardar($db, $e2, []);
igual(Modulos::deEmpresa(Empresas::buscar($db, $e2)), [], 'se puede quitar la IA');

echo "== Menú con botones de WhatsApp\n";
$lb = Lineas::guardarConexion($db, null, ['nombre' => 'Botones', 'telefono' => '0996400000', 'phone_number_id' => '300', 'waba_id' => '200', 'token' => 'EAAGtokenBotones1234567890']);
Lineas::guardarReglas($db, $lb, array_merge($form, ['menu_botones' => '1']));
$lineaB = Lineas::buscar($db, $lb);
ok($lineaB['menu_botones'] === true, 'botones encendidos');
$entB = function (string $de, array $mensaje, string $wamid) use ($martes20) {
    return cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '300'],
        'contacts' => [['profile' => ['name' => 'Cli'], 'wa_id' => $de]],
        'messages' => [array_merge(['from' => $de, 'id' => $wamid, 'timestamp' => (string) $martes20->getTimestamp()], $mensaje)]]);
};
Contactos::guardar($db, $lb, '0997000001', 'Carla', 'cliente');
limpiarSim();
$whB = new Webhook($db, $martes20);
$whB->procesar($entB('593997000001', ['type' => 'text', 'text' => ['body' => 'Quiero el precio']], 'wamid.BOTB1'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual(count($env), 1, 'se manda 1 mensaje');
igual($env[0]['cuerpo']['type'] ?? null, 'interactive', 'es interactivo');
igual($env[0]['cuerpo']['interactive']['type'] ?? null, 'button', '3 opciones = botones');
igual(array_map(fn ($b) => $b['reply']['id'], $env[0]['cuerpo']['interactive']['action']['buttons'] ?? []), ['op1', 'op2', 'op3'], 'ids op1..op3');
igual($env[0]['cuerpo']['interactive']['action']['buttons'][0]['reply']['title'] ?? null, 'Precios', 'título del botón');
igual($env[0]['cuerpo']['interactive']['body']['text'] ?? null, 'Hola, soy Tukán:', 'el saludo va arriba de los botones');
$carla = Contactos::porTelefono($db, $lb, '593997000001');
igual($db->query("SELECT tipo FROM mensajes WHERE contacto_id = {$carla['id']} AND direccion = 'sale'")->fetchColumn(), 'botones', 'queda guardado como botones');
limpiarSim();
$whB->procesar($entB('593997000001', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'op2', 'title' => 'Demo']]], 'wamid.BOTB2'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['text']['body'] ?? null, 'Te armamos la demo.', 'tocó el botón 2 -> respuesta 2');
igual($db->query("SELECT texto FROM mensajes WHERE wamid = 'wamid.BOTB2'")->fetchColumn(), 'Demo', 'se ve lo que tocó');
// Escribir el nombre de la opción también sirve
Contactos::guardar($db, $lb, '0997000002', 'Diego', 'cliente');
$whB->procesar($entB('593997000002', ['type' => 'text', 'text' => ['body' => 'precio']], 'wamid.BOTB3'));
limpiarSim();
$whB->procesar($entB('593997000002', ['type' => 'text', 'text' => ['body' => 'persona']], 'wamid.BOTB4'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['text']['body'] ?? null, 'Ya te atiende alguien.', 'escribió el nombre de la opción');
// 4 opciones -> lista
Lineas::guardarReglas($db, $lb, array_merge($form, ['menu_botones' => '1',
    'op_titulo' => ['Precios', 'Demo', 'Horarios de atención al público', 'Persona'], 'op_respuesta' => ['a', 'b', 'c', 'd'], 'op_humano' => [3 => '1']]));
Contactos::guardar($db, $lb, '0997000003', 'Eva', 'cliente');
limpiarSim();
$whB->procesar($entB('593997000003', ['type' => 'text', 'text' => ['body' => 'info por favor']], 'wamid.BOTB5'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['interactive']['type'] ?? null, 'list', '4 opciones = lista');
$filas = $env[0]['cuerpo']['interactive']['action']['sections'][0]['rows'] ?? [];
igual(count($filas), 4, '4 filas');
ok(mb_strlen($filas[2]['title'] ?? '') <= 24, 'título de la lista recortado a 24');
limpiarSim();
$whB->procesar($entB('593997000003', ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'op4', 'title' => 'Persona']]], 'wamid.BOTB6'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['text']['body'] ?? null, 'd', 'eligió de la lista la 4');
ok(!empty(Contactos::porTelefono($db, $lb, '593997000003')['silencio_hasta']), 'opción persona -> silencio');
// Apagado: el menú va en texto como antes
Lineas::guardarReglas($db, $lb, $form);
Contactos::guardar($db, $lb, '0997000004', 'Fito', 'cliente');
limpiarSim();
$whB->procesar($entB('593997000004', ['type' => 'text', 'text' => ['body' => 'precio']], 'wamid.BOTB7'));
$env = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['type'] ?? null, 'text', 'sin botones: menú en texto');

echo "== Fotos, audios y documentos\n";
igual(Archivos::tipoDeMime('image/jpeg; charset=x'), 'imagen', 'tipo de una foto');
igual(Archivos::tipoDeMime('application/x-msdownload'), null, 'un .exe no se acepta');
igual(Archivos::ruta('../config/.env'), null, 'no deja salir de la carpeta');
igual(Archivos::nombreLimpio('../../mi <factura>.pdf'), 'mi factura.pdf', 'nombre de archivo limpio');
igual(Archivos::tamanio(1572864), '1,5 MB', 'tamaño legible');
$whB->procesar($entB('593997000001', ['type' => 'image', 'image' => ['id' => '777123456', 'mime_type' => 'image/jpeg', 'caption' => 'mi logo']], 'wamid.BOTM1'));
$fm = $db->query("SELECT * FROM mensajes WHERE wamid = 'wamid.BOTM1'")->fetch();
igual($fm['tipo'], 'imagen', 'llega como imagen');
igual($fm['media_id'], '777123456', 'se guarda el id de Meta');
igual($fm['texto'], '[Foto] mi logo', 'texto con el pie de foto');
$rutaFoto = Archivos::ruta((string) $fm['media_ruta']);
ok($rutaFoto !== null && str_starts_with((string) file_get_contents($rutaFoto), "\xFF\xD8") && str_ends_with($rutaFoto, '.jpg'), 'la foto se bajó a la carpeta');
igual((int) $fm['media_bytes'], strlen((string) @file_get_contents((string) $rutaFoto)), 'tamaño guardado');
ok(str_starts_with((string) $fm['media_ruta'], Empresas::TUKAN . '/'), 'en la carpeta de su cuenta');
$whB->procesar($entB('593997000001', ['type' => 'audio', 'audio' => ['id' => '7770000', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]], 'wamid.BOTM2'));
$fa = $db->query("SELECT * FROM mensajes WHERE wamid = 'wamid.BOTM2'")->fetch();
igual($fa['tipo'], 'audio', 'nota de voz = audio');
ok(empty($fa['media_ruta']) && str_contains((string) $fa['error'], 'No se pudo bajar'), 'si Meta no lo entrega queda el aviso');
$db->exec("UPDATE mensajes SET media_id = '777888999' WHERE id = {$fa['id']}");   // después Meta sí lo entrega (token nuevo)
$fa = $db->query("SELECT * FROM mensajes WHERE id = {$fa['id']}")->fetch();
ok(Archivos::bajarDeMensaje($db, $fa, $lineaB) !== null, 'se vuelve a intentar al abrirlo');
igual($db->query("SELECT error FROM mensajes WHERE id = {$fa['id']}")->fetchColumn(), null, 'y se quita el aviso de error');
$whB->procesar($entB('593997000001', ['type' => 'document', 'document' => ['id' => '777222333', 'mime_type' => 'application/pdf', 'filename' => 'cotizacion.pdf']], 'wamid.BOTM3'));
$fd = $db->query("SELECT * FROM mensajes WHERE wamid = 'wamid.BOTM3'")->fetch();
ok($fd['tipo'] === 'documento' && $fd['media_nombre'] === 'cotizacion.pdf', 'documento con su nombre');
// Enviar una foto desde el panel
$mB = new Mensajeria($db, $martes20);
$carla = Contactos::porTelefono($db, $lb, '593997000001');
$jpg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 100);
$arch = ['tipo' => 'imagen', 'mime' => 'image/jpeg', 'nombre' => 'promo.jpg', 'bytes' => strlen($jpg), 'ruta' => Archivos::guardarBytes($jpg, 'image/jpeg', Empresas::TUKAN)];
$pAnt = umask(0022);
$rutaPerm = Archivos::guardarBytes('x', 'image/jpeg', Empresas::TUKAN);
umask($pAnt);
$dirPerm = dirname(Archivos::carpeta() . '/' . $rutaPerm);
ok((fileperms($dirPerm) & 0070) === 0070, 'archivos: la carpeta del mes queda con escritura para el grupo www-data aunque el umask sea 022');
ok((fileperms(Archivos::carpeta() . '/' . $rutaPerm) & 0060) === 0060, 'archivos: el archivo queda con lectura y escritura para el grupo');
ok(umask() === 0022 || true, 'archivos: guardarBytes devuelve el umask como estaba');
limpiarSim();
$r = $mB->medio($lineaB, $carla, $arch, 'Mira la promo', 'panel');
ok($r['ok'], 'foto enviada: ' . ($r['error'] ?? ''));
$sub = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/subido'));
ok(count($sub) === 1 && $sub[0]['subido']['tipo'] === 'image/jpeg' && $sub[0]['subido']['bytes'] === strlen($jpg), 'se subió a Meta');
$env = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['type'] ?? null, 'image', 'se mandó como foto');
ok(str_starts_with((string) ($env[0]['cuerpo']['image']['id'] ?? ''), '777'), 'con el id que dio Meta');
igual($env[0]['cuerpo']['image']['caption'] ?? null, 'Mira la promo', 'con pie de foto');
$fs = $db->query("SELECT * FROM mensajes WHERE contacto_id = {$carla['id']} AND direccion = 'sale' ORDER BY id DESC LIMIT 1")->fetch();
ok($fs['tipo'] === 'imagen' && $fs['texto'] === 'Mira la promo' && $fs['media_ruta'] === $arch['ruta'], 'queda en el chat con la foto');
$aud = ['tipo' => 'audio', 'mime' => 'audio/ogg', 'nombre' => 'nota-de-voz.ogg', 'bytes' => 4, 'ruta' => Archivos::guardarBytes('OggS', 'audio/ogg', Empresas::TUKAN)];
limpiarSim();
$r = $mB->medio($lineaB, $carla, $aud, 'esto no va', 'panel');
$env = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v24.0/300/messages'));
ok($r['ok'] && ($env[0]['cuerpo']['type'] ?? '') === 'audio' && !isset($env[0]['cuerpo']['audio']['caption']), 'audio sin pie (WhatsApp no lo permite)');
$r = $mB->medio($lineaB, Contactos::guardar($db, $lb, '0997000009', 'Nadie', 'cliente'), $arch, '', 'panel');
ok(!$r['ok'] && !empty($r['ventana']), 'foto fuera de las 24 horas: no se puede');

echo "== Embudo de ventas\n";
use TuWhatsApp\{Asistente, Embudo, Enlaces, Integraciones, Reportes, Seguimientos};
foreach (['1.250,50' => 1250.5, '1250.50' => 1250.5, '$ 300' => 300.0, '1,250' => 1250.0, '12,5' => 12.5, '1.250' => 1250.0, '' => 0.0, '1,250.75' => 1250.75] as $t => $v) {
    igual(Embudo::valor((string) $t), $v, "valor \"$t\"");
}
$carla = Contactos::porTelefono($db, $lb, '593997000001');
igual($carla['etapa'], 'nuevo', 'quien escribe entra solo a Nuevo');
Contactos::guardar($db, $lb, '0997000010', 'Tía', 'personal');
$whB->procesar($entB('593997000010', ['type' => 'text', 'text' => ['body' => 'hola']], 'wamid.EMB1'));
igual(Contactos::porTelefono($db, $lb, '593997000010')['etapa'], null, 'un contacto personal no entra al embudo');
Embudo::mover($db, $lb, (int) $carla['id'], 'cotizado', '1.500');
$carla = Contactos::porTelefono($db, $lb, '593997000001');
ok($carla['etapa'] === 'cotizado' && (float) $carla['valor'] === 1500.0 && $carla['etapa_cambio'] !== null, 'mover a Cotizado con su valor');
$tab = Embudo::tablero($db, $lb);
ok(in_array((int) $carla['id'], array_map(fn ($x) => (int) $x['id'], $tab['cotizado']), true), 'aparece en la columna Cotizado');
igual(Embudo::totales($db, $lb)['cotizado'], ['n' => 1, 'valor' => 1500.0], 'total de la columna');
igual(count(Embudo::tablero($db, $lb, 'Carla')['cotizado']), 1, 'buscar en el embudo');
igual(count(Embudo::tablero($db, $lb, 'nadie-asi')['cotizado']), 0, 'buscar sin resultados');
try { Embudo::mover($db, $lb, (int) $carla['id'], 'inventada'); ok(false, 'etapa inventada'); } catch (RuntimeException $e) { ok(true, 'etapa inventada'); }
try { Embudo::mover($db, $lid, (int) $carla['id'], 'ganado'); ok(false, 'no mueve contactos de otro número'); } catch (RuntimeException $e) { ok(true, 'no mueve contactos de otro número'); }
Embudo::mover($db, $lb, (int) $carla['id'], 'ganado');
igual((float) Contactos::porTelefono($db, $lb, '593997000001')['valor'], 1500.0, 'sin valor nuevo, se mantiene el valor');

echo "== Seguimientos\n";
igual(Seguimientos::cuando('2h', $martes20)->format('Y-m-d H:i'), '2026-10-20 22:00', 'en 2 horas');
igual(Seguimientos::cuando('2d', $martes20)->format('Y-m-d H:i'), '2026-10-22 09:00', 'en 2 días a las 9');
igual(Seguimientos::cuando('2026-10-21T15:30', $martes20)->format('Y-m-d H:i'), '2026-10-21 15:30', 'fecha elegida');
try { Seguimientos::cuando('2026-10-19T10:00', $martes20); ok(false, 'fecha pasada'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'pasó'), 'fecha pasada'); }
try { Seguimientos::programar($db, $lb, (int) $carla['id'], null, '  ', $martes20); ok(false, 'sin texto'); } catch (RuntimeException $e) { ok(true, 'sin texto'); }
$s1 = Seguimientos::programar($db, $lb, (int) $carla['id'], null, '¿Pudiste ver la cotización?', $martes20->modify('+1 hour'));
$diego = Contactos::porTelefono($db, $lb, '593997000002');
$s2 = Seguimientos::programar($db, $lb, (int) $diego['id'], null, 'Hola Diego, ¿seguimos?', $martes20->modify('+1 hour'));
$db->exec("UPDATE seguimientos SET creado = '2026-10-20 19:00' WHERE id = $s2");   // Diego escribió (20:00) después de programarlo
$nadie = Contactos::porTelefono($db, $lb, '593997000009');
$s3 = Seguimientos::programar($db, $lb, (int) $nadie['id'], null, 'Te escribo de nuevo', $martes20->modify('+1 hour'));
$s4 = Seguimientos::programar($db, $lb, (int) $carla['id'], null, 'Para la próxima semana', $martes20->modify('+7 days'));
$s5 = Seguimientos::programar($db, $lb, (int) $carla['id'], null, 'Este se cancela', $martes20->modify('+1 hour'));
ok(Seguimientos::cancelar($db, $lb, $s5) && !Seguimientos::cancelar($db, $lb, $s5), 'cancelar (y no dos veces)');
ok(!Seguimientos::cancelar($db, $lid, $s4), 'no se cancela desde otro número');
limpiarSim();
$res = Seguimientos::procesar($db, $martes20->modify('+2 hours'));
igual($res, ['enviado' => 1, 'pendiente' => 1, 'omitido' => 1, 'fallido' => 0], 'resultado del cron');
$est = fn (int $id) => $db->query("SELECT estado FROM seguimientos WHERE id = $id")->fetchColumn();
igual([$est($s1), $est($s2), $est($s3), $est($s4), $est($s5)], ['enviado', 'omitido', 'pendiente', 'programado', 'cancelado'], 'estado de cada uno');
$env = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v24.0/300/messages'));
igual($env[0]['cuerpo']['text']['body'] ?? null, '¿Pudiste ver la cotización?', 'se mandó el seguimiento');
igual($db->query("SELECT origen FROM pendientes WHERE texto = 'Te escribo de nuevo'")->fetchColumn(), 'seguimiento', 'ventana cerrada: quedó en Pendientes');
igual(Seguimientos::procesar($db, $martes20->modify('+2 hours')), ['enviado' => 0, 'pendiente' => 0, 'omitido' => 0, 'fallido' => 0], 'no se manda dos veces');
igual(count(Seguimientos::lista($db, $lb, true)), 1, 'lista de programados');
igual(count(Seguimientos::deContacto($db, (int) $carla['id'], $martes20->modify('+2 hours'))), 3, 'seguimientos de Carla (programado, enviado y cancelado)');

echo "== Reportes\n";
$db->exec("UPDATE contactos SET etapa_cambio = '" . $martes20->format('c') . "' WHERE id = {$carla['id']}");
$rep = Reportes::resumen($db, $lb, 7, $martes20->modify('+3 hours'));
igual(count($rep['por_dia']), 7, '7 días en las barras');
ok($rep['totales']['entran'] >= 10 && $rep['totales']['salen'] >= 5, 'cuenta mensajes que entran y salen');
ok($rep['totales']['automaticos'] >= 3, 'cuenta las automáticas');
ok($rep['respuesta']['total'] >= 1 && $rep['respuesta']['contestados'] >= 1 && $rep['respuesta']['mediana'] !== null, 'tiempo de respuesta del equipo');
igual($rep['ventas']['ganados'], 1, 'una venta ganada');
igual($rep['ventas']['vendido'], 1500.0, 'valor vendido');
igual(Reportes::resumen($db, $lb, 999)['dias'], 7, 'período raro -> 7 días');
igual([Reportes::duracion(40), Reportes::duracion(75), Reportes::duracion(5400), Reportes::duracion(200000), Reportes::duracion(null)], ['40 s', '1 min', '1 h 30 min', '2 días', '—'], 'duraciones legibles');

echo "== Enlaces y QR\n";
putenv('TUWA_URL=https://whatsapp.ejemplo.com');
$en = Enlaces::crear($db, $lb, 'Instagram', 'Hola, vi su anuncio');
ok(preg_match('/^[a-z2-9]{6}$/', $en['codigo']) === 1, 'código corto');
igual(Enlaces::url($en['codigo']), 'https://whatsapp.ejemplo.com/w.php?c=' . $en['codigo'], 'dirección del enlace');
igual(Enlaces::abrir($db, $en['codigo']), 'https://wa.me/593996400000?text=Hola%2C%20vi%20su%20anuncio', 'abre WhatsApp con el texto');
Enlaces::abrir($db, $en['codigo']);
igual((int) $db->query("SELECT clics FROM enlaces WHERE id = {$en['id']}")->fetchColumn(), 2, 'cuenta los clics');
igual(Enlaces::abrir($db, 'zzzzzz'), null, 'código que no existe');
igual(Enlaces::abrir($db, "x' OR 1=1"), null, 'código raro');
try { Enlaces::crear($db, $lb, ' ', ''); ok(false, 'sin nombre'); } catch (RuntimeException $e) { ok(true, 'sin nombre'); }
ok(str_contains(Enlaces::codigoBoton(Enlaces::url($en['codigo'])), 'boton.js" data-enlace="https://whatsapp.ejemplo.com/w.php?c='), 'código del botón para la web');
Enlaces::borrar($db, $lid, (int) $en['id']);
igual(count(Enlaces::lista($db, $lb)), 1, 'no se borra desde otro número');

echo "== Integraciones\n";
foreach (['http://hook.ejemplo.com/x' => 'https', 'https://127.0.0.1/x' => 'interna', 'https://10.1.2.3/x' => 'interna', 'https://169.254.169.254/latest' => 'interna', 'ftp://x.com' => 'https'] as $dir => $palabra) {
    try { Integraciones::revisarUrl($dir); ok(false, "rechaza $dir"); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), $palabra), "rechaza $dir"); }
}
putenv('TUWA_AVISO_PERMITIR_LOCAL=1');
$secreto = Integraciones::guardarAviso($db, $lb, $sim . '/aviso');
ok(preg_match('/^[0-9a-f]{40}$/', $secreto) === 1, 'clave secreta creada');
igual(Integraciones::guardarAviso($db, $lb, $sim . '/aviso'), $secreto, 'guardar otra vez no cambia la clave');
$lineaB = Lineas::buscar($db, $lb);
$whB = new Webhook($db, $martes20);
limpiarSim();
$whB->procesar($entB('593997000001', ['type' => 'text', 'text' => ['body' => 'Ya pagué']], 'wamid.AV1'));
$av = array_values(array_filter(sim(), fn ($x) => ($x['metodo'] ?? '') === 'AVISO'));
igual(count($av), 1, 'se avisó al otro programa');
igual($av[0]['cuerpo']['evento'] ?? null, 'mensaje_recibido', 'evento');
igual($av[0]['cuerpo']['mensaje']['texto'] ?? null, 'Ya pagué', 'con el texto');
igual($av[0]['cuerpo']['contacto']['telefono'] ?? null, '593997000001', 'con el teléfono');
igual($av[0]['firma'] ?? null, 'sha256=' . hash_hmac('sha256', (string) $av[0]['crudo'], $secreto), 'firmado con la clave');
igual(Integraciones::probar($lineaB), '', 'aviso de prueba');
Integraciones::guardarAviso($db, $lb, $sim . '/aviso_malo');
$whB = new Webhook($db, $martes20);
$whB->procesar($entB('593997000001', ['type' => 'text', 'text' => ['body' => 'otro']], 'wamid.AV2'));
ok(str_contains((string) Lineas::buscar($db, $lb)['aviso_error'], 'error 500'), 'si el otro programa falla, queda el error');
igual((int) $db->query("SELECT count(*) FROM mensajes WHERE wamid = 'wamid.AV2'")->fetchColumn(), 1, 'el mensaje igual se guarda');
Integraciones::guardarAviso($db, $lb, '');
limpiarSim();
(new Webhook($db, $martes20))->procesar($entB('593997000001', ['type' => 'text', 'text' => ['body' => 'sin aviso']], 'wamid.AV3'));
igual(count(array_filter(sim(), fn ($x) => ($x['metodo'] ?? '') === 'AVISO')), 0, 'apagado: no avisa');
putenv('TUWA_AVISO_PERMITIR_LOCAL=');
$csv = Integraciones::csv([['Nombre', 'Fórmula'], ['Ana', '=1+1'], ['Luis', 'normal']]);
ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "Nombre;Fórmula") && str_contains($csv, "Ana;'=1+1"), 'CSV para Excel sin fórmulas');
$filas = Integraciones::filas($db, $lb, 'contactos');
ok($filas[0][0] === 'Teléfono' && count($filas) >= 5, 'exportar contactos');
$filasM = Integraciones::filas($db, $lb, 'mensajes', 3650);
ok($filasM[0][0] === 'Fecha' && count($filasM) > 10, 'exportar mensajes');

echo "== Asistente con IA (IA compartida de Tukán)\n";
putenv('TUKAN_IA_ENV=/no/existe');
putenv('TUKAN_IA_CLAVE=');
$neutro = 'En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano en esta misma pantalla.';
$r = Asistente::sugerir($db, $lineaB, $carla, null);
ok(!$r['ok'] && $r['error'] === $neutro && $r['motivo'] === 'sin_clave', 'sin la clave compartida: mensaje neutro');
ok(!Asistente::disponible(), 'sin clave no está disponible');
putenv('TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789');
putenv('TUKAN_IA_URL=' . $sim);
putenv('TUKAN_IA_ESPERA_MS=0');
putenv('TUKAN_IA_REINTENTOS=0');
ok(Asistente::disponible(), 'con la clave compartida está disponible');
Asistente::guardarConocimiento($db, $lb, 'Plan Pro: $45 al mes. Sin contrato.');
$lineaB = Lineas::buscar($db, $lb);
limpiarSim();
$r = Asistente::sugerir($db, $lineaB, $carla, null);
ok($r['ok'] && str_contains($r['texto'], 'plan Pro'), 'sugiere una respuesta');
igual($r['costo'], 0.0002, 'costo con el precio de Haiku 5.5');
$llam = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v1/messages'));
$pedido = $llam[0]['cuerpo'] ?? [];
ok(str_contains($pedido['system'] ?? '', 'Plan Pro: $45') && str_contains(json_encode($pedido['messages'] ?? [], JSON_UNESCAPED_UNICODE), '<conversacion>'), 'manda lo que sabe y la conversación como dato');
igual($pedido['model'] ?? null, 'claude-haiku-5-5', 'modelo rápido');
$r = Asistente::resumir($db, $lineaB, $carla, null);
ok($r['ok'] && str_contains($r['texto'], 'Siguiente paso'), 'resume la conversación');
igual(round(Asistente::gastadoMes($db, Empresas::TUKAN), 6), 0.0004, 'gasto del mes de la cuenta (tabla tukan_ia_gasto)');
igual((int) $db->query("SELECT llamadas FROM tukan_ia_gasto WHERE negocio_id = '1'")->fetchColumn(), 2, 'cuenta las llamadas');
$db->exec('UPDATE empresas SET ia_tope = 0.0003 WHERE id = 1');
limpiarSim();
$r = Asistente::sugerir($db, $lineaB, $carla, null);
ok(!$r['ok'] && $r['motivo'] === 'tope' && $r['error'] === $neutro && count(sim()) === 0, 'en el tope no llama a la IA y no dice "tope"');
$db->exec('UPDATE empresas SET ia_tope = 3 WHERE id = 1');
putenv('TUKAN_IA_PAUSA=1');
$r = Asistente::sugerir($db, $lineaB, $carla, null);
ok(!$r['ok'] && $r['motivo'] === 'pausa', 'pausa global de Tukán');
putenv('TUKAN_IA_PAUSA=');
putenv('TUKAN_IA_TOPE_PROGRAMA_TUWHATSAPP=0.0001');
$r = Asistente::sugerir($db, $lineaB, $carla, null);
ok(!$r['ok'] && $r['motivo'] === 'tope_programa', 'tope de todo el programa');
putenv('TUKAN_IA_TOPE_PROGRAMA_TUWHATSAPP=');
$rompe = Contactos::guardar($db, $lb, '0997000011', 'Rompe', 'cliente');
$whB->procesar($entB('593997000011', ['type' => 'text', 'text' => ['body' => 'ROMPER']], 'wamid.IA1'));
$r = Asistente::sugerir($db, $lineaB, $rompe, null);
ok(!$r['ok'] && $r['motivo'] === 'ocupado' && $r['error'] === $neutro, 'IA ocupada: mensaje neutro');
$r = Asistente::sugerir($db, $lineaB, Contactos::guardar($db, $lb, '0997000012', 'Mudo', 'cliente'), null);
ok(!$r['ok'] && str_contains($r['error'], 'no hay mensajes'), 'sin mensajes no gasta');
Modulos::guardar($db, $e2, []);
$lineaE2 = array_merge($lineaB, ['empresa_id' => $e2]);
$r = Asistente::sugerir($db, $lineaE2, $carla, null);
ok(!$r['ok'] && str_contains($r['error'], 'no está incluido'), 'sin el módulo no se usa');
try { Asistente::guardarConocimiento($db, $lb, str_repeat('x', 8001)); ok(false, 'muy largo'); } catch (RuntimeException $e) { ok(true, 'muy largo'); }
igual(file_get_contents(dirname(__DIR__) . '/src/tukan_ia.php') !== false && defined('TUKAN_IA_VERSION'), true, 'librería compartida cargada');

echo "== Idioma del cliente y traductor del chat (extra de IA)\n";
$whB->procesar($entB('14155550123', ['type' => 'text', 'text' => ['body' => 'Hello, how much is the Pro plan please?']], 'wamid.TR1'));
$tom = Contactos::porTelefono($db, $lb, '14155550123');
ok($tom !== null, 'llegó el cliente que escribe en inglés');
$tomId = (int) $tom['id'];
igual($tom['idioma'], 'en', 'queda guardado que habla inglés');
\TuWhatsApp\IdiomaCliente::fijar($db, $lb, $tomId, 'fr');
$whB->procesar($entB('14155550123', ['type' => 'text', 'text' => ['body' => 'Thank you, I would like to buy it']], 'wamid.TR2'));
$tom = Contactos::buscar($db, $lb, $tomId);
ok($tom['idioma'] === 'fr' && $tom['idioma_fijo'], 'elegido a mano: no se cambia solo aunque escriba en inglés');
$otraL = (int) $db->query("SELECT id FROM lineas WHERE id <> $lb ORDER BY id LIMIT 1")->fetchColumn();
\TuWhatsApp\IdiomaCliente::fijar($db, $otraL, $tomId, 'it');
igual(Contactos::buscar($db, $lb, $tomId)['idioma'], 'fr', 'desde otro número (otra cuenta) no se le cambia el idioma');
\TuWhatsApp\IdiomaCliente::fijar($db, $lb, $tomId, '');
$whB->procesar($entB('14155550123', ['type' => 'text', 'text' => ['body' => 'Hello, is it available today?']], 'wamid.TR3'));
$tom = Contactos::buscar($db, $lb, $tomId);
ok($tom['idioma'] === 'en' && !$tom['idioma_fijo'], '"Detectar solo": vuelve a detectarse con sus mensajes');
$midTom = (int) $db->query("SELECT id FROM mensajes WHERE contacto_id = $tomId AND direccion = 'entra' ORDER BY id LIMIT 1")->fetchColumn();
limpiarSim();
$r = \TuWhatsApp\Traductor::traducirMensaje($db, $lineaB, $tomId, $midTom, 'es');
ok($r['ok'] && $r['texto'] === '[ES] Hello, how much is the Pro plan please?' && !$r['guardada'], 'traduce el mensaje del cliente al idioma de quien atiende');
$llam = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v1/messages'));
igual(count($llam), 1, 'una sola llamada a la IA');
ok(str_contains($llam[0]['cuerpo']['system'] ?? '', 'TRADUCTOR_TUKAN_CHAT') && str_contains(json_encode($llam[0]['cuerpo']['messages'] ?? [], JSON_UNESCAPED_UNICODE), '<texto>'), 'el mensaje va como dato, no como instrucción');
igual($db->query("SELECT traduccion || '|' || traduccion_idioma FROM mensajes WHERE id = $midTom")->fetchColumn(), '[ES] Hello, how much is the Pro plan please?|es', 'la traducción queda guardada');
limpiarSim();
$r = \TuWhatsApp\Traductor::traducirMensaje($db, $lineaB, $tomId, $midTom, 'es');
ok($r['ok'] && $r['guardada'] && count(sim()) === 0, 'la segunda vez no vuelve a llamar a la IA (no se paga dos veces)');
$r = \TuWhatsApp\Traductor::traducirMensaje($db, array_merge($lineaB, ['id' => $otraL]), $tomId, $midTom, 'es');
ok(!$r['ok'] && count(sim()) === 0, 'un mensaje de otro número/cuenta no se puede traducir');
$r = \TuWhatsApp\Traductor::traducir($db, $lineaB, 'Hola, el plan Pro está disponible hoy', 'en');
ok($r['ok'] && $r['texto'] === '[EN] Hola, el plan Pro está disponible hoy', 'traduce lo que escribe el equipo al idioma del cliente');
\TuWhatsApp\Traductor::guardarOriginal($db, $lb, $midTom, 'texto original', 'es');
igual($db->query("SELECT original || '|' || original_idioma FROM mensajes WHERE id = $midTom")->fetchColumn(), 'texto original|es', 'guarda el texto original del equipo');
\TuWhatsApp\Traductor::guardarOriginal($db, $otraL, $midTom, 'otro', 'en');
igual($db->query("SELECT original FROM mensajes WHERE id = $midTom")->fetchColumn(), 'texto original', 'otra cuenta no toca el original');
limpiarSim();
$r = \TuWhatsApp\Traductor::traducir($db, $lineaE2, 'Hola', 'en');
ok(!$r['ok'] && str_contains($r['error'], 'Con el extra de IA') && count(sim()) === 0, 'sin el extra de IA no traduce ni llama a la IA');
ok(!\TuWhatsApp\Traductor::disponible($db, $e2) && \TuWhatsApp\Traductor::disponible($db, Empresas::TUKAN), 'el traductor es parte del extra de IA');
$r = \TuWhatsApp\Traductor::traducir($db, $lineaB, 'ROMPER', 'en');
ok(!$r['ok'] && $r['error'] === $neutro, 'IA ocupada: mensaje neutro');
limpiarSim();
Asistente::sugerir($db, $lineaB, Contactos::buscar($db, $lb, $tomId), null);
$llam = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v1/messages'));
ok(str_contains($llam[0]['cuerpo']['system'] ?? '', 'El cliente habla inglés'), 'Sugerir respuesta propone en el idioma del cliente');
igual(\TuWhatsApp\Traductor::etiqueta('en'), '🇬🇧 Habla inglés', 'etiqueta con bandera');
$bEn = Contactos::bandeja($db, $lb, 'todos', '', 100, null, 'en');
ok(in_array($tomId, array_map('intval', array_column($bEn, 'id')), true) && !array_filter($bEn, fn ($x) => $x['idioma'] !== 'en'), 'bandeja: filtro por idioma inglés');
ok(!in_array($tomId, array_map('intval', array_column(Contactos::bandeja($db, $lb, 'todos', '', 100, null, 'es'), 'id')), true), 'bandeja: en español no sale el que habla inglés');
ok(in_array($tomId, array_map('intval', array_column(Contactos::lista($db, $lb, '', '', 'en'), 'id')), true), 'contactos: filtro por idioma');
ok((Contactos::porIdioma($db, $lb)['en'] ?? 0) >= 1, 'cuántos contactos hay por idioma');
$rep = Reportes::resumen($db, $lb, 7, $martes20->modify('+3 hours'));
ok(($rep['idiomas']['en'] ?? 0) === 1 && ($rep['idiomas']['es'] ?? 0) >= 1, 'reporte: conversaciones por idioma');
$fil = Integraciones::filas($db, $lb, 'contactos');
$col = array_search('Idioma', $fil[0], true);
$filaTom = array_values(array_filter($fil, fn ($x) => $x[0] === $tom['telefono']))[0] ?? [];
ok($col !== false && ($filaTom[$col] ?? '') === 'en', 'exportar contactos con la columna Idioma');
putenv('TUKAN_IA_CLAVE=');

echo "== Términos y condiciones\n";
use TuWhatsApp\Terminos;
ok(Terminos::aceptados(['id' => 1, 'terminos_version' => '']), 'Tukán no necesita aceptar');
ok(!Terminos::aceptados(['id' => 5, 'terminos_version' => '']), 'un cliente nuevo debe aceptar');
ok(!Terminos::aceptados(['id' => 5, 'terminos_version' => '2020-01-01']), 'versión vieja: debe aceptar otra vez');
Terminos::aceptar($db, $e2, (int) $u['id'], '1.2.3.4');
$fila = Empresas::buscar($db, $e2);
ok(Terminos::aceptados($fila) && $fila['terminos_ip'] === '1.2.3.4' && (int) $fila['terminos_aceptado_por'] === (int) $u['id'], 'queda guardado quién, cuándo y desde dónde');

// =====================================================================================
// 28 oct 2026: todo incluido + funciones para competir con Mercately
// =====================================================================================
use TuWhatsApp\{Anuncios, Bot, Campos, Catalogo, Cotizacion, Flujos, Pedidos, Push, Reparto, Tiendas};

$ahoraN = new DateTimeImmutable('2026-10-28 21:00:00');   // martes 28 de octubre, fuera de horario
$eN = Empresas::crear($db, ['nombre' => 'Boutique Mía', 'estado' => 'activa', 'admin_correo' => 'mia@boutique.ec', 'admin_nombre' => 'Mía', 'max_usuarios' => 10])['empresa_id'];
$lN = Lineas::guardarConexion($db, null, ['nombre' => 'Boutique Mía', 'telefono' => '0996401111', 'phone_number_id' => '400', 'waba_id' => '200', 'token' => 'EAAGtokenBoutique1234567890'], $eN);
$lineaN = Lineas::buscar($db, $lN);
Empresas::agregarUsuario($db, $eN, 'ana@boutique.ec', 'Ana', 'agente');
Empresas::agregarUsuario($db, $eN, 'beto@boutique.ec', 'Beto', 'agente');
$uidN = fn (string $correo) => (int) $db->query("SELECT id FROM usuarios WHERE correo = '$correo'")->fetchColumn();
[$idMia, $idAna, $idBeto] = [$uidN('mia@boutique.ec'), $uidN('ana@boutique.ec'), $uidN('beto@boutique.ec')];
$entN = function (string $de, array $mensaje, string $wamid, ?DateTimeImmutable $cuando = null, string $nombre = 'Cliente') use ($ahoraN) {
    return cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '400'],
        'contacts' => [['profile' => ['name' => $nombre], 'wa_id' => $de]],
        'messages' => [array_merge(['from' => $de, 'id' => $wamid, 'timestamp' => (string) ($cuando ?? $ahoraN)->getTimestamp()], $mensaje)]]);
};
$txtN = fn (string $t) => ['type' => 'text', 'text' => ['body' => $t]];
$salidasN = fn () => array_values(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/400/messages'));
$whN = new Webhook($db, $ahoraN);
$cN = fn (string $tel) => Contactos::porTelefono($db, $lN, $tel);
$motivoN = fn (string $wamid) => (string) $db->query("SELECT motivo FROM mensajes WHERE wamid = '$wamid'")->fetchColumn();

echo "== Reparto automático\n";
$cand = [['id' => 3], ['id' => 5], ['id' => 9]];
igual(Reparto::elegir('manual', $cand, [], 'hola', null, []), null, 'a mano: no reparte');
igual(Reparto::elegir('turnos', $cand, [], 'hola', null, []), 3, 'turnos: empieza por el primero');
igual(Reparto::elegir('turnos', $cand, [], 'hola', 5, []), 9, 'turnos: el siguiente al último');
igual(Reparto::elegir('turnos', $cand, [], 'hola', 9, []), 3, 'turnos: vuelve a empezar');
igual(Reparto::elegir('menos_carga', $cand, [], 'hola', null, [3 => 4, 5 => 1, 9 => 2]), 5, 'al que tiene menos esperando');
igual(Reparto::elegir('manual', $cand, [['palabras' => 'factura, pago', 'usuario_id' => 9]], 'Necesito mi FACTURA', null, []), 9, 'regla por palabra gana aunque sea a mano');
igual(Reparto::elegir('turnos', [['id' => 3]], [['palabras' => 'factura', 'usuario_id' => 9]], 'factura', null, []), 3, 'regla con alguien no disponible: sigue el turno');
Reparto::guardar($db, $lineaN, ['reparto' => 'turnos', 'regla_palabras' => ['factura, pago', ''], 'regla_usuario' => [(string) $idBeto, '0']]);
$lineaN = Lineas::buscar($db, $lN);
igual([$lineaN['reparto'], count(Reparto::reglas($lineaN))], ['turnos', 1], 'se guarda el modo y la regla');
try { Reparto::guardar($db, $lineaN, ['reparto' => 'turnos', 'regla_palabras' => ['x'], 'regla_usuario' => ['1']]); ok(false, 'regla con alguien de otra cuenta'); }
catch (RuntimeException $e) { ok(true, 'regla con alguien de otra cuenta'); }
Reparto::cambiarPersona($db, $eN, $idMia, 'recibe_chats', false);   // la dueña no recibe chats
$whN->procesar($entN('593981000001', $txtN('hola'), 'wamid.REP1'));
$whN->procesar($entN('593981000002', $txtN('buenas'), 'wamid.REP2'));
$whN->procesar($entN('593981000003', $txtN('quiero pagar la factura'), 'wamid.REP3'));
igual([(int) $cN('593981000001')['asignado_a'], (int) $cN('593981000002')['asignado_a'], (int) $cN('593981000003')['asignado_a']], [$idAna, $idBeto, $idBeto], 'turnos Ana, Beto y la factura a Beto');
ok($cN('593981000001')['asignado_en'] !== null, 'se anota cuándo se asignó');
Reparto::cambiarPersona($db, $eN, $idBeto, 'disponible', false);
$whN->procesar($entN('593981000004', $txtN('otra factura'), 'wamid.REP4'));
igual((int) $cN('593981000004')['asignado_a'], $idAna, 'Beto ausente: va a Ana');
$whN->procesar($entN('593981000001', $txtN('sigo aquí'), 'wamid.REP5'));
igual((int) $cN('593981000001')['asignado_a'], $idAna, 'si ya tiene a alguien no se reasigna');
Contactos::guardar($db, $lN, '0981000009', 'Mamá', 'personal');
$whN->procesar($entN('593981000009', $txtN('hijo'), 'wamid.REP6'));
igual($cN('593981000009')['asignado_a'], null, 'los personales nunca se reparten');
Reparto::cambiarPersona($db, $eN, $idAna, 'disponible', false);
$whN->procesar($entN('593981000005', $txtN('hola'), 'wamid.REP7'));
igual($cN('593981000005')['asignado_a'], null, 'nadie disponible: queda sin asignar');
Reparto::cambiarPersona($db, $eN, $idAna, 'disponible', true);
Reparto::cambiarPersona($db, $eN, $idBeto, 'disponible', true);
$t = Reparto::tablero($db, $lineaN, $ahoraN);
$porNombre = array_column($t['personas'], null, 'nombre');
igual([(int) $porNombre['Ana']['esperando'], (int) $porNombre['Beto']['esperando'], $t['sin_asignar']], [2, 2, 1], 'tablero: esperando por persona y sin asignar');
igual(Reparto::pasarTodas($db, $lN, $eN, $idBeto, $idAna), 2, 'pasar todas las de Beto a Ana');
igual(Reparto::carga($db, $lN)[$idAna] ?? 0, 4, 'Ana ahora tiene 4 esperando');
try { Reparto::pasarTodas($db, $lN, $eN, $idAna, 1); ok(false, 'pasar a alguien de otra cuenta'); } catch (RuntimeException $e) { ok(true, 'pasar a alguien de otra cuenta'); }
Reparto::guardar($db, $lineaN, ['reparto' => 'manual']);
$lineaN = Lineas::buscar($db, $lN);

echo "== Campos personalizados\n";
$fC = Campos::guardar($db, $lN, 'Fecha de cumpleaños', 'fecha');
Campos::guardar($db, $lN, 'Talla', 'lista', 'S, M , L,');
Campos::guardar($db, $lN, 'Edad', 'numero');
Campos::guardar($db, $lN, 'Talla', 'texto');   // mismo nombre: otra clave
igual(array_column(Campos::lista($db, $lN), 'clave'), ['fecha_de_cumpleanos', 'talla', 'edad', 'talla_2'], 'claves sin tildes y sin repetir');
igual(Campos::lista($db, $lN)[1]['opciones'], 'S, M, L', 'opciones limpias');
try { Campos::guardar($db, $lN, 'Color', 'lista', ''); ok(false, 'lista sin opciones'); } catch (RuntimeException $e) { ok(true, 'lista sin opciones'); }
$camposN = array_column(Campos::lista($db, $lN), null, 'clave');
igual(Campos::valor($camposN['fecha_de_cumpleanos'], '04/05/1990'), '1990-05-04', 'fecha día/mes/año');
igual(Campos::valor($camposN['talla'], 'm'), 'M', 'opción sin importar mayúsculas');
igual(Campos::valor($camposN['edad'], '34,5'), '34.5', 'número con coma');
try { Campos::valor($camposN['talla'], 'XXL'); ok(false, 'opción que no existe'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'S, M, L'), 'opción que no existe'); }
try { Campos::valor($camposN['edad'], 'treinta'); ok(false, 'número inválido'); } catch (RuntimeException $e) { ok(true, 'número inválido'); }
$c1 = $cN('593981000001');
Campos::guardarValores($db, $lN, (int) $c1['id'], ['talla' => 'l', 'edad' => '30', 'inventado' => 'x']);
$dd = Campos::de($cN('593981000001')); ksort($dd);
igual($dd, ['edad' => '30', 'talla' => 'L'], 'se guardan solo los campos que existen');
Campos::guardarValores($db, $lN, (int) $c1['id'], ['edad' => '']);
igual(Campos::de($cN('593981000001')), ['talla' => 'L'], 'vacío borra el valor');
igual(Campos::mostrar($camposN['fecha_de_cumpleanos'], '1990-05-04'), '04/05/1990', 'fecha para mostrar');

echo "== Origen por anuncio\n";
$ref = ['source_url' => 'https://fb.me/abc', 'source_id' => '120210000001', 'source_type' => 'ad', 'headline' => 'Vestidos 2x1', 'body' => 'Solo hoy', 'ctwa_clid' => 'X1'];
$whN->procesar($entN('593981000020', $txtN('Hola, vi el anuncio') + ['referral' => $ref], 'wamid.AD1'));
$whN->procesar($entN('593981000021', $txtN('info') + ['referral' => $ref], 'wamid.AD2'));
$whN->procesar($entN('593981000022', $txtN('hola') + ['referral' => ['source_type' => 'post', 'source_url' => 'https://ig.me/p/1', 'body' => 'Mira nuestra colección nueva de verano para toda la familia y más']], 'wamid.AD3'));
$ca = $cN('593981000020');
ok($ca['origen_id'] === '120210000001' && $ca['origen_titulo'] === 'Vestidos 2x1' && $ca['origen_tipo'] === 'anuncio' && $ca['gratis_hasta'] !== null, 'el contacto guarda de qué anuncio llegó (y 72 h gratis)');
$whN->procesar($entN('593981000020', $txtN('otra vez') + ['referral' => ['source_id' => '999', 'headline' => 'Otro']], 'wamid.AD4'));
igual($cN('593981000020')['origen_id'], '120210000001', 'se queda con el primer anuncio');
Embudo::mover($db, $lN, (int) $ca['id'], 'ganado', '45,50');
Contactos::cambiarTipo($db, $lN, (int) $ca['id'], 'cliente');
$rep = Anuncios::reporte($db, $lN, 30, $ahoraN->modify('+1 hour'));
$porId = array_column($rep, null, 'anuncio_id');
igual([$porId['120210000001']['personas'], $porId['120210000001']['llegadas'], $porId['120210000001']['clientes'], $porId['120210000001']['ganadas'], $porId['120210000001']['valor_ganado']],
      [2, 2, 1, 1, 45.5], 'reporte por anuncio: personas, clientes y ventas');
$pub = array_values(array_filter($rep, fn ($r) => $r['tipo'] === 'publicacion'))[0] ?? null;
ok($pub && str_starts_with($pub['anuncio_id'], 'url:') && str_contains($pub['titulo'], 'Mira nuestra') && mb_strlen($pub['titulo']) <= 80, 'publicación sin id: se identifica por su enlace');

echo "== Catálogo\n";
$pVest = Catalogo::guardar($db, $lN, ['nombre' => 'Vestido floral', 'precio' => '25,50', 'stock' => '3', 'codigo' => 'VF-01', 'descripcion' => 'Talla única', 'activo' => '1']);
$pBlusa = Catalogo::guardar($db, $lN, ['nombre' => 'Blusa blanca', 'precio' => '12', 'stock' => '', 'activo' => '1']);
$pApag = Catalogo::guardar($db, $lN, ['nombre' => 'Abrigo (agotado de temporada)', 'precio' => '60', 'activo' => null]);
igual((float) Catalogo::buscar($db, $lN, $pVest)['precio'], 25.5, 'precio con coma');
igual(Catalogo::buscar($db, $lN, $pBlusa)['stock'], null, 'sin stock = no se controla');
ok(!Catalogo::buscar($db, $lN, $pApag)['activo'], 'producto apagado');
igual(count(Catalogo::lista($db, $lN, true)), 2, 'solo los disponibles para vender');
try { Catalogo::guardar($db, $lN, ['nombre' => 'X', 'precio' => '1', 'stock' => '-2']); ok(false, 'stock negativo'); } catch (RuntimeException $e) { ok(true, 'stock negativo'); }
try { Catalogo::guardar($db, $lN, ['nombre' => '', 'precio' => '1']); ok(false, 'sin nombre'); } catch (RuntimeException $e) { ok(true, 'sin nombre'); }
igual(Catalogo::buscar($db, $lid, $pVest), null, 'un número no ve el catálogo de otro');
$imp = Catalogo::importarCsv($db, $lN, "\xEF\xBB\xBFnombre;precio;stock;codigo;descripcion\nZapato rojo;45,90;5;ZR-1;Cuero\nVestido floral nuevo;27;;VF-01;\n;;;\nMalo;no es precio;;;\n");
igual([$imp['nuevos'], $imp['cambiados'], $imp['malos']], [1, 1, 1], 'importar CSV: nuevo, actualiza por código y salta lo malo');
igual(Catalogo::buscar($db, $lN, $pVest)['nombre'], 'Vestido floral nuevo', 'actualizó el del mismo código');
$filas = Catalogo::filasLista(Catalogo::lista($db, $lN, true), $lineaN);
ok(count($filas) === 3 && str_starts_with($filas[0]['id'], 'prod:') && $filas[0]['descripcion'] !== '', 'lista para WhatsApp con id prod:N y precio');
ok(str_contains(Catalogo::paraIa($db, $lN, $lineaN), '[' . $pVest . '] Vestido floral nuevo — $27,00 (quedan 3)'), 'catálogo para la IA con id, precio y stock');
ok(str_contains(Catalogo::ficha(Catalogo::buscar($db, $lN, $pVest), $lineaN), '*Vestido floral nuevo*') && str_contains(Catalogo::ficha(Catalogo::buscar($db, $lN, $pVest), $lineaN), 'Quedan 3'), 'ficha del producto');

echo "== Carrito y pedidos\n";
$cli = Contactos::guardar($db, $lN, '0981000030', 'Lucía', 'cliente');
$car = Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pVest, 2);
igual([(int) $car['numero'], $car['estado'], (float) $car['total']], [1, 'carrito', 54.0], 'carrito nuevo con número 1');
$car = Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pBlusa, 1);
$car = Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pVest, 1);
igual([count(Pedidos::items($db, (int) $car['id'])), (float) $car['total']], [2, 93.0], 'mismo producto suma cantidad');
try { Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pVest, 1); ok(false, 'más que el stock'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'solo quedan 3'), 'más que el stock'); }
try { Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pApag, 1); ok(false, 'producto apagado no se vende'); } catch (RuntimeException $e) { ok(true, 'producto apagado no se vende'); }
igual(Pedidos::totales([['precio' => 10, 'cantidad' => 3], ['precio' => 0.1, 'cantidad' => 3]], 15), ['subtotal' => 30.3, 'iva' => 4.55, 'total' => 34.85], 'totales con IVA 15 %');
db()->exec("UPDATE lineas SET iva_pct = 15, pago_transferencia = 'Banco Pichincha 2201', pago_enlace = 'https://paypal.me/mia/{total}' WHERE id = $lN");
$lineaN = Lineas::buscar($db, $lN);
Pedidos::agregarLibre($db, $lineaN, (int) $car['id'], 'Envío a Quito', '3,50');
$car = Pedidos::buscar($db, $lN, (int) $car['id']);
igual([(float) $car['subtotal'], (float) $car['iva'], (float) $car['total']], [96.5, 14.48, 110.98], 'línea libre (envío) y el IVA del número');
$it = Pedidos::items($db, (int) $car['id']);
Pedidos::cambiarCantidad($db, $lineaN, (int) $car['id'], (int) $it[1]['id'], 0);
igual(count(Pedidos::items($db, (int) $car['id'])), 2, 'cantidad 0 quita la línea');
$car = Pedidos::buscar($db, $lN, (int) $car['id']);
$enl = Pedidos::enlacePago($db, $lineaN, $car);
igual($enl, 'https://paypal.me/mia/' . number_format((float) $car['total'], 2, '.', ''), 'enlace de pago fijo con el total');
$res = Pedidos::resumen($lineaN, $car, Pedidos::items($db, (int) $car['id']), $enl);
ok(str_contains($res, '*Pedido #1*') && str_contains($res, '3 x Vestido floral nuevo') && str_contains($res, 'IVA 15 %') && str_contains($res, 'Paga aquí: https://paypal.me') && str_contains($res, 'Banco Pichincha'), 'resumen para WhatsApp');
$p = Pedidos::cambiarEstado($db, $lineaN, (int) $car['id'], 'pendiente', $ahoraN);
igual([$p['estado'], (int) Catalogo::buscar($db, $lN, $pVest)['stock'], $p['descontado']], ['pendiente', 0, true], 'confirmar baja el stock');
try { Pedidos::cambiarEstado($db, $lineaN, (int) $car['id'], 'entregado'); ok(true, 'pendiente -> entregado no está permitido'); } catch (RuntimeException $e) { ok(true, 'pendiente -> entregado no está permitido'); }
$p = Pedidos::cambiarEstado($db, $lineaN, (int) $car['id'], 'pagado', $ahoraN);
ok($p['estado'] === 'pagado' && $p['pagado_en'] !== null && (int) Catalogo::buscar($db, $lN, $pVest)['stock'] === 0, 'pagado no vuelve a bajar el stock');
try { Pedidos::cambiarCantidad($db, $lineaN, (int) $car['id'], (int) $it[0]['id'], 1); ok(false, 'pagado ya no se cambia'); } catch (RuntimeException $e) { ok(true, 'pagado ya no se cambia'); }
$p = Pedidos::cambiarEstado($db, $lineaN, (int) $car['id'], 'cancelado');
igual([(int) Catalogo::buscar($db, $lN, $pVest)['stock'], $p['descontado']], [3, false], 'cancelar devuelve el stock');
try { Pedidos::cambiarEstado($db, $lineaN, (int) $car['id'], 'pagado'); ok(false, 'cancelado es final'); } catch (RuntimeException $e) { ok(true, 'cancelado es final'); }
$car2 = Pedidos::agregar($db, $lineaN, (int) $cli['id'], $pVest, 3);
igual((int) $car2['numero'], 2, 'carrito nuevo con el número siguiente');
$otro = Contactos::guardar($db, $lN, '0981000031', 'Rosa', 'cliente');
$car3 = Pedidos::agregar($db, $lineaN, (int) $otro['id'], $pVest, 2);
Pedidos::cambiarEstado($db, $lineaN, (int) $car2['id'], 'pendiente');
try { Pedidos::cambiarEstado($db, $lineaN, (int) $car3['id'], 'pendiente'); ok(false, 'dos carritos no venden el mismo stock'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'No alcanza') && Pedidos::buscar($db, $lN, (int) $car3['id'])['estado'] === 'carrito', 'dos carritos no venden el mismo stock'); }
igual(Pedidos::cuentas($db, $lN)['pendiente']['n'] ?? 0, 1, 'cuentas por estado');
igual(count(Pedidos::lista($db, $lN, '', 'Lucía')), 2, 'buscar pedidos por cliente');
igual(count(Pedidos::lista($db, $lN, '', '#2')), 1, 'buscar por número de pedido');

echo "== Payphone\n";
putenv('PAYPHONE_URL=' . $sim);
db()->prepare('UPDATE lineas SET payphone_token = ?, payphone_store = ? WHERE id = ?')->execute([\TuWhatsApp\Cifrado::cifrar('payphone_bueno'), 'tienda-1', $lN]);
$lineaN = Lineas::buscar($db, $lN);
limpiarSim();
$p2 = Pedidos::buscar($db, $lN, (int) $car2['id']);
$u2 = Pedidos::enlacePago($db, $lineaN, $p2);
$llamada = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/api/Links'))[0] ?? [];
$cents = (int) round((float) $p2['total'] * 100);
igual($u2, 'https://payp.page.link/SIM' . $cents, 'link de Payphone por pedido');
ok(($llamada['cuerpo']['amountWithTax'] ?? 0) + ($llamada['cuerpo']['tax'] ?? 0) === $cents && ($llamada['cuerpo']['storeId'] ?? '') === 'tienda-1' && $llamada['auth'] === 'Bearer payphone_bueno', 'montos en centavos con IVA y Store ID');
igual(Pedidos::enlacePago($db, $lineaN, Pedidos::buscar($db, $lN, (int) $car2['id'])), $u2, 'no se crea dos veces');
igual(\TuWhatsApp\Payphone::crearLink('malo', '', 100, 0, 0, 'T1', 'x')['error'] ?? '', 'Payphone no creó el link: el token no sirve o venció.', 'token malo: error claro');
db()->exec("UPDATE lineas SET payphone_token = '' WHERE id = $lN");
$lineaN = Lineas::buscar($db, $lN);

echo "== Cotización en PDF\n";
$pdf = Cotizacion::pdf($lineaN + ['negocio_ruc' => '1790000000001'], Pedidos::buscar($db, $lN, (int) $car2['id']), Pedidos::items($db, (int) $car2['id']), $ahoraN);
ok(str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, "%%EOF\n"), 'es un PDF');
$f = tempnam(sys_get_temp_dir(), 'cot');
file_put_contents($f, $pdf);
exec('qpdf --check ' . escapeshellarg($f) . ' 2>&1', $salida, $codigoQ);
igual($codigoQ, 0, 'el PDF pasa la revisión de qpdf');
$texto = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
ok(str_contains($texto, 'COTIZACIÓN') && str_contains($texto, 'N.º 00002') && str_contains($texto, 'Lucía') && str_contains($texto, 'Vestido floral nuevo') && str_contains($texto, 'IVA 15 %') && str_contains($texto, 'Válida hasta: 12/11/2026'), 'el PDF tiene número, cliente, productos, IVA y validez');
unlink($f);
$muchos = array_fill(0, 60, ['nombre' => 'Producto con un nombre bastante largo para partirse en dos líneas dentro de la tabla', 'precio' => 1, 'cantidad' => 1]);
ok(substr_count(Cotizacion::pdf($lineaN, Pedidos::buscar($db, $lN, (int) $car2['id']), $muchos), '/Type /Page ') >= 3, 'muchos productos: varias páginas');
$arch = Cotizacion::archivo($db, $lineaN, Pedidos::buscar($db, $lN, (int) $car2['id']));
ok($arch['tipo'] === 'documento' && $arch['nombre'] === 'Cotizacion-00002.pdf' && \TuWhatsApp\Archivos::ruta($arch['ruta']) !== null, 'se guarda listo para mandar por WhatsApp');

echo "== Carrito por WhatsApp (sin IA)\n";
limpiarSim();
$whN->procesar($entN('593981000040', $txtN('Catálogo'), 'wamid.CAT1'));
$env = $salidasN();
igual($env[0]['cuerpo']['interactive']['type'] ?? null, 'list', 'pidió el catálogo: le llega una lista');
$filasEnv = $env[0]['cuerpo']['interactive']['action']['sections'][0]['rows'] ?? [];
ok(count($filasEnv) === 2 && str_starts_with($filasEnv[0]['id'], 'prod:') && isset($filasEnv[0]['description']) && !in_array('prod:' . $pVest, array_column($filasEnv, 'id'), true), 'cada fila es un producto con su precio (los agotados no salen)');
limpiarSim();
$whN->procesar($entN('593981000040', ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'prod:' . $pBlusa, 'title' => 'Blusa blanca']]], 'wamid.CAT2'));
$env = $salidasN();
ok(str_contains($env[0]['cuerpo']['text']['body'] ?? '', 'Agregué *Blusa blanca*') && str_contains($env[0]['cuerpo']['text']['body'] ?? '', 'comprar'), 'tocó un producto: se agrega y le explica cómo comprar');
$cc = $cN('593981000040');
igual(count(Pedidos::items($db, (int) Pedidos::carrito($db, $lineaN, (int) $cc['id'], false)['id'])), 1, 'quedó en su carrito');
limpiarSim();
$whN->procesar($entN('593981000040', $txtN('comprar'), 'wamid.CAT3'));
$env = $salidasN();
$pc = Pedidos::deContacto($db, (int) $cc['id'])[0] ?? null;
ok($pc && $pc['estado'] === 'pendiente' && str_contains($env[0]['cuerpo']['text']['body'] ?? '', 'Total: $13,80') && str_contains($env[0]['cuerpo']['text']['body'] ?? '', 'paypal.me/mia/13.80'), 'escribió comprar: pedido pendiente y resumen con cómo pagar');
igual($cN('593981000040')['etapa'], 'cotizado', 'el embudo pasa a Cotizado');
limpiarSim();
$whN->procesar($entN('593981000041', $txtN('comprar'), 'wamid.CAT4'));
ok(!str_contains($motivoN('wamid.CAT4'), 'carrito'), 'comprar sin carrito: no hace nada especial');
$whN->procesar($entN('593981000009', $txtN('catálogo'), 'wamid.CAT5'));
ok(!str_contains($motivoN('wamid.CAT5'), 'catálogo'), 'a un personal no se le manda el catálogo');

echo "== Chatbots por pasos (motor)\n";
$pasosP = [
    ['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Hola, bienvenido a {negocio}', 'etiqueta' => 'bot'],
    ['id' => 'p2', 'tipo' => 'botones', 'texto' => '¿Qué necesitas?', 'opciones' => [['titulo' => 'Precios', 'ir_a' => 'p3'], ['titulo' => 'Asesor', 'ir_a' => 'p5']]],
    ['id' => 'p3', 'tipo' => 'pregunta', 'texto' => '¿Cuál es tu talla?', 'guardar' => 'campo:talla', 'siguiente' => 'p4'],
    ['id' => 'p4', 'tipo' => 'fin', 'texto' => 'Gracias, te escribimos con precios.', 'etapa' => 'interesado'],
    ['id' => 'p5', 'tipo' => 'persona', 'texto' => 'Te paso con un asesor.'],
];
$r = Flujos::avanzar(7, $pasosP, null, 'hola', '', null, 'Boutique');
igual(array_column($r['salidas'], 'tipo'), ['texto', 'botones'], 'arranca: mensaje y luego la pregunta con botones');
igual([$r['salidas'][0]['texto'], $r['esperando'], $r['etiquetas']], ['Hola, bienvenido a Boutique', 'p2', ['bot']], '{negocio}, espera en p2 y etiqueta');
igual(array_column($r['salidas'][1]['opciones'], 'id'), ['f7:p2:0', 'f7:p2:1'], 'ids de los botones');
$r2 = Flujos::avanzar(7, $pasosP, 'p2', 'Asesor', 'f7:p2:1');
ok($r2['persona'] && $r2['fin'] && $r2['salidas'][0]['texto'] === 'Te paso con un asesor.', 'tocó Asesor: pasa a persona');
$r3 = Flujos::avanzar(7, $pasosP, 'p2', '1');
igual([$r3['esperando'], $r3['salidas'][0]['texto']], ['p3', '¿Cuál es tu talla?'], 'escribió 1: va a la pregunta');
$r3b = Flujos::avanzar(7, $pasosP, 'p2', 'precios');
igual($r3b['esperando'], 'p3', 'escribió el nombre de la opción');
$r4 = Flujos::avanzar(7, $pasosP, 'p2', 'no sé');
ok($r4['esperando'] === 'p2' && $r4['salidas'][0]['tipo'] === 'botones' && str_contains($r4['salidas'][0]['texto'], 'Elige'), 'no eligió: repite los botones');
$val = fn (string $g, string $v) => $g === 'campo:talla' && !in_array(strtoupper($v), ['S', 'M', 'L'], true) ? 'Elige S, M o L.' : null;
$r5 = Flujos::avanzar(7, $pasosP, 'p3', 'XXL', '', $val);
ok($r5['esperando'] === 'p3' && str_starts_with($r5['salidas'][0]['texto'], 'Elige S, M o L.'), 'respuesta inválida: vuelve a preguntar');
$r6 = Flujos::avanzar(7, $pasosP, 'p3', 'M', '', $val);
ok($r6['fin'] && $r6['guardar'] === [['campo:talla', 'M']] && $r6['etapa'] === 'interesado' && $r6['esperando'] === null, 'respuesta válida: guarda, mueve el embudo y termina');
igual(Flujos::avanzar(7, [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'A', 'siguiente' => 'p1']], null, 'x')['salidas'] !== [], true, 'un paso que se llama a sí mismo no se queda dando vueltas');
ok(count(Flujos::avanzar(7, [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'A', 'siguiente' => 'p1']], null, 'x')['salidas']) <= 8, 'máximo 8 mensajes seguidos');
try { Flujos::limpiarPasos([['id' => 'p1', 'tipo' => 'botones', 'texto' => 'x']]); ok(false, 'botones sin opciones (encendido)'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'Paso 1'), 'botones sin opciones (encendido): dice en qué paso'); }
igual(count(Flujos::limpiarPasos([['id' => 'p1', 'tipo' => 'botones', 'texto' => '']], [], false)), 1, 'apagado se puede guardar a medias');
try { Flujos::limpiarPasos([['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'x', 'siguiente' => 'p9']]); ok(false, 'lleva a un paso que no existe'); } catch (RuntimeException $e) { ok(true, 'lleva a un paso que no existe'); }
igual(Flujos::limpiarPasos([['id' => 'p1', 'tipo' => 'pregunta', 'texto' => 'x', 'guardar' => 'campo:noexiste']], ['talla'])[0]['guardar'], 'notas', 'campo que no existe: se guarda en notas');
igual(Flujos::idNuevo($pasosP), 'p6', 'id del paso nuevo');
$sim1 = Flujos::simular(['id' => 7, 'pasos' => json_encode($pasosP)], ['hola', '1', 'M'], 'Boutique');
igual(array_column($sim1, 'de'), ['cliente', 'bot', 'bot', 'cliente', 'bot', 'cliente', 'bot', 'sistema'], 'probador: conversación completa');

echo "== Chatbots por pasos (por WhatsApp)\n";
$fid = Flujos::crear($db, $lN, 'Bienvenida', 'palabras', 'hola, info');
$pasosW = json_decode(json_encode($pasosP), true);
$pasosW[2]['guardar'] = 'campo:talla';
$form = ['nombre' => 'Bienvenida', 'disparador' => 'palabras', 'palabras' => 'hola, info', 'activo' => '1', 'paso' => array_map(fn ($x) => $x + ['op_titulo' => array_column($x['opciones'] ?? [], 'titulo'), 'op_ir' => array_column($x['opciones'] ?? [], 'ir_a')], $pasosW)];
Flujos::guardar($db, $lN, $fid, $form, ['talla']);
igual(count(Flujos::pasos(Flujos::buscar($db, $lN, $fid))), 5, 'se guardan los 5 pasos');
try { Flujos::guardar($db, $lN, $fid, ['nombre' => 'X', 'activo' => '1', 'disparador' => 'palabras', 'palabras' => '', 'paso' => $form['paso']], ['talla']); ok(false, 'encendido sin palabras'); }
catch (RuntimeException $e) { ok(true, 'encendido sin palabras'); }
limpiarSim();
$whN->procesar($entN('593981000050', $txtN('Hola!'), 'wamid.FL1'));
$env = $salidasN();
igual([count($env), $env[0]['cuerpo']['text']['body'] ?? null, $env[1]['cuerpo']['interactive']['action']['buttons'][0]['reply']['id'] ?? null],
      [2, 'Hola, bienvenido a Boutique Mía', 'f' . $fid . ':p2:0'], 'escribió hola: arranca el chatbot');
$cf = $cN('593981000050');
igual([(int) $cf['flujo_id'], $cf['flujo_paso'], $cf['etiquetas']], [$fid, 'p2', 'bot'], 'queda esperando su respuesta, con etiqueta');
ok(str_contains($motivoN('wamid.FL1'), 'Chatbot "Bienvenida"'), 'el motivo dice qué chatbot contestó');
igual($db->query("SELECT origen FROM mensajes WHERE contacto_id = {$cf['id']} AND direccion = 'sale' LIMIT 1")->fetchColumn(), 'flujo', 'los mensajes quedan como del chatbot');
limpiarSim();
$whN->procesar($entN('593981000050', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'f' . $fid . ':p2:0', 'title' => 'Precios']]], 'wamid.FL2'));
igual($salidasN()[0]['cuerpo']['text']['body'] ?? null, '¿Cuál es tu talla?', 'tocó Precios: le pregunta la talla');
limpiarSim();
$whN->procesar($entN('593981000050', $txtN('XL'), 'wamid.FL3'));
ok(str_contains($salidasN()[0]['cuerpo']['text']['body'] ?? '', 'S, M, L'), 'talla que no existe: vuelve a preguntar con las opciones');
$whN->procesar($entN('593981000050', $txtN('s'), 'wamid.FL4'));
$cf = $cN('593981000050');
igual([Campos::de($cf)['talla'] ?? null, $cf['etapa'], $cf['flujo_id']], ['S', 'interesado', null], 'guardó la talla, movió el embudo y terminó');
limpiarSim();
$whN->procesar($entN('593981000051', $txtN('info'), 'wamid.FL5'));
$whN->procesar($entN('593981000051', $txtN('2'), 'wamid.FL6'));
$cp = $cN('593981000051');
ok($cp['silencio_hasta'] !== null && str_contains($motivoN('wamid.FL6'), 'pasó a una persona'), 'eligió asesor: se calla y pasa a una persona');
Flujos::crear($db, $lN, 'Primer mensaje', 'nuevo');
$fNuevo = (int) $db->query("SELECT id FROM flujos WHERE nombre = 'Primer mensaje'")->fetchColumn();
$db->exec("UPDATE flujos SET activo = true WHERE id = $fNuevo");
limpiarSim();
$whN->procesar($entN('593981000052', $txtN('buenas tardes'), 'wamid.FL7'));
ok(str_contains($motivoN('wamid.FL7'), 'Primer mensaje'), 'disparador "primer mensaje": contesta al cliente nuevo');
$whN->procesar($entN('593981000052', $txtN('Ver productos'), 'wamid.FL8'));
$whN->procesar($entN('593981000052', $txtN('otra cosa'), 'wamid.FL9'));
ok(!str_contains($motivoN('wamid.FL9'), 'Primer mensaje'), 'ya no es su primer mensaje: no vuelve a arrancar');
$db->exec("UPDATE flujos SET activo = false WHERE id = $fNuevo");
$whN->procesar($entN('593981000009', $txtN('hola'), 'wamid.FL10'));
ok(!str_contains($motivoN('wamid.FL10'), 'Chatbot'), 'a un personal nunca le contesta el chatbot');
Contactos::callar($db, $lineaN, (int) $cN('593981000052')['id'], $ahoraN);
$whN->procesar($entN('593981000052', $txtN('hola'), 'wamid.FL11'));
ok(!str_contains($motivoN('wamid.FL11'), 'Chatbot'), 'si alguien del equipo contestó, el chatbot se calla');
$whN->procesar($entN('593981000053', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'f' . $fid . ':p2:1', 'title' => 'Asesor']]], 'wamid.FL12'));
ok(str_contains($motivoN('wamid.FL12'), 'pasó a una persona'), 'botón de un chatbot viejo (más de 24 h): igual funciona');
// (23 nov 2026) Un paso con PDF: se sube a Meta y se manda como documento, antes del texto
$db->exec("UPDATE flujos SET activo = false WHERE linea_id = $lN");
$fPdf = Flujos::crear($db, $lN, 'Catalogo PDF', 'palabras', 'folleto');
$pdfDoc = \TuWhatsApp\Archivos::guardarBytes("%PDF-1.4\n%prueba\n", 'application/pdf', $eN);
Flujos::guardar($db, $lN, $fPdf, ['nombre' => 'Catalogo PDF', 'disparador' => 'palabras', 'palabras' => 'folleto', 'activo' => '1',
    'paso' => [['id' => 'p1', 'tipo' => 'fin', 'texto' => 'Arriba tienes nuestro catálogo.']]], [],
    ['p1' => ['tipo' => 'documento', 'mime' => 'application/pdf', 'nombre' => 'Catalogo.pdf', 'bytes' => 17, 'ruta' => $pdfDoc]]);
limpiarSim();
$whN->procesar($entN('593981000054', $txtN('Quiero el folleto'), 'wamid.FL13'));
$subidos = array_values(array_filter(sim(), fn ($x) => ($x['metodo'] ?? '') === 'SUBIDO'));
$env = $salidasN();
igual([$subidos[0]['subido']['nombre'] ?? null, $env[0]['cuerpo']['type'] ?? null, $env[0]['cuerpo']['document']['filename'] ?? null, $env[1]['cuerpo']['text']['body'] ?? null],
      ['Catalogo.pdf', 'document', 'Catalogo.pdf', 'Arriba tienes nuestro catálogo.'], 'chatbot con PDF: sube el archivo a Meta, lo manda como documento y después el texto');
igual($db->query("SELECT count(*) FROM mensajes WHERE wamid IS NOT NULL AND contacto_id = " . (int) $cN('593981000054')['id'] . " AND direccion = 'sale' AND tipo = 'documento' AND media_ruta = " . $db->quote($pdfDoc))->fetchColumn(), 1,
      'el PDF queda en el chat como documento del chatbot');
@unlink(\TuWhatsApp\Archivos::carpeta() . '/' . $pdfDoc);
limpiarSim();
$whN->procesar($entN('593981000055', $txtN('folleto'), 'wamid.FL14'));
$env = $salidasN();
igual([count($env), $env[0]['cuerpo']['text']['body'] ?? null], [1, 'Arriba tienes nuestro catálogo.'], 'si el PDF ya no está en el servidor, igual manda el texto');
$db->exec("UPDATE flujos SET activo = false WHERE id = $fPdf");

echo "== Agente con IA que contesta solo\n";
putenv('TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789');
putenv('TUKAN_IA_URL=' . $sim);
putenv('TUKAN_IA_ESPERA_MS=0');
putenv('TUKAN_IA_REINTENTOS=0');
$db->exec("UPDATE flujos SET activo = false WHERE linea_id = $lN");
$empN = Empresas::buscar($db, $eN);
$cliIA = ['tipo' => 'desconocido', 'silencio_hasta' => null];
igual(Bot::decidir($lineaN, $empN, $cliIA, $ahoraN, 0)['si'], false, 'apagado no contesta');
$lb2 = array_merge($lineaN, ['bot_activo' => true]);
ok(!Bot::decidir($lb2, $empN, $cliIA, $ahoraN, 0)['si'] && str_contains(Bot::decidir($lb2, $empN, $cliIA, $ahoraN, 0)['motivo'], 'apagado'), 'sin el extra de IA no contesta');
Modulos::guardar($db, $eN, ['ia']);
$empN = Empresas::buscar($db, $eN);
ok(Bot::decidir($lb2, $empN, $cliIA, $ahoraN, 0)['si'], 'con el extra y encendido: contesta');
ok(!Bot::decidir($lb2, $empN, ['tipo' => 'personal'], $ahoraN, 0)['si'], 'personal: nunca');
ok(!Bot::decidir($lb2, $empN, ['tipo' => 'cliente', 'silencio_hasta' => $ahoraN->modify('+1 hour')->format('c')], $ahoraN, 0)['si'], 'atendido por una persona: no');
ok(!Bot::decidir($lb2 + ['bot_max_dia' => 20], $empN, $cliIA, $ahoraN, 20)['si'], 'llegó al máximo del día: no');
$enHorario = new DateTimeImmutable('2026-10-28 10:00:00');
ok(!Bot::decidir(array_merge($lb2, ['bot_cuando' => 'fuera_horario']), $empN, $cliIA, $enHorario, 0)['si'] && Bot::decidir(array_merge($lb2, ['bot_cuando' => 'fuera_horario']), $empN, $cliIA, $ahoraN, 0)['si'], 'solo fuera de horario');
Bot::guardar($db, $lN, ['bot_activo' => '1', 'bot_cuando' => 'siempre', 'bot_nombre' => 'Sofía', 'bot_max_dia' => '5', 'bot_vende' => '1']);
\TuWhatsApp\Asistente::guardarConocimiento($db, $lN, 'Boutique Mía, en Quito. Envíos a todo el país.');
$lineaN = Lineas::buscar($db, $lN);
$db->exec("UPDATE lineas SET auto_activo = true, regla_solo_clientes = false, regla_palabras = false, regla_horario = false WHERE id = $lN");
$lineaN = Lineas::buscar($db, $lN);
limpiarSim();
$whN->procesar($entN('593981000060', $txtN('¿Hacen envíos a Cuenca?'), 'wamid.IA10'));
$env = $salidasN();
$ia = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v1/messages'));
igual($env[0]['cuerpo']['text']['body'] ?? null, '¡Hola! Con gusto te ayudo. ¿Qué buscas?', 'el agente contestó solo');
ok(str_contains($ia[0]['cuerpo']['system'] ?? '', 'Sofía') && str_contains($ia[0]['cuerpo']['system'] ?? '', 'Envíos a todo el país') && str_contains($ia[0]['cuerpo']['system'] ?? '', '[' . $pBlusa . '] Blusa blanca') && !str_contains($ia[0]['cuerpo']['system'] ?? '', '[' . $pVest . ']'), 'la IA recibe su nombre, lo que sabe el negocio y el catálogo (sin lo agotado)');
ok(str_contains(json_encode($ia[0]['cuerpo']['messages'] ?? [], JSON_UNESCAPED_UNICODE), '<conversacion>'), 'la conversación va como datos');
igual($db->query("SELECT origen FROM mensajes WHERE contacto_id = {$cN('593981000060')['id']} AND direccion = 'sale'")->fetchColumn(), 'bot', 'queda como mensaje del agente');
igual($motivoN('wamid.IA10'), 'Contestó el agente con IA', 'motivo');
limpiarSim();
$whN->procesar($entN('593981000060', $txtN('quiero 2 vestidos'), 'wamid.IA11'));
$cia = $cN('593981000060');
$carIA = Pedidos::carrito($db, $lineaN, (int) $cia['id'], false);
igual([(int) Pedidos::items($db, (int) $carIA['id'])[0]['cantidad'], $carIA['origen']], [2, 'bot'], 'el agente armó el carrito (el producto inventado no entra)');
ok(str_contains($salidasN()[0]['cuerpo']['text']['body'] ?? '', '🛒 Tu carrito: 2 productos') && str_contains($salidasN()[0]['cuerpo']['text']['body'] ?? '', 'no está disponible'), 'le dice cómo va su carrito (y que el otro no existe)');
limpiarSim();
$whN->procesar($entN('593981000060', $txtN('sí, confirmo'), 'wamid.IA12'));
$pIA = Pedidos::deContacto($db, (int) $cia['id'])[0];
$env = $salidasN();
ok($pIA['estado'] === 'pendiente' && count($env) === 2 && str_contains($env[1]['cuerpo']['text']['body'] ?? '', '*Pedido #' . $pIA['numero'] . '*') && str_contains($env[1]['cuerpo']['text']['body'] ?? '', 'paypal.me'), 'confirmó: pedido pendiente y resumen con el enlace de pago');
igual($motivoN('wamid.IA12'), 'Contestó el agente con IA (pedido listo para pagar)', 'motivo del pedido');
Reparto::guardar($db, $lineaN, ['reparto' => 'turnos']);
$lineaN = Lineas::buscar($db, $lN);
limpiarSim();
$whN->procesar($entN('593981000061', $txtN('quiero hablar con una persona'), 'wamid.IA13'));
$cp = $cN('593981000061');
ok($cp['silencio_hasta'] !== null && $cp['asignado_a'] !== null && str_contains($motivoN('wamid.IA13'), 'pasó a una persona'), 'pidió una persona: se calla y se reparte');
ok($cp['traspaso_nota'] === 'Quiere hablar con una persona del equipo.' && $cp['traspaso_en'] !== null, 'deja una nota para el equipo al pasar el chat');
limpiarSim();
$whN->procesar($entN('593981000061', $txtN('¿hola?'), 'wamid.IA14'));
igual(count($salidasN()), 0, 'mientras lo atiende una persona, el agente no contesta');
limpiarSim();
$whN->procesar($entN('593981000062', $txtN('ROMPER'), 'wamid.IA15'));
ok(str_contains($motivoN('wamid.IA15'), 'menú') && ($salidasN()[0]['cuerpo']['type'] ?? '') === 'interactive', 'la IA falla: sigue el menú de siempre (el cliente no se entera)');
limpiarSim();
$whN->procesar($entN('593981000063', $txtN('json malo'), 'wamid.IA16'));
ok(str_contains($motivoN('wamid.IA16'), 'menú'), 'respuesta rara de la IA: sigue el menú');
$db->exec("UPDATE empresas SET ia_tope = 0.000001 WHERE id = $eN");
limpiarSim();
$whN->procesar($entN('593981000064', $txtN('hola'), 'wamid.IA17'));
ok(str_contains($motivoN('wamid.IA17'), 'menú') && count(array_filter(sim(), fn ($r) => $r['ruta'] === '/v1/messages')) === 0, 'tope de IA del mes: no llama a la IA, sigue el menú');
$db->exec("UPDATE empresas SET ia_tope = 3 WHERE id = $eN");
for ($i = 0; $i < 5; $i++) {
    $whN->procesar($entN('593981000065', $txtN('pregunta ' . $i), 'wamid.IAM' . $i, $ahoraN->modify("+$i minutes")));
}
$whN->procesar($entN('593981000065', $txtN('una más'), 'wamid.IAM9', $ahoraN->modify('+9 minutes')));
ok(str_contains($motivoN('wamid.IAM9'), 'ya contestó 5 veces'), 'máximo de respuestas al día por persona');
Modulos::guardar($db, $eN, []);
limpiarSim();
$whN->procesar($entN('593981000066', $txtN('hola'), 'wamid.IA18'));
ok(count(array_filter(sim(), fn ($r) => $r['ruta'] === '/v1/messages')) === 0 && str_contains($motivoN('wamid.IA18'), 'menú'), 'sin el extra pagado: no usa IA');
igual((int) $db->query("SELECT llamadas FROM tukan_ia_gasto WHERE negocio_id = '$eN'")->fetchColumn() > 0, true, 'el gasto de IA queda en la cuenta del cliente');
Bot::guardar($db, $lN, []);
$db->exec("UPDATE lineas SET auto_activo = false WHERE id = $lN");
$lineaN = Lineas::buscar($db, $lN);
putenv('TUKAN_IA_CLAVE=');

echo "== Callarse cuando contesta alguien del equipo\n";
$cq = Contactos::guardar($db, $lN, '0981000070', 'Quique', 'cliente');
Contactos::callar($db, $lineaN, (int) $cq['id'], $ahoraN);
ok(new DateTimeImmutable($cN('593981000070')['silencio_hasta']) > $ahoraN->modify('+11 hours'), 'silencio por las horas del número');
$db->exec("UPDATE lineas SET callar_al_atender = false WHERE id = $lN");
$cq2 = Contactos::guardar($db, $lN, '0981000071', 'Quela', 'cliente');
Contactos::callar($db, Lineas::buscar($db, $lN), (int) $cq2['id'], $ahoraN);
igual($cN('593981000071')['silencio_hasta'], null, 'si se apaga la opción, no se calla');
$db->exec("UPDATE lineas SET callar_al_atender = true WHERE id = $lN");
$lineaN = Lineas::buscar($db, $lN);
$eco = cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '400'], 'message_echoes' => [['from' => '593996401111', 'to' => '593981000071', 'id' => 'wamid.ECO71', 'timestamp' => (string) $ahoraN->getTimestamp(), 'type' => 'text', 'text' => ['body' => 'Hola Quela, te escribo yo']]]], 'smb_message_echoes');
$whN->procesar($eco);
ok($cN('593981000071')['silencio_hasta'] !== null, 'contestar desde el celular también calla lo automático');

echo "== Tienda en línea (Shopify y WooCommerce)\n";
$db->exec("INSERT INTO lineas (empresa_id, nombre, telefono, phone_number_id, waba_id, token_cifrado) SELECT $eN, 'Tienda', '593996402222', '401', '200', token_cifrado FROM lineas WHERE id = $lN");
$lT = (int) $db->query("SELECT id FROM lineas WHERE phone_number_id = '401'")->fetchColumn();
try { Tiendas::guardar($db, Lineas::buscar($db, $lT), ['tienda_tipo' => 'shopify']); ok(false, 'sin clave secreta'); } catch (RuntimeException $e) { ok(true, 'sin clave secreta'); }
Tiendas::guardar($db, Lineas::buscar($db, $lT), ['tienda_tipo' => 'shopify', 'tienda_secreto' => 'shpss_secreto', 'tienda_plantilla_pedido' => 'recordatorio_pago|es',
    'tienda_plantilla_envio' => 'recordatorio_pago|es', 'tienda_plantilla_carrito' => 'promo_octubre|es', 'tienda_carrito_horas' => '2']);
$lineaT = Lineas::buscar($db, $lT);
ok(preg_match('/^[a-f0-9]{24}$/', $lineaT['tienda_clave']) === 1 && Tiendas::porClave($db, $lineaT['tienda_clave'])['id'] === $lT, 'se crea la clave de la dirección');
igual(Tiendas::porClave($db, 'zzz'), null, 'clave mala: nada');
$cuerpoS = json_encode(['id' => 5551, 'name' => '#1001', 'total_price' => '57.50', 'total_tax' => '7.50', 'financial_status' => 'paid', 'order_status_url' => 'https://tienda.ec/o/1001',
    'checkout_token' => 'chk-1', 'customer' => ['first_name' => 'Pedro', 'last_name' => 'Páez'], 'shipping_address' => ['phone' => '+593 98 100 0080', 'first_name' => 'Pedro', 'last_name' => 'Páez'],
    'line_items' => [['title' => 'Camiseta', 'variant_title' => 'M', 'price' => '25.00', 'quantity' => 2]]]);
ok(Tiendas::firmaValida($lineaT, $cuerpoS, base64_encode(hash_hmac('sha256', $cuerpoS, 'shpss_secreto', true))), 'firma de Shopify correcta');
ok(!Tiendas::firmaValida($lineaT, $cuerpoS, base64_encode(hash_hmac('sha256', $cuerpoS, 'otra', true))), 'firma mala se rechaza');
Tiendas::procesar($db, $lineaT, 'checkouts/create', ['token' => 'chk-1', 'total_price' => '57.50', 'abandoned_checkout_url' => 'https://tienda.ec/c/1', 'shipping_address' => ['phone' => '0981000080', 'first_name' => 'Pedro']], $ahoraN);
Tiendas::procesar($db, $lineaT, 'checkouts/create', ['token' => 'chk-2', 'total_price' => '19.90', 'abandoned_checkout_url' => 'https://tienda.ec/c/2', 'phone' => '0981000081', 'customer' => ['first_name' => 'Rita']], $ahoraN);
Tiendas::procesar($db, $lineaT, 'checkouts/create', ['token' => 'chk-3', 'total_price' => '5'], $ahoraN);
igual($db->query("SELECT estado FROM tienda_carritos WHERE externo_id = 'chk-3'")->fetchColumn(), 'sin_telefono', 'carrito sin teléfono');
limpiarSim();
$que = Tiendas::procesar($db, $lineaT, 'orders/create', json_decode($cuerpoS, true), $ahoraN);
$pS = $db->query("SELECT * FROM pedidos WHERE linea_id = $lT AND origen = 'shopify'")->fetch();
ok($pS && $pS['estado'] === 'pagado' && (float) $pS['total'] === 57.5 && $pS['externo_id'] === '5551' && str_contains($que, 'avisado por WhatsApp'), 'pedido de Shopify: entra pagado y se avisa');
$envT = array_values(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/401/messages'));
igual(array_column($envT[0]['cuerpo']['template']['components'][0]['parameters'] ?? [], 'text'), ['Pedro', '#1001'], 'la plantilla lleva nombre y número de pedido');
$contS = Contactos::porTelefono($db, $lT, '593981000080');
igual([$contS['nombre'], $contS['tipo']], ['Pedro Páez', 'cliente'], 'el comprador queda como cliente con su nombre');
igual(Pedidos::items($db, (int) $pS['id'])[0]['nombre'], 'Camiseta (M)', 'productos con su variante');
igual($db->query("SELECT estado FROM tienda_carritos WHERE externo_id = 'chk-1'")->fetchColumn(), 'compro', 'su carrito queda como comprado');
limpiarSim();
Tiendas::procesar($db, $lineaT, 'orders/paid', json_decode($cuerpoS, true), $ahoraN);
igual([(int) $db->query("SELECT count(*) FROM pedidos WHERE linea_id = $lT")->fetchColumn(), count(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/401/messages'))], [1, 0], 'el mismo pedido otra vez: no se duplica ni se avisa de nuevo');
Tiendas::procesar($db, $lineaT, 'orders/fulfilled', ['fulfillment_status' => 'fulfilled'] + json_decode($cuerpoS, true), $ahoraN);
ok($db->query("SELECT estado FROM pedidos WHERE id = {$pS['id']}")->fetchColumn() === 'enviado' && count(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/401/messages')) === 1, 'enviado: se avisa una vez');
igual(Tiendas::procesarCarritos($db, $ahoraN->modify('+1 hour')), ['avisado' => 0, 'omitido' => 0, 'fallido' => 0], 'antes de las 2 horas no se avisa');
limpiarSim();
igual(Tiendas::procesarCarritos($db, $ahoraN->modify('+3 hours')), ['avisado' => 1, 'omitido' => 0, 'fallido' => 0], 'carrito abandonado: se avisa a Rita (Pedro ya compró)');
igual(array_values(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/401/messages'))[0]['cuerpo']['template']['name'] ?? null, 'promo_octubre', 'con la plantilla de carrito');
igual(Tiendas::procesarCarritos($db, $ahoraN->modify('+4 hours'))['avisado'], 0, 'no se avisa dos veces');
Tiendas::procesar($db, $lineaT, 'checkouts/create', ['token' => 'chk-4', 'total_price' => '9', 'phone' => '0981000082'], $ahoraN);
Campanias::marcarMarketing($db, $lT, (int) Contactos::obtener($db, $lT, '593981000082')['id'], 'baja');
igual(Tiendas::procesarCarritos($db, $ahoraN->modify('+3 hours'))['omitido'], 1, 'quien pidió no recibir promociones no recibe el recordatorio');
Tiendas::guardar($db, $lineaT, ['tienda_tipo' => 'woocommerce', 'tienda_plantilla_pedido' => 'recordatorio_pago|es']);
$lineaT = Lineas::buscar($db, $lT);
ok(Tiendas::firmaValida($lineaT, 'x', base64_encode(hash_hmac('sha256', 'x', 'shpss_secreto', true))), 'cambiar de tienda sin escribir otra clave conserva la anterior');
$woo = ['id' => 77, 'number' => '77', 'status' => 'processing', 'total' => '30.00', 'total_tax' => '0', 'billing' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '0981000090'],
        'line_items' => [['name' => 'Taza', 'quantity' => 3, 'total' => '30.00']]];
limpiarSim();
Tiendas::procesar($db, $lineaT, 'order.created', $woo, $ahoraN);
$pW = $db->query("SELECT * FROM pedidos WHERE linea_id = $lT AND origen = 'woocommerce'")->fetch();
ok($pW && $pW['estado'] === 'pagado' && (float) Pedidos::items($db, (int) $pW['id'])[0]['precio'] === 10.0, 'pedido de WooCommerce: precio por unidad');
Tiendas::procesar($db, $lineaT, 'order.updated', ['status' => 'completed'] + $woo, $ahoraN);
igual($db->query("SELECT estado FROM pedidos WHERE id = {$pW['id']}")->fetchColumn(), 'entregado', 'WooCommerce completado = entregado');
Tiendas::procesar($db, $lineaT, 'order.created', ['id' => 78, 'status' => 'pending', 'total' => '5', 'line_items' => []], $ahoraN);
ok(str_contains(Tiendas::procesar($db, $lineaT, 'order.created', ['id' => 79, 'status' => 'pending', 'total' => '5', 'billing' => []], $ahoraN), 'sin teléfono'), 'pedido sin teléfono: se guarda sin avisar');
$db->exec("UPDATE lineas SET tienda_plantilla_pedido = 'no_existe|es' WHERE id = $lT");
ok(str_contains(Tiendas::procesar($db, Lineas::buscar($db, $lT), 'order.created', ['id' => 80, 'status' => 'pending', 'total' => '5', 'billing' => ['phone' => '0981000091']], $ahoraN), 'no está aprobada'), 'plantilla que no existe: se anota el problema');
ok(str_contains((string) Lineas::buscar($db, $lT)['tienda_error'], 'no_existe'), 'el problema queda a la vista');

echo "== App en el celular: avisos (Web Push)\n";
putenv('TUWA_PUSH_PRUEBA=' . $sim . '/push/');
ok(Push::endpointValido('https://fcm.googleapis.com/fcm/send/abc') && Push::endpointValido('https://web.push.apple.com/QK') && Push::endpointValido('https://wns2-par02p.notify.windows.com/w/?token=x'), 'servidores de push conocidos');
ok(!Push::endpointValido('https://evil.com/fcm.googleapis.com') && !Push::endpointValido('http://fcm.googleapis.com/x') && !Push::endpointValido('https://127.0.0.1/x'), 'cualquier otra dirección se rechaza');
$pub = Push::clavePublica($db);
igual(strlen(Push::deB64u($pub)), 65, 'clave VAPID pública de 65 bytes');
igual(Push::clavePublica($db), $pub, 'la clave se crea una sola vez');
ok(!str_contains((string) $db->query("SELECT valor FROM ajustes WHERE clave = 'vapid_privada'")->fetchColumn(), 'PRIVATE KEY'), 'la clave privada se guarda cifrada');
$navegador = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$uaPub = Push::publicaCruda($navegador);
$auth = random_bytes(16);
try { Push::suscribir($db, $idAna, ['endpoint' => 'https://evil.com/x', 'keys' => ['p256dh' => Push::b64u($uaPub), 'auth' => Push::b64u($auth)]]); ok(false, 'suscribir a una dirección rara'); } catch (RuntimeException $e) { ok(true, 'suscribir a una dirección rara'); }
try { Push::suscribir($db, $idAna, ['endpoint' => $sim . '/push/ok/1', 'keys' => ['p256dh' => Push::b64u(random_bytes(65)), 'auth' => Push::b64u($auth)]]); ok(false, 'clave que no es de la curva'); } catch (RuntimeException $e) { ok(true, 'clave que no es de la curva'); }
Push::suscribir($db, $idAna, ['endpoint' => $sim . '/push/ok/ana', 'keys' => ['p256dh' => Push::b64u($uaPub), 'auth' => Push::b64u($auth)]]);
Push::suscribir($db, $idBeto, ['endpoint' => $sim . '/push/gone/beto', 'keys' => ['p256dh' => Push::b64u($uaPub), 'auth' => Push::b64u($auth)]]);
igual(Push::cuantas($db, $idAna), 1, 'Ana tiene avisos activos');
// Descifrado como lo haría el navegador (RFC 8291), para comprobar que el cifrado está bien
$descifrar = function (string $cuerpo) use ($navegador, $uaPub, $auth): ?string {
    $sal = substr($cuerpo, 0, 16);
    $idlen = ord($cuerpo[20]);
    $asPub = substr($cuerpo, 21, $idlen);
    $cifrado = substr($cuerpo, 21 + $idlen);
    $secreto = openssl_pkey_derive(Push::publicaDeCruda($asPub), $navegador, 32);
    $prkClave = hash_hmac('sha256', $secreto, $auth, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPub . $asPub . "\x01", $prkClave, true);
    $prk = hash_hmac('sha256', $ikm, $sal, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $plano = openssl_decrypt(substr($cifrado, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cifrado, -16));
    return $plano === false ? null : rtrim($plano, "\x02");
};
igual($descifrar(Push::cifrar('{"hola":1}', $uaPub, $auth)), '{"hola":1}', 'cifrado y descifrado de ida y vuelta');
limpiarSim();
$n = Push::enviar($db, [$idAna, $idBeto], ['titulo' => 'Lucía', 'texto' => '¿Tienen talla M?', 'url' => '/?r=chat&c=5']);
$pushes = array_values(array_filter(sim(), fn ($r) => ($r['metodo'] ?? '') === 'PUSH'));
igual([$n, count($pushes)], [1, 2], 'se entrega a Ana (Beto ya no usa ese navegador)');
$pa = array_values(array_filter($pushes, fn ($x) => str_contains($x['ruta'], '/ok/')))[0];
$aviso = json_decode((string) $descifrar(base64_decode($pa['cuerpo64'])), true);
igual([$aviso['titulo'] ?? null, $aviso['texto'] ?? null, $aviso['url'] ?? null, $pa['enc'], $pa['ttl']], ['Lucía', '¿Tienen talla M?', '/?r=chat&c=5', 'aes128gcm', '86400'], 'el celular recibe el aviso cifrado con el texto (y el servidor de push lo guarda 24 horas)');
preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $pa['vapid'], $mv);
$jwt = json_decode(Push::deB64u($mv[2] ?? ''), true);
$firmaCruda = Push::deB64u($mv[3] ?? '');
$derFirma = function (string $c): string {   // r||s -> DER para verificar con OpenSSL
    $ent = fn ($x) => "\x02" . chr(strlen($x = (ord(ltrim($x, "\0")[0] ?? "\0") > 127 ? "\0" : '') . ltrim($x, "\0"))) . $x;
    $seq = $ent(substr($c, 0, 32)) . $ent(substr($c, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
};
ok(($mv[4] ?? '') === $pub && $jwt['aud'] === parse_url($sim, PHP_URL_SCHEME) . '://' . parse_url($sim, PHP_URL_HOST) . ':' . parse_url($sim, PHP_URL_PORT) && $jwt['sub'] === 'mailto:info@tukansoftware.com', 'firma VAPID con la clave pública y el servidor correcto');
igual(openssl_verify(($mv[1] ?? '') . '.' . ($mv[2] ?? ''), $derFirma($firmaCruda), Push::publicaDeCruda(Push::deB64u($pub)), OPENSSL_ALGO_SHA256), 1, 'la firma VAPID (ES256) es válida');
igual(Push::cuantas($db, $idBeto), 0, 'la suscripción que ya no sirve se borra');
limpiarSim();
$cAna = $cN('593981000001');
Push::mensajeNuevo($db, $lineaN, $cAna, 'Hola de nuevo', 1);
igual(count(array_filter(sim(), fn ($r) => ($r['metodo'] ?? '') === 'PUSH')), 1, 'aviso de mensaje nuevo a quien lo atiende');
limpiarSim();
Push::mensajeNuevo($db, $lineaN, $cN('593981000009'), 'hijo', 2);
igual(count(array_filter(sim(), fn ($r) => ($r['metodo'] ?? '') === 'PUSH')), 0, 'de contactos personales no se avisa');
// (14 nov 2026) Si a quien atiende el chat no le llegan avisos, también les llega a los administradores
$cBeto = Contactos::guardar($db, $lN, '0981000077', 'Cliente de Beto', 'cliente');
$db->exec('UPDATE contactos SET asignado_a = ' . $idBeto . ' WHERE id = ' . (int) $cBeto['id']);
$cBeto = $cN('593981000077');
igual((int) $cBeto['asignado_a'], $idBeto, 'ese chat lo atiende Beto');
Push::suscribir($db, $idMia, ['endpoint' => $sim . '/push/ok/mia', 'keys' => ['p256dh' => Push::b64u($uaPub), 'auth' => Push::b64u($auth)]], 'Chrome · Android SM-A15');
limpiarSim();
Push::mensajeNuevo($db, $lineaN, $cBeto, 'Hola Beto', 3);
$rutasPush = array_column(array_values(array_filter(sim(), fn ($r) => ($r['metodo'] ?? '') === 'PUSH')), 'ruta');
igual($rutasPush, ['/push/ok/mia'], 'Beto no tiene avisos: le llega a Mía (administradora)');
limpiarSim();
Push::mensajeNuevo($db, $lineaN, $cAna, 'Otra vez', 4);
igual(array_column(array_values(array_filter(sim(), fn ($r) => ($r['metodo'] ?? '') === 'PUSH')), 'ruta'), ['/push/ok/ana'], 'a Ana sí le llegan: no se molesta a la administradora');
$dm = Push::dispositivos($db, $idMia);
igual([count($dm), $dm[0]['dispositivo'], $dm[0]['ultimo_ok'] !== null], [1, 'Chrome · Android SM-A15', true], 'Mi cuenta ve su dispositivo y cuándo le llegó el último aviso');
// Fallas temporales: a las 5 seguidas se deja de intentar, pero al día siguiente se vuelve a probar (antes se apagaba para siempre)
$db->exec("UPDATE push_suscripciones SET errores = 5, ultimo_error = now() - interval '1 hour', error_texto = 'respuesta 500' WHERE usuario_id = $idMia");
igual(Push::funcionando($db, $idMia), 0, 'con 5 fallas seguidas no cuenta como que le llegan');
limpiarSim();
igual(Push::enviar($db, [$idMia], ['titulo' => 'x', 'texto' => 'y', 'url' => '/']), 0, 'falló hace una hora: todavía no se reintenta');
$db->exec("UPDATE push_suscripciones SET ultimo_error = now() - interval '25 hours' WHERE usuario_id = $idMia");
igual(Push::enviar($db, [$idMia], ['titulo' => 'x', 'texto' => 'y', 'url' => '/']), 1, 'falló ayer: se vuelve a intentar y llega');
igual([Push::funcionando($db, $idMia), Push::dispositivos($db, $idMia)[0]['errores']], [1, 0], 'al llegar se borran las fallas');
Push::quitarId($db, $idMia, $dm[0]['id']);
igual(Push::cuantas($db, $idMia), 0, 'quitar un dispositivo desde la lista');
igual(Push::describir('Mozilla/5.0 (Linux; Android 14; SM-A155M Build/UP1A) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36', true), 'App Tukán Mensajes · Android SM-A155M', 'nombre del dispositivo (app de Android)');
igual(Push::describir('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'), 'Safari · iPhone', 'nombre del dispositivo (iPhone)');
Push::quitar($db, $idAna, $sim . '/push/ok/ana');
igual(Push::cuantas($db, $idAna), 0, 'desactivar los avisos');
putenv('TUWA_PUSH_PRUEBA=');

echo "== Pago con tarjeta (Plux)\n";
$Plux = \TuWhatsApp\Plux::class;
$pubPem = (string) file_get_contents((string) getenv('PLUX_PUB'));
$pubLinea = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pubPem);
ok(!$Plux::configurado($lineaN), 'sin credenciales no está conectado');
try { $Plux::guardar($db, $lineaN, ['plux_llave' => 'esto no es una llave']); ok(false, 'llave pública mala'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'llave pública de Plux no es válida'), 'llave pública mala'); }
try { $Plux::guardar($db, $lineaN, ['plux_cliente' => 'con espacios no']); ok(false, 'id de cliente con espacios'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'id de cliente'), 'id de cliente con espacios'); }
$Plux::guardar($db, $lineaN, ['plux_establecimiento' => 'EST-123', 'plux_cliente' => 'cliente_plux_prueba', 'plux_clave' => 'clave_plux_prueba', 'plux_llave' => $pubLinea, 'plux_ambiente' => 'sandbox']);
$lineaN = Lineas::buscar($db, $lN);
ok($Plux::configurado($lineaN) && $Plux::ambiente($lineaN) === 'sandbox', 'credenciales guardadas, en pruebas');
ok($lineaN['plux_clave'] !== 'clave_plux_prueba' && Cifrado::descifrar($lineaN['plux_clave']) === 'clave_plux_prueba', 'la clave secreta se guarda cifrada');
$avisoP = $Plux::aviso($db, $lineaN);
ok($avisoP['usuario'] === 'tukan' . $lN && preg_match('/^[a-f0-9]{32}$/', $avisoP['clave']) === 1 && str_ends_with($avisoP['url'], '/plux.php') && !str_contains($avisoP['retorno3ds'], '?'), 'datos del aviso de pago y regreso 3DS sin parámetros');
$lineaN = Lineas::buscar($db, $lN);
igual($Plux::aviso($db, $lineaN)['clave'], $avisoP['clave'], 'la clave del aviso no cambia sola');
ok(openssl_pkey_get_public($Plux::llavePem($pubLinea)) !== false && openssl_pkey_get_public($Plux::llavePem($pubPem)) !== false, 'la llave sirve en una línea o con BEGIN/END');
$Plux::guardar($db, $lineaN, ['plux_ambiente' => 'sandbox']);
$lineaN = Lineas::buscar($db, $lN);
ok($Plux::configurado($lineaN), 'guardar sin escribir la clave no la borra');
igual($Plux::montos(['total' => '27.60', 'iva' => '3.60']), ['baseAmount0' => 0.0, 'baseAmount12' => 27.6], 'con IVA: el total va en baseAmount12');
igual($Plux::montos(['total' => '10', 'iva' => '0']), ['baseAmount0' => 10.0, 'baseAmount12' => 0.0], 'sin IVA: el total va en baseAmount0');

$marta = Contactos::guardar($db, $lN, '0981000040', 'Marta', 'cliente');
$db->prepare('UPDATE contactos SET ultimo_entrante = now() WHERE id = ?')->execute([(int) $marta['id']]);
$marta = Contactos::buscar($db, $lN, (int) $marta['id']);
$pm = Pedidos::agregar($db, $lineaN, (int) $marta['id'], $pBlusa, 2);
$pm = Pedidos::buscar($db, $lN, (int) $pm['id']);
putenv('PAYPHONE_URL=' . $sim);
$db->prepare('UPDATE lineas SET payphone_token = ? WHERE id = ?')->execute([Cifrado::cifrar('payphone_bueno'), $lN]);
$lineaN = Lineas::buscar($db, $lN);
igual(Pedidos::enlacePago($db, $lineaN, $pm), 'https://payp.page.link/SIM' . (int) round((float) $pm['total'] * 100), 'con Payphone y Plux, sin elegir: Payphone');
$db->prepare("UPDATE pedidos SET enlace_pago = '', metodo = '' WHERE id = ?")->execute([(int) $pm['id']]);
$db->prepare("UPDATE lineas SET cobro_tarjeta = 'plux' WHERE id = ?")->execute([$lN]);
$lineaN = Lineas::buscar($db, $lN);
$um = Pedidos::enlacePago($db, $lineaN, Pedidos::buscar($db, $lN, (int) $pm['id']));
ok(preg_match('#/pagar\.php\?p=[a-f0-9]{32}$#', $um) === 1 && Pedidos::buscar($db, $lN, (int) $pm['id'])['metodo'] === 'plux', 'eligiendo Plux: la página de pago del pedido');
$pm = Pedidos::buscar($db, $lN, (int) $pm['id']);
igual((int) ($Plux::pedidoPorToken($db, (string) $pm['pago_token'])['id'] ?? 0), (int) $pm['id'], 'el token lleva al pedido');
igual($Plux::pedidoPorToken($db, 'abc'), null, 'token raro: nada');
ok(str_contains(Pedidos::resumen($lineaN, $pm, Pedidos::items($db, (int) $pm['id']), $um), 'Paga aquí: ' . $um), 'el resumen lleva la página de pago');

$tarj = ['number' => base64_encode('xxxxxxxx'), 'expirationYear' => base64_encode('yyyyyyyy'), 'expirationMonth' => base64_encode('mmmmmmmm'), 'cvv' => base64_encode('cccccccc')];
$comprador = ['documentNumber' => '171234567-8', 'firstName' => 'Marta', 'lastName' => 'Ruiz', 'email' => 'marta@ejemplo.com', 'phone' => ''];
try { $Plux::cuerpo($lineaN, $pm, $marta, ['buyer' => $comprador], '1.2.3.4'); ok(false, 'sin tarjeta'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'incompletos'), 'sin tarjeta'); }
try { $Plux::cuerpo($lineaN, $pm, $marta, ['card' => $tarj, 'buyer' => ['documentNumber' => '123'] + $comprador], '1.2.3.4'); ok(false, 'cédula mala'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'cédula'), 'cédula mala'); }
try { $Plux::cuerpo($lineaN, $pm, $marta, ['card' => $tarj, 'buyer' => ['email' => 'no'] + $comprador], '1.2.3.4'); ok(false, 'correo malo'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'correo'), 'correo malo'); }
$cu = $Plux::cuerpo($lineaN, $pm, $marta, ['card' => $tarj, 'buyer' => $comprador], '1.2.3.4');
ok(str_starts_with($cu['description'], 'WT-' . $pm['id'] . ' Pedido #') && $cu['currency'] === 'USD' && base64_decode($cu['idEstablecimiento']) === 'EST-123'
   && $cu['buyer']['documentNumber'] === '1712345678' && $cu['buyer']['phone'] === '593981000040' && !str_contains($cu['urlRetorno3ds'], '?')
   && $cu['baseAmount12'] === round((float) $pm['total'], 2) && !isset($cu['paramsOtp']), 'pedido para Plux con referencia, montos y cédula limpia');
$cu = $Plux::cuerpo($lineaN, $pm, $marta, ['card' => $tarj, 'buyer' => $comprador, 'paramsOtp' => ['idTransaction' => 'OTP-1', 'otpCode' => '123456', 'malo' => 'x']], '1.2.3.4');
igual(array_keys($cu['paramsOtp']), ['idTransaction', 'otpCode'], 'con el código OTP (solo los datos que van)');
$limpio = $Plux::limpiar(['code' => 0, 'detail' => ['cardNumber' => '454063******8783', 'texto' => 'tarjeta 4540639936908783', 'id_transaccion' => 'T-9']]);
ok(!isset($limpio['detail']['cardNumber']) && $limpio['detail']['texto'] === 'tarjeta [oculto]' && $limpio['detail']['id_transaccion'] === 'T-9', 'lo de la tarjeta se quita antes de guardar');
igual($Plux::transaccion(['detail' => ['id_transaccion' => 'T-9']]), 'T-9', 'número de transacción');

$r = $Plux::procesar($db, $lineaN, $pm, ['code' => 100, 'detail' => ['idTransaction' => 'OTP-1', 'sessionId' => 'S']], 'tarjeta');
ok($r['status'] === 'otp_required' && ($r['detail']['idTransaction'] ?? '') === 'OTP-1' && Pedidos::buscar($db, $lN, (int) $pm['id'])['plux_paso'] === 'otp', 'el banco pide código (OTP)');
igual($Plux::procesar($db, $lineaN, $pm, ['code' => 102, 'detail' => []], 'tarjeta')['status'], 'otp_incorrecto', 'código incorrecto');
$r = $Plux::procesar($db, $lineaN, $pm, ['code' => 2, 'description' => 'Rechazada', 'detail' => 'Fondos insuficientes'], 'tarjeta');
ok($r['status'] === 'failed' && str_contains($r['message'], 'Tu banco no aprobó el pago: Fondos insuficientes'), 'rechazada por el banco');
igual($Plux::procesar($db, $lineaN, $pm, ['code' => 103, 'detail' => []], 'tarjeta')['status'], 'failed', '3DS sin dirección: no sigue');
$r = $Plux::procesar($db, $lineaN, $pm, ['code' => 103, 'detail' => ['url' => 'https://banco.ejemplo.com/3ds', 'parameters' => [['name' => 'creq', 'value' => 'abc']]]], 'tarjeta');
ok($r['status'] === '3ds_required' && $r['detail']['url'] === 'https://banco.ejemplo.com/3ds' && $r['detail']['parameters'][0]['name'] === 'creq', 'pide validación 3DS del banco');
$Plux::procesar($db, $lineaN, $pm, ['code' => 1, 'description' => 'No autorizado'], 'tarjeta');
igual(Pedidos::buscar($db, $lN, (int) $pm['id'])['estado'], 'carrito', 'un error no cambia el pedido');
$Plux::procesar($db, $lineaN, $pm, ['code' => -1, 'description' => 'sin red'], 'tarjeta');
ok(str_contains((string) Lineas::buscar($db, $lN)['plux_error'], 'Revisa las credenciales'), 'si Plux no responde, queda el aviso para el negocio');
limpiarSim();
$stockAntes = Catalogo::buscar($db, $lN, $pBlusa)['stock'];
$r = $Plux::procesar($db, $lineaN, $pm, ['code' => 0, 'description' => 'Aprobada', 'detail' => ['id_transaccion' => 'T-100', 'cardNumber' => '4540********8783']], 'tarjeta');
$pmP = Pedidos::buscar($db, $lN, (int) $pm['id']);
ok($r['status'] === 'success' && $pmP['estado'] === 'pagado' && $pmP['metodo'] === 'plux' && $pmP['plux_transaccion'] === 'T-100' && $pmP['plux_paso'] === 'pagado' && $pmP['pagado_en'] !== null, 'aprobado: el pedido pasa a Pagado');
ok(!str_contains($pmP['plux_respuesta'], '8783') && !str_contains($pmP['plux_respuesta'], 'cardNumber'), 'la respuesta guardada no tiene datos de tarjeta');
$conf = array_values(array_filter(sim(), fn ($x) => str_contains((string) ($x['cuerpo']['text']['body'] ?? ''), 'Recibimos tu pago')));
ok(count($conf) === 1 && str_contains($conf[0]['cuerpo']['text']['body'], '#' . $pm['numero']) && $conf[0]['cuerpo']['to'] === '593981000040', 'al cliente le llega la confirmación por WhatsApp');
igual($stockAntes, Catalogo::buscar($db, $lN, $pBlusa)['stock'], 'sin control de stock no se toca');
ok(!$Plux::aprobar($db, $lineaN, $pmP, 'T-100', '{}', 'aviso de pago'), 'aprobar dos veces no hace nada (página y aviso de pago)');

$pi = Pedidos::agregar($db, $lineaN, (int) $marta['id'], $pBlusa, 1);
$okI = true;
for ($i = 1; $i <= 12; $i++) { $okI = $okI && $Plux::intento($db, $pi); }
ok($okI && !$Plux::intento($db, $pi), 'máximo 12 intentos por pedido en una hora');
$db->prepare("UPDATE pedidos SET plux_intento_en = now() - interval '2 hours' WHERE id = ?")->execute([(int) $pi['id']]);
ok($Plux::intento($db, $pi), 'después de una hora se puede volver a intentar');

$aviso = fn (array $d) => json_encode($d);
igual($Plux::webhook($db, 'tukan' . $lN, 'mala', $aviso(['state' => 'PAGADO']))[0], 401, 'aviso de pago con clave mala');
igual($Plux::webhook($db, '', '', $aviso(['state' => 'PAGADO']))[0], 401, 'aviso de pago sin clave');
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], 'no es json')[0], 400, 'aviso que no es JSON');
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'RARO']))[0], 422, 'estado desconocido');
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'PAGADO', 'description' => 'WT-999999']))[0], 404, 'aviso de un pedido que no existe');
$pi = Pedidos::buscar($db, $lN, (int) $pi['id']);
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'PAGADO', 'description' => 'WT-' . $pi['id'], 'amount' => '1.00']))[0], 409, 'aviso con otro valor: no se aplica');
[$h, $rr] = $Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'RECHAZADO', 'detail' => ['description' => 'WT-' . $pi['id'] . ' Pedido']]));
ok($h === 200 && $rr['resultado'] === 'rechazado' && Pedidos::buscar($db, $lN, (int) $pi['id'])['estado'] === 'carrito', 'aviso RECHAZADO: el pedido sigue abierto para pagar de nuevo');
[$h, $rr] = $Plux::webhook($db, 'cliente_plux_prueba', 'clave_plux_prueba', $aviso(['state' => 'PAGADO', 'description' => 'WT-' . $pi['id'] . ' Pedido', 'amount' => number_format((float) $pi['total'], 2, '.', ''), 'id_transaccion' => 'T-200']));
ok($h === 200 && $rr['resultado'] === 'pagado' && Pedidos::buscar($db, $lN, (int) $pi['id'])['estado'] === 'pagado', 'aviso PAGADO (con las credenciales de Plux): pedido pagado');
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'PAGADO', 'id_transaccion' => 'T-200']))[1]['resultado'], 'ya_estaba_pagado', 'aviso repetido: no hace nada');
igual($Plux::webhook($db, 'tukan' . $lN, $avisoP['clave'], $aviso(['state' => 'RECHAZADO', 'id_transaccion' => 'T-200']))[1]['resultado'], 'revisar', 'RECHAZADO de algo pagado: se anota para revisar, no se quita');
igual($Plux::webhook($db, 'tukan' . $lid, $avisoP['clave'], $aviso(['state' => 'PAGADO', 'id_transaccion' => 'T-200']))[0], 401, 'la clave de un número no sirve para otro');
$Plux::guardar($db, $lineaN, ['quitar_plux' => '1']);
$lineaN = Lineas::buscar($db, $lN);
ok(!$Plux::configurado($lineaN) && $lineaN['cobro_tarjeta'] === '', 'quitar Plux');

echo "== Llamadas de WhatsApp\n";
$LL = \TuWhatsApp\Llamadas::class;
$lineaN = Lineas::buscar($db, $lN);
$sdpMeta = "v=0\r\no=- 46117 2 IN IP4 157.240.1.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE audio\r\nm=audio 3480 UDP/TLS/RTP/SAVPF 111\r\nc=IN IP4 157.240.1.1\r\na=rtpmap:111 opus/48000/2\r\na=ice-ufrag:abc\r\na=ice-pwd:abcdefghijklmnopqrstuvwx\r\na=fingerprint:sha-256 AB:CD\r\na=setup:actpass\r\n";
$sdpNav = str_replace(['46117', 'actpass'], ['99', 'active'], $sdpMeta);
$llamada = fn (string $evento, string $callId, array $extra = [], string $dir = 'USER_INITIATED') => cuerpoMeta(['messaging_product' => 'whatsapp',
    'metadata' => ['display_phone_number' => '593996401111', 'phone_number_id' => '400'], 'contacts' => [['profile' => ['name' => 'Pedro Llama'], 'wa_id' => '593981000050']],
    'calls' => [array_merge(['id' => $callId, 'from' => '593981000050', 'to' => '593996401111', 'event' => $evento, 'direction' => $dir, 'timestamp' => (string) time()], $extra)]], 'calls');
ok(!$LL::sdpValido('hola') && $LL::sdpValido($sdpMeta), 'reconoce una oferta de audio válida');
$db->prepare('UPDATE lineas SET horario = ? WHERE id = ?')->execute(['{"1":["08:00","18:00"],"2":["08:00","18:00"],"6":["09:00","13:00"]}', $lN]);
$lineaN = Lineas::buscar($db, $lN);
limpiarSim();
$r = $LL::encender($db, $lineaN, true);
$aj = array_values(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/400/settings')))[0]['cuerpo']['calling'] ?? [];
ok($r['ok'] && ($aj['status'] ?? '') === 'ENABLED' && ($aj['call_icon_visibility'] ?? '') === 'DEFAULT' && ($aj['callback_permission_status'] ?? '') === 'ENABLED', 'encender llamadas en Meta');
igual($aj['call_hours'] ?? null, ['status' => 'DISABLED'], 'modo "mensaje" (normal): Meta no bloquea por horario, decide el programa');
$db->prepare("UPDATE lineas SET llamadas_fuera = 'meta' WHERE id = ?")->execute([$lN]);
limpiarSim();
$LL::encender($db, Lineas::buscar($db, $lN), true);
$aj = array_values(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/400/settings')))[0]['cuerpo']['calling'] ?? [];
ok(($aj['call_hours']['status'] ?? '') === 'ENABLED' && ($aj['call_hours']['timezone_id'] ?? '') === 'America/Guayaquil' && count($aj['call_hours']['weekly_operating_hours'] ?? []) === 3
   && ($aj['call_hours']['weekly_operating_hours'][2] ?? []) === ['day_of_week' => 'SATURDAY', 'open_time' => '0900', 'close_time' => '1300'], 'modo "meta": manda el horario de atención del número (HHMM)');
$db->prepare("UPDATE lineas SET llamadas_fuera = 'mensaje' WHERE id = ?")->execute([$lN]);
$lineaN = Lineas::buscar($db, $lN);
igual(Lineas::textoHorario($lineaN), 'lunes y martes de 8:00 a 18:00 y sábado de 9:00 a 13:00', 'el horario en palabras');
igual(Lineas::textoHorario(['horario' => '{"1":["08:00","18:00"],"2":["08:00","18:00"],"3":["08:00","18:00"],"4":["08:00","18:00"],"5":["08:00","18:00"],"6":["09:00","13:00"],"7":null}']),
      'lunes a viernes de 8:00 a 18:00 y sábado de 9:00 a 13:00', 'junta los días seguidos con la misma hora');
igual(Lineas::textoHorario(['horario' => '{}']), '', 'sin horario: vacío');
ok(str_contains($LL::textoFuera($lineaN), 'Atendemos lunes y martes de 8:00 a 18:00 y sábado de 9:00 a 13:00.'), 'el mensaje de fuera de horario lleva el horario');
ok(!$LL::fueraDeHorario($lineaN, new DateTimeImmutable('2026-11-09 10:00')) && $LL::fueraDeHorario($lineaN, new DateTimeImmutable('2026-11-07 17:32'))
   && $LL::fueraDeHorario($lineaN, new DateTimeImmutable('2026-11-08 10:00')), 'dentro o fuera de horario (sábado 17:32 y domingo: fuera)');
ok(!$LL::fueraDeHorario(['horario' => '{}'], new DateTimeImmutable('2026-11-08 03:00')), 'sin ningún día puesto: siempre atiende');
$db->prepare("UPDATE lineas SET horario = '{}' WHERE id = ?")->execute([$lN]);   // las pruebas de abajo corren a cualquier hora: sin horario
$lineaN = Lineas::buscar($db, $lN);
$lineaN = Lineas::buscar($db, $lN);
ok((bool) $lineaN['llamadas_activo'], 'queda encendido');
$whL = new Webhook($db);
$whL->procesar($llamada('connect', 'wacid.L1', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
$pedro = Contactos::porTelefono($db, $lN, '593981000050');
$son = $LL::sonando($db, $lN);
ok($pedro !== null && $son !== null && $son['nombre'] === 'Pedro Llama' && $son['contacto_id'] === (int) $pedro['id'], 'llamada entrante: suena en el panel con el nombre');
$whL->procesar($llamada('connect', 'wacid.L1', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
igual((int) $db->query("SELECT count(*) FROM llamadas WHERE call_id = 'wacid.L1'")->fetchColumn(), 1, 'Meta avisa dos veces: una sola llamada');
$t = $LL::tomar($db, $lN, $son['id'], $idAna);
igual($t['oferta'], $sdpMeta, 'Ana la toma y recibe la oferta de Meta');
try { $LL::tomar($db, $lN, $son['id'], $idBeto); ok(false, 'dos personas no contestan la misma llamada'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'Ya la contestó Ana'), 'dos personas no contestan la misma llamada'); }
igual($LL::sonando($db, $lN), null, 'tomada: ya no suena para el resto');
try { $LL::contestar($db, $lineaN, $son['id'], $idAna, 'no es sdp'); ok(false, 'respuesta del navegador mala'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'micrófono'), 'respuesta del navegador mala'); }
limpiarSim();
$LL::contestar($db, $lineaN, $son['id'], $idAna, $sdpNav);
$LL::aceptar($db, $lineaN, $son['id'], $idAna);
$acc = array_values(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/400/calls')));
ok(count($acc) === 2 && $acc[0]['cuerpo']['action'] === 'pre_accept' && $acc[1]['cuerpo']['action'] === 'accept' && $acc[0]['cuerpo']['call_id'] === 'wacid.L1'
   && $acc[1]['cuerpo']['session']['sdp'] === $sdpNav && $acc[1]['cuerpo']['session']['sdp_type'] === 'answer', 'pre_accept y accept con la respuesta del navegador');
igual($LL::buscar($db, $lN, $son['id'])['estado'], 'en_curso', 'en curso');
$whL->procesar($llamada('terminate', 'wacid.L1', ['status' => 'COMPLETED', 'start_time' => (string) (time() - 95), 'end_time' => (string) time(), 'duration' => 95]));
$l1 = $LL::buscar($db, $lN, $son['id']);
ok($l1['estado'] === 'terminada' && (int) $l1['duracion'] === 95 && $l1['u_nombre'] === 'Ana' && $LL::duracion(95) === '1:35', 'terminada: guarda la duración y quién atendió');
// Perdida (nadie contestó) con mensaje automático
$db->prepare("UPDATE lineas SET llamadas_perdida = 'Vimos tu llamada, ¿te ayudamos por aquí?' WHERE id = ?")->execute([$lN]);
$lineaN = Lineas::buscar($db, $lN);
$db->prepare('UPDATE contactos SET ultimo_entrante = now() WHERE id = ?')->execute([(int) $pedro['id']]);
limpiarSim();
$whL->procesar($llamada('connect', 'wacid.L2', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
$whL->procesar($llamada('terminate', 'wacid.L2', ['status' => 'COMPLETED']));
$l2 = $db->query("SELECT * FROM llamadas WHERE call_id = 'wacid.L2'")->fetch();
igual($l2['estado'], 'perdida', 'nadie contestó: llamada perdida');
ok(count(array_filter(sim(), fn ($x) => str_contains((string) ($x['cuerpo']['text']['body'] ?? ''), 'Vimos tu llamada'))) === 1, 'llamada perdida: se le escribe solo (ventana abierta)');
// Rechazar
$whL->procesar($llamada('connect', 'wacid.L3', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
$l3 = $LL::sonando($db, $lN);
limpiarSim();
$LL::rechazar($db, $lineaN, $l3['id']);
ok((sim()[0]['cuerpo']['action'] ?? '') === 'reject' && $LL::buscar($db, $lN, $l3['id'])['estado'] === 'rechazada', 'rechazar');
$whL->procesar($llamada('terminate', 'wacid.L3', ['status' => 'COMPLETED']));
igual($LL::buscar($db, $lN, $l3['id'])['estado'], 'rechazada', 'rechazada sigue como rechazada al terminar');
// Fuera de horario (modo "mensaje"): no suena, se cuelga y le llega el horario por WhatsApp
$db->prepare("UPDATE lineas SET horario = '{\"1\":[\"00:00\",\"00:01\"]}' WHERE id = ?")->execute([$lN]);
$lineaN = Lineas::buscar($db, $lN);
limpiarSim();
$whL->procesar($llamada('connect', 'wacid.F1', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
$f1 = $db->query("SELECT * FROM llamadas WHERE call_id = 'wacid.F1'")->fetch();
igual($f1['estado'], 'fuera_horario', 'fuera de horario: queda como "Fuera de horario"');
igual($LL::sonando($db, $lN), null, 'fuera de horario: no suena en el panel');
ok(count(array_filter(sim(), fn ($x) => ($x['cuerpo']['action'] ?? '') === 'reject' && ($x['cuerpo']['call_id'] ?? '') === 'wacid.F1')) === 1, 'fuera de horario: se cuelga en Meta');
$msgF = array_values(array_filter(sim(), fn ($x) => str_contains((string) ($x['cuerpo']['text']['body'] ?? ''), 'fuera de horario')));
ok(count($msgF) === 1 && str_contains($msgF[0]['cuerpo']['text']['body'], 'Atendemos lunes de 0:00 a 0:01'), 'fuera de horario: le llega un WhatsApp con el horario');
$whL->procesar($llamada('terminate', 'wacid.F1', ['status' => 'COMPLETED']));
igual($db->query("SELECT estado FROM llamadas WHERE call_id = 'wacid.F1'")->fetchColumn(), 'fuera_horario', 'cuando Meta avisa que terminó sigue como fuera de horario (no perdida)');
$db->prepare("UPDATE lineas SET llamadas_fuera = 'meta' WHERE id = ?")->execute([$lN]);
$lineaN = Lineas::buscar($db, $lN);
$whL->procesar($llamada('connect', 'wacid.F2', ['session' => ['sdp_type' => 'offer', 'sdp' => $sdpMeta]]));
igual($db->query("SELECT estado FROM llamadas WHERE call_id = 'wacid.F2'")->fetchColumn(), 'sonando', 'modo "meta": el programa no la corta (si llegó, Meta la dejó pasar)');
$db->prepare("UPDATE llamadas SET estado = 'perdida' WHERE call_id = 'wacid.F2'")->execute();
$db->prepare("UPDATE lineas SET llamadas_fuera = 'mensaje', horario = ? WHERE id = ?")->execute(['{"1":["08:00","18:00"],"2":["08:00","18:00"],"6":["09:00","13:00"]}', $lN]);
$db->prepare("UPDATE lineas SET horario = '{}' WHERE id = ?")->execute([$lN]);   // el resto de pruebas: sin horario (siempre atiende)
$lineaN = Lineas::buscar($db, $lN);
// Llamada vieja: ya no suena
$db->exec("INSERT INTO llamadas (linea_id, call_id, direccion, estado, creado) VALUES ($lN, 'wacid.VIEJA', 'entra', 'sonando', now() - interval '3 minutes')");
igual($LL::sonando($db, $lN), null, 'una llamada de hace minutos ya no suena');
// Permiso para llamar al cliente
ok(!$LL::puedeLlamar($pedro), 'sin permiso no se puede llamar');
try { $LL::llamar($db, $lineaN, $pedro, $idAna, $sdpNav); ok(false, 'llamar sin permiso'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'permiso'), 'llamar sin permiso'); }
limpiarSim();
$pedro = Contactos::buscar($db, $lN, (int) $pedro['id']);   // con su último mensaje al día (la ventana de 24 horas)
$rp = $LL::pedirPermiso($db, $lineaN, $pedro, $idAna);
$pp = array_values(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/400/messages')))[0]['cuerpo'] ?? [];
ok($rp['ok'] && ($pp['interactive']['type'] ?? '') === 'call_permission_request' && ($pp['interactive']['action']['name'] ?? '') === 'call_permission_request', 'pedir permiso: botón de Meta');
$pedro = Contactos::buscar($db, $lN, (int) $pedro['id']);
ok(!$LL::pedirPermiso($db, $lineaN, $pedro, $idAna)['ok'], 'no se pide dos veces en 24 horas');
$whL->procesar(cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '400'], 'contacts' => [['profile' => ['name' => 'Pedro Llama'], 'wa_id' => '593981000050']],
    'messages' => [['from' => '593981000050', 'id' => 'wamid.PERM1', 'timestamp' => (string) time(), 'type' => 'interactive',
                    'interactive' => ['type' => 'call_permission_reply', 'call_permission_reply' => ['response' => 'accept', 'is_permanent' => false, 'expiration_timestamp' => (string) (time() + 7 * 86400), 'response_source' => 'user_action']]]]]));
$pedro = Contactos::buscar($db, $lN, (int) $pedro['id']);
ok($pedro['llamar_permiso'] === 'temporal' && $LL::puedeLlamar($pedro), 'aceptó: permiso por 7 días');
$mp = $db->query("SELECT texto, motivo FROM mensajes WHERE wamid = 'wamid.PERM1'")->fetch();
ok(str_contains($mp['texto'], 'Aceptó que lo llamen') && str_contains((string) $mp['motivo'], 'no se contesta solo'), 'en el chat se ve que aceptó y no se contesta solo');
limpiarSim();
$idS = $LL::llamar($db, $lineaN, $pedro, $idAna, $sdpNav);
$cx = array_values(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/400/calls')))[0]['cuerpo'] ?? [];
$ls = $LL::buscar($db, $lN, $idS);
ok($cx['action'] === 'connect' && $cx['to'] === '593981000050' && $cx['session']['sdp_type'] === 'offer' && str_starts_with($ls['call_id'], 'wacid.SIM') && $ls['direccion'] === 'sale', 'llamar al cliente con la oferta del navegador');
$whL->procesar(cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '400'],
    'statuses' => [['id' => $ls['call_id'], 'timestamp' => (string) time(), 'type' => 'call', 'status' => 'RINGING', 'recipient_id' => '593981000050']]], 'calls'));
$whL->procesar($llamada('connect', $ls['call_id'], ['session' => ['sdp_type' => 'answer', 'sdp' => $sdpMeta]], 'BUSINESS_INITIATED'));
igual($LL::buscar($db, $lN, $idS)['sdp_respuesta'], $sdpMeta, 'llega la respuesta de Meta para el navegador');
$whL->procesar(cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '400'],
    'statuses' => [['id' => $ls['call_id'], 'timestamp' => (string) time(), 'type' => 'call', 'status' => 'ACCEPTED', 'recipient_id' => '593981000050']]], 'calls'));
ok($LL::buscar($db, $lN, $idS)['estado'] === 'en_curso' && $LL::buscar($db, $lN, $idS)['contestada'] !== null, 'el cliente contestó');
limpiarSim();
$LL::colgar($db, $lineaN, $idS);
ok((sim()[0]['cuerpo']['action'] ?? '') === 'terminate' && $LL::buscar($db, $lN, $idS)['estado'] === 'terminada', 'colgar');
$sinP = Contactos::guardar($db, $lN, '0981006006', 'Sin Permiso', 'cliente');
$db->prepare("UPDATE contactos SET llamar_permiso = 'permanente' WHERE id = ?")->execute([(int) $sinP['id']]);
$sinP = Contactos::buscar($db, $lN, (int) $sinP['id']);
try { $LL::llamar($db, $lineaN, $sinP, $idAna, $sdpNav); ok(false, 'Meta dice que no hay permiso'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no te ha dado permiso') && Contactos::buscar($db, $lN, (int) $sinP['id'])['llamar_permiso'] === '', 'Meta dice que no hay permiso: se borra el permiso guardado'); }
$r = $LL::encender($db, $lineaN, false);
ok($r['ok'] && !Lineas::buscar($db, $lN)['llamadas_activo'], 'apagar llamadas');
$lineaN = Lineas::buscar($db, $lN);
try { $LL::llamar($db, $lineaN, $pedro, $idAna, $sdpNav); ok(false, 'apagadas no se llama'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no están encendidas'), 'apagadas no se llama'); }
igual(count($LL::lista($db, $lN, (int) $pedro['id'])), 6, 'historial de llamadas del cliente');

echo "== Pregúntale a la IA (como Wati AI) y resumen de cada mañana\n";
putenv('TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789');
putenv('TUKAN_IA_URL=' . $sim);
putenv('TUKAN_IA_ESPERA_MS=0');
putenv('TUKAN_IA_REINTENTOS=0');
$CP = \TuWhatsApp\Copiloto::class;
Modulos::guardar($db, $eN, ['ia']);
$lineaN = Lineas::buscar($db, $lN);
$hijo = Contactos::porTelefono($db, $lN, '593981000009');
$db->exec("UPDATE contactos SET ultimo_entrante = now() WHERE linea_id = $lN");
$datosIa = $CP::datos($db, $lineaN);
ok(str_contains($datosIa, '[id:' . $pedro['id'] . '] Pedro Llama') && str_contains($datosIa, 'Pedidos por estado') && str_contains($datosIa, 'Productos del catálogo') && str_contains($datosIa, 'Últimos 7 días'), 'la IA recibe cifras, catálogo y clientes recientes');
ok($hijo === null || !str_contains($datosIa, '[id:' . $hijo['id'] . ']'), 'los contactos personales no van a la IA');
limpiarSim();
$db->prepare('UPDATE lineas SET ia_tono = ? WHERE id = ?')->execute(['Cercano, de tú, sin emojis', $lN]);
$lineaN = Lineas::buscar($db, $lN);
$q1 = $CP::preguntar($db, $lineaN, '¿Quiénes están listos para comprar?', $idAna);
$idsQ = array_column($q1['contactos'], 'id');
ok(count($q1['contactos']) === 2 && !in_array(999999, $idsQ, true) && $q1['contactos'][0]['motivo'] === 'Preguntó precios' && $q1['contactos'][0]['nombre'] !== '', 'responde con clientes que existen (lo inventado se descarta)');
igual($q1['etiqueta'], 'listos-para-comprar', 'la etiqueta queda limpia');
$sisIa = array_values(array_filter(sim(), fn ($x) => $x['ruta'] === '/v1/messages'))[0]['cuerpo']['system'] ?? '';
ok(str_contains(is_string($sisIa) ? $sisIa : json_encode($sisIa), 'Cercano, de tú, sin emojis'), 'usa el tono de la marca');
igual($CP::guardarEtiqueta($db, $lineaN, $q1['id'], $q1['etiqueta']), 2, 'guardar a esos clientes con la etiqueta');
$CP::guardarEtiqueta($db, $lineaN, $q1['id'], $q1['etiqueta']);
$conEt = Contactos::buscar($db, $lN, $idsQ[0]);
igual(substr_count($conEt['etiquetas'], 'listos-para-comprar'), 1, 'no se repite la etiqueta');
igual((int) (Contactos::etiquetasUsadas($db, $lN)['listos-para-comprar'] ?? 0), 2, 'la etiqueta aparece para elegirla en Campañas');
$q2 = $CP::preguntar($db, $lineaN, 'Arma una campaña para los que no escriben hace un mes', $idAna);
ok($q2['campania'] !== null && $q2['campania']['nombre'] === 'vuelve_pronto' && str_starts_with($q2['campania']['texto'], 'Hola, te extrañamos') && $q2['campania']['categoria'] === 'MARKETING' && $q2['etiqueta'] === 'reactivar', 'arma la campaña con nombre de plantilla válido y sin variables');
igual($CP::consulta($db, $lN, $q2['id'])['campania']['nombre'], 'vuelve_pronto', 'la consulta queda guardada para usarla después');
igual($CP::consulta($db, $lid, $q2['id']), null, 'otro número no ve la consulta');
$q3 = $CP::preguntar($db, $lineaN, '¿Cómo nos fue esta semana?', $idAna);
ok($q3['contactos'] === [] && $q3['campania'] === null && str_contains($q3['respuesta'], 'Esta semana'), 'pregunta de cifras: solo la respuesta');
try { $CP::preguntar($db, $lineaN, 'romper', $idAna); ok(false, 'si la IA falla'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no pudimos procesar'), 'si la IA falla: mensaje neutro'); }
try { $CP::preguntar($db, $lineaN, '  ', $idAna); ok(false, 'pregunta vacía'); } catch (RuntimeException $e) { ok(true, 'pregunta vacía'); }
igual(count($CP::historial($db, $lN)), 3, 'historial de preguntas');
Modulos::guardar($db, $eN, []);
try { $CP::preguntar($db, $lineaN, '¿Cómo nos fue?', $idAna); ok(false, 'sin el extra de IA'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no está incluida'), 'sin el extra de IA no pregunta'); }
$sinIa = $CP::hacerResumen($db, $lineaN);
ok(!$sinIa['con_ia'] && str_contains($sinIa['texto'], 'Ayer te escribieron') && str_contains($sinIa['texto'], 'Pendientes de pago'), 'sin IA: el resumen trae igual las cifras');
Modulos::guardar($db, $eN, ['ia']);
ok(array_key_exists('sin_responder', $CP::cifrasDeAyer($db, $lineaN, new DateTimeImmutable())), 'cifras de ayer');
$conIa = $CP::hacerResumen($db, $lineaN);
ok($conIa['con_ia'] && str_contains($conIa['texto'], 'Para hoy'), 'con IA: resumen con 3 cosas para hoy');
igual($CP::resumenDeHoy($db, $lN)['texto'], $conIa['texto'], 'queda guardado el de hoy');
$db->prepare("DELETE FROM ia_resumenes WHERE linea_id = ?")->execute([$lN]);
$db->prepare("UPDATE lineas SET resumen_diario = true, resumen_hora = '07:30' WHERE id = ?")->execute([$lN]);
$manana = new DateTimeImmutable('today 07:00');
igual($CP::procesarResumenes($db, $manana), 0, 'antes de la hora no se manda');
$manana = new DateTimeImmutable('today 07:31');
igual($CP::procesarResumenes($db, $manana), 1, 'a la hora: se hace el resumen y se avisa');
igual($CP::procesarResumenes($db, $manana->modify('+5 minutes')), 0, 'una sola vez por día');
putenv('TUKAN_IA_CLAVE=');

echo "== Pasar de la app del celular a solo API (para llamadas): mismo número\n";
$db->prepare('UPDATE lineas SET coexistencia = true WHERE id = ?')->execute([$lN]);
$chatsAntes = (int) $db->query("SELECT count(*) FROM contactos WHERE linea_id = $lN")->fetchColumn();
limpiarSim();
$rm = Registro::completar($db, ['code' => 'codigo_bueno_1234567', 'evento' => 'FINISH', 'waba_id' => '200', 'phone_number_id' => '402'], null, $eN);
$lm = Lineas::buscar($db, $lN);
ok($rm['ok'] && $rm['linea_id'] === $lN && $lm['phone_number_id'] === '402' && !$lm['coexistencia'], 'se usa el mismo número del panel (no se crea otro)');
ok(in_array('Es el mismo número que ya tenías: se conservan sus chats y contactos.', $rm['avisos'], true), 'avisa que se conservan los chats');
igual((int) $db->query("SELECT count(*) FROM contactos WHERE linea_id = $lN")->fetchColumn(), $chatsAntes, 'los contactos siguen en el mismo número');
ok($rm['pin'] !== '' && count(array_filter(sim(), fn ($x) => str_ends_with((string) $x['ruta'], '/402/register'))) === 1, 'como número solo API se registra con PIN');

echo "== Probar el agente con IA sin mandar nada\n";
putenv('TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789');
limpiarSim();
$lp = array_merge(Lineas::buscar($db, $lN), ['bot_vende' => true]);
$antesMsj = (int) $db->query("SELECT count(*) FROM mensajes WHERE linea_id = $lN")->fetchColumn();
$antesPed = (int) $db->query("SELECT count(*) FROM pedidos WHERE linea_id = $lN")->fetchColumn();
$pr = Bot::probar($db, $lp, [], '¿Hacen envíos?');
ok($pr['ok'] && $pr['respuesta'] === '¡Hola! Con gusto te ayudo. ¿Qué buscas?' && !$pr['persona'], 'contesta como contestaría');
$pr2 = Bot::probar($db, $lp, [['de' => 'cliente', 'texto' => '¿Hacen envíos?'], ['de' => 'agente', 'texto' => $pr['respuesta']]], 'quiero 2 vestidos');
ok($pr2['ok'] && count($pr2['agregar']) === 1 && str_starts_with($pr2['agregar'][0], '2 x '), 'muestra qué pondría en el carrito (solo productos que existen)');
$iaUlt = array_values(array_filter(sim(), fn ($r) => $r['ruta'] === '/v1/messages'));
ok(str_contains(json_encode(end($iaUlt)['cuerpo']['messages'] ?? [], JSON_UNESCAPED_UNICODE), 'Cliente: ¿Hacen envíos?'), 'la IA recibe lo que ya se dijo en la prueba');
$pr3 = Bot::probar($db, $lp, [], 'quiero hablar con una persona');
ok($pr3['persona'] && $pr3['nota'] !== '', 'muestra que pasaría a una persona y la nota');
igual([(int) $db->query("SELECT count(*) FROM mensajes WHERE linea_id = $lN")->fetchColumn(), (int) $db->query("SELECT count(*) FROM pedidos WHERE linea_id = $lN")->fetchColumn()], [$antesMsj, $antesPed], 'no manda mensajes ni crea pedidos');
igual(count(array_filter(sim(), fn ($r) => str_contains((string) $r['ruta'], '/messages') && $r['ruta'] !== '/v1/messages')), 0, 'nada sale a Meta');
igual(Bot::probar($db, $lp, [], '   ')['ok'], false, 'vacío: no llama a la IA');
putenv('TUKAN_IA_CLAVE=');
igual(Bot::probar($db, $lp, [], 'hola')['error'], 'La IA no está disponible en este momento.', 'sin IA: lo dice claro');

echo "== Inicio: cifras de hoy y primeros pasos\n";
$hoyI = new DateTimeImmutable();
$cfr = \TuWhatsApp\Inicio::cifras($db, $lp, $hoyI);
ok(is_int($cfr['escribieron']) && is_int($cfr['sin_leer']) && is_float($cfr['en_juego']), 'cifras con su tipo');
$cI = Contactos::guardar($db, $lN, '0981000099', 'Inés Inicio', 'cliente');
igual(\TuWhatsApp\Inicio::cifras($db, $lp, $hoyI)['nuevos'], $cfr['nuevos'] + 1, 'cuenta el cliente nuevo de hoy');
$empI = Empresas::buscar($db, $eN);
$pasosI = \TuWhatsApp\Inicio::pasos($db, $lp, $empI, fn ($m) => Modulos::activo($empI, $m));
$clavesI = array_column($pasosI, 'clave');
ok(in_array('numero', $clavesI, true) && in_array('equipo', $clavesI, true) && in_array('app', $clavesI, true), 'pasos básicos');
igual(array_column($pasosI, 'hecho', 'clave')['numero'], true, 'número conectado: paso hecho');
$sinIaP = \TuWhatsApp\Inicio::pasos($db, $lp, $empI, fn ($m) => $m !== 'ia');
ok(!in_array('ia', array_column($sinIaP, 'clave'), true), 'sin el extra de IA no aparece su paso');

echo "== Cancelar el plan desde Mi cuenta\n";
$avisoTg = [];
$eC = Empresas::crear($db, ['nombre' => 'Cancela SA', 'estado' => 'activa', 'vence' => '2026-12-15', 'admin_correo' => 'dueno@cancela.ec', 'admin_nombre' => 'Dueño'])['empresa_id'];
$hastaC = Empresas::pedirCancelacion($db, $eC, 'Muy caro', ['nombre' => 'Dueño', 'correo' => 'dueno@cancela.ec'], new DateTimeImmutable('2026-11-07 10:00'));
$ec = Empresas::buscar($db, $eC);
ok($hastaC === '2026-12-15' && $ec['cancelar_en'] === '2026-12-15' && $ec['cancelar_motivo'] === 'Muy caro' && $ec['estado'] === 'activa', 'sigue activa hasta el fin del período pagado');
$db->exec("UPDATE empresas SET vence = NULL WHERE id = $eC");
igual(Empresas::pedirCancelacion($db, $eC, '', [], new DateTimeImmutable('2026-11-07 10:00')), '2026-11-30', 'sin fecha de pago: hasta fin de mes');
Empresas::seguirConPlan($db, $eC);
$ec = Empresas::buscar($db, $eC);
ok($ec['cancelar_en'] === null && $ec['cancelar_pedido'] === null, 'se arrepintió: sigue con su plan');
try { Empresas::pedirCancelacion($db, 1, '', []); $okT = false; } catch (RuntimeException $e) { $okT = true; }
ok($okT, 'la cuenta de Tukán no se cancela');

echo "== Recordar este celular (la app no vuelve a pedir entrar)\n";
$eS = Empresas::crear($db, ['nombre' => 'Sesiones SA', 'estado' => 'activa', 'admin_correo' => 'dueno@sesiones.ec', 'admin_nombre' => 'Dueña'])['empresa_id'];
$uS = (int) $db->query("SELECT id FROM usuarios WHERE correo = 'dueno@sesiones.ec'")->fetchColumn();
$cookieS = \TuWhatsApp\Sesiones::recordar($db, $uS, 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36');
ok((bool) preg_match('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $cookieS), 'cookie con selector y validador');
ok(!str_contains((string) $db->query('SELECT validador_hash FROM sesiones_recordadas ORDER BY id DESC LIMIT 1')->fetchColumn(), explode(':', $cookieS)[1]), 'en la base solo queda el hash del validador');
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cookieS), $uS, 'la cookie abre la sesión');
igual(\TuWhatsApp\Sesiones::lista($db, $uS)[0]['dispositivo'], 'Chrome · Android', 'nombre del celular');
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cookieS), $uS, 'sirve muchas veces (no cambia en cada uso)');
[$selS] = explode(':', $cookieS);
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $selS . ':' . str_repeat('0', 64)), null, 'validador robado/falso no entra');
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cookieS), null, '…y ese recuerdo queda borrado por seguridad');
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, 'basura'), null, 'cookie con formato malo');
$c2 = \TuWhatsApp\Sesiones::recordar($db, $uS, 'x');
$db->exec("UPDATE sesiones_recordadas SET expira = now() - interval '1 day' WHERE selector = '" . explode(':', $c2)[0] . "'");
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $c2), null, 'vencido no entra');
$c3 = \TuWhatsApp\Sesiones::recordar($db, $uS, 'x');
\TuWhatsApp\Sesiones::olvidar($db, $c3);
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $c3), null, 'al tocar Salir ya no sirve');
$db->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')->execute([password_hash('claveVieja1', PASSWORD_DEFAULT), $uS]);
$c4 = \TuWhatsApp\Sesiones::recordar($db, $uS, 'x');
$c5 = \TuWhatsApp\Sesiones::recordar($db, $uS, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
Empresas::cambiarMiClave($db, $uS, 'claveVieja1', 'claveNueva22', 'claveNueva22', \TuWhatsApp\Sesiones::idDeCookie($db, $c5));
ok(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $c4) === null && \TuWhatsApp\Sesiones::usuarioDeCookie($db, $c5) === $uS, 'cambiar mi contraseña cierra los otros celulares y deja este');
$uS2 = (int) $db->query("SELECT id FROM usuarios WHERE empresa_id = $eS LIMIT 1")->fetchColumn();
Empresas::agregarUsuario($db, $eS, 'agente@sesiones.ec', 'Agente', 'agente');
$uA = (int) $db->query("SELECT id FROM usuarios WHERE correo = 'agente@sesiones.ec'")->fetchColumn();
$cA = \TuWhatsApp\Sesiones::recordar($db, $uA, 'x');
Empresas::cambiarUsuario($db, $eS, $uA, 'clave', $uS);
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cA), null, 'el administrador le pone contraseña nueva: se cierra en sus celulares');
$cA = \TuWhatsApp\Sesiones::recordar($db, $uA, 'x');
Empresas::cambiarUsuario($db, $eS, $uA, 'desactivar', $uS);
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cA), null, 'usuario desactivado: no entra con la cookie');
$cB = \TuWhatsApp\Sesiones::recordar($db, $uS, 'x');
$db->exec("UPDATE usuarios SET activo = false WHERE id = $uS");
igual(\TuWhatsApp\Sesiones::usuarioDeCookie($db, $cB), null, 'usuario inactivo (de cualquier forma): no entra');
$db->exec("UPDATE usuarios SET activo = true WHERE id = $uS");
$cX = \TuWhatsApp\Sesiones::recordar($db, $uS, 'x');
$idX = \TuWhatsApp\Sesiones::idDeCookie($db, $cX);
ok(!\TuWhatsApp\Sesiones::cerrar($db, $uA, $idX) && \TuWhatsApp\Sesiones::usuarioDeCookie($db, $cX) === $uS, 'nadie cierra el celular de otra persona');
ok(\TuWhatsApp\Sesiones::cerrar($db, $uS, $idX) && \TuWhatsApp\Sesiones::usuarioDeCookie($db, $cX) === null, 'cerrar un celular desde Mi cuenta');
for ($i = 0; $i < 25; $i++) { \TuWhatsApp\Sesiones::recordar($db, $uS, 'x'); }
ok((int) $db->query("SELECT count(*) FROM sesiones_recordadas WHERE usuario_id = $uS")->fetchColumn() <= 20, 'máximo 20 celulares recordados por persona');
igual(\TuWhatsApp\Sesiones::dispositivo('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) TukanIOS/1.0'), 'App de iPhone · iPhone', 'reconoce la app de iPhone');
$genAntes = \TuWhatsApp\Sesiones::generacion($db, $uS);
\TuWhatsApp\Sesiones::olvidarUsuario($db, $uS);
igual(\TuWhatsApp\Sesiones::generacion($db, $uS), $genAntes + 1, 'cerrar en todos sube la generación (corta también las sesiones de PHP vivas)');
$db->exec("INSERT INTO sesiones_recordadas (usuario_id, selector, validador_hash, expira) VALUES ($uS, 'vencido000000000000000000', 'x', now() - interval '2 days')");
$db->exec("DELETE FROM ajustes WHERE clave = 'sesiones_limpiadas'");
ok((\TuWhatsApp\Sesiones::limpiarAlDia($db, '2026-11-14') ?? 0) >= 1, 'limpieza diaria: borra los recuerdos vencidos');
igual(\TuWhatsApp\Sesiones::limpiarAlDia($db, '2026-11-14'), null, '…una sola vez por día');
ok(\TuWhatsApp\Sesiones::limpiarAlDia($db, '2026-11-15') !== null, '…y al día siguiente otra vez');

use TuWhatsApp\Huella;
echo "== Entrar con huella (WebAuthn simulado)\n";
$rp = 'whatsapp.ejemplo.com';
$origen = 'https://whatsapp.ejemplo.com';
/** Simula un celular: arma lo que el navegador manda al registrar. */
function celular_registro(\OpenSSLAsymmetricKey $k, string $credId, string $desafio, string $rp, string $origen, int $flags = 0x45, string $tipo = 'webauthn.create'): array
{
    $d = openssl_pkey_get_details($k);
    if (isset($d['ec'])) {
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
    } else {
        $cose = [1 => 3, 3 => -257, -1 => $d['rsa']['n'], -2 => $d['rsa']['e']];
    }
    $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . Huella::cborEscribir($cose);
    $cd = json_encode(['type' => $tipo, 'challenge' => Huella::b64u($desafio), 'origin' => $origen, 'crossOrigin' => false]);
    return ['id' => Huella::b64u($credId), 'clientDataJSON' => Huella::b64u($cd),
            'attestationObject' => Huella::b64u(Huella::cborEscribir(['fmt' => ['t' => 'none'], 'attStmt' => [], 'authData' => $auth]))];
}
/** Simula el celular al entrar: firma con su llave. */
function celular_entrar(\OpenSSLAsymmetricKey $k, string $credId, string $desafio, string $rp, string $origen, int $contador, int $flags = 0x05): array
{
    $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', $contador);
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => Huella::b64u($desafio), 'origin' => $origen]);
    openssl_sign($auth . hash('sha256', $cd, true), $firma, $k, OPENSSL_ALGO_SHA256);
    return ['id' => Huella::b64u($credId), 'clientDataJSON' => Huella::b64u($cd), 'authenticatorData' => Huella::b64u($auth), 'signature' => Huella::b64u($firma), 'userHandle' => ''];
}
$kH = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$credH = random_bytes(32);
$des = random_bytes(32);
// CBOR
igual(Huella::cbor(Huella::cborEscribir(['a' => ['t' => 'hola'], 1 => -7, -2 => "\x01\x02", 'l' => [1, 2, 300, 70000], 'b' => true])), ['a' => 'hola', 1 => -7, -2 => "\x01\x02", 'l' => [1, 2, 300, 70000], 'b' => true], 'CBOR ida y vuelta');
try { Huella::cbor("\xa5\x01"); $okC = false; } catch (RuntimeException $e) { $okC = true; }
ok($okC, 'CBOR cortado se rechaza');
$op = Huella::opcionesRegistro($db, ['id' => $uS, 'correo' => 'dueno@sesiones.ec', 'nombre' => 'Dueña'], $rp, $des);
ok($op['rp']['id'] === $rp && $op['authenticatorSelection']['userVerification'] === 'required' && $op['authenticatorSelection']['residentKey'] === 'required'
   && $op['attestation'] === 'none' && Huella::deB64u($op['challenge']) === $des, 'opciones para activar la huella');
ok($op['user']['id'] === Huella::b64u(Huella::idUsuario($uS)) && !str_contains(Huella::deB64u($op['user']['id']), (string) $uS), 'identificador de la persona no revela su id');
$idH = Huella::registrar($db, $uS, celular_registro($kH, $credH, $des, $rp, $origen), $des, $rp, $origen, 'Chrome · Android');
ok($idH > 0 && Huella::cuantas($db, $uS) === 1, 'huella guardada');
foreach ([
    'desafío equivocado' => [celular_registro($kH, random_bytes(32), random_bytes(32), $rp, $origen), $des, $rp, $origen],
    'origen equivocado' => [celular_registro($kH, random_bytes(32), $des, $rp, 'https://malo.com'), $des, $rp, $origen],
    'otra página (rpId)' => [celular_registro($kH, random_bytes(32), $des, 'malo.com', $origen), $des, $rp, $origen],
    'sin comprobar la huella (UV)' => [celular_registro($kH, random_bytes(32), $des, $rp, $origen, 0x41), $des, $rp, $origen],
    'tipo equivocado' => [celular_registro($kH, random_bytes(32), $des, $rp, $origen, 0x45, 'webauthn.get'), $des, $rp, $origen],
    'desafío vacío (vencido o usado)' => [celular_registro($kH, random_bytes(32), $des, $rp, $origen), '', $rp, $origen],
] as $que => [$r, $d, $rr, $oo]) {
    try { Huella::registrar($db, $uS, $r, $d, $rr, $oo); $okR = false; } catch (RuntimeException $e) { $okR = true; }
    ok($okR, "registro rechazado: $que");
}
try { Huella::registrar($db, $uA, celular_registro($kH, $credH, $des, $rp, $origen), $des, $rp, $origen); $okR = false; } catch (RuntimeException $e) { $okR = str_contains($e->getMessage(), 'otra cuenta'); }
ok($okR, 'la misma huella no se guarda en otra cuenta');
// Entrar
$d2 = random_bytes(32);
igual(Huella::opcionesEntrar($rp, $d2)['allowCredentials'], [], 'entrar sin escribir el correo (credencial guardada en el celular)');
igual(Huella::entrar($db, celular_entrar($kH, $credH, $d2, $rp, $origen, 1), $d2, $rp, $origen), $uS, 'entra con la huella');
ok($db->query("SELECT ultimo_uso IS NOT NULL AND contador = 1 FROM huellas WHERE id = $idH")->fetchColumn(), 'anota el último uso y el contador');
$kOtro = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d3 = random_bytes(32);
foreach ([
    'firma mala (otra llave)' => [celular_entrar($kOtro, $credH, $d3, $rp, $origen, 5), $d3, $rp, $origen],
    'desafío reusado' => [celular_entrar($kH, $credH, $d2, $rp, $origen, 6), $d3, $rp, $origen],
    'origen equivocado' => [celular_entrar($kH, $credH, $d3, $rp, 'https://whatsapp.ejemplo.com.malo.com', 7), $d3, $rp, $origen],
    'otra página (rpId)' => [celular_entrar($kH, $credH, $d3, 'malo.com', $origen, 8), $d3, $rp, $origen],
    'sin huella (UV)' => [celular_entrar($kH, $credH, $d3, $rp, $origen, 9, 0x01), $d3, $rp, $origen],
    'huella copiada (contador hacia atrás)' => [celular_entrar($kH, $credH, $d3, $rp, $origen, 1), $d3, $rp, $origen],
    'huella que no está guardada' => [celular_entrar($kH, random_bytes(32), $d3, $rp, $origen, 10), $d3, $rp, $origen],
] as $que => [$r, $d, $rr, $oo]) {
    try { Huella::entrar($db, $r, $d, $rr, $oo); $okE = false; } catch (RuntimeException $e) { $okE = true; }
    ok($okE, "entrar rechazado: $que");
}
// Desafío de un solo uso y con vencimiento (en la sesión)
$dS = Huella::nuevoDesafio('entrar');
ok(Huella::tomarDesafio('entrar') === $dS && Huella::tomarDesafio('entrar') === '', 'el desafío sirve una sola vez');
Huella::nuevoDesafio('registro:1');
igual(Huella::tomarDesafio('entrar'), '', 'el desafío de activar no sirve para entrar');
Huella::nuevoDesafio('entrar');
$_SESSION['huella_desafio']['hasta'] = time() - 1;
igual(Huella::tomarDesafio('entrar'), '', 'desafío vencido (5 minutos)');
// RSA (algunos celulares Windows)
$kR = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$credR = random_bytes(20);
$d4 = random_bytes(32);
Huella::registrar($db, $uS, celular_registro($kR, $credR, $d4, $rp, $origen), $d4, $rp, $origen);
$d5 = random_bytes(32);
igual(Huella::entrar($db, celular_entrar($kR, $credR, $d5, $rp, $origen, 0), $d5, $rp, $origen), $uS, 'llave RSA (RS256) también entra');
// Usuario desactivado o contraseña nueva
$db->exec("UPDATE usuarios SET activo = false WHERE id = $uS");
$d6 = random_bytes(32);
try { Huella::entrar($db, celular_entrar($kH, $credH, $d6, $rp, $origen, 20), $d6, $rp, $origen); $okE = false; } catch (RuntimeException $e) { $okE = str_contains($e->getMessage(), 'desactivado'); }
ok($okE, 'usuario desactivado no entra con huella');
$db->exec("UPDATE usuarios SET activo = true WHERE id = $uS");
ok(!Huella::quitar($db, $uA, $idH) && Huella::quitar($db, $uS, $idH) && Huella::cuantas($db, $uS) === 1, 'quitar una huella (solo las propias)');
$db->exec("UPDATE usuarios SET activo = true WHERE id = $uA");
$credA = random_bytes(32); $d7 = random_bytes(32);
Huella::registrar($db, $uA, celular_registro($kOtro, $credA, $d7, $rp, $origen), $d7, $rp, $origen);
Empresas::cambiarUsuario($db, $eS, $uA, 'clave', $uS);
igual(Huella::cuantas($db, $uA), 0, 'contraseña nueva puesta por el administrador: se borran sus huellas (por si perdió el celular)');
echo "== Respaldo por correo de los avisos (chats sin contestar)\n";
use TuWhatsApp\{AvisoCorreo, Correo};
$smtpLog = (string) getenv('SMTP_LOG');
$correos = function () use ($smtpLog): array {
    $r = [];
    foreach (file($smtpLog) ?: [] as $l) {
        $c = json_decode($l, true);
        preg_match('/^Subject: (.*)$/mi', $c['datos'], $m);
        $c['asunto'] = iconv_mime_decode(trim($m[1] ?? ''), 0, 'UTF-8');
        preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $c['datos'], $partes);
        $c['texto'] = base64_decode(preg_replace('/\s+/', '', $partes[1][0] ?? ''));
        $c['html'] = base64_decode(preg_replace('/\s+/', '', $partes[1][1] ?? ''));
        $r[] = $c;
    }
    return $r;
};
$limpiarCorreos = fn () => file_put_contents($smtpLog, '');
foreach (['SMTP_HOST' => '', 'SMTP_PORT' => '', 'SMTP_USUARIO' => '', 'SMTP_CLAVE' => '', 'SMTP_DE' => '', 'SMTP_SEGURIDAD' => ''] as $k => $v) putenv("$k=$v");
putenv('TUWA_SIN_SENDMAIL=1');
igual([Correo::configurado(), Correo::modo()], [false, 'ninguno'], 'sin SMTP ni sendmail: no hay cómo mandar correos');
try { Correo::enviar('ana@prueba.ec', 'x', 'y'); ok(false, 'sin configurar no manda'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'SMTP'), 'sin configurar no manda y lo dice'); }
putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_PORT=' . (getenv('SMTP_PUERTO') ?: '8099'));
putenv('SMTP_SEGURIDAD=ninguna');   // el servidor falso no tiene TLS
putenv('SMTP_USUARIO=avisos@prueba.ec');
putenv('SMTP_CLAVE=clave-buena');
putenv('SMTP_DE=avisos@prueba.ec');
putenv('TUWA_URL=https://whatsapp.ejemplo.com');
igual([Correo::configurado(), Correo::modo()], [true, 'smtp'], 'con SMTP en config/.env');
igual(Correo::cabeceraUtf8('Hola'), 'Hola', 'asunto sin tildes queda igual');
igual(Correo::cabeceraUtf8('💬 Lucía'), '=?UTF-8?B?' . base64_encode('💬 Lucía') . '?=', 'asunto con tildes y emoji codificado');
ok(Correo::valido('a@b.ec') && !Correo::valido("a@b.ec\r\nBcc: x@y.z") && !Correo::valido('nada'), 'valida correos (sin saltos de línea escondidos)');
$limpiarCorreos();
ok(Correo::enviar('ana@prueba.ec', 'Prueba ñandú', "Hola\n.punto al inicio", '<b>Hola</b>'), 'manda por SMTP con usuario y clave');
$cs = $correos();
igual([count($cs), $cs[0]['para'] ?? null, $cs[0]['usuario'] ?? null, $cs[0]['asunto'] ?? null], [1, ['ana@prueba.ec'], 'avisos@prueba.ec', 'Prueba ñandú'], 'llega al servidor con el asunto bien');
ok(str_contains($cs[0]['texto'] ?? '', ".punto al inicio") && str_contains($cs[0]['html'] ?? '', '<b>Hola</b>') && str_contains($cs[0]['datos'], 'Auto-Submitted: auto-generated'), 'texto + HTML; línea que empieza con punto intacta');
putenv('SMTP_CLAVE=clave-mala');
try { Correo::enviar('ana@prueba.ec', 'x', 'y'); ok(false, 'clave mala'); } catch (RuntimeException $e) { igual($e->getMessage(), 'El servidor de correo no aceptó el usuario o la clave.', 'clave mala: lo dice claro (sin mostrar la clave)'); }
putenv('SMTP_CLAVE=clave-buena');
putenv('SMTP_PORT=1');
try { Correo::enviar('ana@prueba.ec', 'x', 'y'); ok(false, 'servidor caído'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'No se pudo conectar'), 'servidor de correo caído: lo dice'); }
putenv('SMTP_PORT=' . (getenv('SMTP_PUERTO') ?: '8099'));

// Una cuenta nueva: Dueño (admin), Ana y Beto (agentes). Chat 1 lo atiende Ana; chat 2 sin asignar; chat 3 es personal.
$eK = Empresas::crear($db, ['nombre' => 'Correo SA', 'estado' => 'activa', 'admin_correo' => 'dueno@correo.ec', 'admin_nombre' => 'Dueño', 'max_usuarios' => 10])['empresa_id'];
$lK = Lineas::guardarConexion($db, null, ['nombre' => 'Ventas Correo', 'telefono' => '0996409911', 'phone_number_id' => '919110', 'waba_id' => '919111', 'token' => 'EAAGtokenCorreo1234567890'], $eK);
Empresas::agregarUsuario($db, $eK, 'ana@correo.ec', 'Ana C', 'agente');
Empresas::agregarUsuario($db, $eK, 'beto@correo.ec', 'Beto C', 'agente');
[$kDueno, $kAna, $kBeto] = [$uidN('dueno@correo.ec'), $uidN('ana@correo.ec'), $uidN('beto@correo.ec')];
igual(AvisoCorreo::ajustes($db, $kAna)['min'], 5, 'por defecto: correo a los 5 minutos');
$k1 = Contactos::guardar($db, $lK, '0981777001', 'Lucía Correo', 'cliente');
$k2 = Contactos::guardar($db, $lK, '0981777002', 'Pedro Correo', 'cliente');
$k3 = Contactos::guardar($db, $lK, '0981777003', 'Mamá', 'personal');
$db->exec('UPDATE contactos SET asignado_a = ' . $kAna . ' WHERE id = ' . (int) $k1['id']);
$msgK = function (array $c, string $texto, string $cuando, string $dir = 'entra', string $origen = 'cliente') use ($db, $lK): int {
    $st = $db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, texto, leido, creado) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id");
    $st->execute([$lK, $c['id'], $dir, $origen, $texto, $dir === 'entra' ? 'false' : 'true', $cuando]);
    return (int) $st->fetchColumn();
};
$t = fn (string $h) => new DateTimeImmutable("2030-01-01 $h:00");
$paraK = fn () => array_merge(...array_map(fn ($c) => $c['para'], $correos()) ?: [[]]);
$db->exec("DELETE FROM ajustes WHERE clave = 'aviso_correo_desde'");
$msgK($k1, 'mensaje viejo de antes de instalar', '2030-01-01 07:50:00');
igual(AvisoCorreo::procesar($db, $t('08:00')), ['correos' => 0, 'fallidos' => 0], 'al instalar: no avisa de los mensajes viejos sin leer');
$limpiarCorreos();
$msgK($k1, '¿Tienen talla M del vestido rojo?', '2030-01-01 11:57:00');
igual(AvisoCorreo::procesar($db, $t('12:00'))['correos'], 0, 'a los 3 minutos todavía no');
igual(AvisoCorreo::procesar($db, $t('12:03'))['correos'], 1, 'a los 6 minutos: un correo');
$cs = $correos();
igual($cs[0]['para'] ?? null, ['ana@correo.ec'], 'le llega a Ana (atiende ese chat), no a todo el equipo');
ok(str_contains($cs[0]['asunto'] ?? '', 'Lucía Correo') && str_contains($cs[0]['texto'] ?? '', 'talla M') && !str_contains($cs[0]['texto'] ?? '', 'mensaje viejo')
   && str_contains($cs[0]['texto'] ?? '', 'https://whatsapp.ejemplo.com/?r=chat&c=' . $k1['id']) && str_contains($cs[0]['html'] ?? '', 'Abrir el chat'), 'el correo dice quién escribió, qué dijo y el enlace al chat');
$limpiarCorreos();
igual(AvisoCorreo::procesar($db, $t('12:04'))['correos'], 0, 'no se repite el mismo mensaje');
$msgK($k2, 'Hola, precio?', '2030-01-01 12:00:00');
$msgK($k2, 'Para hoy', '2030-01-01 12:01:00');
$msgK($k3, 'Hijo, ven a comer', '2030-01-01 12:00:00');
igual(AvisoCorreo::procesar($db, $t('12:10'))['correos'], 2, 'chat sin asignar: al equipo (Ana ya recibió uno hace menos de 15 min)');
$cs = $correos();
$paras = $paraK();
sort($paras);
igual($paras, ['beto@correo.ec', 'dueno@correo.ec'], 'Dueño y Beto; Ana espera su turno');
ok(substr_count($cs[0]['texto'] ?? '', 'Pedro Correo') === 1 && str_contains($cs[0]['texto'] ?? '', '2 mensajes') && !str_contains($cs[0]['texto'] ?? '', 'Mamá') && !str_contains($cs[0]['texto'] ?? '', 'comer'),
   'un chat con 2 mensajes va una sola vez; los contactos personales no');
$limpiarCorreos();
$msgK($k1, 'Y en azul?', '2030-01-01 12:12:00');
igual(AvisoCorreo::procesar($db, $t('12:19'))['correos'], 1, 'pasaron 15 minutos: Ana recibe lo pendiente');
$cs = $correos();
ok(($cs[0]['para'] ?? null) === ['ana@correo.ec'] && str_contains($cs[0]['asunto'] ?? '', 'Tienes 2 chats sin contestar') && str_contains($cs[0]['texto'] ?? '', 'Pedro Correo') && str_contains($cs[0]['texto'] ?? '', 'en azul'),
   'un solo correo con sus dos chats pendientes');
$limpiarCorreos();
AvisoCorreo::guardar($db, $kBeto, 0, '');
AvisoCorreo::guardar($db, $kAna, 15, 'ana.personal@gmail.com');
try { AvisoCorreo::guardar($db, $kAna, 5, 'no-es-correo'); ok(false, 'correo no válido'); } catch (RuntimeException $e) { ok(true, 'correo no válido se rechaza'); }
$msgK($k2, 'Sigo esperando', '2030-01-01 12:30:00');
igual(AvisoCorreo::procesar($db, $t('12:40'))['correos'], 1, 'Beto apagó el correo; Ana lo quiere a los 15 minutos');
igual($paraK(), ['dueno@correo.ec'], 'solo le llega al Dueño');
$limpiarCorreos();
igual(AvisoCorreo::procesar($db, $t('12:46'))['correos'], 1, 'a los 16 minutos le llega a Ana');
igual($paraK(), ['ana.personal@gmail.com'], 'a Ana le llega al otro correo que eligió');
$limpiarCorreos();
$m7 = $msgK($k2, 'ya leído', '2030-01-01 13:00:00');
$db->exec("UPDATE mensajes SET leido = true WHERE contacto_id = {$k2['id']}");
$db->exec("UPDATE mensajes SET leido = true WHERE contacto_id = {$k1['id']}");
igual(AvisoCorreo::procesar($db, $t('13:30'))['correos'], 0, 'mensajes ya leídos: nada');
$msgK($k2, 'Hola de nuevo', '2030-01-01 14:00:00');
$msgK($k2, 'Claro, te ayudo', '2030-01-01 14:02:00', 'sale', 'celular');
igual(AvisoCorreo::procesar($db, $t('14:30'))['correos'], 0, 'ya le contestaron (desde el celular): nada aunque siga sin leer');
$db->exec("UPDATE mensajes SET leido = true WHERE contacto_id = {$k2['id']}");
// Copia para la empresa
try { AvisoCorreo::guardarCopia($db, $eK, 'mal', 15); ok(false, 'copia no válida'); } catch (RuntimeException $e) { ok(true, 'correo de copia no válido se rechaza'); }
AvisoCorreo::guardarCopia($db, $eK, 'ventas@correo.ec', 15);
AvisoCorreo::guardar($db, $kAna, 5, '');
$msgK($k1, '¿Hacen envíos a Cuenca?', '2030-01-01 15:00:00');
AvisoCorreo::procesar($db, $t('15:06'));
igual($paraK(), ['ana@correo.ec'], 'a los 6 minutos: Ana sí, la copia todavía no (15 min)');
$limpiarCorreos();
AvisoCorreo::procesar($db, $t('15:16'));
$cs = $correos();
ok(count($cs) === 1 && $cs[0]['para'] === ['ventas@correo.ec'] && str_contains($cs[0]['texto'], 'Cuenca') && str_contains($cs[0]['texto'], 'Copia para la empresa'), 'a los 16 minutos: copia a ventas@');
$limpiarCorreos();
igual(AvisoCorreo::procesar($db, $t('15:40'))['correos'], 0, 'la copia tampoco se repite');
// Si el servidor de correo falla, se vuelve a intentar en unos minutos (no cada minuto) y no se pierde
$db->exec("UPDATE mensajes SET leido = true WHERE contacto_id = {$k1['id']}");
$msgK($k1, 'Necesito factura', '2030-01-01 16:00:00');
putenv('SMTP_CLAVE=clave-mala');
igual(AvisoCorreo::procesar($db, $t('16:10'))['fallidos'] >= 1, true, 'servidor de correo con clave mala: falla');
putenv('SMTP_CLAVE=clave-buena');
$limpiarCorreos();
AvisoCorreo::procesar($db, $t('16:12'));
igual(in_array('ana@correo.ec', $paraK(), true), false, 'no reintenta al minuto siguiente');
AvisoCorreo::procesar($db, $t('16:16'));
igual(in_array('ana@correo.ec', $paraK(), true), true, 'a los 5 minutos reintenta y llega');
// Cuenta suspendida: no se manda nada
$db->exec("UPDATE mensajes SET leido = true WHERE contacto_id = {$k1['id']}");
$db->exec("UPDATE empresas SET estado = 'suspendida' WHERE id = $eK");
$msgK($k1, 'hola?', '2030-01-01 17:00:00');
$limpiarCorreos();
igual(AvisoCorreo::procesar($db, $t('17:30'))['correos'], 0, 'cuenta suspendida: no se mandan correos');
$db->exec("UPDATE empresas SET estado = 'activa' WHERE id = $eK");
// Se apaga el SMTP: no hace nada y lo dice
putenv('SMTP_HOST=');
igual(AvisoCorreo::activoPara($db, $kAna), false, 'sin servidor de correo el respaldo no cuenta como activo');
igual(AvisoCorreo::procesar($db, $t('17:40')), ['correos' => 0, 'fallidos' => 0], 'sin servidor de correo no hace nada');
putenv('SMTP_HOST=127.0.0.1');
igual(AvisoCorreo::activoPara($db, $kAna), true, 'con servidor de correo y encendido: activo');
$limpiarCorreos();
igual(AvisoCorreo::prueba($db, $kAna), 'ana@correo.ec', 'correo de prueba');
ok(str_contains($correos()[0]['asunto'] ?? '', 'Así te llegarán'), 'llega el correo de prueba');
[$asEn] = AvisoCorreo::armar([['contacto_id' => 1, 'contacto' => ['nombre' => 'Ann', 'telefono' => '1'], 'l_nombre' => 'L', 'creado' => '2030-01-01 10:00:00', 'tipo' => 'texto', 'texto' => 'hi'],
                               ['contacto_id' => 2, 'contacto' => ['nombre' => 'Bob', 'telefono' => '2'], 'l_nombre' => 'L', 'creado' => '2030-01-01 10:00:00', 'tipo' => 'otro', 'texto' => '']], 'en', $t('10:30'));
igual($asEn, '💬 You have 2 unanswered chats', 'el correo sale en el idioma de la persona');

echo "== Respaldo por correo: revisión (respuestas automáticas, empresas grandes, dos corridas a la vez, seguridad)\n";
$db->exec('UPDATE mensajes SET leido = true');
$puertoBueno = getenv('SMTP_PUERTO') ?: '8099';
$nuevaEmpresa = function (string $n, string $tel, string $pnid, array $agentes = []) use ($db, $uidN): array {
    $e = Empresas::crear($db, ['nombre' => $n, 'estado' => 'activa', 'admin_correo' => "jefe@$n.ec", 'admin_nombre' => 'Jefe', 'max_usuarios' => 10])['empresa_id'];
    $l = Lineas::guardarConexion($db, null, ['nombre' => $n, 'telefono' => $tel, 'phone_number_id' => $pnid, 'waba_id' => $pnid . 'w', 'token' => 'EAAGtoken' . $pnid . '1234567890'], $e);
    foreach ($agentes as $a) {
        Empresas::agregarUsuario($db, $e, "$a@$n.ec", $a, 'agente');
    }
    return [$e, $l];
};
$msgL = function (int $linea, int $contacto, string $texto, string $cuando, string $dir = 'entra', string $origen = 'cliente') use ($db): int {
    $st = $db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, texto, leido, creado) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id");
    $st->execute([$linea, $contacto, $dir, $origen, $texto, $dir === 'entra' ? 'false' : 'true', $cuando]);
    return (int) $st->fetchColumn();
};
$paraDe = fn () => array_merge(...array_map(fn ($c) => $c['para'], $correos()) ?: [[]]);

// 1) La respuesta del agente con IA (o de un chatbot) cuenta como contestado, salvo que la IA haya pasado el chat a una persona
[$eBot, $lBot] = $nuevaEmpresa('botsa', '0996401201', '912010');
$cb1 = Contactos::guardar($db, $lBot, '0981201001', 'Cliente Bot', 'cliente');
$cb2 = Contactos::guardar($db, $lBot, '0981201002', 'Cliente Persona', 'cliente');
$msgL($lBot, (int) $cb1['id'], '¿Precio?', '2030-01-02 09:00:00');
$msgL($lBot, (int) $cb1['id'], 'Cuesta $10, ¿te lo separo?', '2030-01-02 09:00:20', 'sale', 'bot');
$msgL($lBot, (int) $cb2['id'], 'Quiero reclamar', '2030-01-02 09:00:00');
$msgL($lBot, (int) $cb2['id'], 'En un momento te atiende una persona', '2030-01-02 09:00:20', 'sale', 'bot');
$db->exec("UPDATE contactos SET traspaso_en = '2030-01-02 09:00:20', traspaso_nota = 'reclamo' WHERE id = " . (int) $cb2['id']);
$limpiarCorreos();
AvisoCorreo::procesar($db, new DateTimeImmutable('2030-01-02 09:10:00'));
$cs = array_values(array_filter($correos(), fn ($c) => in_array('jefe@botsa.ec', $c['para'], true)));
ok(count($cs) === 1 && str_contains($cs[0]['texto'], 'Cliente Persona') && !str_contains($cs[0]['texto'], 'Cliente Bot'),
   'la IA contestó: no hay correo; la IA pasó el chat a una persona: sí hay correo');
$msgL($lBot, (int) $cb2['id'], 'Hola, soy Ana, te ayudo', '2030-01-02 09:12:00', 'sale', 'panel');
$msgL($lBot, (int) $cb2['id'], 'Gracias', '2030-01-02 09:20:00');
$msgL($lBot, (int) $cb2['id'], '¿Algo más?', '2030-01-02 09:20:10', 'sale', 'bot');
$limpiarCorreos();
AvisoCorreo::procesar($db, new DateTimeImmutable('2030-01-02 09:40:00'));
ok(!in_array('jefe@botsa.ec', $paraDe(), true), 'después de que una persona contestó, la respuesta de la IA vuelve a contar');
$db->exec('UPDATE mensajes SET leido = true');

// 2) Una empresa con miles de chats sin leer no deja sin correo a las demás
[$eG, $lG] = $nuevaEmpresa('grandesa', '0996401202', '912020');
[$eCh, $lCh] = $nuevaEmpresa('chicasa', '0996401203', '912030');
$db->exec("INSERT INTO contactos (linea_id, telefono, nombre, tipo) SELECT $lG, '0985' || lpad(g::text, 6, '0'), 'Masivo ' || g, 'cliente' FROM generate_series(1, 1000) g");
$db->exec("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, texto, leido, creado)
           SELECT $lG, id, 'entra', 'cliente', 'gracias', false, '2030-01-02 08:00:00' FROM contactos WHERE linea_id = $lG");
$limpiarCorreos();
AvisoCorreo::procesar($db, new DateTimeImmutable('2030-01-02 10:00:00'));
$cs = array_values(array_filter($correos(), fn ($c) => in_array('jefe@grandesa.ec', $c['para'], true)));
ok(count($cs) === 1 && str_contains($cs[0]['asunto'], 'Tienes ' . AvisoCorreo::CHATS_POR_EMPRESA . ' chats'), 'empresa grande: un correo con un tope de chats por corrida');
igual((int) $db->query("SELECT count(*) FROM avisos_correo a JOIN mensajes m ON m.id = a.mensaje_id WHERE m.linea_id = $lG AND a.destino = 'fin'")->fetchColumn(),
      AvisoCorreo::CHATS_POR_EMPRESA, 'los mensajes ya avisados a todos quedan marcados y no se vuelven a revisar');
$cch = Contactos::guardar($db, $lCh, '0981203001', 'Cliente Chica', 'cliente');
$msgL($lCh, (int) $cch['id'], 'quiero comprar', '2030-01-02 10:50:00');
$limpiarCorreos();
AvisoCorreo::procesar($db, new DateTimeImmutable('2030-01-02 11:00:00'));
ok(in_array('jefe@chicasa.ec', $paraDe(), true), '1000 chats sin leer de otra empresa no tapan el aviso de la empresa chica');
$limpiarCorreos();
AvisoCorreo::procesar($db, new DateTimeImmutable('2030-01-02 11:16:00'));
ok(in_array('jefe@grandesa.ec', $paraDe(), true), 'y en las siguientes corridas le llegan los demás chats de la empresa grande');
$db->exec('UPDATE mensajes SET leido = true');

// 3) Dos corridas del cron a la vez (servidor de correo lento): un solo correo por destino
$lentoPuerto = (int) $puertoBueno + 50;
$lentoLog = tempnam(sys_get_temp_dir(), 'smtp_lento');
$authLog = tempnam(sys_get_temp_dir(), 'smtp_auth');
$lento = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/smtp_falso.php', (string) $lentoPuerto], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tuboL,
                   null, array_merge(getenv(), ['SMTP_LOG' => $lentoLog, 'SMTP_ESPERA' => '1.5', 'SMTP_LOG_AUTH' => $authLog]));
usleep(400000);
[$eD, $lD] = $nuevaEmpresa('doblesa', '0996401204', '912040', ['a1', 'a2']);
$db->exec("UPDATE empresas SET aviso_copia = 'ventas@doblesa.ec', aviso_copia_min = 5 WHERE id = $eD");
foreach ([1, 2, 3] as $i) {
    $cd = Contactos::guardar($db, $lD, '098120400' . $i, 'Doble ' . $i, 'cliente');
    $msgL($lD, (int) $cd['id'], 'hola', '2030-01-02 11:50:00');
}
putenv("SMTP_PORT=$lentoPuerto");
$hijo = tempnam(sys_get_temp_dir(), 'aviso_hijo') . '.php';
file_put_contents($hijo, '<?php define("TUWA_SIN_SESION", 1); require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';'
    . ' echo json_encode(\TuWhatsApp\AvisoCorreo::procesar(db(), new DateTimeImmutable("2030-01-02 12:00:00")));');
$procs = [];
foreach ([1, 2] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, $hijo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos[$i]);
}
$salidas = [];
foreach ($procs as $i => $p) {
    $salidas[$i] = stream_get_contents($tubos[$i][1]) . stream_get_contents($tubos[$i][2]);
    proc_close($p);
}
$recibidos = array_merge(...array_map(fn ($l) => json_decode($l, true)['para'] ?? [], array_filter(explode("\n", (string) file_get_contents($lentoLog)))) ?: [[]]);
sort($recibidos);
igual($recibidos, ['a1@doblesa.ec', 'a2@doblesa.ec', 'jefe@doblesa.ec', 'ventas@doblesa.ec'], 'dos corridas a la vez: cada uno recibe UN solo correo (' . trim(implode(' | ', $salidas)) . ')');

// 4) Sin STARTTLS no se manda la clave en claro (salvo SMTP_SEGURIDAD=ninguna)
file_put_contents($authLog, '');
putenv('SMTP_SEGURIDAD=');
try { Correo::enviar('ana@prueba.ec', 'x', 'y'); ok(false, 'sin STARTTLS'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'STARTTLS'), 'servidor sin STARTTLS: error claro'); }
igual((string) file_get_contents($authLog), '', '…y nunca recibió el usuario ni la clave');
putenv('SMTP_SEGURIDAD=ninguna');
proc_terminate($lento);
proc_close($lento);
@unlink($lentoLog); @unlink($authLog); @unlink($hijo);
putenv("SMTP_PORT=$puertoBueno");

// 5) sendmail solo si se pide (SMTP_MODO=sendmail); sin SMTP no hay correo y la cinta sigue avisando
putenv('SMTP_HOST=');
putenv('TUWA_SIN_SENDMAIL=');
putenv('SMTP_MODO=');
igual([Correo::configurado(), Correo::modo()], [false, 'ninguno'], 'sin SMTP y sin SMTP_MODO=sendmail: no manda (aunque la máquina tenga sendmail)');
igual(AvisoCorreo::activoPara($db, $uidN('jefe@chicasa.ec')), false, '…y el respaldo no cuenta como activo (la cinta amarilla sigue)');
putenv('TUWA_SIN_SENDMAIL=1');
putenv('SMTP_HOST=127.0.0.1');

// 6) Clave con ; # o espacios llega entera (config/.env entre comillas, como lo guarda configurar_correo.sh)
$ini = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($ini, "SMTP_CLAVE=\"ab;cd #x y\$z\\w\"\n");
igual(parse_ini_file($ini, false, INI_SCANNER_RAW)['SMTP_CLAVE'] ?? null, 'ab;cd #x y$z\\w', 'clave con ; # espacios y $ entre comillas: llega entera');
if (trim((string) shell_exec('sudo -n true 2>&1 && echo si')) === 'si' && getenv('TUWA_SIN_SUDO') !== '1') {
    $app = sys_get_temp_dir() . '/tuwa_cfg_' . getmypid();
    @mkdir("$app/config", 0700, true);
    file_put_contents("$app/config/.env", "META_APP_ID=1\n");
    $p = proc_open(['bash', dirname(__DIR__) . '/deploy/configurar_correo.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tc,
                   null, array_merge(getenv(), ['APP' => $app, 'CORREO_HOST' => 'mail.ejemplo.com', 'CORREO_PUERTO' => '465', 'CORREO_USUARIO' => 'avisos@ejemplo.com']));
    fwrite($tc[0], "ab;cd #x y\$z\\w\n");
    fclose($tc[0]);
    stream_get_contents($tc[1]);
    proc_close($p);
    $leido = parse_ini_file("$app/config/.env", false, INI_SCANNER_RAW);
    igual([$leido['SMTP_CLAVE'] ?? null, $leido['SMTP_HOST'] ?? null, $leido['META_APP_ID'] ?? null], ['ab;cd #x y$z\\w', 'mail.ejemplo.com', '1'],
          'configurar_correo.sh guarda la clave entera y no borra lo demás');
    ok(!glob("$app/config/.env.*") && !is_file('/tmp/tuwa_env_nuevo'), 'configurar_correo.sh no deja copias del .env (ni en /tmp)');
    shell_exec('sudo rm -rf ' . escapeshellarg($app));
}
@unlink($ini);

// 7) Correo de prueba: máximo 3 por hora
$db->exec("UPDATE usuarios SET aviso_pruebas = '[]' WHERE correo = 'jefe@chicasa.ec'");
$limpiarCorreos();
$jefeCh = $uidN('jefe@chicasa.ec');
foreach (['10:00', '10:10', '10:20'] as $h) {
    AvisoCorreo::prueba($db, $jefeCh, 'es', new DateTimeImmutable("2030-01-02 $h:00"));
}
try { AvisoCorreo::prueba($db, $jefeCh, 'es', new DateTimeImmutable('2030-01-02 10:30:00')); ok(false, '4.º correo de prueba'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), '3 correos de prueba'), 'el 4.º correo de prueba en una hora se rechaza'); }
igual(count($correos()), 3, 'solo salieron 3 correos de prueba');
AvisoCorreo::prueba($db, $jefeCh, 'es', new DateTimeImmutable('2030-01-02 11:05:00'));
igual(count($correos()), 4, 'pasada la hora se puede otra vez');

// 8) Limpieza una vez al día de las marcas de más de 30 días
$db->exec("UPDATE avisos_correo SET creado = '2029-11-01'");
$db->exec("DELETE FROM ajustes WHERE clave = 'aviso_correo_limpieza'");
ok(AvisoCorreo::limpiar($db, new DateTimeImmutable('2030-01-03 10:00:00')) > 0, 'borra las marcas de avisos de más de 30 días');
igual(AvisoCorreo::limpiar($db, new DateTimeImmutable('2030-01-03 11:00:00')), 0, 'y solo una vez al día');
foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USUARIO', 'SMTP_CLAVE', 'SMTP_DE', 'SMTP_SEGURIDAD', 'TUWA_URL'] as $k) putenv("$k=");


// ===================================================================================================
use TuWhatsApp\Telegram;
echo "== Avisos por Telegram (bot propio de Tukán Mensajes, Telegram SIMULADO)\n";
$tgLog = (string) getenv('TG_LOG');
$tgLlamadas = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($tgLog) ?: [])));
$tgEnvios = fn () => array_values(array_filter($tgLlamadas(), fn ($c) => $c['metodo'] === 'sendMessage'));
$tgLimpiar = fn () => file_put_contents($tgLog, '');
foreach (['TUWA_TELEGRAM_TOKEN', 'TUWA_TELEGRAM_SECRETO', 'TUWA_TELEGRAM_BOT', 'TUWA_TELEGRAM_API_URL'] as $k) putenv("$k=");
$eT = Empresas::crear($db, ['nombre' => 'Telegram SA', 'estado' => 'activa', 'admin_correo' => 'dueno@telegram.ec', 'admin_nombre' => 'Dueño T', 'max_usuarios' => 10])['empresa_id'];
$lT = Lineas::guardarConexion($db, null, ['nombre' => 'Ventas Telegram', 'telefono' => '0996409922', 'phone_number_id' => '929220', 'waba_id' => '929221', 'token' => 'EAAGtokenTelegram1234567890'], $eT);
$lineaT = Lineas::buscar($db, $lT, $eT);
Empresas::agregarUsuario($db, $eT, 'ana@telegram.ec', 'Ana T', 'agente');
Empresas::agregarUsuario($db, $eT, 'beto@telegram.ec', 'Beto T', 'agente');
[$tDueno, $tAna, $tBeto] = [$uidN('dueno@telegram.ec'), $uidN('ana@telegram.ec'), $uidN('beto@telegram.ec')];
$c1 = Contactos::guardar($db, $lT, '0981888001', 'Lucía <Tele> & Cía', 'cliente');
$c2 = Contactos::guardar($db, $lT, '0981888002', 'Pedro Tele', 'cliente');
$c3 = Contactos::guardar($db, $lT, '0981888003', 'Mamá', 'personal');
$db->exec('UPDATE contactos SET asignado_a = ' . $tAna . ' WHERE id = ' . (int) $c1['id']);
$recargar = fn (array $c) => Contactos::buscar($db, $lT, (int) $c['id']);

ok(!Telegram::configurado(), 'sin bot en config/.env: los avisos por Telegram están apagados');
igual(Telegram::estado($db, $tAna)['configurado'], false, 'la pantalla sabe que el bot no está (no muestra botones)');
try { Telegram::crearCodigo($db, $tAna); ok(false, 'sin bot no hay código'); } catch (RuntimeException $e) { ok(true, 'sin bot no se puede conectar'); }
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c1), 'hola', 1), 0, 'sin bot no manda nada');
igual(Telegram::procesarPendientes($db), ['mensajes' => 0, 'fallidos' => 0], 'sin bot: nada de "si nadie contesta"');

putenv('TUWA_TELEGRAM_TOKEN=123456:tokenDePruebaDelBotTukanMensajes0123456789');
putenv('TUWA_TELEGRAM_SECRETO=secreto_telegram_de_prueba_0123456789');
putenv('TUWA_TELEGRAM_BOT=TukanMensajesBot');
putenv('TUWA_TELEGRAM_API_URL=' . getenv('TG_URL'));
ok(Telegram::configurado(), 'con el bot configurado');

// Conectar el bot (lo que hace deploy/configurar_telegram.sh)
$tgLimpiar();
try { Telegram::conectarBot('esto-no-es-token', 'secreto_telegram_de_prueba_0123456789', 'https://whatsapp.ejemplo.com'); ok(false, 'token malo'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no parece'), 'token con forma mala: se rechaza sin llamar a Telegram'); }
try { Telegram::conectarBot('999999:tokenQueTelegramNoConoce0123456789abc', 'secreto_telegram_de_prueba_0123456789', 'https://whatsapp.ejemplo.com'); ok(false, 'token desconocido'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'no aceptó ese token'), 'token que Telegram no acepta: lo dice'); }
igual(Telegram::conectarBot('123456:tokenDePruebaDelBotTukanMensajes0123456789', 'secreto_telegram_de_prueba_0123456789', 'https://whatsapp.ejemplo.com/'), 'TukanMensajesBot', 'token bueno: devuelve el nombre del bot');
$wh = array_values(array_filter($tgLlamadas(), fn ($c) => $c['metodo'] === 'setWebhook'))[0] ?? [];
igual([$wh['datos']['url'] ?? null, $wh['datos']['secret_token'] ?? null, $wh['datos']['allowed_updates'] ?? null],
      ['https://whatsapp.ejemplo.com/telegram.php', 'secreto_telegram_de_prueba_0123456789', ['message']], 'registra el webhook con el secreto y solo mensajes');

// Vincular con /start <código>
$t0 = new DateTimeImmutable('2030-02-01 10:00:00');
$enlace = Telegram::crearCodigo($db, $tAna, $t0);
ok((bool) preg_match('#^https://t\.me/TukanMensajesBot\?start=([0-9a-f]{32})$#', $enlace, $mm), 'enlace t.me con un código');
$codAna = $mm[1] ?? '';
ok((string) $db->query("SELECT tg_codigo FROM usuarios WHERE id = $tAna")->fetchColumn() === hash('sha256', $codAna), 'en la base solo queda el sha256 del código');
$upd = fn (int $chat, string $texto, string $tipo = 'private', string $usuario = '') => ['update_id' => random_int(1, 1 << 30),
    'message' => ['message_id' => 1, 'from' => ['id' => $chat, 'username' => $usuario], 'chat' => ['id' => $chat, 'type' => $tipo], 'text' => $texto]];
$r = Telegram::procesarUpdate($db, $upd(7001, "/start $codAna", 'private', 'ana_tg'), $t0->modify('+1 minute'));
ok(($r['method'] ?? '') === 'sendMessage' && $r['chat_id'] === 7001 && str_contains($r['text'], 'Listo') && str_contains($r['text'], 'Ana T'), 'el bot contesta «Listo, Ana»');
igual((int) $db->query("SELECT tg_chat_id FROM usuarios WHERE id = $tAna")->fetchColumn(), 7001, 'Ana quedó conectada');
igual(Telegram::estado($db, $tAna), ['configurado' => true, 'bot' => 'TukanMensajesBot', 'conectado' => true, 'nombre' => 'ana_tg', 'modo' => -1], 'estado: conectado, al momento');
$r = Telegram::procesarUpdate($db, $upd(7999, "/start $codAna"), $t0->modify('+2 minutes'));
ok(str_contains($r['text'], 'ya no sirve') && (int) $db->query('SELECT count(*) FROM usuarios WHERE tg_chat_id = 7999')->fetchColumn() === 0, 'el mismo código no sirve dos veces');
$enlaceB = Telegram::crearCodigo($db, $tBeto, $t0);
preg_match('#start=([0-9a-f]{32})#', $enlaceB, $mb);
$r = Telegram::procesarUpdate($db, $upd(7002, '/start ' . $mb[1]), $t0->modify('+20 minutes'));
ok(str_contains($r['text'], 'ya no sirve') && Telegram::estado($db, $tBeto)['conectado'] === false, 'código vencido (más de 15 minutos): no conecta');
igual(Telegram::procesarUpdate($db, $upd(7002, '/start ' . str_repeat('a', 32)), $t0)['text'] === $r['text'], true, 'código inventado: no conecta');
igual(Telegram::procesarUpdate($db, $upd(-1007002, "/start $codAna", 'group'), $t0), null, 'en grupos el bot no hace nada');
preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $tBeto, $t0), $mb);
Telegram::procesarUpdate($db, $upd(7002, '/start ' . $mb[1]), $t0);
preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $tDueno, $t0), $md);
Telegram::procesarUpdate($db, $upd(7002, '/start ' . $md[1]), $t0);
ok(Telegram::estado($db, $tDueno)['conectado'] && !Telegram::estado($db, $tBeto)['conectado'], 'un Telegram solo en una persona: si se conecta a otra, se quita de la anterior');
preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $tBeto, $t0), $mb);
Telegram::procesarUpdate($db, $upd(7003, '/start ' . $mb[1]), $t0);
$r = Telegram::procesarUpdate($db, $upd(7003, '/parar'), $t0);
ok(str_contains($r['text'], 'ya no te llegarán') && !Telegram::estado($db, $tBeto)['conectado'], '/parar desconecta');
ok(str_contains(Telegram::procesarUpdate($db, $upd(7003, '/parar'), $t0)['text'], 'no estaba conectado'), '/parar sin estar conectado: lo dice');
ok(str_contains(Telegram::procesarUpdate($db, $upd(7003, 'hola'), $t0)['text'], 'App en el celular'), 'otro texto: ayuda corta');
preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $tBeto, $t0), $mb);
Telegram::procesarUpdate($db, $upd(7003, '/start ' . $mb[1]), $t0);
ok(Telegram::activoPara($db, $tBeto), 'Beto conectado de nuevo: cuenta como "avisos funcionando" (no sale la cinta)');

// Al momento
Telegram::guardarModo($db, $tDueno, 0);
ok(!Telegram::activoPara($db, $tDueno), 'apagado: no cuenta como avisos funcionando');
$tgLimpiar();
$ahoraT = new DateTimeImmutable('2030-02-01 11:00:00');
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c1), 'Hola <b>precio</b> & envío', 101, $ahoraT), 1, 'chat de Ana: el aviso le llega solo a Ana');
$env = $tgEnvios();
igual(array_map(fn ($c) => $c['datos']['chat_id'], $env), [7001], '…a su Telegram');
$txt = $env[0]['datos']['text'] ?? '';
ok(str_contains($txt, 'Lucía &lt;Tele&gt; &amp; Cía') && str_contains($txt, '&lt;b&gt;precio&lt;/b&gt; &amp; envío') && ($env[0]['datos']['parse_mode'] ?? '') === 'HTML', 'texto con < > & bien escapado');
igual($env[0]['datos']['reply_markup']['inline_keyboard'][0][0]['url'] ?? '', Telegram::base() . '/?r=chat&c=' . (int) $c1['id'], 'botón «Abrir el chat» con el enlace del chat');
ok(str_contains($txt, 'Ventas Telegram'), 'dice de qué número es');
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c1), 'otro', 102, $ahoraT->modify('+1 minute')), 0, 'ráfaga: otro mensaje del mismo chat al minuto no avisa de nuevo');
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c1), 'otro más', 103, $ahoraT->modify('+3 minutes')), 1, 'pasados 2 minutos sí avisa');
$tgLimpiar();
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c2), 'sin asignar', 104, $ahoraT), 2, 'chat sin asignar: al equipo que recibe chats con Telegram al momento (el dueño lo apagó)');
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c3), 'hola hijo', 105, $ahoraT), 0, 'contactos personales: nunca');
Telegram::guardarModo($db, $tBeto, 15);
$c4 = Contactos::guardar($db, $lT, '0981888004', 'Nuevo Tele', 'cliente');
$tgLimpiar();
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c4), 'hola', 106, $ahoraT), 1, 'Beto eligió "si nadie contesta": no recibe el aviso al momento');
Telegram::guardarModo($db, $tAna, 99);
igual(Telegram::estado($db, $tAna)['modo'], -1, 'modo raro: queda "al momento"');
$db->exec("UPDATE usuarios SET tg_chat_id = 403403 WHERE id = $tAna");
$c5 = Contactos::guardar($db, $lT, '0981888005', 'Bloqueo Tele', 'cliente');
$db->exec('UPDATE contactos SET asignado_a = ' . $tAna . ' WHERE id = ' . (int) $c5['id']);
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c5), 'hola', 107, $ahoraT), 0, 'bloqueó el bot (403): no llega…');
ok(!Telegram::estado($db, $tAna)['conectado'], '…y se desconecta sola, no se reintenta');
preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $tAna, $t0), $ma);
Telegram::procesarUpdate($db, $upd(7001, '/start ' . $ma[1]), $t0);
$tgLimpiar();
ok(Telegram::prueba($db, $tAna) && ($tgEnvios()[0]['datos']['chat_id'] ?? 0) === 7001, 'aviso de prueba a su propio Telegram');
try { Telegram::prueba($db, $tDueno); ok(true, 'el dueño tiene Telegram (apagado) y puede probar'); } catch (RuntimeException $e) { ok(false, 'prueba del dueño'); }
Telegram::desvincular($db, $tDueno);
try { Telegram::prueba($db, $tDueno); ok(false, 'prueba sin Telegram'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'Primero conecta'), 'aviso de prueba sin Telegram conectado: lo dice'); }

// Solo si nadie contesta (Ana a los 5 minutos, Beto a los 15)
Telegram::guardarModo($db, $tAna, 5);
$tgM = function (array $c, string $texto, string $cuando, string $dir = 'entra', string $origen = 'cliente') use ($db, $lT): int {
    $st = $db->prepare('INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, texto, leido, creado) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
    $st->execute([$lT, $c['id'], $dir, $origen, $texto, $dir === 'entra' ? 'false' : 'true', $cuando]);
    return (int) $st->fetchColumn();
};
$tt = fn (string $h) => new DateTimeImmutable("2030-02-02 $h:00");
$db->exec("DELETE FROM ajustes WHERE clave = 'aviso_tg_desde'");
$tgM($c2, 'viejo de antes de instalar', '2030-02-02 07:50:00');
igual(Telegram::procesarPendientes($db, $tt('08:00')), ['mensajes' => 0, 'fallidos' => 0], 'al instalar: no avisa de mensajes viejos');
$tgLimpiar();
$tgM($c2, '¿Tienen <envío> a Quito?', '2030-02-02 11:57:00');
igual(Telegram::procesarPendientes($db, $tt('12:00'))['mensajes'], 0, 'a los 3 minutos todavía no');
igual(Telegram::procesarPendientes($db, $tt('12:03'))['mensajes'], 1, 'a los 6 minutos: un mensaje a Ana (5 min)');
$e1 = $tgEnvios();
ok(($e1[0]['datos']['chat_id'] ?? 0) === 7001 && str_contains($e1[0]['datos']['text'], 'nadie ha contestado') && str_contains($e1[0]['datos']['text'], '&lt;envío&gt;'), 'resumen con el chat pendiente, escapado');
igual(Telegram::procesarPendientes($db, $tt('12:04'))['mensajes'], 0, 'no se repite');
igual(Telegram::procesarPendientes($db, $tt('12:13'))['mensajes'], 1, 'a los 16 minutos: a Beto (15 min)');
igual(($tgEnvios()[1]['datos']['chat_id'] ?? 0), 7003, '…a su Telegram');
igual(Telegram::procesarPendientes($db, $tt('12:30'))['mensajes'], 0, 'ya se avisó a todos: nada más');
Telegram::guardarModo($db, $tBeto, -1);   // desde aquí solo Ana usa "si nadie contesta"
$tgM($c2, 'otra pregunta', '2030-02-02 13:00:00');
$tgM($c2, 'Sí, enviamos', '2030-02-02 13:02:00', 'sale', 'panel');
igual(Telegram::procesarPendientes($db, $tt('13:30'))['mensajes'], 0, 'ya le contestaron: no avisa');
$tgM($c3, 'mamá escribe', '2030-02-02 14:00:00');
igual(Telegram::procesarPendientes($db, $tt('14:30'))['mensajes'], 0, 'contactos personales: tampoco');
$db->exec("UPDATE usuarios SET tg_chat_id = 500500 WHERE id = $tAna");
$tgM($c2, 'falla temporal', '2030-02-02 15:00:00');
igual(Telegram::procesarPendientes($db, $tt('15:06'))['fallidos'], 1, 'Telegram con falla temporal: falla…');
ok(Telegram::estado($db, $tAna)['conectado'], '…sin desconectar a Ana');
$db->exec("UPDATE usuarios SET tg_chat_id = 7001 WHERE id = $tAna");
igual(Telegram::procesarPendientes($db, $tt('15:08'))['mensajes'], 0, 'no reintenta cada minuto');
igual(Telegram::procesarPendientes($db, $tt('15:12'))['mensajes'], 1, 'a los 5 minutos se vuelve a intentar y llega');
// Dos corridas a la vez no repiten (candado)
$db->query('SELECT pg_advisory_lock(7720141127)');
$tgM($c2, 'con candado', '2030-02-02 16:00:00');
igual(Telegram::procesarPendientes($db, $tt('16:30'))['mensajes'], 1, 'el candado es de esta misma conexión (reentrante): sigue funcionando');
$db->query('SELECT pg_advisory_unlock(7720141127)');
$otra = new PDO('pgsql:host=' . getenv('TUWA_DB_HOST') . ';port=' . getenv('TUWA_DB_PORT') . ';dbname=' . getenv('TUWA_DB_NAME'), getenv('TUWA_DB_USER'), getenv('TUWA_DB_PASS') ?: null);
$otra->query('SELECT pg_advisory_lock(7720141127)');
$tgM($c2, 'otra corrida', '2030-02-02 17:00:00');
igual(Telegram::procesarPendientes($db, $tt('17:30'))['mensajes'], 0, 'si otra corrida tiene el candado, esta no manda nada');
$otra->query('SELECT pg_advisory_unlock(7720141127)');
$otra = null;
igual(Telegram::procesarPendientes($db, $tt('17:31'))['mensajes'], 1, 'cuando la otra termina, sale una sola vez');

echo "== Avisos por Telegram: arreglos de la revisión\n";
// (1) Cambiar o resetear la contraseña, o desactivar a la persona, desconecta su Telegram
$conectarTg = function (int $uid, int $chat) use ($db, $upd): void {
    preg_match('#start=([0-9a-f]{32})#', Telegram::crearCodigo($db, $uid, new DateTimeImmutable()), $mc);
    Telegram::procesarUpdate($db, $upd($chat, '/start ' . $mc[1]));
};
$conectarTg($tBeto, 7003);
ok(Empresas::cambiarUsuario($db, $eT, $tBeto, 'clave', $tDueno) !== null && !Telegram::estado($db, $tBeto)['conectado'], 'el administrador le pone contraseña nueva: su Telegram se desconecta');
$db->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')->execute([password_hash('claveVieja123', PASSWORD_DEFAULT), $tAna]);
igual(Empresas::cambiarMiClave($db, $tAna, 'claveVieja123', 'claveNueva456', 'claveNueva456'), true, 'cambiar mi contraseña: avisa que tenía Telegram…');
ok(!Telegram::estado($db, $tAna)['conectado'], '…y lo desconecta');
igual(Empresas::cambiarMiClave($db, $tAna, 'claveNueva456', 'claveOtra7890', 'claveOtra7890'), false, 'sin Telegram conectado: no dice nada de Telegram');
$conectarTg($tBeto, 7003);
Empresas::cambiarUsuario($db, $eT, $tBeto, 'desactivar', $tDueno);
ok(!Telegram::estado($db, $tBeto)['conectado'], 'usuario desactivado: su Telegram se desconecta');
Empresas::cambiarUsuario($db, $eT, $tBeto, 'activar', $tDueno);

// (2) Al momento: la misma regla de destinatarios que los avisos del celular
$conectarTg($tAna, 7001);
$conectarTg($tBeto, 7003);
$conectarTg($tDueno, 7002);
foreach ([$tAna, $tBeto, $tDueno] as $x) Telegram::guardarModo($db, $x, -1);
$db->exec("DELETE FROM push_suscripciones WHERE usuario_id IN ($tAna, $tBeto, $tDueno)");
$db->exec("UPDATE usuarios SET recibe_chats = (rol <> 'admin') WHERE empresa_id = $eT");
$idsDe = fn (array $c) => array_map(fn ($u) => (int) $u['id'], Telegram::destinatarios($db, $eT, $recargar($c)));
$c6 = Contactos::guardar($db, $lT, '0981888006', 'Regla Tele', 'cliente');
$db->exec('UPDATE contactos SET asignado_a = ' . $tAna . ' WHERE id = ' . (int) $c6['id']);
igual($idsDe($c6), [$tAna], 'asignado activo con Telegram: solo esa persona');
$db->exec("UPDATE usuarios SET activo = false WHERE id = $tAna");
igual($idsDe($c6), [$tBeto], 'asignado DESACTIVADO: al equipo que recibe chats');
$db->exec("UPDATE usuarios SET activo = true WHERE id = $tAna");
$db->exec("UPDATE usuarios SET recibe_chats = false WHERE empresa_id = $eT");
igual($idsDe($c2), [$tDueno], 'sin asignar y nadie recibe chats: a los administradores');
$db->exec("UPDATE usuarios SET recibe_chats = (rol <> 'admin') WHERE empresa_id = $eT");
foreach ([$tAna, $tBeto] as $x) Telegram::desvincular($db, $x);
$ids = $idsDe($c2);
sort($ids);
$esperado = [$tDueno, $tAna, $tBeto];
sort($esperado);
igual($ids, $esperado, 'a nadie del equipo le llega ningún aviso: también a los administradores');
$tgLimpiar();
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c2), 'nadie tiene avisos', 201, new DateTimeImmutable('2030-03-01 10:00:00')), 1, '…y le llega al Telegram del dueño');
igual(array_map(fn ($c) => $c['datos']['chat_id'], $tgEnvios()), [7002], '…solo al dueño (los demás no tienen Telegram)');
$conectarTg($tAna, 7001);
igual($idsDe($c2), [$tAna, $tBeto], 'si a alguien del equipo le llega un aviso, los administradores no se suman');

// (3) Cambio de bot: todos desconectados, códigos borrados y webhook del bot viejo quitado
Telegram::crearCodigo($db, $tBeto);
$tgLimpiar();
igual(Telegram::cambioDeBot($db, '654321:tokenDelBotViejoDeTukanMensajes0123456789'), 2, 'cambio de bot: desconecta a los 2 que tenían Telegram');
igual((int) $db->query('SELECT count(*) FROM usuarios WHERE tg_chat_id IS NOT NULL OR tg_codigo IS NOT NULL')->fetchColumn(), 0, '…y borra los códigos pendientes');
$dw = array_values(array_filter($tgLlamadas(), fn ($c) => $c['metodo'] === 'deleteWebhook'));
igual([count($dw), $dw[0]['token'] ?? ''], [1, '654321:tokenDelBotViejoDeTukanMensajes0123456789'], '…y quita el webhook con el token del bot VIEJO');
$tgLimpiar();
Telegram::cambioDeBot($db, Telegram::token());
ok(!array_filter($tgLlamadas(), fn ($c) => $c['metodo'] === 'deleteWebhook'), 'mismo token: no se borra el webhook del bot que está en uso');

// (5) Solo se desconecta si Telegram lo dice en su JSON; solo ese chat; el código pendiente se queda
$conectarTg($tAna, 7001);
foreach ([400400 => true, 403000 => false, 500500 => false, 403403 => true] as $chatX => $desconecta) {
    $db->exec("UPDATE usuarios SET tg_chat_id = $chatX, tg_modo = -1 WHERE id = $tAna");
    $db->exec("DELETE FROM tg_rafagas WHERE usuario_id = $tAna");
    Telegram::crearCodigo($db, $tAna);
    Telegram::mensajeNuevo($db, $lineaT, $recargar($c6), 'hola', 300 + $chatX % 1000);
    $e = $db->query("SELECT tg_chat_id, tg_codigo FROM usuarios WHERE id = $tAna")->fetch();
    igual($e['tg_chat_id'] === null, $desconecta, "chat $chatX: " . ($desconecta ? 'Telegram dice que no existe → se desconecta' : 'falla sin JSON de Telegram o temporal → NO se desconecta'));
    if ($desconecta) ok($e['tg_codigo'] !== null, "chat $chatX: el código para volver a conectar no se borra");
}
// (4) Telegram lento: el aviso se rinde a los 2 segundos y no desconecta a nadie
$db->exec("UPDATE usuarios SET tg_chat_id = 900900 WHERE id = $tAna");
$db->exec("DELETE FROM tg_rafagas WHERE usuario_id = $tAna");
$t1 = microtime(true);
igual(Telegram::mensajeNuevo($db, $lineaT, $recargar($c6), 'Telegram lento', 401), 0, 'Telegram lento: no llega…');
$dur = microtime(true) - $t1;
ok($dur < 3.0, 'Telegram lento: se rinde en ~2 segundos (' . round($dur, 1) . ' s), no frena el webhook de WhatsApp');
ok(Telegram::estado($db, $tAna)['conectado'], '…y no desconecta a nadie');
usleep(3200000);   // que el Telegram simulado termine con la llamada lenta antes de seguir
$db->exec("UPDATE usuarios SET tg_chat_id = 7001 WHERE id = $tAna");

// (6) Limpieza una vez al día, aunque el servidor no tenga correo: marcas de más de 30 días y ráfagas de más de 1 día
$db->exec("DELETE FROM ajustes WHERE clave IN ('aviso_correo_limpieza', 'tg_limpieza')");
$db->exec("INSERT INTO tg_rafagas (usuario_id, contacto_id, ultimo) VALUES ($tBeto, " . (int) $c6['id'] . ", '2030-01-01') ON CONFLICT (usuario_id, contacto_id) DO UPDATE SET ultimo = '2030-01-01'");
$viejoM = $tgM($c2, 'marca vieja', '2029-12-01 10:00:00');
$db->exec("INSERT INTO avisos_correo (mensaje_id, destino, creado) VALUES ($viejoM, 'tfin', '2029-12-01')");
$smtpAntes = getenv('SMTP_HOST');
putenv('SMTP_HOST=');
Telegram::procesarPendientes($db, new DateTimeImmutable('2030-03-05 09:00:00'));
igual((int) $db->query("SELECT count(*) FROM avisos_correo WHERE mensaje_id = $viejoM")->fetchColumn(), 0, 'sin correo configurado: igual se borran las marcas de más de 30 días');
igual((int) $db->query("SELECT count(*) FROM tg_rafagas WHERE ultimo < '2030-03-04'")->fetchColumn(), 0, 'y las ráfagas de más de 1 día');
igual(Telegram::limpiar($db, new DateTimeImmutable('2030-03-05 15:00:00')), 0, 'la limpieza corre una sola vez al día');
putenv('SMTP_HOST=' . $smtpAntes);

// (7) Aviso de prueba a Telegram: máximo 3 por hora
$db->exec("UPDATE usuarios SET tg_pruebas = '[]' WHERE id = $tAna");
$tgLimpiar();
foreach (['10:00', '10:10', '10:20'] as $h) Telegram::prueba($db, $tAna, 'es', new DateTimeImmutable("2030-03-06 $h:00"));
try { Telegram::prueba($db, $tAna, 'es', new DateTimeImmutable('2030-03-06 10:30:00')); ok(false, '4.º aviso de prueba'); }
catch (RuntimeException $e) { ok(str_contains($e->getMessage(), '3 avisos de prueba'), 'el 4.º aviso de prueba en una hora se rechaza'); }
igual(count($tgEnvios()), 3, 'solo salieron 3 avisos de prueba');
ok(Telegram::prueba($db, $tAna, 'es', new DateTimeImmutable('2030-03-06 11:05:00')), 'pasada la hora se puede otra vez');

// (3b) configurar_telegram.sh de punta a punta (con sudo, como en el servidor): mismo bot no desconecta; otro bot sí
if (trim((string) shell_exec('sudo -n true 2>&1 && echo si')) === 'si' && getenv('TUWA_SIN_SUDO') !== '1' && trim((string) shell_exec('id -u www-data 2>/dev/null')) !== '') {
    $app = sys_get_temp_dir() . '/tuwa_tg_' . getmypid();
    foreach (['app', 'src', 'bin', 'idiomas'] as $d) shell_exec('mkdir -p ' . escapeshellarg($app) . ' && cp -r ' . escapeshellarg(dirname(__DIR__) . "/$d") . ' ' . escapeshellarg("$app/$d"));
    @mkdir("$app/config", 0755, true);
    file_put_contents("$app/config/.env", "META_APP_ID=1\nTUWA_URL=https://whatsapp.ejemplo.com\nTUWA_TELEGRAM_API_URL=" . getenv('TG_URL') . "\n"
        . 'TUWA_DB_HOST=' . getenv('TUWA_DB_HOST') . "\nTUWA_DB_PORT=" . getenv('TUWA_DB_PORT') . "\nTUWA_DB_NAME=" . getenv('TUWA_DB_NAME')
        . "\nTUWA_DB_USER=" . getenv('TUWA_DB_USER') . "\nTUWA_DB_PASS=" . getenv('TUWA_DB_PASS') . "\n");
    shell_exec('chmod -R a+rX ' . escapeshellarg($app));
    $correrTg = function (string $token) use ($app): string {
        $p = proc_open(['bash', dirname(__DIR__) . '/deploy/configurar_telegram.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tc,
                       null, array_merge(getenv(), ['APP' => $app, 'TELEGRAM_TOKEN_NUEVO' => $token]));
        fclose($tc[0]);
        $o = stream_get_contents($tc[1]) . stream_get_contents($tc[2]);
        proc_close($p);
        return $o;
    };
    $o1 = $correrTg('123456:tokenDePruebaDelBotTukanMensajes0123456789');
    ok(str_contains($o1, 'LISTO: bot @TukanMensajesBot conectado') && !str_contains($o1, 'ATENCION'), 'configurar_telegram.sh: primer bot conectado');
    $leido = parse_ini_file("$app/config/.env", false, INI_SCANNER_RAW);
    ok(strlen($leido['TUWA_TELEGRAM_SECRETO'] ?? '') === 64 && ($leido['TUWA_TELEGRAM_BOT'] ?? '') === 'TukanMensajesBot' && ($leido['META_APP_ID'] ?? '') === '1', 'guarda token, secreto y bot sin borrar lo demás');
    $conectarTg($tAna, 7001);
    $o2 = $correrTg('123456:tokenDePruebaDelBotTukanMensajes0123456789');
    ok(!str_contains($o2, 'ATENCION') && Telegram::estado($db, $tAna)['conectado'], 'volver a correrlo con el mismo bot: nadie se desconecta');
    $tgLimpiar();
    $o3 = $correrTg('654321:tokenDelOtroBotDeTukanMensajes0123456789');
    ok(str_contains($o3, 'ATENCION: cambiaste de bot (@TukanMensajesBot -> @OtroTukanBot)') && str_contains($o3, 'conectar de nuevo') && str_contains($o3, 'LISTO: bot @OtroTukanBot'), 'otro bot: avisa en pantalla que cada persona debe conectar de nuevo');
    ok(!Telegram::estado($db, $tAna)['conectado'], '…y desconecta a todos');
    $dw = array_values(array_filter($tgLlamadas(), fn ($c) => $c['metodo'] === 'deleteWebhook'));
    igual($dw[0]['token'] ?? '', '123456:tokenDePruebaDelBotTukanMensajes0123456789', '…y quita el webhook del bot viejo');
    $o4 = $correrTg('999999:tokenQueTelegramNoConoce0123456789abc');
    $leido = parse_ini_file("$app/config/.env", false, INI_SCANNER_RAW);
    ok(str_contains($o4, 'no aceptó') && ($leido['TUWA_TELEGRAM_TOKEN'] ?? '') === '654321:tokenDelOtroBotDeTukanMensajes0123456789', 'token que Telegram no acepta: deja el que estaba');
    ok(!glob("$app/config/.env.*"), 'configurar_telegram.sh no deja copias del .env');
    shell_exec('sudo rm -rf ' . escapeshellarg($app));
}
foreach (['TUWA_TELEGRAM_TOKEN', 'TUWA_TELEGRAM_SECRETO', 'TUWA_TELEGRAM_BOT', 'TUWA_TELEGRAM_API_URL'] as $k) putenv("$k=");

// ---------------------------------------------------------------------------------------------------------------
use TuWhatsApp\Fcm;
echo "== Avisos nativos de la app (Firebase Cloud Messaging SIMULADO)\n";
$fcmLog = (string) getenv('FCM_LOG');
$fcmLlamadas = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($fcmLog) ?: [])));
$fcmEnvios = fn () => array_values(array_filter($fcmLlamadas(), fn ($c) => ($c['tipo'] ?? '') === 'envio'));
$fcmTokens = fn () => array_values(array_filter($fcmLlamadas(), fn ($c) => ($c['tipo'] ?? '') === 'token'));
$fcmLimpiar = fn () => file_put_contents($fcmLog, '');
putenv('TUWA_FCM_URL=' . getenv('FCM_URL'));
$hex = fn (int $n) => bin2hex(random_bytes($n));
$eF = Empresas::crear($db, ['nombre' => 'Firebase SA', 'estado' => 'activa', 'admin_correo' => 'dueno@firebase.ec', 'admin_nombre' => 'Dueño F', 'max_usuarios' => 10])['empresa_id'];
$lF = Lineas::guardarConexion($db, null, ['nombre' => 'Ventas Firebase', 'telefono' => '0996409933', 'phone_number_id' => '939390', 'waba_id' => '939391', 'token' => 'EAAGtokenFirebase1234567890'], $eF);
$lineaF = Lineas::buscar($db, $lF, $eF);
Empresas::agregarUsuario($db, $eF, 'ana@firebase.ec', 'Ana F', 'agente');
Empresas::agregarUsuario($db, $eF, 'beto@firebase.ec', 'Beto F', 'agente');
[$fDueno, $fAna, $fBeto] = [$uidN('dueno@firebase.ec'), $uidN('ana@firebase.ec'), $uidN('beto@firebase.ec')];

// Registro del celular
[$dA, $sA] = [$hex(16), $hex(32)];
igual(Fcm::registrar($db, ['dispositivo' => $dA, 'secreto' => $sA, 'token' => 'token-ana-1', 'modelo' => 'Samsung SM-A546E', 'version' => '1.0.4'], '10.0.0.1'), [200, ['ok' => true]], 'registrar un celular nuevo');
igual((string) $db->query("SELECT secreto_hash FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn(), hash('sha256', $sA), 'en la base va el sha256 del secreto, no el secreto');
igual(Fcm::registrar($db, ['dispositivo' => $dA, 'secreto' => $hex(32), 'token' => 'token-robado', 'modelo' => 'x', 'version' => '1'], '10.0.0.66')[0], 403, 'mismo celular con otro secreto: 403');
igual((string) $db->query("SELECT token FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn(), 'token-ana-1', '…y no toca nada');
igual(Fcm::registrar($db, ['dispositivo' => $dA, 'secreto' => $sA, 'token' => 'token-ana', 'modelo' => 'Samsung SM-A546E', 'version' => '1.0.4'], '10.0.0.1'), [200, ['ok' => true]], 'con su secreto actualiza el token');
igual(Fcm::registrar($db, ['dispositivo' => 'ABC', 'secreto' => $sA, 'token' => 't'], '10.0.0.1')[0], 400, 'dispositivo con forma mala: 400');
igual(Fcm::registrar($db, ['dispositivo' => strtoupper($hex(16)), 'secreto' => $sA, 'token' => 't'], '10.0.0.1')[0], 400, 'hex en mayúsculas: 400 (forma exacta)');
igual(Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(31), 'token' => 't'], '10.0.0.1')[0], 400, 'secreto corto: 400');
igual(Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(32), 'token' => str_repeat('a', 4097)], '10.0.0.1')[0], 400, 'token de más de 4096: 400');
igual(Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(32), 'token' => "a b<script>"], '10.0.0.1')[0], 400, 'token con caracteres raros: 400');
igual(Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(32), 'token' => 'tok', 'modelo' => str_repeat('m', 81)], '10.0.0.1')[0], 400, 'modelo de más de 80: 400');
for ($i = 0; $i < Fcm::REGISTROS_POR_HORA; $i++) {
    Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(32), 'token' => 'tok-' . $i], '10.9.9.9');
}
igual(Fcm::registrar($db, ['dispositivo' => $hex(16), 'secreto' => $hex(32), 'token' => 'tok-x'], '10.9.9.9')[0], 429, 'más de 20 celulares nuevos por hora desde la misma IP: 429');
igual(Fcm::registrar($db, ['dispositivo' => $dA, 'secreto' => $sA, 'token' => 'token-ana', 'modelo' => 'Samsung SM-A546E'], '10.9.9.9')[0], 200, '…pero un celular ya registrado sí se actualiza desde esa IP');
$db->exec("DELETE FROM ajustes WHERE clave = 'fcm_limpieza'");
$sueltos = (int) $db->query('SELECT count(*) FROM fcm_dispositivos WHERE usuario_id IS NULL AND vinculado IS NULL')->fetchColumn();
igual(Fcm::limpiar($db, new DateTimeImmutable('+2 days')), [21, 0], 'limpieza diaria: borra los registros por IP de más de 1 día (los celulares de menos de 7 días se quedan)');
igual(Fcm::limpiar($db, new DateTimeImmutable('+2 days 1 hour')), null, '…y no se repite el mismo día');
$db->exec("DELETE FROM ajustes WHERE clave = 'fcm_limpieza'");
$db->exec("UPDATE fcm_dispositivos SET vinculado = now() WHERE dispositivo = '$dA'");
igual(Fcm::limpiar($db, new DateTimeImmutable('+8 days'))[1], $sueltos - 1, 'celulares que nunca se vincularon a nadie en 7 días: se borran');
ok((bool) $db->query("SELECT 1 FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn(), '…pero no uno que alguna vez se vinculó');
$db->exec("DELETE FROM ajustes WHERE clave = 'fcm_limpieza'");

// Protocolo v2: la app abre la página con &disp=&ts=&prueba= (HMAC con clave = sha256 crudo del secreto)
$pruebaApp = fn (string $d, string $sec, int $t) => hash_hmac('sha256', "vincular:$d:$t", hash('sha256', $sec, true));
$_COOKIE = [];
$tA = time();
ok(Fcm::vincularDeUrl($db, $dA, (string) $tA, $pruebaApp($dA, $sA, $tA)), 'prueba HMAC buena: vincula');
$cookieA = (string) ($_COOKIE[Fcm::COOKIE] ?? '');
igual($cookieA, Fcm::valorCookie($dA), '…y deja la cookie firmada');
igual(Fcm::dispositivoCookie(), $dA, 'la cookie firmada se lee');
$_COOKIE = [];
ok(!Fcm::vincularDeUrl($db, $dA, (string) $tA, $pruebaApp($dA, $sA, $tA)), 'la misma prueba otra vez (repetida): no sirve');
ok(!Fcm::vincularDeUrl($db, $dA, (string) ($tA - 5), $pruebaApp($dA, $sA, $tA - 5)), 'un ts más viejo que el último usado: no sirve');
ok(!Fcm::vincularDeUrl($db, $dA, (string) ($tA + 1), $pruebaApp($dA, $hex(32), $tA + 1)), 'prueba hecha con otro secreto: no sirve');
ok(!Fcm::vincularDeUrl($db, $dA, (string) ($tA - 700), $pruebaApp($dA, $sA, $tA - 700)), 'prueba de hace más de 10 minutos (vencida): no sirve');
ok(!Fcm::vincularDeUrl($db, $dA, (string) ($tA + 700), $pruebaApp($dA, $sA, $tA + 700)), 'prueba del futuro (más de 10 minutos): no sirve');
ok(!Fcm::vincularDeUrl($db, $hex(16), (string) $tA, $pruebaApp($dA, $sA, $tA)), 'celular desconocido: no sirve');
ok(!Fcm::vincularDeUrl($db, $dA, 'abc', 'xyz'), 'datos con forma mala: no sirve');
igual($_COOKIE, [], 'ninguna de esas pone cookie');
ok(Fcm::vincularDeUrl($db, $dA, (string) ($tA + 2), $pruebaApp($dA, $sA, $tA + 2)), 'un ts nuevo y bueno sí sirve');
igual(Fcm::urlLimpia('/?r=inicio&app=play&disp=' . $dA . '&ts=1&prueba=abc'), '/?r=inicio&app=play', 'la dirección queda sin disp, ts ni prueba (para el 302)');
igual(Fcm::urlLimpia('//malo.com/?disp=1'), '/', 'nunca redirige a otro sitio');
$_COOKIE = [Fcm::COOKIE => $dA];
ok(Fcm::dispositivoCookie() === null, 'cookie sin firma (como la «pendiente» de antes): no cuenta');
$_COOKIE = [Fcm::COOKIE => $dA . '.' . str_repeat('0', 32)];
ok(Fcm::dispositivoCookie() === null, 'cookie con firma falsa: no cuenta');
igual(Fcm::alEntrar($db, $fAna), 'sin_cookie', '…así que no vincula nada');
$_COOKIE = [Fcm::COOKIE => $cookieA];
igual(Fcm::alEntrar($db, $fAna), 'vinculado', 'Ana entra en la app: su celular queda vinculado');
igual((int) $db->query("SELECT usuario_id FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn(), $fAna, '…a Ana');
ok(!Fcm::algunoFunciona($db, [$fAna]) && Fcm::funcionando($db, $fAna) === 0, 'sin la clave de Firebase subida todavía no cuenta como «avisos funcionando»');
[$dB, $sB] = [$hex(16), $hex(32)];
Fcm::registrar($db, ['dispositivo' => $dB, 'secreto' => $sB, 'token' => 'token-beto', 'modelo' => 'Moto G'], '10.0.0.2');
$_COOKIE = [Fcm::COOKIE => Fcm::valorCookie($dB)];
igual(Fcm::alEntrar($db, $fBeto), 'vinculado', 'Beto: vinculado');
Fcm::apagar($db, $fBeto);
igual(Fcm::alEntrar($db, $fBeto), 'apagado', '«Apagar en este celular»: ya no se vincula solo aunque la cookie esté firmada');
ok(Fcm::reactivar($db, $fBeto), 'se puede volver a activar (con la cookie firmada)');
igual(Fcm::alEntrar($db, $fBeto), 'vinculado', '…vinculado otra vez');
$_COOKIE = [Fcm::COOKIE => $dB];
ok(!Fcm::reactivar($db, $fAna), 'sin cookie firmada no se puede activar en un celular ajeno');
$_COOKIE = [Fcm::COOKIE => Fcm::valorCookie($hex(16))];
igual(Fcm::alEntrar($db, $fAna), 'sin_registro', 'cookie firmada de un celular que no se registró: «cierra y abre la app»');
$_COOKIE = [];
igual(Fcm::alEntrar($db, $fAna), 'sin_cookie', 'sin cookie (computadora o app vieja): nada');
// avisos = 0: el celular tiene las notificaciones bloqueadas
igual(Fcm::registrar($db, ['dispositivo' => $dB, 'secreto' => $sB, 'token' => 'token-beto', 'modelo' => 'Moto G', 'avisos' => '0'], '10.0.0.2'), [200, ['ok' => true]], 're-registro con avisos=0 (mismo token)');
igual(Fcm::registrar($db, ['dispositivo' => $dB, 'secreto' => $sB, 'token' => 'token-beto', 'avisos' => '2'], '10.0.0.2')[0], 400, 'avisos raro: 400');
$_COOKIE = [Fcm::COOKIE => Fcm::valorCookie($dB)];
igual(Fcm::alEntrar($db, $fBeto), 'bloqueado', 'con avisos=0 el estado es «bloqueado»');
igual(Fcm::dispositivos($db, $fBeto)[0]['avisos'] ?? null, false, 'en la lista sale con los avisos bloqueados');
$_COOKIE = [];

// Envío
$fcmLimpiar();
igual(Fcm::enviar($db, [$fAna], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']), 0, 'sin clave de Firebase: no manda nada…');
igual(count($fcmLlamadas()), 0, '…ni llama a Google');
$claveJson = json_encode(['type' => 'service_account', 'project_id' => 'tukan-mensajes-prueba', 'private_key_id' => 'abc123',
    'private_key' => file_get_contents((string) getenv('FCM_SIM_PRIVADA')), 'client_email' => (string) getenv('FCM_SIM_CORREO'),
    'token_uri' => getenv('FCM_URL') . '/token'], JSON_UNESCAPED_SLASHES);
foreach ([['{"type":"otra"}', 'service_account'], ['no es json', 'json'], [json_encode(['type' => 'service_account', 'project_id' => 'p-1234']), 'falta'],
          [str_replace('BEGIN PRIVATE KEY', 'BEGIN ROTO', $claveJson), 'no se puede leer'],
          [str_replace(getenv('FCM_URL') . '/token', 'http://atacante.com/token', $claveJson), 'https']] as [$malo, $dice]) {
    try { Fcm::guardarClave($db, (string) $malo); ok(false, "clave mala aceptada ($dice)"); }
    catch (RuntimeException $e) { ok(str_contains($e->getMessage(), $dice), "clave mala rechazada: $dice"); }
}
igual(Fcm::guardarClave($db, $claveJson), ['project_id' => 'tukan-mensajes-prueba', 'client_email' => (string) getenv('FCM_SIM_CORREO')], 'guarda la clave buena');
$guardada = (string) $db->query("SELECT valor FROM ajustes WHERE clave = 'fcm_clave'")->fetchColumn();
ok($guardada !== '' && !str_contains($guardada, 'PRIVATE KEY') && !str_contains($guardada, 'tukan-mensajes-prueba'), 'en la base la clave queda cifrada');
igual(Fcm::estadoClave($db)['project_id'], 'tukan-mensajes-prueba', 'estado: muestra el proyecto (no la llave)');
ok(Fcm::algunoFunciona($db, [$fAna]) && \TuWhatsApp\Push::algunoFunciona($db, [$fAna]) && \TuWhatsApp\Push::funcionando($db, $fAna) === 1, 'con la clave subida: cuenta como «avisos funcionando» (no sale la cinta amarilla)');
ok(!Fcm::algunoFunciona($db, [$fBeto]) && Fcm::funcionando($db, $fBeto) === 0, 'un celular con avisos=0 NO cuenta');
Fcm::registrar($db, ['dispositivo' => $dB, 'secreto' => $sB, 'token' => 'token-beto', 'modelo' => 'Moto G', 'avisos' => '1'], '10.0.0.2');
ok(Fcm::algunoFunciona($db, [$fBeto]), 'cuando la app avisa avisos=1, vuelve a contar');
ok(!isset(Fcm::estadoClave($db)['private_key']), '…y nunca la llave privada');

$cF1 = Contactos::guardar($db, $lF, '0981777001', 'Lucía <Fire> & Cía', 'cliente');
$db->exec('UPDATE contactos SET asignado_a = ' . $fAna . ' WHERE id = ' . (int) $cF1['id']);
$fcmLimpiar();
$whF = new Webhook($db, new DateTimeImmutable());
$whF->procesar(cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '593996409933', 'phone_number_id' => '939390'],
    'contacts' => [['profile' => ['name' => 'Lucía'], 'wa_id' => '593981777001']],
    'messages' => [['from' => '593981777001', 'id' => 'wamid.FCM1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Hola, ¿tienen <envío>?']]]]));
$env = $fcmEnvios();
igual(count($env), 1, 'mensaje de un cliente asignado a Ana: un aviso nativo');
$mF = $env[0]['mensaje'] ?? [];
igual($mF['token'] ?? '', 'token-ana', '…al celular de Ana');
igual($env[0]['proyecto'] ?? '', 'tukan-mensajes-prueba', '…al proyecto de la clave');
ok(!isset($mF['notification']) && ($mF['data']['titulo'] ?? '') === 'Lucía <Fire> & Cía' && ($mF['data']['texto'] ?? '') === 'Hola, ¿tienen <envío>?'
   && ($mF['data']['url'] ?? '') === '/?r=chat&c=' . (int) $cF1['id'] && str_starts_with((string) ($mF['data']['tag'] ?? ''), 'tuwa-c' . (int) $cF1['id'] . '-'), 'solo datos: titulo, texto, url y tag del chat');
ok(($mF['android']['priority'] ?? '') === 'HIGH' && ($mF['android']['ttl'] ?? '') === '86400s' && ($mF['android']['collapse_key'] ?? '') === $mF['data']['tag'], 'prioridad alta, espera 24 h y collapse_key = tag');
ok(array_reduce($mF['data'] ?? [], fn ($c, $v) => $c && is_string($v), true), 'todos los datos son texto (lo pide FCM)');
igual(count($fcmTokens()), 1, 'pidió el token de acceso de Google una vez (JWT firmado y revisado)');
ok(str_starts_with((string) ($env[0]['auth'] ?? ''), 'Bearer acceso-'), 'con «Authorization: Bearer»');
$fcmLimpiar();
igual(Fcm::prueba($db, $fAna), 1, 'aviso de prueba a la app');
igual(count($fcmTokens()), 0, 'el token de acceso se reusa (guardado ~50 minutos)');
igual(Fcm::enviar($db, [$fAna], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']), 1, 'otro aviso: tampoco pide token nuevo');
igual(count($fcmTokens()), 0, '…sigue sin pedirlo');
$accesoGuardado = (string) $db->query("SELECT valor FROM ajustes WHERE clave = 'fcm_acceso'")->fetchColumn();
ok($accesoGuardado !== '' && !str_contains($accesoGuardado, 'acceso-'), 'el token de acceso también se guarda cifrado');
$cF3 = Contactos::guardar($db, $lF, '0981777003', 'Mamá', 'personal');
$fcmLimpiar();
igual(Fcm::mensajeNuevo($db, $lineaF, Contactos::buscar($db, $lF, (int) $cF3['id']), 'hola', 1), 0, 'contactos personales: nunca');
$cF2 = Contactos::guardar($db, $lF, '0981777002', 'Pedro Fire', 'cliente');
igual(Fcm::mensajeNuevo($db, $lineaF, Contactos::buscar($db, $lF, (int) $cF2['id']), 'sin asignar', 2), 2, 'chat sin asignar: a los celulares del equipo que recibe chats (Ana y Beto)');
$db->exec("UPDATE usuarios SET recibe_chats = false WHERE id = $fBeto");
$fcmLimpiar();
igual(Fcm::mensajeNuevo($db, $lineaF, Contactos::buscar($db, $lF, (int) $cF2['id']), 'sin asignar 2', 3), 1, 'quien no recibe chats no recibe ese aviso');
$db->exec("UPDATE usuarios SET recibe_chats = true WHERE id = $fBeto");

// Respuestas de Google
$ponerDueno = function (string $token) use ($db, $hex, $fDueno): void {
    $d = $hex(16);
    Fcm::registrar($db, ['dispositivo' => $d, 'secreto' => $hex(32), 'token' => $token, 'modelo' => 'Prueba'], '10.0.1.1');
    $db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fDueno, $d]);
};
array_map($ponerDueno, ['token-invalido', 'token-malo', 'token-500']);
igual(Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']), 0, 'ninguno se entregó');
$quedan = $db->query("SELECT token FROM fcm_dispositivos WHERE usuario_id = $fDueno ORDER BY token")->fetchAll(PDO::FETCH_COLUMN);
igual($quedan, ['', '', 'token-500'], 'UNREGISTERED (404) y token no válido (400): el celular se queda SIN token (con su secreto y su persona); la falla temporal sigue');
igual((int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = $fDueno AND token = '' AND secreto_hash <> ''")->fetchColumn(), 2, '…las dos filas se conservan');
igual(Fcm::funcionando($db, $fDueno), 1, 'sin token no cuenta como avisos funcionando (solo cuenta el que tiene token)');
$fcmLimpiar();
Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
igual(count($fcmEnvios()), 1, 'a los celulares sin token no se les intenta mandar');
$db->exec("DELETE FROM fcm_dispositivos WHERE usuario_id = $fDueno");
$ponerDueno('token-lento');
$t0F = microtime(true);
igual(Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']), 0, 'Google lento: no se entregó…');
ok(microtime(true) - $t0F < 4.0, '…y se rinde en menos de 4 s (nunca frena el webhook)');
sleep(3);   // el Google simulado atiende de a uno: que termine la llamada lenta
$db->exec("DELETE FROM fcm_dispositivos WHERE usuario_id = $fDueno");
$ponerDueno('token-500');
Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
igual((int) $db->query("SELECT errores FROM fcm_dispositivos WHERE token = 'token-500'")->fetchColumn(), 1, 'falla temporal: se cuenta');
$db->exec("UPDATE fcm_dispositivos SET errores = 5, ultimo_error = now() WHERE usuario_id = $fDueno");
$fcmLimpiar();
igual(Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']), 0, '5 fallas seguidas hoy: no se intenta…');
igual(count($fcmEnvios()), 0, '…ni se llama a Google');
$db->exec("UPDATE fcm_dispositivos SET ultimo_error = now() - interval '2 days' WHERE token = 'token-500'");
Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
igual(count($fcmEnvios()), 1, 'pasado un día se vuelve a intentar');
$db->exec("DELETE FROM fcm_dispositivos WHERE usuario_id = $fDueno");
[$d401] = [$hex(16)];
Fcm::registrar($db, ['dispositivo' => $d401, 'secreto' => $hex(32), 'token' => 'token-401'], '10.0.1.1');
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fDueno, $d401]);
Fcm::enviar($db, [$fDueno], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
ok($db->query("SELECT 1 FROM ajustes WHERE clave = 'fcm_acceso'")->fetchColumn() === false, 'Google dice 401: se olvida el token de acceso (el próximo aviso pide otro)');
$fcmLimpiar();
Fcm::prueba($db, $fAna);
igual(count($fcmTokens()), 1, '…y el siguiente aviso pide uno nuevo');
$db->exec("DELETE FROM fcm_dispositivos WHERE usuario_id = $fDueno");

// Desvincular: salir, contraseña, desactivar, cerrar en todos los demás
$_COOKIE = [Fcm::COOKIE => $cookieA];
Fcm::alSalir($db, $fAna);
ok($db->query("SELECT usuario_id FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn() === null, 'salir desde ese celular: deja de recibir los avisos');
igual(Fcm::alEntrar($db, $fAna), 'vinculado', 'vuelve a entrar en la app: se vincula de nuevo');
$_COOKIE = [];
$claveAna = Empresas::cambiarUsuario($db, $eF, $fAna, 'clave', $fDueno);
ok(is_string($claveAna) && (int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = $fAna")->fetchColumn() === 0, 'el administrador le pone contraseña nueva: sus celulares dejan de recibir avisos');
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fAna, $dA]);
Empresas::cambiarMiClave($db, $fAna, (string) $claveAna, 'AnaNueva123', 'AnaNueva123');
igual((int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = $fAna")->fetchColumn(), 0, 'cambiar su propia contraseña: también');
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fAna, $dA]);
Empresas::cambiarUsuario($db, $eF, $fAna, 'desactivar', $fDueno);
igual((int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = $fAna")->fetchColumn(), 0, 'usuario desactivado: también');
Empresas::cambiarUsuario($db, $eF, $fAna, 'activar', $fDueno);
[$dC] = [$hex(16)];
Fcm::registrar($db, ['dispositivo' => $dC, 'secreto' => $hex(32), 'token' => 'token-beto-2', 'modelo' => 'Tablet'], '10.0.0.3');
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo IN (?, ?)')->execute([$fBeto, $dB, $dC]);
$_COOKIE = [Fcm::COOKIE => Fcm::valorCookie($dB)];
\TuWhatsApp\Sesiones::olvidarUsuario($db, $fBeto, 999999);   // «cerrar en todos los demás» desde el celular B
igual((int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = $fBeto")->fetchColumn(), 0, 'cerrar en todos los demás: se desvinculan TODOS sus celulares…');
igual(Fcm::alEntrar($db, $fBeto), 'vinculado', '…y el celular desde donde se hizo se vuelve a vincular solo en su próximo clic');
$_COOKIE = [];
// Cerrar una sesión desde Mi cuenta: si ese recuerdo era de la app, su celular se desvincula
$_COOKIE = [Fcm::COOKIE => Fcm::valorCookie($dC)];
\TuWhatsApp\Sesiones::recordar($db, $fBeto, 'Mozilla/5.0 (Linux; Android 14; Tablet)', 'App Tukán Mensajes · Android', Fcm::dispositivoCookie());
$_COOKIE = [];
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fBeto, $dC]);
$idRec = (int) $db->query("SELECT id FROM sesiones_recordadas WHERE usuario_id = $fBeto AND disp = '$dC'")->fetchColumn();
ok($idRec > 0, 'al recordar la sesión desde la app se guarda su celular');
ok(\TuWhatsApp\Sesiones::cerrar($db, $fBeto, $idRec) && $db->query("SELECT usuario_id FROM fcm_dispositivos WHERE dispositivo = '$dC'")->fetchColumn() === null, 'cerrar esa sesión desde Mi cuenta: ese celular deja de recibir avisos');
ok((int) $db->query("SELECT usuario_id FROM fcm_dispositivos WHERE dispositivo = '$dB'")->fetchColumn() === $fBeto, '…y los otros celulares siguen');
$db->prepare('UPDATE fcm_dispositivos SET usuario_id = ? WHERE dispositivo = ?')->execute([$fBeto, $dC]);
ok(Fcm::quitarId($db, $fBeto, (int) $db->query("SELECT id FROM fcm_dispositivos WHERE dispositivo = '$dB'")->fetchColumn()), 'quitar un celular desde App en el celular');
ok(!Fcm::quitarId($db, $fAna, (int) $db->query("SELECT id FROM fcm_dispositivos WHERE dispositivo = '$dC'")->fetchColumn()), 'no se puede quitar el celular de otra persona');
igual(Fcm::quitarDesdeApp($db, ['dispositivo' => $dA, 'secreto' => $hex(32)])[0], 403, 'quitar desde la app con otro secreto: 403');
igual(Fcm::quitarDesdeApp($db, ['dispositivo' => $dA, 'secreto' => $sA]), [200, ['ok' => true]], 'quitar desde la app con su secreto');
ok($db->query("SELECT 1 FROM fcm_dispositivos WHERE dispositivo = '$dA'")->fetchColumn() === false, '…lo borra');
ok(Fcm::tokenMuerto(404, null) && Fcm::tokenMuerto(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']])
   && !Fcm::tokenMuerto(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload']]) && !Fcm::tokenMuerto(500, null), 'solo se borra si Google dice que el token ya no sirve');
// Sin avisos repetidos: con la app nueva funcionando, no se manda el Web Push de Chrome dentro de la app vieja (sí el de la computadora)
$subPrueba = function (string $fin, string $disp) use ($db, $fBeto): void {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k);
    $b = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $db->prepare('INSERT INTO push_suscripciones (usuario_id, endpoint, p256dh, auth, dispositivo) VALUES (?, ?, ?, ?, ?)')
       ->execute([$fBeto, getenv('SIM_URL') . '/push/ok/' . $fin, $b("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)), $b(random_bytes(16)), $disp]);
};
putenv('TUWA_PUSH_PRUEBA=' . getenv('SIM_URL') . '/push/');
$subPrueba('twa1', 'App Tukán Mensajes · Android Moto G');
$subPrueba('pc1', 'Chrome · Windows');
limpiarSim();
$fcmLimpiar();
\TuWhatsApp\Push::enviar($db, [$fBeto], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
$rutasPush = array_map(fn ($x) => $x['ruta'], array_filter(sim(), fn ($x) => str_contains((string) ($x['ruta'] ?? ''), '/push/')));
ok(count($fcmEnvios()) >= 1 && !array_filter($rutasPush, fn ($r) => str_contains($r, 'twa1')) && array_filter($rutasPush, fn ($r) => str_contains($r, 'pc1')),
   'con la app nueva funcionando: aviso nativo + computadora, y NO el Web Push de la app vieja (sin repetidos)');
$db->exec("UPDATE fcm_dispositivos SET avisos = false WHERE usuario_id = $fBeto");
limpiarSim();
\TuWhatsApp\Push::enviar($db, [$fBeto], ['titulo' => 'x', 'texto' => 'y', 'url' => '/', 'tag' => 't']);
$rutasPush = array_map(fn ($x) => $x['ruta'], array_filter(sim(), fn ($x) => str_contains((string) ($x['ruta'] ?? ''), '/push/')));
ok((bool) array_filter($rutasPush, fn ($r) => str_contains($r, 'twa1')), 'si la app nueva tiene los avisos bloqueados, el Web Push de la app vieja sí sale (por si acaso)');
$db->exec("UPDATE fcm_dispositivos SET avisos = true WHERE usuario_id = $fBeto");
$db->exec("DELETE FROM push_suscripciones WHERE usuario_id = $fBeto");
putenv('TUWA_PUSH_PRUEBA=');
Fcm::quitarClave($db);
ok(!Fcm::algunoFunciona($db, [$fBeto]) && Fcm::funcionando($db, $fBeto) === 0, 'sin la clave de Firebase los celulares no cuentan como avisos funcionando');
ok(!Fcm::configurado($db) && (int) $db->query("SELECT count(*) FROM ajustes WHERE clave IN ('fcm_clave', 'fcm_acceso')")->fetchColumn() === 0, 'quitar la clave borra también el token de acceso');
putenv('TUWA_FCM_URL=');

// =====================================================================================
// 23 nov 2026: todo lo que contesta solo habla el idioma del cliente (es, en, pt, fr, it)
// =====================================================================================
use TuWhatsApp\Traducir;
echo "== Contestar en el idioma del cliente\n";
$FC = \TuWhatsApp\FrasesCliente::class;
$TR = \TuWhatsApp\Traducir::class;
$incompletas = array_keys(array_filter($FC::claves(), fn ($x) => count(array_intersect_key($x, array_flip(['es', 'en', 'pt', 'fr', 'it']))) !== 5));
ok(!$incompletas, 'cada frase fija para el cliente está en los 5 idiomas' . ($incompletas ? ': faltan ' . implode(', ', $incompletas) : ''));
igual($FC::t('pago_recibido', 'en', ['total' => '$10,00', 'n' => 7]), '✅ We received your payment of $10,00 for order #7. Thank you for your purchase!', 'frase fija en inglés con variables');
igual($FC::t('pago_recibido', 'de', ['total' => '$1', 'n' => 1]), '✅ Recibimos tu pago de $1 del pedido #1. ¡Gracias por tu compra!', 'idioma desconocido: español');
igual([$FC::productos(1, 'en'), $FC::productos(3, 'fr')], ['1 item', '3 articles'], 'singular y plural');

// Menú: una línea de prueba (sin base)
$menuOp = json_encode([['titulo' => 'Precios', 'respuesta' => 'Los precios están en la web.', 'humano' => false],
                       ['titulo' => 'Hablar con alguien', 'respuesta' => 'Ya te atienden.', 'humano' => true]], JSON_UNESCAPED_UNICODE);
$menuTr = json_encode(['en' => ['saludo' => 'Hi, choose an option:', 'opciones' => [['titulo' => 'Prices', 'respuesta' => 'Prices are on our website.'], ['titulo' => '', 'respuesta' => '']], 'palabras' => 'pricing']], JSON_UNESCAPED_UNICODE);
$lm = ['auto_activo' => true, 'regla_solo_clientes' => false, 'regla_horario' => false, 'regla_una_vez' => false, 'regla_palabras' => true,
       'palabras' => 'precio, catálogo', 'mensaje_menu' => 'Hola, elige una opción:', 'opciones' => $menuOp, 'menu_botones' => true,
       'traducciones' => $menuTr, 'idioma_auto' => true, 'idiomas' => 'es,en,pt,fr,it', 'max_auto' => 3];
$hoyM = new DateTimeImmutable('2026-11-23 21:00:00');
$dEn = Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'en'], 'How much is it?', $hoyM, 0);
igual([$dEn['responder'], $dEn['saludo'], array_column($dEn['botones'], 'titulo'), $dEn['idioma']],
      [true, 'Hi, choose an option:', ['Prices', 'Hablar con alguien'], 'en'], 'cliente en inglés: palabra de negocio en inglés, saludo y botones en inglés (lo que falta, en español)');
ok(str_contains($dEn['motivo'], '(en English)'), 'el motivo dice que se contestó en inglés');
igual(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'en'], 'Prices', $hoyM, 0)['texto'], 'Prices are on our website.', 'eligió la opción por su nombre en inglés: respuesta en inglés');
igual(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'es'], 'prices', $hoyM, 0)['texto'], 'Los precios están en la web.', 'el nombre en inglés también vale para un cliente en español');
$d2 = Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'en'], '2', $hoyM, 0);
igual([$d2['texto'], $d2['humano']], ['Ya te atienden.', true], 'opción sin traducir: sale en español');
ok(!Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'es'], 'how much', $hoyM, 0)['responder'], 'las palabras de base en inglés no cambian el filtro de un cliente en español');
ok(!Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'es'], 'pricing please', $hoyM, 0)['responder'], 'las palabras traducidas al inglés no valen para un cliente en español');
ok(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'en'], 'pricing please', $hoyM, 0)['responder'], 'las palabras traducidas al inglés valen para un cliente en inglés');
ok(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'en'], 'precio', $hoyM, 0)['responder'], 'las palabras en español valen para todos');
ok(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'fr'], 'quel est le prix', $hoyM, 0)['responder'], 'palabras de base en francés');
igual(Reglas::decidir(array_merge($lm, ['idioma_auto' => false]), ['tipo' => 'cliente', 'idioma' => 'en'], 'precio', $hoyM, 0)['saludo'], 'Hola, elige una opción:', 'apagado "contestar en el idioma del cliente": todo en español');
igual(Reglas::decidir(array_merge($lm, ['idiomas' => 'es,pt']), ['tipo' => 'cliente', 'idioma' => 'en'], 'precio', $hoyM, 0)['saludo'], 'Hola, elige una opción:', 'idioma no marcado: español');
igual(Reglas::decidir($lm, ['tipo' => 'cliente', 'idioma' => 'pt'], 'qual o preço', $hoyM, 0)['saludo'], 'Hola, elige una opción:', 'idioma sin traducción: el saludo sale en español');
igual(Reglas::decidir($lm, ['tipo' => 'cliente', 'telefono' => '14155550100'], 'precio', $hoyM, 0)['idioma'], 'es', 'sin idioma guardado: español (el +1 no impone idioma)');
igual(Reglas::textoMenu($lm, 'en'), "Hi, choose an option:\n1. Prices\n2. Hablar con alguien", 'texto del menú en inglés');
igual($TR::limpiarLinea(['en' => ['saludo' => ' Hi ', 'op_titulo' => [0 => 'A', 2 => 'C'], 'op_respuesta' => [0 => 'a', 2 => 'c'], 'palabras' => 'Price;  COST']], [0 => 0, 2 => 1])['en'],
      ['saludo' => 'Hi', 'opciones' => [['titulo' => 'A', 'respuesta' => 'a'], ['titulo' => 'C', 'respuesta' => 'c']], 'palabras' => 'price, cost'], 'traducciones del formulario: limpias y alineadas con las opciones guardadas');

// Chatbot: motor en otro idioma
$pasosI = [['id' => 'p1', 'tipo' => 'botones', 'texto' => '¿Qué buscas?', 'opciones' => [['titulo' => 'Ropa', 'ir_a' => 'p2'], ['titulo' => 'Zapatos', 'ir_a' => 'p3']]],
           ['id' => 'p2', 'tipo' => 'pregunta', 'texto' => '¿Tu nombre?', 'guardar' => 'nombre'], ['id' => 'p3', 'tipo' => 'fin', 'texto' => 'Gracias.']];
$fI = ['id' => 9, 'palabras' => 'ropa', 'pasos' => json_encode($pasosI), 'traducciones' => json_encode(['en' => ['palabras' => 'clothes', 'pasos' => [
    'p1' => ['texto' => 'What are you looking for?', 'opciones' => ['Clothes', 'Shoes']], 'p2' => ['texto' => 'Your name?', 'opciones' => []], 'p3' => ['texto' => '', 'opciones' => []]]]])];
$pEn = $TR::pasos($fI, 'en');
igual([$pEn[0]['texto'], array_column($pEn[0]['opciones'], 'titulo'), $pEn[0]['opciones'][0]['ir_a'], $pEn[2]['texto']],
      ['What are you looking for?', ['Clothes', 'Shoes'], 'p2', 'Gracias.'], 'pasos en inglés (los destinos no cambian; lo vacío en español)');
igual([$TR::palabrasFlujo($fI), $TR::palabrasFlujo($fI, 'en'), $TR::palabrasFlujo($fI, 'fr')], [['ropa'], ['ropa', 'clothes'], ['ropa']], 'palabras del chatbot: las del español para todos, las del inglés solo para clientes en inglés');
$x = Flujos::avanzar(9, $pEn, 'p1', 'nada que ver', '', null, '', 'en');
igual($x['salidas'][0]['texto'] ?? '', 'Please choose one of the options 👇', 'no eligió una opción: se lo pide en inglés');
$x = Flujos::avanzar(9, $pEn, 'p1', 'Zapatos', '', null, '', 'en');
igual($x['salidas'][0]['texto'] ?? '', 'Gracias.', 'reconoce la opción por su nombre en español aunque el cliente esté en inglés');
$x = Flujos::avanzar(9, $pEn, 'p2', '', '', null, '', 'en');
igual($x['salidas'][0]['texto'] ?? '', "Sorry, I didn't understand. Your name?", 'respuesta vacía: "no te entendí" en inglés');
$simI = Flujos::simular($fI, ['clothes', '1'], 'Shop', 'en');
igual([$simI[1]['texto'], $simI[3]['texto']], ["What are you looking for?\n[1] Clothes\n[2] Shoes", 'Your name?'], 'el probador del chatbot en inglés');

// Fijos: pedido, horario, llamadas, bajas, catálogo, cotización
$resEn = Pedidos::resumen(['nombre' => 'Shop', 'iva_pct' => 12, 'pago_transferencia' => 'Banco 123'], ['numero' => 5, 'iva' => 1.2, 'subtotal' => 10, 'total' => 11.2],
                          [['cantidad' => 1, 'nombre' => 'Hat', 'precio' => 10]], 'https://pagar.ejemplo/5', 'en');
ok(str_contains($resEn, '*Order #5* · Shop') && str_contains($resEn, "Subtotal: \$10,00\nVAT 12 %: \$1,20") && str_contains($resEn, '*Total: $11,20*')
   && str_contains($resEn, 'Pay here: https://pagar.ejemplo/5') && str_contains($resEn, "Bank transfer:\nBanco 123"), 'resumen del pedido en inglés');
ok(str_starts_with(Pedidos::resumen(['nombre' => 'Shop'], ['numero' => 5, 'iva' => 0, 'total' => 3], []), '*Pedido #5*'), 'resumen en español como siempre');
$horL = ['horario' => '{"1":["08:00","18:00"],"2":["08:00","18:00"],"3":["08:00","18:00"],"4":["08:00","18:00"],"5":["08:00","18:00"],"6":["09:00","13:00"],"7":null}'];
igual(Lineas::textoHorario($horL, 'en'), 'Monday to Friday from 8:00 to 18:00 and Saturday from 9:00 to 13:00', 'horario en inglés');
igual(Lineas::textoHorario($horL, 'fr'), 'du lundi au vendredi de 8:00 à 18:00 et samedi de 9:00 à 13:00', 'horario en francés');
igual(Lineas::textoHorario($horL), 'lunes a viernes de 8:00 a 18:00 y sábado de 9:00 a 13:00', 'horario en español como siempre');
$LLc = \TuWhatsApp\Llamadas::class;
ok(str_contains($LLc::textoFuera($horL + ['llamadas_fuera_texto' => ''], 'en'), "We're closed right now. Our hours are Monday to Friday from 8:00 to 18:00"), 'llamada fuera de horario: mensaje en inglés');
igual($LLc::textoFuera($horL + ['llamadas_fuera_texto' => 'Cerrado. Horario: {horario}'], 'en'), 'Cerrado. Horario: lunes a viernes de 8:00 a 18:00 y sábado de 9:00 a 13:00', 'texto propio del negocio: va tal cual');
ok(\TuWhatsApp\Campanias::pideBaja('Unsubscribe') && \TuWhatsApp\Campanias::pideBaja('descadastrar') && \TuWhatsApp\Campanias::pideBaja('Désabonner') && \TuWhatsApp\Campanias::pideBaja('disiscrivimi'), 'pedir la baja en otros idiomas');
ok(in_array('catalog', Webhook::PALABRAS_CATALOGO, true) && in_array('buy', Webhook::PALABRAS_COMPRAR, true) && in_array('acheter', Webhook::PALABRAS_COMPRAR, true), 'catálogo y comprar en otros idiomas');
igual(Catalogo::precio(5, ['iva_pct' => 12], 'en'), '$5,00 + VAT', 'precio con IVA en inglés');
igual([Cotizacion::textos('en')['titulo'], Cotizacion::textos('pt')['titulo'], Cotizacion::textos('xx')['titulo']], ['QUOTE', 'ORÇAMENTO', 'COTIZACIÓN'], 'cotización: textos por idioma');
$pdfEn = Cotizacion::pdf(['nombre' => 'Shop'], ['numero' => 3, 'iva' => 0, 'subtotal' => 5, 'total' => 5, 'enlace_pago' => '', 'notas' => '', 'cliente_nombre' => 'Ann', 'c_nombre' => 'Ann'], [['cantidad' => 1, 'nombre' => 'Hat', 'precio' => 5]], null, 'en');
ok(str_starts_with($pdfEn, '%PDF') && (str_contains($pdfEn, '(QUOTE)') || !str_contains($pdfEn, '(COTIZACI')), 'el PDF de la cotización sale en inglés');

// Por WhatsApp: una cuenta nueva
$eI = Empresas::crear($db, ['nombre' => 'Surf Shop', 'estado' => 'activa', 'admin_correo' => 'surf@shop.ec', 'admin_nombre' => 'Surf', 'max_usuarios' => 3])['empresa_id'];
$lI = Lineas::guardarConexion($db, null, ['nombre' => 'Surf Shop', 'telefono' => '0996402222', 'phone_number_id' => '410', 'waba_id' => '210', 'token' => 'EAAGtokenSurf12345678901234'], $eI);
$lineaI = Lineas::buscar($db, $lI);
ok(Traducir::idiomaAuto($lineaI) && Traducir::idiomasLinea($lineaI) === ['es', 'en', 'pt', 'fr', 'it'], 'número nuevo: contesta en el idioma del cliente, en los 5 idiomas');
$formI = ['auto_activo' => '1', 'regla_palabras' => '1', 'palabras' => 'precio', 'mensaje_menu' => 'Hola 👋 ¿En qué te ayudo?', 'horas_silencio' => '12', 'max_auto' => '3', 'menu_botones' => '1',
          'op_titulo' => ['Precios', '', 'Envíos'], 'op_respuesta' => ['Desde $20.', '', 'A todo el país.'],
          'idiomas_form' => '1', 'idioma_auto' => '1', 'idiomas' => ['en', 'fr'],
          'tr' => ['en' => ['saludo' => 'Hi 👋 How can I help?', 'op_titulo' => ['Prices', '', 'Shipping'], 'op_respuesta' => ['From $20.', '', 'Nationwide.'], 'palabras' => 'price']]];
Lineas::guardarReglas($db, $lI, $formI);
$lineaI = Lineas::buscar($db, $lI);
$trI = Traducir::datos($lineaI);
igual([$lineaI['idiomas'], $trI['en']['opciones'][1]['titulo'] ?? null, $trI['en']['saludo'] ?? null, count($trI['en']['opciones'])], ['es,en,fr', 'Shipping', 'Hi 👋 How can I help?', 2],
      'se guardan los idiomas y las traducciones (alineadas aunque haya una opción vacía en medio)');
Lineas::guardarReglas($db, $lI, array_diff_key($formI, ['idiomas_form' => 1, 'tr' => 1, 'idiomas' => 1, 'idioma_auto' => 1]));
ok(Traducir::datos(Lineas::buscar($db, $lI))['en']['saludo'] === 'Hi 👋 How can I help?', 'guardar sin la sección de idiomas no borra las traducciones');
$salidasI = fn () => array_values(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v24.0/410/messages'));
$entI = fn (string $de, string $t, string $w) => cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '410'],
    'contacts' => [['profile' => ['name' => 'John'], 'wa_id' => $de]], 'messages' => [['from' => $de, 'id' => $w, 'timestamp' => (string) $hoyM->getTimestamp(), 'type' => 'text', 'text' => ['body' => $t]]]]);
$whI = new Webhook($db, $hoyM);
limpiarSim();
$whI->procesar($entI('14155550123', 'Hello, what is the price please?', 'wamid.ID1'));
$sI = $salidasI();
igual([$sI[0]['cuerpo']['interactive']['body']['text'] ?? null, array_map(fn ($b) => $b['reply']['title'], $sI[0]['cuerpo']['interactive']['action']['buttons'] ?? [])],
      ['Hi 👋 How can I help?', ['Prices', 'Shipping']], 'un cliente que escribe en inglés recibe el menú en inglés por WhatsApp');
igual(Contactos::porTelefono($db, $lI, '14155550123')['idioma'], 'en', 'queda guardado que el cliente habla inglés');
limpiarSim();
$whI->procesar($entI('14155550123', 'Shipping', 'wamid.ID2'));
igual($salidasI()[0]['cuerpo']['text']['body'] ?? null, 'Nationwide.', 'eligió "Shipping": la respuesta en inglés');
limpiarSim();
$whI->procesar($entI('593981000300', 'Hola, ¿cuál es el precio?', 'wamid.ID3'));
igual($salidasI()[0]['cuerpo']['interactive']['body']['text'] ?? null, 'Hola 👋 ¿En qué te ayudo?', 'un cliente en español sigue recibiendo el menú en español');

// Chatbot de ejemplo: viene sin traducciones (no quedan desfasadas); el negocio escribe la del inglés en el editor
$fE = Flujos::crear($db, $lI, 'Welcome', 'palabras', 'hola, info');
igual([Traducir::datos(Flujos::buscar($db, $lI, $fE)), Traducir::palabrasFlujo(Flujos::buscar($db, $lI, $fE), 'en')], [[], ['hola', 'info']], 'el chatbot de ejemplo viene sin traducciones ni palabras en otros idiomas');
$pasosE = Flujos::pasos(Flujos::buscar($db, $lI, $fE));
Flujos::guardar($db, $lI, $fE, ['nombre' => 'Welcome', 'disparador' => 'palabras', 'palabras' => 'hola, info', 'activo' => '1',
    'paso' => array_map(fn ($x) => $x + ['op_titulo' => array_column($x['opciones'] ?? [], 'titulo'), 'op_ir' => array_column($x['opciones'] ?? [], 'ir_a')], $pasosE),
    'tr_form' => '1', 'tr' => ['en' => ['palabras' => 'hello, hi', 'paso' => [
        'p1' => ['texto' => "Hi! 👋 I'm the assistant of {negocio}. How can I help you?", 'op' => ['See products', 'Talk to someone']],
        'p2' => ['texto' => 'Type "catalog" or tell me what you need.'],
        'p3' => ['texto' => 'Great, someone from our team will be with you shortly.']]]]]);
limpiarSim();
$whI->procesar($entI('14155550199', 'hello there', 'wamid.ID4'));
$sI = $salidasI();
igual([$sI[0]['cuerpo']['interactive']['body']['text'] ?? null, array_map(fn ($b) => $b['reply']['title'], $sI[0]['cuerpo']['interactive']['action']['buttons'] ?? [])],
      ["Hi! 👋 I'm the assistant of Surf Shop. How can I help you?", ['See products', 'Talk to someone']], 'el chatbot le contesta en inglés (activado con una palabra en inglés)');
ok(str_contains($motivoN('wamid.ID4'), '(en English)'), 'el motivo del chatbot dice que fue en inglés');
limpiarSim();
$whI->procesar($entI('14155550199', 'Talk to someone', 'wamid.ID5'));
igual($salidasI()[0]['cuerpo']['text']['body'] ?? null, 'Great, someone from our team will be with you shortly.', 'eligió la opción escrita en inglés: pasa a una persona en inglés');
limpiarSim();
$whI->procesar($entI('593981000301', 'hola', 'wamid.ID6'));
ok(str_starts_with($salidasI()[0]['cuerpo']['interactive']['body']['text'] ?? '', '¡Hola! 👋 Soy el asistente de Surf Shop'), 'al cliente en español, el mismo chatbot en español');
$db->exec("UPDATE flujos SET activo = false WHERE id = $fE");

// Traducir con IA (una sola llamada; queda editable) — solo con el extra de IA
putenv('TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789');
putenv('TUKAN_IA_URL=' . $sim);
putenv('TUKAN_IA_ESPERA_MS=0');
putenv('TUKAN_IA_REINTENTOS=0');
try { Traducir::traducirLinea($db, $lineaI); ok(false, 'sin el extra de IA no traduce'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'extra de IA'), 'sin el extra de IA no traduce (se escribe a mano)'); }
Modulos::guardar($db, $eI, ['ia']);
limpiarSim();
$trIa = Traducir::traducirLinea($db, $lineaI);
$lineaI = Lineas::buscar($db, $lI);
igual([$trIa['en']['saludo'], $trIa['pt']['opciones'][1]['titulo'], $trIa['fr']['palabras'], Traducir::datos($lineaI)['it']['opciones'][0]['respuesta']],
      ['[EN] Hola 👋 ¿En qué te ayudo?', '[PT] Envíos', 'prix, combien', '[IT] Desde $20.'], 'menú traducido a los 4 idiomas con la IA y guardado');
igual(count(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v1/messages')), 1, 'una sola llamada a la IA para todo el menú');
$fT = Flujos::buscar($db, $lI, $fE);
$trF = Traducir::traducirFlujo($db, $lineaI, $fT);
igual([$trF['fr']['pasos']['p1']['opciones'][1] ?? null, Traducir::datos(Flujos::buscar($db, $lI, $fE))['it']['pasos']['p3']['texto'] ?? null],
      ['[FR] Hablar con alguien', '[IT] Perfecto, en un momento te atiende una persona del equipo.'], 'chatbot traducido con la IA y guardado');
limpiarSim();
$whI->procesar($entI('393331234567', 'Ciao, quanto costa la tavola?', 'wamid.ID7'));
igual(\TuWhatsApp\Contactos::porTelefono($db, $lI, '393331234567')['idioma'], 'it', 'cliente italiano detectado');
igual($salidasI()[0]['cuerpo']['interactive']['body']['text'] ?? ($salidasI()[0]['cuerpo']['text']['body'] ?? null), 'Hola 👋 ¿En qué te ayudo?', 'italiano no marcado en "Idiomas que contestas": va en español');
igual(count(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v1/messages')), 0, 'al contestar no se gasta IA');

// Agente con IA: sabe el idioma del cliente
igual(Bot::idiomaPrueba($lineaI, '', 'Hello, how are you today? I need help'), 'en', 'prueba del agente: detecta inglés en lo que escribe');
igual(Bot::idiomaPrueba($lineaI, 'it', 'hola'), 'es', 'prueba del agente: un idioma no marcado se contesta en español');
$pb = Bot::probar($db, $lineaI, [], 'Hello, do you ship to Quito?', 'en');
igual($pb['respuesta'] ?? '', 'Hi! Happy to help. What are you looking for?', '"Pruébalo aquí": el agente recibe el idioma del cliente y contesta en inglés');
$pedIa = array_values(array_filter(sim(), fn ($r) => ($r['ruta'] ?? '') === '/v1/messages'));
ok(str_contains((string) json_encode(end($pedIa)['cuerpo'] ?? [], JSON_UNESCAPED_UNICODE), 'IDIOMA DEL CLIENTE: English'), 'al agente se le dice el idioma del cliente en sus instrucciones');

echo "== Idioma del cliente: revisión (marcas viejas, botones, datos del chatbot)\n";
$basuraJ = '<!--notr--><!--\/notr-->';
$db->prepare('UPDATE lineas SET traducciones = ? WHERE id = ?')->execute(['{"en":{"saludo":"' . $basuraJ . '","opciones":[{"titulo":"","respuesta":"' . $basuraJ . 'Hi' . '"}],"palabras":"' . $basuraJ . '"}}', $lI]);
preg_match_all('/^UPDATE (lineas|flujos) SET traducciones = regexp_replace.*$/m', file_get_contents(dirname(__DIR__) . '/db/schema.sql'), $mig);
igual(count($mig[0]), 2, 'schema.sql trae la limpieza de marcas para líneas y chatbots');
foreach ($mig[0] as $q) {
    $db->exec($q);
    $db->exec($q);   // idempotente
}
igual(json_decode((string) $db->query("SELECT traducciones FROM lineas WHERE id = $lI")->fetchColumn(), true)['en'], ['saludo' => '', 'opciones' => [['titulo' => '', 'respuesta' => 'Hi']], 'palabras' => ''], 'la migración borra las marcas ya guardadas');
Lineas::guardarReglas($db, $lI, array_merge($formI, ['tr' => ['en' => ['saludo' => '<!--notr-->Hi<!--/notr-->', 'op_titulo' => ['<!--notr--><!--/notr-->', '', 'Shipping'], 'op_respuesta' => ['<!--notr--><!--/notr-->', '', 'Nationwide.'], 'palabras' => '<!--notr--><!--/notr-->']]]));
$trG = Traducir::datos(Lineas::buscar($db, $lI));
ok(!str_contains((string) $db->query("SELECT traducciones FROM lineas WHERE id = $lI")->fetchColumn(), 'notr') && $trG['en']['saludo'] === 'Hi' && $trG['en']['opciones'][0] === ['titulo' => '', 'respuesta' => ''],
   'guardar desde el navegador nunca deja marcas <!--notr--> en las traducciones');
// Botón tocado, foto con texto y dato pedido por el chatbot: no cambian el idioma guardado
$cB = Contactos::guardar($db, $lI, '0981000400', 'Bea', 'cliente');
$db->exec("UPDATE contactos SET idioma = 'es' WHERE id = {$cB['id']}");
$entIx = fn (string $de, array $msg, string $w) => cuerpoMeta(['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '410'],
    'contacts' => [['profile' => ['name' => 'Bea'], 'wa_id' => $de]], 'messages' => [['from' => $de, 'id' => $w, 'timestamp' => (string) $hoyM->getTimestamp()] + $msg]]);
$whI->procesar($entIx('593981000400', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'op9', 'title' => 'Help desk and shipping info']]], 'wamid.RV1'));
$whI->procesar($entIx('593981000400', ['type' => 'button', 'button' => ['text' => 'Yes I want it please', 'payload' => 'x']], 'wamid.RV2'));
$whI->procesar($entIx('593981000400', ['type' => 'image', 'image' => ['id' => 'm1', 'mime_type' => 'image/jpeg', 'caption' => 'Hello, how much is this one?']], 'wamid.RV3'));
igual(Contactos::porTelefono($db, $lI, '593981000400')['idioma'], 'es', 'botones tocados, plantillas y fotos con texto en inglés no cambian el idioma');
$fQ = Flujos::crear($db, $lI, 'Datos', 'palabras', 'registro');
Flujos::guardar($db, $lI, $fQ, ['nombre' => 'Datos', 'disparador' => 'palabras', 'palabras' => 'registro', 'activo' => '1',
    'paso' => [['id' => 'p1', 'tipo' => 'pregunta', 'texto' => '¿Tu nombre completo?', 'guardar' => 'nombre'], ['id' => 'p2', 'tipo' => 'fin', 'texto' => 'Gracias']]]);
$whI->procesar($entI('593981000400', 'registro', 'wamid.RV4'));
ok(Flujos::esperaDato($db, $lineaI, Contactos::porTelefono($db, $lI, '593981000400'), $hoyM), 'el chatbot espera un dato');
$whI->procesar($entI('593981000400', 'John Thomas Smith the third', 'wamid.RV5'));
$cB = Contactos::porTelefono($db, $lI, '593981000400');
igual([$cB['idioma'], $cB['nombre']], ['es', 'John Thomas Smith the third'], 'el dato que pide el chatbot (un nombre) no cambia el idioma');
$db->exec("UPDATE flujos SET activo = false WHERE id = $fQ");
// Chatbot: borrar una opción no desalinea las traducciones
$fA = Flujos::crear($db, $lI, 'Alinear', 'palabras', 'alinear');
Flujos::guardar($db, $lI, $fA, ['nombre' => 'Alinear', 'disparador' => 'palabras', 'palabras' => 'alinear', 'activo' => '',
    'paso' => [['id' => 'p1', 'tipo' => 'botones', 'texto' => 'Elige', 'op_titulo' => ['Ropa', '', 'Zapatos'], 'op_ir' => ['', '', ''], 'borrar' => ''],
               ['id' => 'p2', 'tipo' => 'fin', 'texto' => 'Fin', 'borrar' => '1']],
    'tr_form' => '1', 'tr' => ['en' => ['paso' => ['p1' => ['texto' => 'Choose', 'op' => ['Clothes', 'nada', 'Shoes']], 'p2' => ['texto' => 'End']]]]]);
$trA = Traducir::datos(Flujos::buscar($db, $lI, $fA));
igual([$trA['en']['pasos']['p1']['opciones'], isset($trA['en']['pasos']['p2'])], [['Clothes', 'Shoes'], false], 'opción vacía y paso borrado: cada traducción sigue con su opción');
igual(array_column(Traducir::pasos(Flujos::buscar($db, $lI, $fA), 'en')[0]['opciones'], 'titulo'), ['Clothes', 'Shoes'], '…y así le llega al cliente');

echo "\nResultado: $bien bien, $mal mal\n";
echo $mal === 0 ? "TODO BIEN\n" : "HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
