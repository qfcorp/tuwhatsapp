<?php
/** @var string $vapid @var int $suscripciones @var list<array> $dispositivos @var array $ajustesCorreo @var string $correoModo @var array $empresaApp @var array $telegram
 *  @var list<array> $appNativa @var string $estadoFcm @var bool $fcmListo */
$fechaCorta = function (?string $f): string {
    if ($f === null || $f === '') {
        return '—';
    }
    $d = new DateTimeImmutable($f);
    $hoy = new DateTimeImmutable('today');
    return $d >= $hoy ? 'hoy ' . $d->format('H:i') : ($d >= $hoy->modify('-1 day') ? 'ayer ' . $d->format('H:i') : $d->format('d/m/Y H:i'));
};
$correoListo = $correoModo !== 'ninguno';
$minCorreo = (int) $ajustesCorreo['min'];
?>
<div class="cabeza tk-titulo-pagina"><h1>App en el celular</h1></div>
<section class="tarjeta tk-tarjeta">
    <h2>1. Instálalo como app</h2>
    <p>Así se abre con un toque desde la pantalla de inicio, a pantalla completa, como una app.</p>
    <button type="button" class="boton tk-boton" id="instalar-app" hidden>📲 Instalar la app</button>
    <ul class="pasos-cortos">
        <li><b>Android:</b> instala <b>Tukán Mensajes</b> desde Google Play, o en Chrome toca los tres puntos ⋮ → <b>Instalar app</b>.</li>
        <li><b>iPhone (Safari):</b> toca el botón Compartir ⬆️ → <b>Agregar a inicio</b>. Ábrelo desde ese ícono para recibir avisos.</li>
        <li><b>Computadora (Chrome o Edge):</b> el ícono de instalar en la barra de direcciones.</li>
    </ul>
</section>
<section class="tarjeta tk-tarjeta" id="caja-push" data-vapid="<?= e($vapid) ?>">
    <h2>2. Avisos aunque la app esté cerrada</h2>
    <p>Te llega un aviso al celular cada vez que un cliente te escribe, como en WhatsApp (si la conversación está asignada a otra persona, le llega a ella;
    si a esa persona no le llegan avisos, también les llega a los administradores).</p>
    <div class="caja-app-nativa" id="caja-app-nativa">
        <?php if ($estadoFcm === 'vinculado'): ?>
        <p><b>📱 Este celular recibe los avisos con la app Tukán Mensajes ✅</b> <span class="suave pequeno">(llegan aunque la app esté cerrada, como en WhatsApp)</span></p>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="fcm_prueba">
            <button class="boton boton-claro tk-boton secundario">Mandarme un aviso de prueba a la app</button></form>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="fcm_apagar">
            <button class="enlace">Apagar en este celular</button></form>
        <?php elseif ($estadoFcm === 'bloqueado'): ?>
        <p class="tk-aviso aviso aviso-error">🔕 <b>Este celular tiene los avisos bloqueados:</b> Ajustes → Apps → Tukán Mensajes → Notificaciones (permitir).
        Después cierra la app y vuelve a abrirla.</p>
        <?php elseif ($estadoFcm === 'apagado'): ?>
        <p><b>📱 Apagaste los avisos de la app en este celular.</b></p>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="fcm_activar">
            <button class="boton tk-boton">🔔 Volver a activar los avisos en este celular</button></form>
        <?php elseif ($estadoFcm === 'sin_registro'): ?>
        <p class="tk-aviso aviso aviso-info pequeno">📱 Falta un paso: cierra la app <b>Tukán Mensajes</b> (deslízala de las apps abiertas) y vuelve a abrirla.
        Si el celular pregunta si permites las notificaciones, toca <b>Permitir</b>.</p>
        <?php else: ?>
        <p class="suave pequeno">📱 <b>En Android, lo mejor es la app Tukán Mensajes de Google Play</b>: los avisos llegan solos, con el nombre de la app, aunque esté cerrada.
        Instálala (o actualízala), ábrela, entra con tu usuario y toca <b>Permitir</b> cuando pregunte por las notificaciones.</p>
        <?php endif; ?>
        <?php if (!$fcmListo && es_super()): ?>
        <p class="tk-aviso aviso aviso-info pequeno">Falta la clave de Firebase del servidor: súbela en <a href="<?= e(url('firebase')) ?>">Avisos de la app (Firebase)</a>.</p>
        <?php endif; ?>
    </div>
    <?php if ($estadoFcm === 'sin_cookie'): ?>
    <p class="suave pequeno">En la computadora, iPhone u otro navegador, activa los avisos del navegador:</p>
    <?php endif; ?>
    <button type="button" class="boton tk-boton" id="activar-push">🔔 Activar avisos en este celular</button>
    <button type="button" class="boton boton-claro tk-boton secundario" id="probar-push" hidden>Mandarme un aviso de prueba</button>
    <button type="button" class="enlace" id="quitar-push" hidden>Desactivar en este celular</button>
    <p class="suave pequeno" id="estado-push" aria-live="polite"><?= $suscripciones > 0 ? 'Tienes avisos activos en ' . $suscripciones . ($suscripciones === 1 ? ' dispositivo.' : ' dispositivos.') : '' ?></p>
    <?php if ($dispositivos || $appNativa): ?>
    <h3 class="pequeno">Dispositivos que reciben avisos</h3>
    <ul class="lista-dispositivos">
        <?php foreach ($appNativa as $d): $falla = $d['errores'] >= 5 || !$d['avisos'] || !$d['con_token']; ?>
        <li>
            <span><?= $falla ? '⚠️' : '✅' ?> <b>📱 App Tukán Mensajes<?= $d['modelo'] !== '' ? ' · ' . e($d['modelo']) : '' ?></b>
                <span class="suave pequeno">· último aviso entregado: <?= e($fechaCorta($d['ultimo_ok'])) ?><?php if (!$d['avisos']): ?> ·
                avisos bloqueados en el celular (Ajustes → Apps → Tukán Mensajes → Notificaciones)<?php elseif (!$d['con_token']): ?> ·
                hay que abrir la app de nuevo<?php elseif ($d['errores'] > 0): ?> ·
                <?= $d['errores'] >= 5 ? 'NO le están llegando (se vuelve a intentar cada día)' : 'falló ' . $d['errores'] . ($d['errores'] === 1 ? ' vez' : ' veces') ?><?php endif; ?></span></span>
            <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_app"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="enlace pequeno">Quitar</button></form>
        </li>
        <?php endforeach; ?>
        <?php foreach ($dispositivos as $d): $falla = $d['errores'] >= 5; ?>
        <li>
            <span><?= $falla ? '⚠️' : '✅' ?> <b><?= e($d['dispositivo']) ?></b>
                <span class="suave pequeno">· último aviso entregado: <?= e($fechaCorta($d['ultimo_ok'])) ?><?php if ($d['errores'] > 0): ?> ·
                <?= $falla ? 'NO le están llegando (se vuelve a intentar cada día)' : 'falló ' . $d['errores'] . ($d['errores'] === 1 ? ' vez' : ' veces') ?>
                <?= $d['ultimo_error'] ? '(' . e($fechaCorta($d['ultimo_error'])) . ')' : '' ?><?php endif; ?></span></span>
            <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_dispositivo"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="enlace pequeno">Quitar</button></form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <details class="pequeno">
        <summary><b>¿No te llegan los avisos? (Android)</b></summary>
        <ol>
            <li>Instala o actualiza la app <b>Tukán Mensajes</b> desde <b>Google Play</b>, ábrela, entra con tu usuario y toca <b>Permitir</b> cuando pregunte por las notificaciones.
            Aquí debe decir <b>«Este celular recibe los avisos con la app Tukán Mensajes ✅»</b>. Después toca <b>Mandarme un aviso de prueba a la app</b>.</li>
            <li>Ajustes del celular → <b>Apps → Tukán Mensajes → Notificaciones</b>: que estén <b>permitidas</b>.</li>
            <li>Ajustes → <b>Apps → Chrome → Batería</b>: elige <b>Sin restricciones</b> (o "No optimizar"). Lo mismo para <b>Tukán Mensajes</b>.</li>
            <li><b>Xiaomi / Redmi / POCO:</b> Ajustes → Apps → Chrome → <b>Inicio automático</b> encendido y Ahorro de batería → <b>Sin restricciones</b>.</li>
            <li><b>Samsung:</b> Ajustes → Batería → Límites de uso en segundo plano: saca a <b>Chrome</b> y <b>Tukán Mensajes</b> de "Apps en suspensión" y agrégalas a "Apps que nunca se suspenden".</li>
            <li><b>Huawei / Honor:</b> Ajustes → Batería → Inicio de aplicaciones → Chrome: <b>Administrar manualmente</b> y enciende las tres opciones.</li>
            <li>No cierres Chrome "deslizándolo" de las apps recientes con el limpiador del celular.</li>
        </ol>
        <p>Si el celular está apagado o sin internet, el aviso espera hasta <b>24 horas</b> y llega cuando vuelve la conexión. Y si nadie lo lee, te llega el <b>correo de respaldo</b> (abajo).</p>
    </details>
</section>
<section class="tarjeta tk-tarjeta" id="respaldo-correo">
    <h2>3. Respaldo por correo</h2>
    <p>Si un cliente te escribe y <b>nadie lo lee ni contesta</b>, te llega un correo con el enlace para abrir el chat. Así no se pierde ningún cliente aunque la app esté cerrada o el celular sin batería.</p>
    <?php if (!$correoListo): ?>
    <p class="tk-aviso aviso aviso-info pequeno">El servidor todavía no tiene configurado el envío de correos, así que por ahora no se mandan. <?= es_super() ? 'Configúralo con deploy/configurar_correo.sh.' : 'Tukán lo está activando.' ?></p>
    <?php endif; ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="correo">
        <label>Avisarme por correo si no contesto en
            <select name="minutos">
                <?php foreach ([5 => '5 minutos', 15 => '15 minutos', 30 => '30 minutos', 60 => '1 hora', 0 => 'Apagado (no mandarme correos)'] as $v => $t): ?>
                <option value="<?= $v ?>" <?= $minCorreo === $v ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label>Mandarlo a este correo <span class="suave pequeno">(vacío = <?= u($ajustesCorreo['de_entrar']) ?>)</span>
            <input type="email" name="correo" value="<?= e($ajustesCorreo['correo']) ?>" placeholder="<?= e($ajustesCorreo['de_entrar']) ?>" maxlength="200"></label>
        <button class="boton tk-boton">Guardar</button>
    </form>
    <?php if ($correoListo && $minCorreo > 0): ?>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="correo_prueba">
        <button class="boton boton-claro tk-boton secundario">Mandarme un correo de prueba</button></form>
    <?php endif; ?>
    <p class="suave pequeno">Un solo correo con todos tus chats pendientes y, como máximo, uno cada 15 minutos. No se avisa de los contactos personales.</p>
    <?php if (es_admin()): ?>
    <form method="post" class="formulario borde-arriba">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="copia">
        <h3 class="pequeno">Copia para la empresa (solo el administrador)</h3>
        <label>Correo que recibe copia de todos los chats sin contestar <span class="suave pequeno">(por ejemplo ventas@tuempresa.com; vacío = no se usa)</span>
            <input type="email" name="copia" value="<?= e($empresaApp['aviso_copia'] ?? '') ?>" maxlength="200"></label>
        <label>Si nadie contesta en
            <select name="copia_min">
                <?php foreach ([5 => '5 minutos', 15 => '15 minutos', 30 => '30 minutos', 60 => '1 hora'] as $v => $t): ?>
                <option value="<?= $v ?>" <?= (int) ($empresaApp['aviso_copia_min'] ?? 15) === $v ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select></label>
        <button class="boton tk-boton">Guardar copia</button>
    </form>
    <?php endif; ?>
</section>

<section class="tarjeta tk-tarjeta" id="avisos-telegram">
    <h2>4. Avisos por Telegram</h2>
    <p>Recibe en tu <b>Telegram</b> los mensajes de tus clientes, con un botón para abrir el chat. Telegram avisa siempre, aunque esta app esté cerrada.</p>
    <?php if (!$telegram['configurado']): ?>
    <p class="tk-aviso aviso aviso-info pequeno">Tukán todavía no activó los avisos por Telegram en este servidor. <?= es_super() ? 'Actívalo con deploy/configurar_telegram.sh.' : 'Pronto vas a poder conectarlo aquí.' ?></p>
    <?php elseif (!$telegram['conectado']): ?>
    <ol class="pasos-cortos">
        <li>Instala <b>Telegram</b> en tu celular (si no lo tienes) y entra con tu número.</li>
        <li>Toca <b>Conectar mi Telegram</b>: se abre el bot <b>@<?= e($telegram['bot']) ?></b>.</li>
        <li>En Telegram toca <b>Iniciar</b> (o <b>Start</b>). El bot te contesta «Listo».</li>
        <li>Vuelve aquí: debe decir <b>Conectado ✅</b>.</li>
    </ol>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_conectar">
        <button class="boton tk-boton" id="conectar-telegram">✈️ Conectar mi Telegram</button></form>
    <p class="suave pequeno">El enlace sirve 15 minutos y una sola vez. Si ya pasó, vuelve a tocar el botón.</p>
    <?php else: ?>
    <p><b>Conectado ✅</b><?= $telegram['nombre'] !== '' ? ' <span class="suave">(@' . e($telegram['nombre']) . ')</span>' : '' ?></p>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="tg_modo">
        <label>¿Cuándo te aviso por Telegram?
            <select name="modo">
                <?php foreach ([-1 => 'Cada mensaje nuevo al momento', 5 => 'Solo si nadie contesta en 5 minutos', 15 => 'Solo si nadie contesta en 15 minutos',
                                30 => 'Solo si nadie contesta en 30 minutos', 60 => 'Solo si nadie contesta en 1 hora', 0 => 'Apagado (no mandarme nada)'] as $v => $t): ?>
                <option value="<?= $v ?>" <?= (int) $telegram['modo'] === $v ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select></label>
        <button class="boton tk-boton">Guardar</button>
    </form>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_prueba">
        <button class="boton boton-claro tk-boton secundario">Mandarme un aviso de prueba</button></form>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_desconectar">
        <button class="enlace">Desconectar</button></form>
    <p class="suave pequeno">Te llega lo de los chats que atiendes (o de todos, si el chat no tiene a nadie). Nunca de los contactos personales.
    Si muchos mensajes llegan seguidos del mismo cliente, te llega un aviso cada 2 minutos como máximo. En Telegram también puedes escribir <b>/parar</b>.</p>
    <?php endif; ?>
</section>
