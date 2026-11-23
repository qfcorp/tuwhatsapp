<?php
declare(strict_types=1);

/**
 * (23 nov 2026) Los 4 chatbots de ventas de Tukán (src/ChatbotsTukan.php): se instalan, se reinstalan sin duplicar,
 * arrancan con las palabras correctas en cada idioma, contestan en el idioma del cliente y llegan a cada enlace.
 * Uso: bash tests/correr.sh (necesita la base de prueba)   → termina en "CHATBOTS TUKAN: TODO BIEN"
 */
putenv('TUWA_LLAVE=' . str_repeat('ab', 32));
if (!getenv('TUWA_ARCHIVOS')) {
    putenv('TUWA_ARCHIVOS=' . sys_get_temp_dir() . '/tuwa_archivos_prueba_chatbots');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\{Archivos, ChatbotsTukan, Flujos, Reglas, Traducir};

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
ok($r['faltan'] === [] && $r['pdf'] >= 25, 'pone los PDF de planes en los pasos (' . $r['pdf'] . ')');
$rutas1 = $db->query("SELECT pasos FROM flujos WHERE linea_id = $l AND nombre = 'Precios y planes'")->fetchColumn();
$db->exec("UPDATE flujos SET activo = false, palabras = 'cambiada' WHERE linea_id = $l AND nombre = 'Soporte'");
$r = ChatbotsTukan::instalar($db, $l);
ok($r['creados'] === [] && $r['actualizados'] === ChatbotsTukan::NOMBRES, 'la segunda vez los actualiza (no duplica)');
ok($db->query("SELECT pasos FROM flujos WHERE linea_id = $l AND nombre = 'Precios y planes'")->fetchColumn() === $rutas1, 'reinstalar reutiliza los mismos PDF (no copia otra vez)');
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
    ok(!preg_match('/whatsapp\.tukanec\.com\/\?r=registro|tuner\.tukanec\.com\/\?r=registro|demo=1/u', $todo), "{$nombre}: WhatsApp Tukán, TuDron y TuBroadcast sin demo");
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
    ['Hola, vengo del anuncio de TuVocalía y quiero una demostración', 'es', 'Pedir demo'],   // (23 nov) mensaje real del anuncio
    ['¿Tienen versión de prueba?', 'es', 'Pedir demo'],
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
    ['Pedir demo', 'es', ['6'], 'TuDron* no tiene demo'],
    ['Pedir demo', 'en', ['Pets', 'TuGroomer'], 'Request your *TuGroomer* demo'],
    ['Pedir demo', 'pt', ['Carros', '2'], 'Peça sua demonstração do *TuMecánica*'],
    ['Pedir demo', 'fr', ['1', 'TuSpa'], 'Demandez votre démo de *TuSpa*'],
    ['Pedir demo', 'it', ['Eventi e hotel', 'TuHospedaje'], 'Richiedi la demo di *TuHospedaje*'],
    ['Precios y planes', 'es', ['1', '1'], '$12,99 + IVA'],
    ['Precios y planes', 'es', ['2', 'TuGuardería Canina'], 'Cada sede extra: $10'],
    ['Precios y planes', 'es', ['3', '3'], 'Flota Tukán'],
    ['Precios y planes', 'es', ['5', '1'], 'Cada deporte adicional: $7'],
    ['Precios y planes', 'en', ['Sports and academies', 'TuAcademia'], 'Each extra discipline: $7'],
    ['Precios y planes', 'en', ['WhatsApp Tukán'], '$15 + VAT'],
    ['Precios y planes', 'pt', ['Eventos e hotéis', 'TuHospedaje'], 'US$ 33,99'],
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

echo "== El PDF de cada programa\n";
$usados = [];
foreach ($flujos as $nombre => $f) {
    foreach (Flujos::pasos($f) as $p) {
        if (!empty($p['archivo'])) {
            $a = $p['archivo'];
            $usados[$a['nombre']] = true;
            ok($a['tipo'] === 'documento' && $a['mime'] === 'application/pdf' && Archivos::ruta($a['ruta']) !== null && filesize(Archivos::ruta($a['ruta'])) === $a['bytes'],
               "{$nombre} {$p['id']}: el PDF {$a['nombre']} está guardado y completo");
            ok(str_starts_with($a['ruta'], $e . '/'), "{$nombre} {$p['id']}: el PDF queda en la carpeta de la cuenta");
        }
    }
}
$enCarpeta = array_map('basename', glob(ChatbotsTukan::carpetaPdf() . '/*.pdf') ?: []);
sort($enCarpeta);
$u = array_keys($usados);
sort($u);
ok(count($enCarpeta) === 15 && $u === $enCarpeta, 'los 15 PDF de la carpeta se usan en los chatbots (' . count($u) . ')');
ok(!array_filter($enCarpeta, fn ($x) => stripos($x, 'factura') !== false), 'no hay PDF de TuFactura (todavía no se vende)');
foreach ([['es', ['1', '1'], 'Tukan_TuSalon_Planes_y_Precios.pdf'], ['en', ['Pets', 'TuGroomer'], 'Tukan_TuGroomer_Planes_y_Precios.pdf'],
          ['es', ['WhatsApp Tukán'], 'Tukan_WhatsApp_Tukan_Planes_y_Precios.pdf'], ['it', ['Auto e flotte', 'Flota Tukán'], 'Tukan_Flota_Tukan_Planes_y_Precios.pdf']] as [$i, $toques, $pdf]) {
    $f = $flujos['Precios y planes'];
    $pasos = Traducir::pasos($f, $i);
    $r = Flujos::avanzar((int) $f['id'], $pasos, null, 'x', '', null, 'Tukán Software', $i);
    foreach ($toques as $t) {
        $r = Flujos::avanzar((int) $f['id'], $pasos, $r['esperando'], $t, '', null, 'Tukán Software', $i);
    }
    ok(count($r['salidas']) === 2 && $r['salidas'][0]['tipo'] === 'archivo' && $r['salidas'][0]['archivo']['nombre'] === $pdf && $r['salidas'][1]['tipo'] === 'texto',
       "precios ($i) " . implode(' > ', $toques) . ": primero el PDF $pdf y después el texto");
}
$f = $flujos['Pedir demo'];
$r = Flujos::avanzar((int) $f['id'], Traducir::pasos($f, 'es'), 'p2', '1', '', null, 'Tukán Software', 'es');
ok(($r['salidas'][0]['archivo']['nombre'] ?? '') === 'Tukan_TuSalon_Planes_y_Precios.pdf' && str_contains($r['salidas'][1]['texto'], 'tusalon.tukanec.com'), 'la demo de TuSalón manda su PDF y el enlace');
$sim = Flujos::simular($flujos['Precios y planes'], ['precios', '1', '1'], 'Tukán Software');
ok((bool) array_filter($sim, fn ($x) => str_contains($x['texto'], '📎 Tukan_TuSalon_Planes_y_Precios.pdf')), 'el probador muestra el PDF que se mandaría');

echo "== Archivos en los pasos (editor)\n";
$fid = (int) $db->query("INSERT INTO flujos (linea_id, nombre, activo, palabras, pasos) VALUES ($l, 'Con archivo', false, 'catalogo', '[]') RETURNING id")->fetchColumn();
$doc = ['tipo' => 'documento', 'mime' => 'application/pdf', 'nombre' => 'catalogo.pdf', 'bytes' => 9, 'ruta' => Archivos::guardarBytes('%PDF-1.4 ', 'application/pdf', $e)];
$form = ['nombre' => 'Con archivo', 'disparador' => 'palabras', 'palabras' => 'catalogo', 'activo' => '1',
         'paso' => [['id' => 'p1', 'tipo' => 'fin', 'texto' => '', 'archivo' => ['tipo' => 'documento', 'mime' => 'application/pdf', 'nombre' => 'x.pdf', 'bytes' => 1, 'ruta' => '999/2026-11/robado.pdf']]]];
try {
    Flujos::guardar($db, $l, $fid, $form);
    ok(false, 'un paso "terminar" vacío y sin archivo no se puede guardar encendido');
} catch (RuntimeException) {
    ok(true, '');
}
$p = Flujos::pasos(Flujos::buscar($db, $l, $fid));
ok(!isset($p[0]['archivo']), 'el archivo escrito en el formulario se ignora (nadie apunta a archivos de otra cuenta)');
Flujos::guardar($db, $l, $fid, $form, [], ['p1' => $doc]);
$p = Flujos::pasos(Flujos::buscar($db, $l, $fid));
ok(($p[0]['archivo']['ruta'] ?? '') === $doc['ruta'], 'el archivo subido queda en el paso (un "terminar" solo con archivo vale)');
Flujos::guardar($db, $l, $fid, ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Mira nuestro catálogo']]] + $form);
$p = Flujos::pasos(Flujos::buscar($db, $l, $fid));
ok(($p[0]['archivo']['ruta'] ?? '') === $doc['ruta'] && $p[0]['texto'] === 'Mira nuestro catálogo', 'al guardar otra vez se mantiene el archivo');
$r = Flujos::avanzar($fid, $p, null, 'catalogo', '', null, 'Tienda', 'es');
ok(array_column($r['salidas'], 'tipo') === ['archivo', 'texto'], 'manda el archivo y después el texto');
Flujos::guardar($db, $l, $fid, ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Sin archivo', 'quitar_archivo' => '1']]] + $form);
ok(!isset(Flujos::pasos(Flujos::buscar($db, $l, $fid))[0]['archivo']), '"Quitar el adjunto" lo quita');
// "+ Agregar paso" (revisión): el paso nuevo no hereda el adjunto de uno borrado, y "Quitar el adjunto" se respeta
Flujos::guardar($db, $l, $fid, ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Uno'], ['id' => 'p2', 'tipo' => 'fin', 'texto' => 'Dos']]] + $form, [], ['p2' => $doc]);
$fx = Flujos::buscar($db, $l, $fid);
$dx = Flujos::conPasoNuevo($fx, ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Uno'], ['id' => 'p2', 'tipo' => 'fin', 'texto' => 'Dos', 'borrar' => '1']]] + $form);
Flujos::guardar($db, $l, $fid, $dx);
$px = Flujos::pasos(Flujos::buscar($db, $l, $fid));
ok(count($px) === 2 && $px[1]['id'] === 'p3' && !isset($px[1]['archivo']) && !isset($px[0]['archivo']), 'agregar paso tras borrar uno con adjunto: el nuevo sale vacío (p3, sin adjunto)');
Flujos::guardar($db, $l, $fid, ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Uno'], ['id' => 'p3', 'tipo' => 'fin', 'texto' => 'Tres']]] + $form, [], ['p1' => $doc]);
$dx = Flujos::conPasoNuevo(Flujos::buscar($db, $l, $fid), ['paso' => [['id' => 'p1', 'tipo' => 'mensaje', 'texto' => 'Uno', 'quitar_archivo' => '1'], ['id' => 'p3', 'tipo' => 'fin', 'texto' => 'Tres']]] + $form);
Flujos::guardar($db, $l, $fid, $dx);
$px = Flujos::pasos($fy = Flujos::buscar($db, $l, $fid));
ok(count($px) === 3 && !isset($px[0]['archivo']) && $px[2]['id'] === 'p4' && !$fy['activo'], 'agregar paso con "Quitar el adjunto" marcado: lo quita; el chatbot queda apagado');
ok(Flujos::archivoValido(['tipo' => 'audio', 'mime' => 'audio/ogg', 'ruta' => '1/a.ogg']) === null && Flujos::archivoValido(['tipo' => 'documento', 'mime' => 'application/pdf', 'ruta' => '../x.pdf']) === null
   && Flujos::archivoValido(['tipo' => 'imagen', 'mime' => 'image/png', 'nombre' => 'f.png', 'bytes' => 3, 'ruta' => '1/2026-11/a.png']) !== null, 'solo PDF/documentos, fotos y videos, y nunca rutas con ".."');

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
ok($r['persona'] && $r['salidas'][0]['tipo'] === 'archivo' && str_contains($r['salidas'][1]['texto'], 'no tiene demo'), 'WhatsApp Tukán: manda su PDF, dice que no tiene demo y pasa a una persona');
$r = Flujos::avanzar((int) $flujos['Hablar con un asesor']['id'], Traducir::pasos($flujos['Hablar con un asesor'], 'en'), null, 'agent', '', null, 'Tukán Software', 'en');
ok($r['persona'] && str_contains($r['salidas'][0]['texto'], 'Someone from our team'), 'asesor en inglés: contesta en inglés y pasa a una persona');
$r = Flujos::avanzar((int) $flujos['Pedir demo']['id'], Traducir::pasos($flujos['Pedir demo'], 'es'), null, 'demo', '', null, 'Tukán Software', 'es');
ok($r['etiquetas'] === [] && count($r['salidas'][0]['opciones']) === 8, 'el primer paso muestra los 8 tipos de negocio en una lista');

$db->exec("DELETE FROM empresas WHERE id = $e");
echo $mal === 0 ? "CHATBOTS TUKAN: TODO BIEN ($bien pruebas)\n" : "CHATBOTS TUKAN: $mal FALLAS de " . ($bien + $mal) . "\n";
exit($mal === 0 ? 0 : 1);
