<?php
declare(strict_types=1);

/**
 * Pruebas de la pieza compartida Tukán Idiomas (src/tukan_idiomas.php) y de los diccionarios de WhatsApp Tukán.
 * Uso: php tests/prueba_idiomas.php   → termina en "IDIOMAS: TODO BIEN"
 */
require dirname(__DIR__) . '/src/tukan_idiomas.php';

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

// --- Motor con un diccionario de prueba ---------------------------------------------------------
$tmp = sys_get_temp_dir() . '/tukan_idiomas_' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/en.php", '<?php return ' . var_export([
    'Guardar' => 'Save',
    'Quedan {n} días' => '{n} days left',
    'Hola, {s}' => 'Hello, {s}',
    'Estado' => 'Status',
    'Bandeja' => 'Inbox',
    'Activo' => 'Active',
    'Escribe tu respuesta…' => 'Write your reply…',
], true) . ';');
tukan_idioma_config(['dir' => $tmp]);

ok(tukan_tr('Guardar', 'en') === 'Save', 'texto directo');
ok(tukan_tr('  Guardar  ', 'en') === '  Save  ', 'respeta espacios');
ok(tukan_tr('Guardar', 'es') === 'Guardar', 'español queda igual');
ok(tukan_tr('Guardar', 'fr') === 'Guardar', 'idioma sin diccionario queda en español');
ok(tukan_tr('Quedan 5 días', 'en') === '5 days left', 'con números');
ok(tukan_tr('Hola, Ana', 'en') === 'Hello, Ana', 'con texto variable {s}');
ok(tukan_tr('📋 Guardar', 'en') === '📋 Save', 'con ícono adelante');
ok(tukan_tr('Estado:', 'en') === 'Status:', 'con dos puntos');
ok(tukan_tr('Bandeja · Estado', 'en') === 'Inbox · Status', 'partes con ·');
ok(tukan_tr('Estado: Activo', 'en') === 'Status: Active', 'etiqueta: valor');
ok(tukan_tr('Peluquería Ana', 'en') === 'Peluquería Ana', 'lo que no está en el diccionario no se toca');

$html = '<!doctype html><html lang="es"><body><h1>Bandeja</h1><p>Hola, Ana</p>'
    . '<input placeholder="Escribe tu respuesta…"><button title="Guardar">Guardar</button>'
    . '<script>var t = "Guardar";</script><textarea>Guardar</textarea><!--notr--><b>Guardar</b><!--/notr-->'
    . '<input type="submit" value="Guardar"><p>Tom &amp; Guardar</p></body></html>';
$t = tukan_traducir_html($html, 'en');
ok(str_contains($t, '<html lang="en"'), 'cambia lang del html');
ok(str_contains($t, '<h1>Inbox</h1>'), 'traduce títulos');
ok(str_contains($t, '<p>Hello, Ana</p>'), 'traduce plantillas en la página');
ok(str_contains($t, 'placeholder="Write your reply…"'), 'traduce placeholder');
ok(str_contains($t, 'title="Save">Save</button>'), 'traduce title y botón');
ok(str_contains($t, 'var t = "Guardar";'), 'no toca scripts');
ok(str_contains($t, '<textarea>Guardar</textarea>'), 'no toca áreas de texto (lo que escribe el usuario)');
ok(str_contains($t, '<!--notr--><b>Guardar</b><!--/notr-->'), 'no toca lo marcado notr');
ok(str_contains($t, 'value="Save"'), 'traduce botones input');
ok(str_contains($t, '<p>Tom &amp; Guardar</p>'), 'no rompe entidades');
ok(tukan_traducir_html($html, 'es') === $html, 'en español la página queda idéntica');

// Selector y JS
$_GET = ['r' => 'bandeja'];
$_SERVER['REQUEST_URI'] = '/index.php?r=bandeja';
$GLOBALS['__tukan_idioma'] = 'en';
$sel = tukan_idioma_selector();
ok(substr_count($sel, '<a href=') === 5, 'selector con 5 idiomas');
ok(str_contains($sel, 'r=bandeja&amp;lang=fr'), 'selector conserva la página actual');
ok(str_contains($sel, 'class="activo"') && str_contains($sel, '🌐 <span>EN</span>'), 'selector marca el idioma actual');
$js = tukan_idioma_js(['Guardar', 'No existe']);
ok(str_contains($js, '"Guardar":"Save"') && !str_contains($js, 'No existe'), 'textos para JavaScript');
ok(str_contains(tukan_idioma_opciones('fr'), 'value="fr" selected'), 'opciones para select');
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);

// --- Diccionarios reales de WhatsApp Tukán ---------------------------------------------------------
$dir = dirname(__DIR__) . '/idiomas';
foreach (['en', 'fr', 'pt', 'it'] as $l) {
    $f = "$dir/$l.php";
    ok(is_file($f), "existe idiomas/$l.php");
    if (!is_file($f)) {
        continue;
    }
    $d = include $f;
    ok(is_array($d) && count($d) > 500, "idiomas/$l.php tiene traducciones (" . (is_array($d) ? count($d) : 0) . ')');
    $malas = [];
    foreach ($d as $es => $tr) {
        foreach (['{n}', '{s}'] as $ph) {
            if (substr_count((string) $es, $ph) !== substr_count((string) $tr, $ph)) {
                $malas[] = $es;
            }
        }
        if (trim((string) $tr) === '') {
            $malas[] = $es;
        }
    }
    ok(!$malas, "idiomas/$l.php: marcadores {n}/{s} completos" . ($malas ? ' → ' . implode(' | ', array_slice($malas, 0, 3)) : ''));
    // Los textos del JavaScript deben estar traducidos
    $js = require dirname(__DIR__) . '/app/frases_js.php';
    $faltan = array_filter($js, fn ($x) => !isset($d[$x]));
    ok(!$faltan, "idiomas/$l.php: textos del JavaScript" . ($faltan ? ' faltan: ' . implode(' | ', array_slice($faltan, 0, 3)) : ''));
}

echo "\nIdiomas: $bien bien, $mal mal\n";
echo $mal === 0 ? "IDIOMAS: TODO BIEN\n" : "IDIOMAS: HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
