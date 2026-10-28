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
ok($pl[1] === 20 && !Modulos::activo($fe2, 'ia') && Modulos::activo($fe2, 'embudo') && (int) $fe2['max_usuarios'] >= 50, 'plan Todo incluido $20');
$x = Modulos::detalle($fe2);
igual([$x['plan'], $x['mensual']], ['todo', 20.0], 'cuesta $20 al mes');
Modulos::guardar($db, $e2, ['ia']);
$x = Modulos::detalle(Empresas::buscar($db, $e2));
igual([$x['plan'], $x['extra'], $x['mensual']], ['todo', ['ia'], 25.0], 'con IA aparte: $25');
$pl = Modulos::aplicarPlan($db, $e2, 'todo_ia');
igual(Modulos::detalle(Empresas::buscar($db, $e2))['mensual'], 25.0, 'plan Todo incluido + IA: $25');
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
igual([$aviso['titulo'] ?? null, $aviso['texto'] ?? null, $aviso['url'] ?? null, $pa['enc'], $pa['ttl']], ['Lucía', '¿Tienen talla M?', '/?r=chat&c=5', 'aes128gcm', '3600'], 'el celular recibe el aviso cifrado con el texto');
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

echo "\nResultado: $bien bien, $mal mal\n";
echo $mal === 0 ? "TODO BIEN\n" : "HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
