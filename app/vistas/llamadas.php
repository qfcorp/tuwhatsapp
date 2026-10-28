<?php
/** @var array $linea @var list<array> $llamadas */
use TuWhatsApp\Llamadas;
$activo = !empty($linea['llamadas_activo']);
$iconos = ['entra' => '↙', 'sale' => '↗'];
?>
<div class="cabeza"><h1>Llamadas</h1></div>
<section class="tarjeta">
    <h2>Llamadas de WhatsApp <?= $activo ? '<small class="chip chip-cliente">Encendidas</small>' : '<small class="chip">Apagadas</small>' ?></h2>
    <p class="suave pequeno">Tus clientes te llaman por WhatsApp (el botón 📞 de tu chat) y contestas desde el panel o desde la app en el celular.
    También puedes llamarlos tú, si te dan permiso. Las llamadas que te hacen son <b>gratis</b>; las que haces tú las cobra Meta por minuto.</p>
    <?php if ((string) $linea['llamadas_error'] !== ''): ?><p class="aviso aviso-error pequeno"><?= e($linea['llamadas_error']) ?></p><?php endif; ?>
    <?php if (!empty($linea['coexistencia'])): ?>
    <div class="aviso aviso-info explica-llamadas">
        <p><b>Tu número también está en la app WhatsApp Business del celular.</b> Así Meta <b>no permite llamadas</b>. Para tenerlas, el número tiene que quedar <b>solo en WhatsApp Tukán</b> ("solo API").</p>
        <details>
            <summary><b>Cómo pasarlo a "solo API" (10 a 15 minutos)</b></summary>
            <p class="pequeno"><b>Qué cambia:</b> ya no usarás ese número en la app del celular (atiendes desde WhatsApp Tukán, también en el celular con la app de Más → App en el celular);
            los chats que ya están aquí <b>se quedan</b>; los chats que solo estaban en el celular y los grupos de ese número <b>se pierden</b>; ya no hay "enviar gratis desde el celular".</p>
            <ol class="pequeno">
                <li>Ten a la mano el celular con ese número (te llega un código por SMS o llamada). Si quieres, haz una copia de seguridad de los chats en la app.</li>
                <li>En el celular: <b>WhatsApp Business → Ajustes → Cuenta → Eliminar mi cuenta</b>. Escribe tu número y confirma.</li>
                <li>Espera <b>3 minutos</b>.</li>
                <li>Aquí: <a href="<?= e(url('lineas')) ?>">Números conectados</a> → <b>Conectar con WhatsApp Business (código QR)</b>. En la ventana de Meta elige <b>agregar tu número</b> (no "Conectar la app WhatsApp Business"), escribe el código y toca <b>Finalizar</b>.</li>
                <li>El programa reconoce que es el mismo número y <b>conserva tus chats y contactos</b>. Después vuelve aquí y toca <b>Encender llamadas en mi número</b>.</li>
            </ol>
            <p class="pequeno">Si Meta dice que el número todavía está en uso, espera unos minutos más. ¿Dudas? Escríbele a Tukán al <a href="https://wa.me/593996408397" target="_blank" rel="noopener">099 640 8397</a>.</p>
        </details>
    </div>
    <?php endif; ?>
    <?php if (es_admin()): ?>
    <form method="post" class="fila-botones">
        <?= csrf_campo() ?>
        <?php if ($activo): ?>
        <button class="boton boton-claro" name="accion" value="apagar">Apagar llamadas</button>
        <?php else: ?>
        <button class="boton" name="accion" value="encender">Encender llamadas en mi número</button>
        <?php endif; ?>
    </form>
    <details <?= $activo ? '' : 'open' ?>>
        <summary><b>Lo que pide Meta</b></summary>
        <ul class="pequeno">
            <li>El número debe estar <b>solo en la API de WhatsApp</b> (no también en la app WhatsApp Business del celular).</li>
            <li>Tu número debe poder mandar a <b>2.000 personas al día</b> o más (Meta lo sube solo cuando usas el número sin reportes).</li>
            <li>En la app de Meta, el webhook debe tener suscrito el campo <b>calls</b> (Tukán lo deja listo).</li>
            <li>Se usa el <b>horario de atención</b> de Respuestas automáticas: fuera de horario, al cliente le sale que estás cerrado.</li>
            <li>Para llamar tú a un cliente, primero tiene que aceptar tu <b>pedido de permiso</b> (vale 7 días, o siempre si así lo elige). Meta deja pedirlo 1 vez al día y 2 por semana.</li>
        </ul>
    </details>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
        <label>Mensaje si no alcanzas a contestar (opcional)<textarea name="llamadas_perdida" rows="2" maxlength="1000" placeholder="Hola, vimos tu llamada. Ahora no pudimos contestar, ¿te ayudamos por aquí?"><?= e($linea['llamadas_perdida']) ?></textarea></label>
        <p class="suave pequeno">Se manda solo si el cliente te escribió en las últimas 24 horas (así no cuesta plantilla).</p>
        <button class="boton boton-claro">Guardar</button>
    </form>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2>Últimas llamadas</h2>
    <?php if (!$llamadas): ?>
    <p class="suave">Todavía no hay llamadas.</p>
    <?php else: ?>
    <ul class="lista-llamadas">
        <?php foreach ($llamadas as $l): ?>
        <li class="llamada-<?= e($l['estado']) ?>">
            <span class="ll-ico" aria-hidden="true"><?= $l['estado'] === 'perdida' ? '📵' : $iconos[$l['direccion']] ?></span>
            <a href="<?= e(url('chat', ['c' => $l['contacto_id']])) ?>"><b><?= u(Llamadas::nombre($l)) ?></b></a>
            <span class="suave pequeno"><?= $l['direccion'] === 'entra' ? 'Te llamó' : 'Llamaste' ?> · <?= e(tukan_tr(Llamadas::ESTADOS[$l['estado']] ?? $l['estado'])) ?><?= $l['duracion'] !== null ? ' · ' . e(Llamadas::duracion((int) $l['duracion'])) : '' ?><?= $l['u_nombre'] ? ' · ' . u($l['u_nombre']) : '' ?> · <?= e(hora_corta((string) $l['creado'])) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
