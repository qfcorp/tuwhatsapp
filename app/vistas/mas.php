<?php
use TuWhatsApp\Modulos;

/** Todo lo que no cabe en la barra de abajo. Los módulos apagados se ven con candado para que el cliente sepa que existen. */
$basico = [
    ['plantillas', 'Plantillas', 'Mensajes aprobados por Meta para escribir pasadas 24 horas.', false],
    ['respuestas', 'Respuestas rápidas', 'Textos guardados para pegar en el chat con un toque.', true],
    ['gasto', 'Gasto en Meta', 'Cuánto llevas gastado este mes y el tope.', false],
    ['lineas', 'Números conectados', 'Tus números de WhatsApp y cómo conectarlos.', true],
    ['cuenta', 'Mi cuenta', 'Contraseña, equipo y token de la API.', false],
];
$extras = [
    'campanias' => 'campanias', 'embudo' => 'embudo', 'seguimientos' => 'seguimientos', 'reportes' => 'reportes',
    'ia' => 'ia', 'enlaces' => 'enlaces', 'integraciones' => 'integraciones', 'agentes' => null,
];
?>
<div class="cabeza"><h1>Más</h1></div>
<ul class="mosaico">
    <?php foreach ($basico as [$ruta, $nombre, $texto, $soloAdmin]): if ($soloAdmin && !es_admin()) continue; ?>
    <li><a href="<?= e(url($ruta)) ?>"><b><?= e($nombre) ?></b><span><?= e($texto) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<h2 class="separador">Módulos</h2>
<ul class="mosaico">
    <?php foreach ($extras as $clave => $ruta): [$nombre, $texto] = Modulos::LISTA[$clave]; $on = modulo($clave);
        if ($on && in_array($clave, ['campanias', 'reportes', 'ia', 'enlaces', 'integraciones'], true) && !es_admin()) continue; ?>
    <li class="<?= $on ? '' : 'bloqueado' ?>">
        <?php if ($on && $ruta): ?>
        <a href="<?= e(url($ruta)) ?>"><b><?= e($nombre) ?></b><span><?= e($texto) ?></span></a>
        <?php else: ?>
        <div><b><?= e($nombre) ?> <?= $on ? '<small class="chip chip-cliente">Activo</small>' : '<small class="chip">No incluido</small>' ?></b><span><?= e($texto) ?></span>
        <?php if (!$on): ?><a class="pequeno" href="https://wa.me/593996408397?text=<?= rawurlencode('Hola, quiero activar el módulo "' . $nombre . '" en WhatsApp Tukán.') ?>" target="_blank" rel="noopener">Pedir a Tukán</a><?php endif; ?></div>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
