<?php
declare(strict_types=1);

/**
 * (23 nov 2026, revisión independiente) Idioma del cliente: detección sin IA y traducciones limpias. No usa base ni red.
 * Uso: php tests/prueba_idioma_cliente.php   → termina en "IDIOMA DEL CLIENTE: TODO BIEN"
 */
spl_autoload_register(function (string $c): void {
    $f = dirname(__DIR__) . '/src/' . str_replace('TuWhatsApp\\', '', $c) . '.php';
    if (str_starts_with($c, 'TuWhatsApp\\') && is_file($f)) {
        require $f;
    }
});
use TuWhatsApp\{Flujos, IdiomaCliente as I, Reglas, Traducir};

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
function igual(mixed $a, mixed $b, string $que): void
{
    ok($a === $b, $que . ' (esperaba ' . var_export($b, true) . ', salió ' . var_export($a, true) . ')');
}

echo "== Mensajes cortos reales (Ecuador +593, Estados Unidos +1, Brasil +55)\n";
// Por mensaje: idioma con contacto nuevo / con español guardado / con inglés guardado, para +593, +1 y +55 (9 resultados).
$casos = [
    ['ok', 'es es en es es en es es en'],
    ['Ok', 'es es en es es en es es en'],
    ['si', 'es es en es es en es es en'],
    ['Si', 'es es en es es en es es en'],
    ['sí', 'es es en es es en es es en'],
    ['Hola', 'es es en es es en es es en'],
    ['hola', 'es es en es es en es es en'],
    ['buenas', 'es es en es es en es es en'],
    ['Buenas tardes', 'es es es es es es es es es'],
    ['info', 'es es en es es en es es en'],
    ['Info', 'es es en es es en es es en'],
    ['precio?', 'es es en es es en es es en'],
    ['Precio', 'es es en es es en es es en'],
    ['gracias', 'es es en es es en es es en'],
    ['Gracias!', 'es es en es es en es es en'],
    ['hi', 'es es en en es en es es en'],
    ['Hi', 'es es en en es en es es en'],
    ['hello', 'es es en en es en es es en'],
    ['Hello', 'es es en en es en es es en'],
    ['👍', 'es es en es es en es es en'],
    ['🙏🏻', 'es es en es es en es es en'],
    ['😂😂', 'es es en es es en es es en'],
    ['', 'es es en es es en es es en'],
    ['Hola, I need info', 'en en en en en en en en en'],
    ['Hola I need info', 'en en en en en en en en en'],
    ['hola buenas', 'es es es es es es es es es'],
    ['buen dia', 'es es en es es en es es en'],
    ['buen día', 'es es en es es en es es en'],
    ['buenos dias', 'es es es es es es es es es'],
    ['q tal', 'es es en es es en es es en'],
    ['k tal', 'es es en es es en es es en'],
    ['cuanto', 'es es en es es en es es en'],
    ['cuánto vale', 'es es en es es en es es en'],
    ['valor', 'es es en es es en es es en'],
    ['costo?', 'es es en es es en es es en'],
    ['ubicacion', 'es es en es es en es es en'],
    ['dónde están', 'es es es es es es es es es'],
    ['donde', 'es es en es es en es es en'],
    ['dirección', 'es es en es es en es es en'],
    ['horario', 'es es en es es en es es en'],
    ['envios', 'es es en es es en es es en'],
    ['tienen delivery?', 'es es en es es en es es en'],
    ['delivery', 'es es en es es en es es en'],
    ['menu', 'es es en es es en es es en'],
    ['Menu', 'es es en es es en es es en'],
    ['catalogo', 'es es en es es en es es en'],
    ['ya', 'es es en es es en es es en'],
    ['listo', 'es es en es es en es es en'],
    ['dale', 'es es en es es en es es en'],
    ['bueno', 'es es en es es en es es en'],
    ['de una', 'es es en es es en es es en'],
    ['chevere', 'es es en es es en es es en'],
    ['ok gracias', 'es es en es es en es es en'],
    ['okey', 'es es en es es en es es en'],
    ['oki', 'es es en es es en es es en'],
    ['vale', 'es es en es es en es es en'],
    ['perfecto', 'es es en es es en es es en'],
    ['yes', 'es es en en es en es es en'],
    ['no', 'es es en es es en es es en'],
    ['No', 'es es en es es en es es en'],
    ['Nop', 'es es en es es en es es en'],
    ['1', 'es es en es es en es es en'],
    ['2', 'es es en es es en es es en'],
    ['Quiero info', 'es es en es es en es es en'],
    ['me interesa', 'es es es es es es es es es'],
    ['Me interesa', 'es es es es es es es es es'],
    ['Info por favor', 'es es en es es en es es en'],
    ['Info pls', 'es es en en es en es es en'],
    ['ok thanks', 'es es en en es en es es en'],
    ['thanks', 'es es en en es en es es en'],
    ['thx', 'es es en en es en es es en'],
    ['Buenas noches', 'es es es es es es es es es'],
    ['Saludos', 'es es en es es en es es en'],
    ['Disculpa', 'es es en es es en es es en'],
    ['Una pregunta', 'es es en es es en es es en'],
    ['tengo una duda', 'es es es es es es es es es'],
    ['Necesito ayuda', 'es es es es es es es es es'],
    ['ayuda', 'es es en es es en es es en'],
    ['help', 'es es en en es en es es en'],
    ['Promo', 'es es en es es en es es en'],
    ['promociones', 'es es en es es en es es en'],
    ['Que precio tiene', 'es es es es es es es es es'],
    ['que precio', 'es es en es es en es es en'],
    ['Precio por favor', 'es es en es es en es es en'],
    ['Me pasa info', 'es es es es es es es es es'],
    ['info please', 'es es en en es en es es en'],
    ['pedido', 'es es en es es en es es en'],
    ['comprar', 'es es en es es en es es en'],
    ['quiero comprar', 'es es es es es es es es es'],
    ['the price', 'en en en en en en en en en'],
    ['price', 'es es en en es en es es en'],
    ['price?', 'es es en en es en es es en'],
    ['sale', 'es es en es es en es es en'],
    ['is it available?', 'en en en en en en en en en'],
    ['good morning', 'en en en en en en en en en'],
    ['Buen día, tienen stock?', 'es es es es es es es es es'],
    ['Ciao', 'es es en es es en es es en'],
    ['oi', 'es es en es es en pt es en'],
    ['Olá', 'es es en es es en pt es en'],
    ['bom dia', 'es es en es es en pt es en'],
    ['come', 'es es en es es en es es en'],
    ['come estas', 'es es en es es en es es en'],
    ['come va', 'es es en es es en es es en'],
    ['per favore', 'it it it it it it it it it'],
    ['como esta', 'es es en es es en es es en'],
    ['Como estas', 'es es en es es en es es en'],
    ['mi pedido', 'es es es es es es es es es'],
    ['mi', 'es es en es es en es es en'],
    ['con', 'es es en es es en es es en'],
    ['es', 'es es en es es en es es en'],
    ['el precio', 'es es es es es es es es es'],
    ['a como', 'es es en es es en es es en'],
    ['a cómo', 'es es en es es en es es en'],
    ['yo', 'es es en es es en es es en'],
    ['ok, de acuerdo', 'es es en es es en es es en'],
    ['Hola!! 👋', 'es es en es es en es es en'],
    ['Hola me das info', 'es es es es es es es es es'],
    ['Hello i want info', 'en en en en en en en en en'],
    ['i', 'es es en en es en es es en'],
    ['it', 'es es en en es en es es en'],
    ['do you', 'es es en en es en es es en'],
    ['Hola, info', 'es es en es es en es es en'],
    ['Hola, I need info about price', 'en en en en en en en en en'],
    ['Hello, necesito información', 'es es es es es es es es es'],
    ['Hi, quiero precio', 'es es es es es es es es es'],
    ['Hola how much', 'en en en en en en en en en'],
    ['Hi buenas', 'es es en es es en es es en'],
    ['sim', 'es es en es es en pt es en'],
    ['do', 'es es en es es en es es en'],
    ['da', 'es es en es es en pt es en'],
    ['tu', 'es es en es es en es es en'],
    ['tem', 'es es en es es en pt es en'],
    ['boa tarde', 'pt pt pt pt pt pt pt pt pt'],
    ['Disponible?', 'es es en es es en es es en'],
    ['Envíos a Quito?', 'es es en es es en es es en'],
    ['Para cuando', 'es es en es es en es es en'],
    ['Para mañana', 'es es es es es es es es es'],
    ['por fa', 'es es en es es en es es en'],
    ['porfa', 'es es en es es en es es en'],
    ['pls', 'es es en en es en es es en'],
    ['ma', 'es es en es es en es es en'],
    ['il', 'es es en es es en es es en'],
    ['la', 'es es en es es en es es en'],
    ['le', 'es es en es es en es es en'],
    ['les', 'es es en es es en es es en'],
    ['est', 'es es en es es en es es en'],
    ['ou', 'es es en es es en es es en'],
    ['non', 'es es en es es en es es en'],
    ['oui', 'es es en es es en es es en'],
    ['salut', 'es es en es es en es es en'],
    ['vous', 'es es en es es en es es en'],
    ['sono', 'es es en es es en es es en'],
    ['costa', 'es es en es es en es es en'],
    ['Cuánto cuesta?', 'es es es es es es es es es'],
    ['Cuanto costa', 'es es en es es en es es en'],
    ['Costa?', 'es es en es es en es es en'],
    ['precio de la camisa', 'es es es es es es es es es'],
    ['la talla M', 'es es es es es es es es es'],
    ['una', 'es es en es es en es es en'],
    ['talla', 'es es en es es en es es en'],
    ['how much', 'en en en en en en en en en'],
    ['Hi!', 'es es en en es en es es en'],
    ['HI', 'es es en en es en es es en'],
    ['Hey', 'es es en en es en es es en'],
    ['hey', 'es es en en es en es es en'],
    ['ola', 'es es en es es en pt es en'],
    ['Ola', 'es es en es es en pt es en'],
    ['buenas buenas', 'es es en es es en es es en'],
    ['bien', 'es es en es es en es es en'],
    ['muy bien', 'es es en es es en es es en'],
    ['estoy bien', 'es es en es es en es es en'],

];
$tels = ['593991234567', '14155551234', '5511999999999'];
foreach ($casos as [$m, $esperado]) {
    $r = [];
    foreach ($tels as $tel) {
        foreach (['', 'es', 'en'] as $act) {
            $r[] = I::detectar($m, $tel, $act);
        }
    }
    igual(implode(' ', $r), $esperado, 'idioma de ' . json_encode($m, JSON_UNESCAPED_UNICODE));
}
ok(count($casos) >= 150, 'al menos 150 mensajes del revisor (' . count($casos) . ')');
// Reglas que piden los puntos 2 y 3
foreach (['Hola', 'hola', 'buenas', 'Precio', 'gracias', 'buen día', 'Hola!! 👋'] as $m) {
    igual(I::detectar($m, '14155551234', ''), 'es', "\"$m\" desde +1: español (el país no impone)");
}
foreach (['ok', 'Ok', '1', '2', '👍', '😂😂', '', 'info', 'Info', 'menu', 'delivery', 'juan@correo.com', 'https://www.tienda.com/the-price-is-right', '0991234567'] as $m) {
    igual([I::detectar($m, '14155551234', ''), I::detectar($m, '593991234567', 'en'), I::detectar($m, '5511999999999', 'pt')], ['es', 'en', 'pt'], 'sin señal ' . json_encode($m, JSON_UNESCAPED_UNICODE) . ': español o el que ya tenía');
}
igual([I::detectar('hi', '14155551234', ''), I::detectar('hi', '593991234567', ''), I::detectar('hi', '593991234567', 'es')], ['en', 'es', 'es'], '"hi": inglés solo si el país desempata; con una palabra suelta no cambia el guardado');
igual(I::detectar('thanks', '14155551234', 'es'), 'es', 'cambiar un idioma guardado pide 2 palabras propias');
igual(I::detectar('Hello, how are you?', '593991234567', 'es'), 'en', '…con 2 palabras sí cambia');
igual(I::detectar('Buenas tardes, quiero info', '14155551234', 'en'), 'es', 'un cliente guardado en inglés que escribe claro en español pasa a español');
foreach (['18095551234', '18295551234', '18495551234', '17875551234', '19395551234'] as $t) {
    igual([I::delTelefono($t), I::detectar('hi', $t, '')], ['es', 'es'], "Caribe hispano +$t: español");
}
igual([I::delTelefono('14155551234'), I::delTelefono('5511999999999'), I::delTelefono('393331234567'), I::delTelefono('593991234567')], ['en', 'pt', 'it', 'es'], 'código de país');
igual([I::de(['telefono' => '14155551234']), I::de(['telefono' => '14155551234', 'idioma' => '']), I::de(['idioma' => 'fr'])], ['es', 'es', 'fr'], 'sin idioma guardado = español (no por el número)');

echo "== Botones del negocio y archivos (no deben cambiar el idioma)\n";
foreach (["Más info", "Info y precios", "Quiero más info", "Info de cursos", "Ver info", "Información", "Precios", "Hablar con alguien", "Hablar con un asesor", "Horarios", "Ubicación", "Ver productos", "Ver catálogo", "Promociones", "Delivery", "Sí, me interesa", "No, gracias", "Confirmar", "Cancelar", "Agendar cita", "Mi pedido", "Estado de mi pedido", "Pagar", "Hablar con una persona", "Planes y precios", "Precios y planes", "Persona", "Soporte técnico", "Sí", "Me interesa", "Info planes", "Info del evento", "Comprar entradas", "Help desk", "Contactar", "Ofertas", "Tienda online", "Combo familiar", "Menú del día", "Pizza Hawaiana", "Hamburguesa doble", "Pedido para hoy", "Buen día", "Boletos", "Precio de la entrada", "Ver más", "Otro", "Otra consulta", "Más opciones", "Hablar con ventas"] as $b) {
    igual(I::detectar($b, '593991234567', 'es'), 'es', "botón \"$b\" con cliente en español");
}
foreach (["[Foto]", "[Audio]", "[Sticker]", "[Archivo del historial]", "[Ubicación] Av. de los Shyris y Portugal", "[Documento] factura.pdf", "[Reacción 👍]", "[Contacto compartido]", "[Mensaje no compatible]", "[Ubicación] Hotel Il Giardino", "📞 Aceptó que lo llamen", "[Documento] Comprobante de pago.pdf"] as $m) {
    igual(I::detectar($m, '593991234567', 'en'), 'en', "\"$m\" no cambia un idioma guardado");
}
ok(str_contains(file_get_contents(dirname(__DIR__) . '/src/Webhook.php'), "(\$m['type'] ?? '') === 'text'") && str_contains(file_get_contents(dirname(__DIR__) . '/src/Webhook.php'), 'Flujos::esperaDato'),
   'Webhook solo detecta con texto escrito (no botones, archivos ni datos que pide el chatbot)');

echo "== Traductor del chat: saltarse la confirmación solo si es muy claro\n";
igual([I::seguro('Hello'), I::seguro('Hi there'), I::seguro('ok thanks a lot'), I::seguro('Hello, how much is the price of this?'), I::seguro('Hola, ¿cuánto cuesta el envío a Quito?')],
      ['', '', '', 'en', 'es'], 'detección fuerte: 3+ palabras y ventaja clara');

echo "== Marcas <!--notr--> (textarea) nunca se guardan ni se mandan\n";
$vistas = glob(dirname(__DIR__) . '/app/vistas/*.php');
$malas = [];
foreach ($vistas as $v) {
    $src = file_get_contents($v);
    preg_match_all('~<textarea.*?</textarea>~s', $src, $mm);
    preg_match_all('~<input\b[^\n]*~', $src, $mi);
    foreach (array_merge($mm[0], $mi[0]) as $x) {
        if (str_contains($x, 'notr')) {
            $malas[] = basename($v) . ': ' . mb_substr($x, 0, 60);
        }
    }
}
ok(!$malas, 'ningún textarea ni input de las vistas tiene <!--notr-->' . ($malas ? ' → ' . implode(' | ', $malas) : ''));
$basura = '<!--notr--><!--/notr-->';
$tr = Traducir::limpiarLinea(array_fill_keys(Traducir::OTROS, ['saludo' => $basura, 'palabras' => $basura, 'op_titulo' => ['', ''], 'op_respuesta' => [$basura, '<!--notr-->Hi<!--/notr-->']]), [0 => 0, 1 => 1]);
igual($tr['en'], ['saludo' => '', 'opciones' => [['titulo' => '', 'respuesta' => ''], ['titulo' => '', 'respuesta' => 'Hi']], 'palabras' => ''], 'limpiarLinea borra las marcas y deja vacío lo vacío');
$ops = [['titulo' => 'Precios', 'respuesta' => 'Nuestros precios: $10', 'humano' => false], ['titulo' => 'Más info', 'respuesta' => 'Somos X', 'humano' => false]];
$linea = ['auto_activo' => true, 'regla_solo_clientes' => false, 'regla_horario' => false, 'regla_palabras' => false, 'regla_una_vez' => false, 'max_auto' => 3,
          'mensaje_menu' => 'Hola, elige:', 'opciones' => json_encode($ops), 'menu_botones' => true, 'palabras' => 'precio', 'idioma_auto' => true, 'idiomas' => 'es,en,pt,fr,it',
          'traducciones' => json_encode(['en' => ['saludo' => $basura, 'opciones' => [['titulo' => '', 'respuesta' => $basura]], 'palabras' => $basura]])];   // guardado viejo, con marcas
$d = Reglas::decidir($linea, ['tipo' => 'cliente', 'idioma' => 'en'], 'Hello there', new DateTimeImmutable('2026-11-23 21:00'), 0);
igual([$d['saludo'], array_column($d['botones'], 'titulo')], ['Hola, elige:', ['Precios', 'Más info']], 'traducción vieja con marcas: al cliente le llega el español, nunca "<!--notr-->"');
igual(Reglas::decidir($linea, ['tipo' => 'cliente', 'idioma' => 'en'], '1', new DateTimeImmutable('2026-11-23 21:00'), 0)['texto'], 'Nuestros precios: $10', '…y la respuesta de la opción también');
foreach ([['Hola', '14155551234'], ['Más info', '593991234567'], ['2', '14155551234'], ['Buen día', '593991234567']] as [$t, $tel]) {
    $c = ['tipo' => 'cliente', 'telefono' => $tel, 'idioma' => I::detectar($t, $tel, '')];
    $x = Reglas::decidir($linea, $c, $t, new DateTimeImmutable('2026-11-23 21:00'), 0);
    ok(!str_contains((string) $x['texto'] . ($x['saludo'] ?? ''), 'notr'), "escenario del revisor \"$t\" ($tel): sin marcas");
}
$sql = file_get_contents(dirname(__DIR__) . '/db/schema.sql');
ok(str_contains($sql, "UPDATE lineas SET traducciones = regexp_replace(traducciones, '<!--\\\\?/?notr-->'") && str_contains($sql, "UPDATE flujos SET traducciones = regexp_replace"), 'schema.sql limpia las marcas ya guardadas (idempotente)');

echo "== Chatbots: traducciones alineadas por paso y opción\n";
$pasos = Flujos::limpiarPasos([
    ['id' => 'p1', 'tipo' => 'botones', 'texto' => '¿Qué buscas?', 'op_titulo' => ['Ropa', '', 'Zapatos', 'Gorras'], 'op_ir' => ['', '', '', '']],
    ['id' => 'p3', 'tipo' => 'fin', 'texto' => 'Gracias'],
], [], true, $mapaOps);
igual([array_column($pasos[0]['opciones'], 'titulo'), $mapaOps['p1']], [['Ropa', 'Zapatos', 'Gorras'], [0 => 0, 2 => 1, 3 => 2]], 'opción vacía en medio: se saltan y se anota el mapa');
$trF = Traducir::limpiarFlujo(['en' => ['palabras' => 'clothes', 'paso' => ['p3' => ['texto' => 'Thanks'], 'p2' => ['texto' => 'borrado'],
    'p1' => ['texto' => '<!--notr-->What?<!--/notr-->', 'op' => [0 => 'Clothes', 1 => 'x', 2 => 'Shoes', 3 => 'Caps']]]]], $pasos, $mapaOps);
igual([$trF['en']['pasos']['p1'], $trF['en']['pasos']['p3']['texto'], isset($trF['en']['pasos']['p2'])],
      [['texto' => 'What?', 'opciones' => ['Clothes', 'Shoes', 'Caps']], 'Thanks', false], 'cada traducción queda con su opción y su paso (y sin marcas)');
igual(Traducir::limpiarFlujo(['en' => ['pasos' => ['p1' => ['opciones' => ['A', 'B', 'C']]]]], $pasos)['en']['pasos']['p1']['opciones'], ['A', 'B', 'C'], 'sin mapa (traducción con IA): mismo orden');
$fl = ['palabras' => 'ropa', 'pasos' => json_encode($pasos), 'traducciones' => json_encode(['en' => ['palabras' => 'clothes'], 'pt' => ['palabras' => 'roupa']])];
igual([Traducir::palabrasFlujo($fl), Traducir::palabrasFlujo($fl, 'en'), Traducir::palabrasFlujo($fl, 'pt')], [['ropa'], ['ropa', 'clothes'], ['ropa', 'roupa']], 'palabras de un idioma solo para clientes de ese idioma');
$crear = file_get_contents(dirname(__DIR__) . '/src/Flujos.php');
ok(!str_contains($crear, "'palabras' => 'hello"), 'el chatbot de ejemplo no trae palabras en otros idiomas');

echo "== Agente con IA y palabras del carrito\n";
$sis = new ReflectionMethod(\TuWhatsApp\Bot::class, 'sistema');
$base = ['nombre' => 'Shop', 'horario' => '{}', 'idioma_auto' => true];
$sEs = $sis->invoke(null, $base, '', null, [], 'es');
$sEn = $sis->invoke(null, $base, '', null, [], 'en');
$sOff = $sis->invoke(null, ['idioma_auto' => false] + $base, '', null, [], 'en');
ok(!str_contains($sEs, 'IDIOMA DEL CLIENTE') && !str_contains($sEs, 'Responde SIEMPRE en Español') && str_contains($sEs, 'EN EL MISMO IDIOMA en que escribe el cliente'), 'cliente en español: la regla de siempre (no se fuerza el español)');
ok(str_contains($sEn, 'IDIOMA DEL CLIENTE: English (en). Responde SIEMPRE en English'), 'cliente en inglés: se le dice el idioma');
ok(!str_contains($sOff, 'IDIOMA DEL CLIENTE') && str_contains($sOff, 'EN EL MISMO IDIOMA'), 'función apagada: la regla de siempre');
foreach (['menu'] as $p) {
    ok(!in_array($p, \TuWhatsApp\Webhook::PALABRAS_CATALOGO, true), "\"$p\" no abre el catálogo");
}
foreach (['compra', 'finalizar'] as $p) {
    ok(!in_array($p, \TuWhatsApp\Webhook::PALABRAS_COMPRAR, true), "\"$p\" no confirma el carrito");
}

echo "\nIdioma del cliente: $bien bien, $mal mal\n";
echo $mal === 0 ? "IDIOMA DEL CLIENTE: TODO BIEN\n" : "IDIOMA DEL CLIENTE: HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
