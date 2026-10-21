<?php
/** @var array $linea @var list<array> $campanias @var list<array> $plantillas @var ?string $errorMeta @var array $etiquetas @var array $form @var array $cuentas */
use TuWhatsApp\Campanias;
use TuWhatsApp\Gasto;
$f = fn (string $k, string $d = '') => (string) ($form[$k] ?? $d);
$maxEsp = $plantillas ? max(array_column($plantillas, 'espacios')) : 0;
?>
<div class="cabeza"><h1>Campañas</h1></div>
<p class="suave">Envía una plantilla aprobada a muchos contactos a la vez. Sale poco a poco (para cuidar tu número), respeta el tope de gasto y <b>nunca</b> llega a Personales ni a quien pidió la baja.</p>
<p class="nota">Meta pide que en promociones solo escribas a quien <b>aceptó</b> recibirlas. Si mandas a quien no quiere, te reportan y Meta puede limitar o bloquear tu número. Hoy tienes <b><?= (int) $cuentas['acepta'] ?></b> contactos que aceptaron y <b><?= (int) $cuentas['clientes'] ?></b> clientes en total. Si alguien responde "BAJA" o "STOP", sale solo de las campañas.</p>

<details class="tarjeta plegable" id="nueva-campania" <?= !$campanias || $form ? 'open' : '' ?>>
    <summary>Nueva campaña</summary>
    <?php if ($errorMeta): ?>
    <div class="aviso aviso-error"><?= e($errorMeta) ?></div>
    <?php elseif (!$plantillas): ?>
    <p>Primero necesitas una plantilla aprobada. <a href="<?= e(url('plantillas')) ?>">Crear plantilla</a></p>
    <?php else: ?>
    <form method="post" class="formulario" id="form-campania">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="revisar">
        <label>Nombre (solo para ti)<input name="nombre" maxlength="100" value="<?= e($f('nombre')) ?>" placeholder="Promo de noviembre"></label>
        <fieldset class="lista-plantillas">
            <legend>Plantilla</legend>
            <?php foreach ($plantillas as $i => $p): $v = $p['nombre'] . '|' . $p['idioma']; ?>
            <label class="opcion-plantilla">
                <input type="radio" name="plantilla" value="<?= e($v) ?>" data-espacios="<?= (int) $p['espacios'] ?>" <?= $f('plantilla') === $v || ($f('plantilla') === '' && $i === 0) ? 'checked' : '' ?>>
                <span><b><?= e($p['nombre']) ?></b> <small class="chip"><?= e(Gasto::CATEGORIAS[$p['categoria']] ?? $p['categoria']) ?></small>
                    <span class="cuerpo-plantilla"><?= nl2br(e($p['cuerpo'])) ?></span></span>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <div id="datos-campania">
            <?php for ($n = 1; $n <= $maxEsp; $n++): $t = (string) ($form['dato_tipo'][$n] ?? ($n === 1 ? 'nombre' : 'fijo')); ?>
            <fieldset class="opcion-menu" data-n="<?= $n ?>">
                <legend>Qué va en {{<?= $n ?>}}</legend>
                <select name="dato_tipo[<?= $n ?>]" aria-label="Tipo de dato {{<?= $n ?>}}">
                    <option value="nombre" <?= $t === 'nombre' ? 'selected' : '' ?>>Nombre del contacto</option>
                    <option value="fijo" <?= $t === 'fijo' ? 'selected' : '' ?>>El mismo texto para todos</option>
                </select>
                <label>Texto (si es nombre: lo que va cuando no lo sabemos)<input name="dato_valor[<?= $n ?>]" maxlength="500" value="<?= e((string) ($form['dato_valor'][$n] ?? '')) ?>" placeholder="<?= $t === 'nombre' ? 'amigo' : '20 %' ?>"></label>
            </fieldset>
            <?php endfor; ?>
        </div>
        <label>A quién
            <select name="filtro_tipo"><?php foreach (Campanias::FILTROS as $k => $n): ?><option value="<?= $k ?>" <?= $f('filtro_tipo', 'cliente') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        </label>
        <label>Solo con la etiqueta (opcional)
            <input name="filtro_etiqueta" list="lista-etiquetas" maxlength="40" value="<?= e($f('filtro_etiqueta')) ?>" placeholder="vip">
            <datalist id="lista-etiquetas"><?php foreach ($etiquetas as $et => $n): ?><option value="<?= e($et) ?>"><?= e($et) ?> (<?= (int) $n ?>)</option><?php endforeach; ?></datalist>
        </label>
        <label class="check"><input type="checkbox" name="solo_acepta" value="1" <?= !$form || !empty($form['solo_acepta']) ? 'checked' : '' ?>> Solo a quien aceptó recibir promociones (recomendado)</label>
        <label>Mensajes por minuto<input type="number" name="por_minuto" min="1" max="600" value="<?= e($f('por_minuto', '30')) ?>"></label>
        <p class="suave pequeno">Con un número nuevo o con poco uso, empieza despacio (30 por minuto). Meta deja escribir a pocas personas nuevas por día al principio y sube el límite si la calidad es buena.</p>
        <button class="boton boton-grande">Revisar antes de enviar</button>
    </form>
    <?php endif; ?>
</details>

<?php if ($campanias): ?>
<h2 class="separador">Mis campañas</h2>
<?php foreach ($campanias as $k):
    $hechos = (int) $k['enviados'] + (int) $k['fallidos'] + (int) $k['omitidos'];
    $pct = $k['total'] > 0 ? (int) floor($hechos * 100 / (int) $k['total']) : 100;
    $clase = ['enviando' => 'espera', 'pausada' => 'mal', 'terminada' => 'ok', 'cancelada' => 'mal'][$k['estado']] ?? 'espera';
?>
<section class="tarjeta">
    <div class="pl-cabeza"><b><?= e($k['nombre']) ?></b><span class="chip estado-<?= $clase ?>"><?= e(Campanias::ESTADOS[$k['estado']]) ?></span></div>
    <p class="suave pequeno">Plantilla <?= e($k['plantilla']) ?> · <?= e(hora_corta($k['creado'])) ?> · <?= (int) $k['por_minuto'] ?> por minuto</p>
    <div class="tope-barra grande"><span style="width: <?= $pct ?>%"></span></div>
    <div class="cifras">
        <span><b><?= (int) $k['enviados'] ?></b> de <?= (int) $k['total'] ?> enviados</span>
        <span><?= (int) $k['leidos'] ?> leídos</span>
        <span><?= (int) $k['respondieron'] ?> respondieron</span>
        <?php if ($k['fallidos'] > 0): ?><span class="texto-alerta"><?= (int) $k['fallidos'] ?> fallaron</span><?php endif; ?>
        <?php if ($k['omitidos'] > 0): ?><span><?= (int) $k['omitidos'] ?> omitidos</span><?php endif; ?>
        <span>Costo: <?= e(dinero($k['costo'])) ?></span>
    </div>
    <?php if ($k['estado'] === 'pausada' && $k['motivo_pausa'] !== ''): ?><p class="nota"><?= e($k['motivo_pausa']) ?></p><?php endif; ?>
    <?php foreach ($k['fallidos'] > 0 ? Campanias::errores(db(), (int) $k['id']) : [] as $er): ?>
    <p class="suave pequeno">· <?= (int) $er['n'] ?> × <?= e($er['error']) ?></p>
    <?php endforeach; ?>
    <?php if (in_array($k['estado'], ['enviando', 'pausada'], true)): ?>
    <form method="post" class="fila-botones">
        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
        <?php if ($k['estado'] === 'enviando'): ?><button class="boton boton-claro" name="accion" value="pausar">Pausar</button>
        <?php else: ?><button class="boton" name="accion" value="reanudar">Reanudar</button><?php endif; ?>
        <button class="boton boton-claro" name="accion" value="cancelar">Cancelar lo que falta</button>
    </form>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
