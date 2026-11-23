<?php
declare(strict_types=1);

/**
 * (23 nov 2026) Los 4 chatbots de ventas de Tukán (src/ChatbotsTukan.php): se instalan, se reinstalan sin duplicar,
 * arrancan con las palabras correctas en cada idioma, contestan en el idioma del cliente y llegan a cada enlace.
 * Uso: bash tests/correr.sh (necesita la base de prueba)   → termina en "CHATBOTS TUKAN: TODO BIEN"
 */
putenv('TUWA_LLAVE=' . str_repeat('ab', 32));
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\{ChatbotsTukan, Flujos, Reglas, Traducir};

$bien = 0;
$mal = 0;
function ok(bool $c, string $que): void
{
    global $bien, $mal;
    $c ? $bien++ : $mal++;
    if (!$c) {
        echo "  FALLA: $que\n";
    }
}

$db = db();
if (!str_contains((string) $db->query('SELECT current_database()')->fetchColumn(), 'prueba')) {
    exit("Solo en una base de prueba\n");
}
$e = (int) $db->query("INSERT INTO empresas (nombre) VALUES ('Tukan Software prueba chatbots') RETURNING id")->fetchColumn();
$l = (int) $db->query("INSERT INTO lineas (empresa_id, nombre, telefono) VALUES ($e, 'Tukán Software', '593983662181') RETURNING id")->fetchColumn();
$otro = (int) $db->query("INSERT INTO flujos (linea_id, nombre, activo, palabras, pasos) VALUES ($l, 'Mi chatbot', true, 'hola', '[]') RETURNING id")->fetchColumn();

echo "== Instalar y reinstalar\n";
$r = ChatbotsTukan::instalar($db, $l);
ok($r['creados'] === ChatbotsTukan::NOMBRES && $r['actualizados'] === [], 'crea los 4 chatbots');
$db->exec("UPDATE flujos SET activo = false, palabras = 'cambiada' WHERE linea_id = $l AND nombre = 'Soporte'");
$r = ChatbotsTukan::instalar($db, $l);
ok($r['creados'] === [] && $r['actualizados'] === ChatbotsTukan::NOMBRES, 'la segunda vez los actualiza (no duplica)');
ok((int) $db->query("SELECT count(*) FROM flujos WHERE linea_id = $l")->fetchColumn() === 5, 'quedan 4 + el chatbot propio del negocio');
ok((bool) $db->query("SELECT activo AND palabras LIKE 'soporte%' FROM flujos WHERE linea_id = $l AND nombre = 'Soporte'")->fetchColumn(), 'vuelve a poner textos de fábrica y lo enciende');
ok($db->query("SELECT palabras FROM flujos WHERE id = $otro")->fetchColumn() === 'hola', 'no toca otros chatbots');
try {
    ChatbotsTukan::instalar($db, 999999);
    ok(false, 'número que no existe');
} catch (RuntimeException) {
    ok(true, '');
}

$flujos = [];
foreach (Flujos::lista($db, $l) as $f) {
    if ($f['nombre'] !== 'Mi chatbot') {
        $flujos[$f['nombre']] = $f;
    }
}
$linea = ['id' => $l, 'nombre' => 'Tukán Software', 'idioma_auto' => true, 'idiomas' => 'es,en,pt,fr,it'];

echo "== Todo traducido, botones que caben y nada prohibido\n";
foreach ($flujos as $nombre => $f) {
    ok($f['disparador'] === 'palabras' && $f['activo'], "$nombre: arranca solo con palabras (no tapa el menú del primer mensaje)");
    $tr = Traducir::datos($f);
    foreach (Flujos::pasos($f) as $p) {
        foreach (Traducir::OTROS as $i) {
            ok(trim((string) ($tr[$i]['pasos'][$p['id']]['texto'] ?? '')) !== '', "$nombre {$p['id']}: tiene texto en $i");
            foreach ($p['opciones'] ?? [] as $k => $o) {
                $t = (string) ($tr[$i]['pasos'][$p['id']]['opciones'][$k] ?? '');
                ok($t !== '', "$nombre {$p['id']}: opción $k traducida al $i");
                ok(mb_strlen($t) <= (count($p['opciones']) <= 3 ? 20 : 24), "$nombre {$p['id']}: «{$t}» cabe en el botón");
            }
            ok(trim((string) ($tr[$i]['palabras'] ?? '')) !== '', "$nombre: palabras en $i");
        }
        foreach ($p['opciones'] ?? [] as $o) {
            ok(mb_strlen($o['titulo']) <= (count($p['opciones']) <= 3 ? 20 : 24), "$nombre {$p['id']}: «{$o['titulo']}» cabe en el botón");
        }
    }
    $todo = $f['pasos'];   // lo que el chatbot dice (las palabras que lo arrancan pueden decir "free trial": eso lo escribe el cliente)
    foreach ($tr as $x) {
        $todo .= json_encode($x['pasos'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    ok(!str_contains($todo, 'TuFactura'), "$nombre: no ofrece TuFactura (todavía no se vende)");
    ok(!preg_match('/gratis|grátis|free trial|prueba gratuita/iu', $todo), "$nombre: no ofrece nada gratis");
    ok(!preg_match('/whatsapp\.tukanec\.com\/\?r=registro|tuner\.tukanec\.com\/\?r=registro/u', $todo), "$nombre: WhatsApp Tukán y TuBroadcast sin demo");
}

/** Qué chatbot arranca con este mensaje para un cliente de este idioma ('' = ninguno), como en Flujos::responder. */
function cual(array $flujos, string $texto, string $idioma): string
{
    foreach ($flujos as $nombre => $f) {
        if (Reglas::tienePalabra($texto, Traducir::palabrasFlujo($f, $idioma))) {
            return $nombre;
        }
    }
    return '';
}

echo "== Qué chatbot arranca con mensajes reales\n";
foreach ([
    ['Hola Tukán, quiero una demo de TuSalón para mi peluquería.', 'es', 'Pedir demo'],   // el botón de tukansoftware.com
    ['Hola Tukán, quiero una demo para mi negocio.', 'es', 'Pedir demo'],
    ['Hi, I would like a demo', 'en', 'Pedir demo'],
    ['Can I get a free trial?', 'en', 'Pedir demo'],
    ['Quero testar o programa', 'pt', 'Pedir demo'],
    ['¿Cuánto cuesta?', 'es', 'Precios y planes'],
    ['Hola, precios por favor', 'es', 'Precios y planes'],
    ['Hi, how much is it?', 'en', 'Precios y planes'],
    ['What are your prices?', 'en', 'Precios y planes'],
    ['Quanto custa?', 'pt', 'Precios y planes'],
    ['Bonjour, combien ça coûte ?', 'fr', 'Precios y planes'],
    ['Quanto costa?', 'it', 'Precios y planes'],
    ['Tengo un problema con TuSalón', 'es', 'Soporte'],
    ['No puedo entrar a mi cuenta', 'es', 'Soporte'],
    ['Olvidé mi contraseña', 'es', 'Soporte'],
    ['It is not working', 'en', 'Soporte'],
    ['Quiero hablar con un asesor', 'es', 'Hablar con un asesor'],
    ['I want to talk to someone', 'en', 'Hablar con un asesor'],
    ['Posso falar com alguém?', 'pt', 'Hablar con un asesor'],
    ['No hay problema, gracias', 'es', ''],          // "problema" suelto no es soporte
    ['Le escribí por error', 'es', ''],
    ['Hola', 'es', ''],                              // el saludo lo contesta el menú de siempre
    ['Buenos días, info', 'es', ''],
    ['Hello', 'en', ''],
    ['how much is it?', 'es', ''],                  // las palabras en inglés solo para clientes en inglés
] as [$texto, $idioma, $esperado]) {
    $sale = cual($flujos, $texto, $idioma);
    ok($sale === $esperado, "«{$texto}» ($idioma) → esperaba «{$esperado}», salió «{$sale}»");
}

echo "== Recorridos completos en cada idioma\n";
$caminos = [
    // [chatbot, idioma, toques (número de opción o texto), lo que debe decir el último mensaje]
    ['Pedir demo', 'es', ['1', '1'], 'https://tusalon.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['1', '2'], 'https://tuspa.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['2', '1'], 'https://tugroomer.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['2', '2'], 'https://tuguarderia.tukanec.com/pedir_demo.php'],
    ['Pedir demo', 'es', ['3', '1'], 'https://tulavadora.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['3', '2'], 'https://tumecanica.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['4', '1'], 'https://tuevento.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['4', '2'], 'https://tuplanner.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['4', '3'], 'https://tuhospedaje.tukanec.com/?r=registro'],
    ['Pedir demo', 'es', ['6'], 'https://tudron.tukanec.com/?r=registro&demo=1'],
    ['Pedir demo', 'en', ['Pets', 'TuGroomer'], 'Request your *TuGroomer* demo'],
    ['Pedir demo', 'pt', ['Carros', '2'], 'Peça sua demonstração do *TuMecánica*'],
    ['Pedir demo', 'fr', ['1', 'TuSpa'], 'Demandez votre démo de *TuSpa*'],
    ['Pedir demo', 'it', ['Eventi e hotel', 'TuHospedaje'], 'Richiedi la demo di *TuHospedaje*'],
    ['Precios y planes', 'es', ['1'], '$12,99 + IVA'],
    ['Precios y planes', 'en', ['WhatsApp Tukán'], '$15 + VAT'],
    ['Precios y planes', 'pt', ['TuHospedaje'], 'US$ 33,99'],
    ['Precios y planes', 'es', ['TuBroadcast'], 'https://tuner.tukanec.com/?r=comprar'],
    ['Soporte', 'es', ['3'], 'https://t.me/+dQfU0W9yvo85MzRh'],
    ['Soporte', 'en', ['Help group'], 'https://t.me/+dQfU0W9yvo85MzRh'],
];
foreach ($caminos as [$nombre, $idioma, $toques, $debe]) {
    $f = $flujos[$nombre];
    $pasos = Traducir::pasos($f, $idioma);
    $r = Flujos::avanzar((int) $f['id'], $pasos, null, 'x', '', null, 'Tukán Software', $idioma);
    foreach ($toques as $t) {
        $r = Flujos::avanzar((int) $f['id'], $pasos, $r['esperando'], $t, '', null, 'Tukán Software', $idioma);
    }
    $ultimo = (string) (end($r['salidas'])['texto'] ?? '');
    ok($r['fin'] && str_contains($ultimo, $debe), "$nombre ($idioma) " . implode(' > ', $toques) . " → «{$debe}»" . ($r['fin'] ? '' : ' (no terminó)'));
}

echo "== Pasa a una persona y guarda lo que escribe\n";
$f = $flujos['Pedir demo'];
$pasos = Traducir::pasos($f, 'es');
$r = Flujos::avanzar((int) $f['id'], $pasos, null, 'demo', '', null, 'Tukán Software', 'es');
$r = Flujos::avanzar((int) $f['id'], $pasos, $r['esperando'], '5', '', null, 'Tukán Software', 'es');
ok(str_contains($r['salidas'][0]['texto'], 'TuVocalía') && $r['esperando'] === 'p6', 'deportes: pregunta los datos de la liga o academia');
$r = Flujos::avanzar((int) $f['id'], $pasos, $r['esperando'], 'Liga Barrial La Floresta, Quito, fútbol', '', null, 'Tukán Software', 'es');
ok($r['persona'] && $r['guardar'] === [['notas', 'Liga Barrial La Floresta, Quito, fútbol']], 'guarda la nota y pasa a una persona');
$r = Flujos::avanzar((int) $f['id'], $pasos, null, 'demo', '', null, 'Tukán Software', 'es');
$r = Flujos::avanzar((int) $f['id'], $pasos, $r['esperando'], 'WhatsApp de mi negocio', '', null, 'Tukán Software', 'es');
ok($r['persona'] && str_contains($r['salidas'][0]['texto'], 'no tiene demo'), 'WhatsApp Tukán: dice que no tiene demo y pasa a una persona');
$r = Flujos::avanzar((int) $flujos['Hablar con un asesor']['id'], Traducir::pasos($flujos['Hablar con un asesor'], 'en'), null, 'agent', '', null, 'Tukán Software', 'en');
ok($r['persona'] && str_contains($r['salidas'][0]['texto'], 'Someone from our team'), 'asesor en inglés: contesta en inglés y pasa a una persona');
$r = Flujos::avanzar((int) $flujos['Pedir demo']['id'], Traducir::pasos($flujos['Pedir demo'], 'es'), null, 'demo', '', null, 'Tukán Software', 'es');
ok($r['etiquetas'] === [] && count($r['salidas'][0]['opciones']) === 8, 'el primer paso muestra los 8 tipos de negocio en una lista');

$db->exec("DELETE FROM empresas WHERE id = $e");
echo $mal === 0 ? "CHATBOTS TUKAN: TODO BIEN ($bien pruebas)\n" : "CHATBOTS TUKAN: $mal FALLAS de " . ($bien + $mal) . "\n";
exit($mal === 0 ? 0 : 1);
