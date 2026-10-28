<?php
/** Ventanita de la llamada (sin el menú del panel). @var array $linea @var ?array $lla @var ?array $cl @var string $titulo */
use TuWhatsApp\Contactos;
use TuWhatsApp\Llamadas;
use TuWhatsApp\Telefono;
$entrante = $lla !== null && $lla['direccion'] === 'entra';
$puede = $cl !== null && Llamadas::puedeLlamar($cl);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#14211F">
<meta name="robots" content="noindex, nofollow">
<title>📞 <?= e($titulo) ?> · WhatsApp Tukán</title>
<link rel="stylesheet" href="/assets/app.css?v=16">
<?= tukan_idioma_js(require RAIZ . '/app/frases_js.php') ?>
</head>
<body class="sin-sesion ventana-llamada">
<main class="llamada" id="llamada" data-csrf="<?= e(csrf()) ?>" data-modo="<?= $entrante ? 'entra' : 'sale' ?>"
      data-id="<?= $lla ? (int) $lla['id'] : '' ?>" data-c="<?= $cl ? (int) $cl['id'] : '' ?>" data-contestar="<?= !empty($_GET['contestar']) ? '1' : '' ?>">
    <div class="llamada-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($cl ? Contactos::nombre($cl) : '?', 0, 1))) ?></div>
    <h1><?= u($cl ? Contactos::nombre($cl) : 'Llamada') ?></h1>
    <p class="suave"><?= $cl ? e(Telefono::bonito((string) $cl['telefono'])) : '' ?></p>
    <p class="llamada-estado" id="ll-estado" aria-live="polite"><?= $entrante ? 'Llamada entrante' : ($puede ? 'Lista para llamar' : 'Sin permiso para llamar') ?></p>
    <p class="llamada-tiempo" id="ll-tiempo" hidden>0:00</p>
    <div class="llamada-botones">
        <?php if ($entrante): ?>
        <button type="button" class="boton boton-verde" id="ll-contestar">Contestar</button>
        <button type="button" class="boton boton-rojo" id="ll-rechazar">Rechazar</button>
        <?php elseif ($puede): ?>
        <button type="button" class="boton boton-verde" id="ll-llamar">📞 Llamar</button>
        <?php else: ?>
        <p class="aviso aviso-info">Este cliente todavía no te dio permiso para llamarlo. En su chat toca <b>Pedir permiso para llamar</b>.</p>
        <?php endif; ?>
        <button type="button" class="boton" id="ll-sonido" hidden>🔊 Activar sonido</button>
        <button type="button" class="boton boton-claro" id="ll-silencio" hidden>Silenciar micrófono</button>
        <button type="button" class="boton boton-rojo" id="ll-colgar" hidden>Colgar</button>
    </div>
    <p class="suave pequeno">Deja esta ventana abierta mientras hablas. Puedes seguir usando el panel en la otra.</p>
    <audio id="ll-audio" autoplay></audio>
</main>
<script src="/assets/llamar.js?v=1" defer></script>
</body>
</html>
