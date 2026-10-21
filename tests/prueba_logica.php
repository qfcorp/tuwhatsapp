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

use TuWhatsApp\{Avisos, Contactos, Gasto, Lineas, Mensajeria, Pendientes, Reglas, Telefono, Webhook};

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

echo "== Precios\n";
Gasto::guardarPrecios($db, ['servicio' => '0,02', 'utilidad' => '0.0113', 'marketing' => '0.074', 'autenticacion' => '0.0113']);
igual(Gasto::precios($db)['servicio'], 0.02, 'precio cambiado');
try {
    Gasto::guardarPrecios($db, ['servicio' => '5', 'utilidad' => '0', 'marketing' => '0', 'autenticacion' => '0']);
    ok(false, 'precio absurdo');
} catch (RuntimeException $e) {
    ok(true, 'precio absurdo');
}

echo "\nResultado: $bien bien, $mal mal\n";
echo $mal === 0 ? "TODO BIEN\n" : "HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
