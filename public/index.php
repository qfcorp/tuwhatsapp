<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\Contactos;
use TuWhatsApp\Empresas;
use TuWhatsApp\Gasto;
use TuWhatsApp\Lineas;
use TuWhatsApp\Mensajeria;
use TuWhatsApp\Meta;
use TuWhatsApp\Pendientes;
use TuWhatsApp\Registro;

$rutas = ['login', 'salir', 'bandeja', 'chat', 'nuevo_chat', 'pendientes', 'contactos', 'reglas', 'gasto', 'lineas', 'linea', 'plantilla', 'cambiar_linea', 'nuevos', 'conectar_qr', 'plantillas',
          'cuenta', 'bloqueada', 'clientes', 'cliente', 'ver_cuenta', 'volver_tukan', 'campanias', 'respuestas', 'aceptar', 'mas', 'archivo',
          'embudo', 'seguimientos', 'reportes', 'ia', 'enlaces', 'integraciones',
          'catalogo', 'foto_producto', 'cobros', 'pedidos', 'pedido', 'cotizacion', 'flujos', 'flujo', 'equipo', 'campos', 'tienda', 'app', 'push',
          'llamada', 'llamar', 'llamadas', 'asistente'];
$ruta = (string) ($_GET['r'] ?? 'bandeja');
if (!in_array($ruta, $rutas, true)) {
    http_response_code(404);
    $ruta = 'bandeja';
}
if ($ruta !== 'login' && !usuario()) {
    redirigir('login');
}
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

// Permisos: lo de Tukán solo para su personal; la configuración solo para administradores de la cuenta
$soloSuper = ['clientes', 'cliente', 'ver_cuenta'];
$soloAdmin = ['reglas', 'lineas', 'linea', 'conectar_qr', 'campanias', 'respuestas', 'reportes', 'ia', 'enlaces', 'integraciones',
              'catalogo', 'cobros', 'flujos', 'flujo', 'equipo', 'campos', 'tienda'];
if (usuario()) {
    if (in_array($ruta, $soloSuper, true) && !es_super()) {
        http_response_code(403);
        $ruta = 'bandeja';
    }
    if ((in_array($ruta, $soloAdmin, true) || ($post && in_array($ruta, ['gasto', 'plantillas'], true))) && !es_admin()) {
        aviso('Solo el administrador de la cuenta puede cambiar eso.', 'error');
        redirigir('bandeja');
    }
    // Cuenta suspendida o vencida: no entra (el personal de Tukán sí, para dar soporte)
    $empresaHoy = empresa_actual();
    if (!es_super() && !Empresas::alDia($empresaHoy) && !in_array($ruta, ['salir', 'bloqueada'], true)) {
        redirigir('bloqueada');
    }
    // Módulo apagado para esta cuenta: se ofrece pedirlo a Tukán
    if ($nombreMod = \TuWhatsApp\Modulos::rutaBloqueada($empresaHoy, $ruta)) {
        aviso('"' . $nombreMod . '" no está incluido en tu plan. Escribe a Tukán para activarlo.', 'info');
        redirigir('mas');
    }
    // El administrador del cliente acepta los términos una vez por versión (la cuenta de Tukán no)
    if (!es_super() && !\TuWhatsApp\Terminos::aceptados($empresaHoy) && !in_array($ruta, ['salir', 'bloqueada', 'aceptar'], true)) {
        redirigir('aceptar');
    }
}
if ($ruta === 'conectar_qr') {   // lo llama el botón "Conectar con WhatsApp Business" (responde JSON)
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$post || !csrf_ok()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => tukan_tr('La página estaba abierta hace mucho. Recárgala e intenta de nuevo.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $r = Registro::completar(db(), $_POST, null, (int) empresa_actual()['id']);
    } catch (\Throwable $e) {
        error_log('tuwhatsapp conectar_qr: ' . $e);
        $r = ['ok' => false, 'error' => 'Error del servidor al guardar el número. Intenta de nuevo.'];
    }
    if ($r['ok']) {
        $_SESSION['linea_id'] = $r['linea_id'];
        aviso('Número conectado con código QR: ' . $r['numero'] . ($r['coexistencia'] ? ' (sigue funcionando en la app del celular).' : '.'));
        if ($r['pin'] !== '') {
            aviso('PIN de verificación en dos pasos de este número: ' . $r['pin'] . '. Guárdalo; Meta lo pide si cambias de servidor.', 'info');
        }
        foreach ($r['avisos'] as $a) {
            aviso($a, 'error');
        }
        $r['ir'] = url('linea', ['id' => $r['linea_id']]);
    }
    unset($r['pin']);
    if (isset($r['error'])) {
        $r['error'] = tukan_tr((string) $r['error']);   // en el idioma del botón 🌐
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($post && !csrf_ok()) {
    aviso('La página estaba abierta hace mucho. Intenta de nuevo.', 'error');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

/** Muestra una vista dentro del diseño. */
function vista(string $nombre, string $titulo, array $datos = []): never
{
    extract($datos);
    $u = usuario();
    $linea = $datos['linea'] ?? (usuario() ? linea_actual() : null);
    ob_start();
    require RAIZ . '/app/vistas/' . $nombre . '.php';
    $contenido = ob_get_clean();
    require RAIZ . '/app/vistas/_diseno.php';
    exit;
}

// Páginas que necesitan un número conectado
$linea = usuario() ? linea_actual() : null;
if ($linea === null && in_array($ruta, ['bandeja', 'chat', 'nuevo_chat', 'pendientes', 'contactos', 'reglas', 'gasto', 'plantilla', 'nuevos', 'plantillas', 'campanias', 'respuestas',
                                    'embudo', 'seguimientos', 'reportes', 'ia', 'enlaces', 'integraciones',
                                    'catalogo', 'foto_producto', 'cobros', 'pedidos', 'pedido', 'cotizacion', 'flujos', 'flujo', 'equipo', 'campos', 'tienda',
                                    'llamada', 'llamar', 'llamadas', 'asistente'], true)) {
    if (!es_admin()) {
        aviso('El administrador de tu cuenta todavía no conecta el número de WhatsApp.', 'info');
        redirigir('cuenta');
    }
    aviso('Primero conecta tu número de WhatsApp de Meta.', 'info');
    redirigir('linea');
}
$lid = $linea ? (int) $linea['id'] : 0;

/** Manda por WhatsApp el resumen del pedido con cómo pagar (crea el enlace de pago si hace falta). */
function enviar_resumen(array $linea, array $contacto, array $pedido): array
{
    $enlace = '';
    try {
        $enlace = \TuWhatsApp\Pedidos::enlacePago(db(), $linea, $pedido);
    } catch (\RuntimeException $ex) {
        aviso('No se pudo crear el enlace de pago: ' . $ex->getMessage(), 'error');
    }
    $pedido = \TuWhatsApp\Pedidos::buscar(db(), (int) $linea['id'], (int) $pedido['id']);
    $texto = \TuWhatsApp\Pedidos::resumen($linea, $pedido, \TuWhatsApp\Pedidos::items(db(), (int) $pedido['id']), $enlace);
    $r = (new Mensajeria(db()))->texto($linea, $contacto, $texto, 'panel', (int) usuario()['id']);
    if ($r['ok']) {
        Contactos::callar(db(), $linea, (int) $contacto['id']);
    }
    return $r;
}

/** Arma la cotización en PDF y la manda por WhatsApp como documento; el embudo pasa a "Cotizado". */
function enviar_cotizacion(array $linea, array $contacto, array $pedido): array
{
    $archivo = \TuWhatsApp\Cotizacion::archivo(db(), $linea, $pedido);
    $r = (new Mensajeria(db()))->medio($linea, $contacto, $archivo, 'Cotización N.º ' . $pedido['numero'] . ' · ' . $linea['nombre'], 'panel', (int) usuario()['id']);
    if ($r['ok']) {
        Contactos::callar(db(), $linea, (int) $contacto['id']);
        if (in_array($contacto['etapa'] ?? null, [null, 'nuevo', 'interesado'], true) && $contacto['tipo'] !== 'personal') {
            \TuWhatsApp\Embudo::mover(db(), (int) $linea['id'], (int) $contacto['id'], 'cotizado', number_format((float) $pedido['total'], 2, '.', ''));
        }
    }
    return $r;
}

/** Aviso del resultado de un envío por el sistema. */
function aviso_envio(array $r, string $que = 'Enviado'): void
{
    if ($r['ok']) {
        aviso($que . ($r['costo'] > 0 ? ' (' . dinero($r['costo'], true) . ')' : ' (gratis)') . '.');
    } else {
        aviso($r['error'], 'error');
    }
}

try {
    switch ($ruta) {

    case 'login':
        if (usuario()) {
            redirigir('bandeja');
        }
        $error = null;
        $hayUsuarios = (int) db()->query('SELECT count(*) FROM usuarios')->fetchColumn() > 0;
        if ($post) {
            $ip = ip_cliente();
            db()->prepare("DELETE FROM intentos_login WHERE creado < now() - interval '1 day'")->execute();
            $st = db()->prepare("SELECT count(*) FROM intentos_login WHERE ip = ? AND creado > now() - interval '15 minutes'");
            $st->execute([$ip]);
            if ((int) $st->fetchColumn() >= 10) {
                $error = 'Demasiados intentos. Espera 15 minutos.';
            } else {
                $st = db()->prepare('SELECT id, clave, super FROM usuarios WHERE lower(correo) = lower(?) AND activo');
                $st->execute([trim((string) ($_POST['correo'] ?? ''))]);
                $f = $st->fetch();
                if ($f && $f['super'] && \TuWhatsApp\TukanCentral::soloCentral()) {
                    vista('login', 'Entrar', ['error' => 'El personal de Tukán entra solo desde el Panel Tukán Central.', 'hayUsuarios' => true]);
                }
                if ($f && password_verify((string) ($_POST['clave'] ?? ''), $f['clave'])) {
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int) $f['id'];
                    redirigir('bandeja');
                }
                db()->prepare('INSERT INTO intentos_login (ip) VALUES (?)')->execute([$ip]);
                $error = 'Correo o contraseña incorrectos.';
            }
        }
        vista('login', 'Entrar', ['error' => $error, 'hayUsuarios' => $hayUsuarios]);

    case 'salir':
        $_SESSION = [];
        session_destroy();
        redirigir('login');

    case 'cambiar_linea':
        if ($post && Lineas::buscar(db(), (int) ($_POST['linea_id'] ?? 0), (int) empresa_actual()['id'])) {
            $_SESSION['linea_id'] = (int) $_POST['linea_id'];
        }
        redirigir('bandeja');

    case 'nuevos':   // para que la bandeja se actualice sola
        header('Content-Type: application/json');
        $st = db()->prepare('SELECT count(*), COALESCE(max(id), 0) FROM mensajes WHERE linea_id = ? AND NOT leido');
        $st->execute([$lid]);
        [$n, $ultimo] = $st->fetch(PDO::FETCH_NUM);
        $st = db()->prepare('SELECT COALESCE(max(id), 0) FROM mensajes WHERE linea_id = ?' . (isset($_GET['c']) ? ' AND contacto_id = ' . (int) $_GET['c'] : ''));
        $st->execute([$lid]);
        $ultimoChat = (int) $st->fetchColumn();
        // El último mensaje sin leer que llegó (para el aviso del navegador)
        $st = db()->prepare("SELECT m.id, m.contacto_id, m.texto, c.nombre, c.nombre_whatsapp, c.telefono FROM mensajes m JOIN contactos c ON c.id = m.contacto_id
                             WHERE m.linea_id = ? AND m.direccion = 'entra' AND NOT m.leido ORDER BY m.id DESC LIMIT 1");
        $st->execute([$lid]);
        $ult = $st->fetch();
        echo json_encode(['sin_leer' => (int) $n, 'ultimo' => $ultimoChat, 'aviso' => $ult ? [
            'id' => (int) $ult['id'], 'nombre' => Contactos::nombre($ult), 'texto' => mb_strimwidth((string) $ult['texto'], 0, 90, '…'),
            'url' => url('chat', ['c' => $ult['contacto_id']]),
        ] : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

    case 'bandeja':
        $filtro = (string) ($_GET['f'] ?? 'todos');
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('bandeja', 'Bandeja', [
            'linea' => $linea,
            'filtro' => $filtro,
            'buscar' => $buscar,
            'conversaciones' => Contactos::bandeja(db(), $lid, $filtro, $buscar, 100, (int) usuario()['id']),
        ]);

    case 'nuevo_chat':
        if ($post) {
            $c = Contactos::guardar(db(), $lid, (string) ($_POST['telefono'] ?? ''), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['tipo'] ?? 'cliente'));
            redirigir('chat', ['c' => $c['id']]);
        }
        redirigir('contactos');

    case 'chat':
        $c = Contactos::buscar(db(), $lid, (int) ($_GET['c'] ?? 0));
        if (!$c) {
            aviso('Esa conversación no existe.', 'error');
            redirigir('bandeja');
        }
        $cid = (int) $c['id'];
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'pedir_llamada') {
                aviso_envio(\TuWhatsApp\Llamadas::pedirPermiso(db(), $linea, $c, (int) usuario()['id']), 'Pedido de permiso para llamar enviado');
            } elseif ($accion === 'tipo') {
                Contactos::cambiarTipo(db(), $lid, $cid, (string) ($_POST['tipo'] ?? ''));
                aviso('Ahora es: ' . tipo_contacto((string) $_POST['tipo']) . '.');
            } elseif ($accion === 'asignar' && modulo('agentes')) {
                $quien = (string) ($_POST['usuario_id'] ?? '');
                $uid = $quien === 'yo' ? (int) usuario()['id'] : ($quien === '' ? null : (int) $quien);
                Contactos::asignar(db(), $lid, $cid, $uid, (int) empresa_actual()['id']);
                aviso($uid === null ? 'La conversación quedó sin asignar.' : ($uid === (int) usuario()['id'] ? 'Ahora atiendes tú esta conversación.' : 'Conversación asignada.'));
            } elseif ($accion === 'marketing') {
                \TuWhatsApp\Campanias::marcarMarketing(db(), $lid, $cid, (string) ($_POST['marketing'] ?? ''));
                aviso('Guardado.');
            } elseif ($accion === 'datos') {
                Contactos::guardar(db(), $lid, (string) $c['telefono'], (string) ($_POST['nombre'] ?? ''), (string) $c['tipo'], (string) ($_POST['notas'] ?? ''));
                Contactos::guardarEtiquetas(db(), $lid, $cid, (string) ($_POST['etiquetas'] ?? ''));
                if (isset($_POST['campo']) && is_array($_POST['campo'])) {
                    \TuWhatsApp\Campos::guardarValores(db(), $lid, $cid, $_POST['campo']);
                }
                aviso('Datos guardados.');
            } elseif ($accion === 'silencio') {
                $horas = (int) ($_POST['horas'] ?? 0);
                db()->prepare('UPDATE contactos SET silencio_hasta = ? WHERE id = ?')
                    ->execute([$horas > 0 ? (new DateTimeImmutable("+$horas hours"))->format('c') : null, $cid]);
                aviso($horas > 0 ? "No se le contestará solo durante $horas horas." : 'Se volvió a activar la respuesta automática para esta persona.');
            } elseif ($accion === 'enviar') {
                $adjunto = $_FILES['archivo'] ?? null;
                if (is_array($adjunto) && ($adjunto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $archivo = \TuWhatsApp\Archivos::deSubida($adjunto, (int) $linea['empresa_id']);
                        $r = (new Mensajeria(db()))->medio($linea, $c, $archivo, (string) ($_POST['texto'] ?? ''), 'panel', (int) usuario()['id']);
                    } catch (\RuntimeException $ex) {
                        $r = ['ok' => false, 'error' => $ex->getMessage()];
                    }
                } else {
                    $r = (new Mensajeria(db()))->texto($linea, $c, (string) ($_POST['texto'] ?? ''), 'panel', (int) usuario()['id']);
                }
                if ($r['ok']) {
                    Contactos::tomarSiLibre(db(), $cid, (int) usuario()['id']);
                    Contactos::callar(db(), $linea, $cid);
                    aviso('Enviado' . ($r['costo'] > 0 ? ' (' . dinero($r['costo'], true) . ')' : ' (gratis)') . '.');
                } else {
                    $_SESSION['borrador'][$cid] = (string) ($_POST['texto'] ?? '');
                    aviso($r['error'], 'error');
                }
            } elseif ($accion === 'pendiente') {
                Pendientes::agregar(db(), $lid, (string) $c['telefono'], Contactos::nombre($c), (string) ($_POST['texto'] ?? ''), 'chat');
                aviso('Agregado a Pendientes.');
            } elseif ($accion === 'etapa' && modulo('embudo')) {
                \TuWhatsApp\Embudo::mover(db(), $lid, $cid, (string) ($_POST['etapa'] ?? ''), (string) ($_POST['valor'] ?? '0'));
                aviso('Embudo actualizado.');
            } elseif ($accion === 'seguimiento' && modulo('seguimientos')) {
                $cuando = \TuWhatsApp\Seguimientos::cuando((string) ($_POST['cuando'] ?? '') === 'fecha' ? (string) ($_POST['fecha'] ?? '') : (string) ($_POST['cuando'] ?? ''), new DateTimeImmutable());
                \TuWhatsApp\Seguimientos::programar(db(), $lid, $cid, (int) usuario()['id'], (string) ($_POST['texto_seguimiento'] ?? ''), $cuando, isset($_POST['solo_sin_respuesta']));
                aviso('Seguimiento programado para el ' . $cuando->format('d/m/Y \a \l\a\s H:i') . '.');
            } elseif ($accion === 'cancelar_seguimiento' && modulo('seguimientos')) {
                aviso(\TuWhatsApp\Seguimientos::cancelar(db(), $lid, (int) ($_POST['id'] ?? 0)) ? 'Seguimiento cancelado.' : 'Ese seguimiento ya no se puede cancelar.', 'info');
            } elseif ($accion === 'carrito_agregar') {
                $car = \TuWhatsApp\Pedidos::agregar(db(), $linea, $cid, (int) ($_POST['producto_id'] ?? 0), (int) ($_POST['cantidad'] ?? 1), 'chat', (int) usuario()['id']);
                aviso('Agregado al carrito. Total ' . dinero($car['total']) . '.');
            } elseif (in_array($accion, ['carrito_resumen', 'carrito_cotizacion'], true)) {
                $car = \TuWhatsApp\Pedidos::carrito(db(), $linea, $cid, false);
                if (!$car || !\TuWhatsApp\Pedidos::items(db(), (int) $car['id'])) {
                    throw new RuntimeException('El carrito está vacío.');
                }
                aviso_envio($accion === 'carrito_resumen' ? enviar_resumen($linea, $c, $car) : enviar_cotizacion($linea, $c, $car),
                            $accion === 'carrito_resumen' ? 'Resumen enviado' : 'Cotización enviada');
            } elseif ($accion === 'enviar_producto') {
                $p = \TuWhatsApp\Catalogo::buscar(db(), $lid, (int) ($_POST['producto_id'] ?? 0));
                if (!$p) {
                    throw new RuntimeException('Ese producto no existe.');
                }
                $ficha = \TuWhatsApp\Catalogo::ficha($p, $linea);
                $foto = $p['foto_ruta'] ? \TuWhatsApp\Archivos::ruta((string) $p['foto_ruta']) : null;
                $r = $foto
                    ? (new Mensajeria(db()))->medio($linea, $c, ['tipo' => 'imagen', 'mime' => str_ends_with($foto, '.png') ? 'image/png' : 'image/jpeg',
                        'nombre' => basename($foto), 'bytes' => (int) filesize($foto), 'ruta' => (string) $p['foto_ruta']], $ficha, 'panel', (int) usuario()['id'])
                    : (new Mensajeria(db()))->texto($linea, $c, $ficha, 'panel', (int) usuario()['id']);
                if ($r['ok']) {
                    Contactos::callar(db(), $linea, $cid);
                }
                aviso_envio($r, 'Producto enviado');
            } elseif ($accion === 'enviar_catalogo') {
                $prods = \TuWhatsApp\Catalogo::lista(db(), $lid, true, '', 10);
                if (!$prods) {
                    throw new RuntimeException('No hay productos disponibles en el catálogo.');
                }
                $r = (new Mensajeria(db()))->botones($linea, $c, 'Estos son nuestros productos. Toca uno para agregarlo a tu carrito 👇',
                                                    \TuWhatsApp\Catalogo::filasLista($prods, $linea), 'panel', (int) usuario()['id'], 'Ver productos', true);
                aviso_envio($r, 'Catálogo enviado');
            } elseif (in_array($accion, ['ia_sugerir', 'ia_resumir'], true) && modulo('ia')) {
                $r = $accion === 'ia_sugerir'
                    ? \TuWhatsApp\Asistente::sugerir(db(), $linea, $c, (int) usuario()['id'])
                    : \TuWhatsApp\Asistente::resumir(db(), $linea, $c, (int) usuario()['id']);
                if (!$r['ok']) {
                    aviso($r['error'], 'error');
                } elseif ($accion === 'ia_sugerir') {
                    $_SESSION['borrador'][$cid] = $r['texto'];
                    aviso('La IA dejó un borrador en la caja de texto: revísalo y cámbialo antes de enviar.', 'info');
                } else {
                    $_SESSION['ia_resumen'][$cid] = $r['texto'];
                }
            }
            redirigir('chat', ['c' => $cid]);
        }
        db()->prepare('UPDATE mensajes SET leido = true WHERE contacto_id = ? AND NOT leido')->execute([$cid]);
        $st = db()->prepare('SELECT * FROM (SELECT * FROM mensajes WHERE contacto_id = ? ORDER BY creado DESC, id DESC LIMIT 200) x ORDER BY creado, id');
        $st->execute([$cid]);
        $borrador = $_SESSION['borrador'][$cid] ?? '';
        unset($_SESSION['borrador'][$cid]);
        $resumenIa = $_SESSION['ia_resumen'][$cid] ?? '';
        unset($_SESSION['ia_resumen'][$cid]);
        vista('chat', Contactos::nombre($c), [
            'linea' => $linea, 'c' => $c, 'mensajes' => $st->fetchAll(), 'borrador' => $borrador,
            'equipo' => array_values(array_filter(\TuWhatsApp\Empresas::usuarios(db(), (int) empresa_actual()['id']), fn ($x) => $x['activo'])),
            'respuestas' => \TuWhatsApp\Respuestas::lista(db(), $lid),
            'ventana' => Gasto::ventanaAbierta($c, new DateTimeImmutable()),
            'precio' => Gasto::estimar(db(), 'servicio', $c, new DateTimeImmutable()),
            'seguimientos' => modulo('seguimientos') ? \TuWhatsApp\Seguimientos::deContacto(db(), $cid) : [],
            'llamadas' => \TuWhatsApp\Llamadas::lista(db(), $lid, $cid, 3),
            'resumenIa' => $resumenIa,
            'productos' => \TuWhatsApp\Catalogo::lista(db(), $lid, true, '', 300),
            'carrito' => $car = \TuWhatsApp\Pedidos::carrito(db(), $linea, $cid, false),
            'itemsCarrito' => $car ? \TuWhatsApp\Pedidos::items(db(), (int) $car['id']) : [],
            'pedidosCliente' => \TuWhatsApp\Pedidos::deContacto(db(), $cid),
            'campos' => \TuWhatsApp\Campos::lista(db(), $lid),
        ]);

    case 'plantilla':
        $c = Contactos::buscar(db(), $lid, (int) ($_GET['c'] ?? 0));
        if (!$c) {
            redirigir('bandeja');
        }
        $r = Meta::deLinea($linea)->plantillas();
        $lista = $r['ok'] ? $r['plantillas'] : [];
        if ($post) {
            $elegida = null;
            foreach ($lista as $p) {
                if ($p['nombre'] . '|' . $p['idioma'] === (string) ($_POST['plantilla'] ?? '')) {
                    $elegida = $p;
                }
            }
            if (!$elegida) {
                aviso('Elige una plantilla.', 'error');
            } else {
                $datos = array_slice(array_map('strval', (array) ($_POST['dato'] ?? [])), 0, $elegida['espacios']);
                while (count($datos) < $elegida['espacios']) {
                    $datos[] = '';
                }
                $res = (new Mensajeria(db()))->plantilla($linea, $c, $elegida, $datos, 'panel', (int) usuario()['id']);
                if ($res['ok']) {
                    Contactos::callar(db(), $linea, (int) $c['id']);
                    aviso('Plantilla enviada' . ($res['costo'] > 0 ? ' (' . dinero($res['costo'], true) . ')' : ' (gratis)') . '.');
                    redirigir('chat', ['c' => $c['id']]);
                }
                aviso($res['error'], 'error');
            }
            redirigir('plantilla', ['c' => $c['id']]);
        }
        vista('plantilla', 'Enviar plantilla', [
            'linea' => $linea, 'c' => $c, 'plantillas' => $lista, 'errorMeta' => $r['ok'] ? null : $r['error'],
            'precios' => Gasto::precios(db()),
        ]);

    case 'plantillas':
        $meta = Meta::deLinea($linea);
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'crear') {
                $_SESSION['form_plantilla'] = $_POST;
                $datos = \TuWhatsApp\Plantillas::armar($_POST);
                $r = $meta->crearPlantilla($datos);
                if ($r['ok']) {
                    unset($_SESSION['form_plantilla']);
                    aviso('Plantilla "' . $datos['name'] . '" enviada a Meta. ' . ($r['estado'] === 'APPROVED'
                        ? 'Ya está aprobada y lista para usar.'
                        : 'Meta la revisa: normalmente tarda de unos minutos a 24 horas. Aquí verás si la aprueban.'));
                } else {
                    aviso('Meta no aceptó la plantilla: ' . $r['error'], 'error');
                }
            } elseif ($accion === 'borrar') {
                $nombre = (string) ($_POST['nombre'] ?? '');
                $r = $meta->borrarPlantilla($nombre);
                aviso($r['ok'] ? 'Plantilla "' . $nombre . '" borrada.' : 'No se pudo borrar: ' . $r['error'], $r['ok'] ? 'ok' : 'error');
            }
            redirigir('plantillas', array_filter(['c' => $_GET['c'] ?? null]));
        }
        $r = $meta->todasLasPlantillas();
        $form = $_SESSION['form_plantilla'] ?? [];
        unset($_SESSION['form_plantilla']);
        vista('plantillas', 'Plantillas', [
            'linea' => $linea, 'plantillas' => $r['ok'] ? $r['plantillas'] : [], 'errorMeta' => $r['ok'] ? null : $r['error'],
            'precios' => Gasto::precios(db()), 'form' => $form,
            'volverChat' => isset($_GET['c']) ? Contactos::buscar(db(), $lid, (int) $_GET['c']) : null,
        ]);

    case 'bloqueada':
        $e = empresa_actual();
        if (es_super() || Empresas::alDia($e)) {
            redirigir('bandeja');
        }
        vista('bloqueada', 'Cuenta no disponible', ['linea' => null, 'empresa' => $e]);

    case 'aceptar':
        $e = empresa_actual();
        if (es_super() || \TuWhatsApp\Terminos::aceptados($e)) {
            redirigir('bandeja');
        }
        if ($post) {
            if (!es_admin()) {
                throw new RuntimeException('Solo el administrador de la cuenta puede aceptar.');
            }
            if (empty($_POST['acepto'])) {
                throw new RuntimeException('Marca la casilla para aceptar los términos.');
            }
            \TuWhatsApp\Terminos::aceptar(db(), (int) $e['id'], (int) usuario()['id'], ip_cliente());
            aviso('¡Listo! Bienvenido a WhatsApp Tukán.');
            redirigir('bandeja');
        }
        vista('aceptar', 'Términos y condiciones', ['linea' => null, 'empresa' => $e]);

    case 'cuenta':
        $e = empresa_actual();
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'mi_clave') {
                Empresas::cambiarMiClave(db(), (int) usuario()['id'], (string) ($_POST['actual'] ?? ''), (string) ($_POST['nueva'] ?? ''), (string) ($_POST['repetir'] ?? ''));
                aviso('Tu contraseña se cambió.');
            } elseif (!es_admin()) {
                throw new RuntimeException('Solo el administrador de la cuenta puede cambiar eso.');
            } elseif ($accion === 'agregar_usuario') {
                $clave = Empresas::agregarUsuario(db(), (int) $e['id'], (string) ($_POST['correo'] ?? ''), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['rol'] ?? 'agente'));
                $_SESSION['clave_mostrar'] = ['correo' => mb_strtolower(trim((string) $_POST['correo'])), 'clave' => $clave];
                aviso('Usuario creado. Pásale su correo y su contraseña (se muestra una sola vez).');
            } elseif ($accion === 'usuario') {
                $clave = Empresas::cambiarUsuario(db(), (int) $e['id'], (int) ($_POST['id'] ?? 0), (string) ($_POST['que'] ?? ''), (int) usuario()['id']);
                if ($clave !== null) {
                    $st = db()->prepare('SELECT correo FROM usuarios WHERE id = ?');
                    $st->execute([(int) $_POST['id']]);
                    $_SESSION['clave_mostrar'] = ['correo' => (string) $st->fetchColumn(), 'clave' => $clave];
                    aviso('Contraseña nueva creada (se muestra una sola vez).');
                } else {
                    aviso('Cambiado.');
                }
            } elseif ($accion === 'token_api') {
                $_SESSION['token_mostrar'] = Empresas::nuevoTokenApi(db(), (int) $e['id']);
                aviso('Token de API nuevo creado. El anterior ya no sirve.');
            }
            redirigir('cuenta');
        }
        $clave = $_SESSION['clave_mostrar'] ?? null;
        $token = $_SESSION['token_mostrar'] ?? null;
        unset($_SESSION['clave_mostrar'], $_SESSION['token_mostrar']);
        vista('cuenta', 'Mi cuenta', ['empresa' => $e, 'usuarios' => Empresas::usuarios(db(), (int) $e['id']),
            'claveNueva' => $clave, 'tokenNuevo' => $token, 'numeros' => count(Lineas::todas(db(), (int) $e['id']))]);

    case 'clientes':
        if ($post) {
            $r = Empresas::crear(db(), $_POST);
            $_SESSION['clave_mostrar'] = ['correo' => mb_strtolower(trim((string) $_POST['admin_correo'])), 'clave' => $r['clave'], 'empresa' => (string) $_POST['nombre']];
            aviso('Cliente creado.');
            redirigir('cliente', ['id' => $r['empresa_id']]);
        }
        vista('clientes', 'Clientes de Tukán', ['empresas' => Empresas::lista(db())]);

    case 'cliente':
        $e = Empresas::buscar(db(), (int) ($_GET['id'] ?? 0));
        if (!$e) {
            aviso('Ese cliente no existe.', 'error');
            redirigir('clientes');
        }
        if ($post) {
            if (($_POST['accion'] ?? '') === 'agregar_usuario') {
                $clave = Empresas::agregarUsuario(db(), (int) $e['id'], (string) ($_POST['correo'] ?? ''), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['rol'] ?? 'admin'));
                $_SESSION['clave_mostrar'] = ['correo' => mb_strtolower(trim((string) $_POST['correo'])), 'clave' => $clave];
                aviso('Usuario creado.');
            } elseif (($_POST['accion'] ?? '') === 'usuario') {
                $clave = Empresas::cambiarUsuario(db(), (int) $e['id'], (int) ($_POST['id'] ?? 0), (string) ($_POST['que'] ?? ''), (int) usuario()['id']);
                if ($clave !== null) {
                    $st = db()->prepare('SELECT correo FROM usuarios WHERE id = ?');
                    $st->execute([(int) $_POST['id']]);
                    $_SESSION['clave_mostrar'] = ['correo' => (string) $st->fetchColumn(), 'clave' => $clave];
                }
                aviso('Cambiado.');
            } elseif (str_starts_with((string) ($_POST['accion'] ?? ''), 'plan:')) {
                $plan = \TuWhatsApp\Modulos::aplicarPlan(db(), (int) $e['id'], substr((string) $_POST['accion'], 5));
                aviso('Plan ' . $plan[0] . ' aplicado: módulos, ' . $plan[2] . ' número(s) y ' . $plan[3] . ' usuarios como mínimo.');
            } elseif (($_POST['accion'] ?? '') === 'modulos') {
                \TuWhatsApp\Modulos::guardar(db(), (int) $e['id'], (array) ($_POST['modulos'] ?? []));
                aviso('Módulos guardados.');
            } else {
                Empresas::guardar(db(), (int) $e['id'], $_POST);
                aviso('Datos del cliente guardados.');
            }
            redirigir('cliente', ['id' => $e['id']]);
        }
        $clave = $_SESSION['clave_mostrar'] ?? null;
        unset($_SESSION['clave_mostrar']);
        $reg = db()->prepare('SELECT momento, quien, texto FROM tukan_central_registro WHERE empresa_id = ? ORDER BY momento DESC, id DESC LIMIT 10');
        $reg->execute([(int) $e['id']]);
        vista('cliente', $e['nombre'], ['empresa' => $e, 'usuarios' => Empresas::usuarios(db(), (int) $e['id']),
            'numeros' => Lineas::todas(db(), (int) $e['id']), 'claveNueva' => $clave, 'registroCentral' => $reg->fetchAll()]);

    case 'archivo':   // foto, audio, video o documento de un chat (solo de la cuenta que se está mirando)
        $st = db()->prepare('SELECT m.*, l.empresa_id FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE m.id = ?');
        $st->execute([(int) ($_GET['id'] ?? 0)]);
        $msg = $st->fetch();
        if (!$msg || (int) $msg['empresa_id'] !== (int) empresa_actual()['id'] || (!$msg['media_ruta'] && !$msg['media_id'])) {
            http_response_code(404);
            exit('No existe');
        }
        $ruta = $msg['media_ruta'] ? \TuWhatsApp\Archivos::ruta((string) $msg['media_ruta']) : null;
        if ($ruta === null) {
            $rel = \TuWhatsApp\Archivos::bajarDeMensaje(db(), $msg, Lineas::buscar(db(), (int) $msg['linea_id']));
            $ruta = $rel ? \TuWhatsApp\Archivos::ruta($rel) : null;
        }
        if ($ruta === null) {
            http_response_code(404);
            exit('El archivo ya no está disponible en Meta (pasaron más de 30 días o falló la descarga).');
        }
        $mime = (string) ($msg['media_mime'] ?: 'application/octet-stream');
        $enLinea = (bool) preg_match('#^(image/(jpeg|png|webp)|audio/|video/(mp4|3gpp)|application/pdf)#', $mime);
        $nombre = (string) ($msg['media_nombre'] ?: 'archivo-' . (int) $msg['id'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($enLinea ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: ' . ($enLinea && empty($_GET['bajar']) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($nombre));
        header('Cache-Control: private, max-age=86400');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
        readfile($ruta);
        exit;

    case 'mas':
        vista('mas', 'Más');

    case 'ver_cuenta':   // personal de Tukán entra a la cuenta de un cliente para dar soporte
        if ($post && ($e = Empresas::buscar(db(), (int) ($_POST['empresa_id'] ?? 0)))) {
            $_SESSION['empresa_vista'] = (int) $e['id'] === (int) usuario()['empresa_id'] ? null : (int) $e['id'];
            unset($_SESSION['linea_id']);
            aviso('Estás viendo la cuenta de ' . $e['nombre'] . '. Todo lo que hagas queda en su cuenta.', 'info');
        }
        redirigir('bandeja');

    case 'volver_tukan':
        unset($_SESSION['empresa_vista'], $_SESSION['linea_id']);
        redirigir(es_super() ? 'clientes' : 'bandeja');

    case 'campanias':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if (in_array($accion, ['pausar', 'reanudar', 'cancelar'], true)) {
                \TuWhatsApp\Campanias::cambiar(db(), $lid, (int) ($_POST['id'] ?? 0), $accion);
                aviso(['pausar' => 'Campaña pausada.', 'reanudar' => 'Campaña reanudada: sigue enviando en el próximo minuto.', 'cancelar' => 'Campaña cancelada. Lo que faltaba ya no se envía.'][$accion]);
                redirigir('campanias');
            }
            $aprobadas = Meta::deLinea($linea)->plantillas();
            if (!$aprobadas['ok']) {
                throw new RuntimeException($aprobadas['error']);
            }
            $form = $accion === 'lanzar' ? ($_SESSION['campania_form'] ?? []) : $_POST;
            $_SESSION['campania_form'] = $form;
            $prep = \TuWhatsApp\Campanias::preparar(db(), $linea, $form, $aprobadas['plantillas']);
            if ($accion === 'lanzar') {
                if ((int) ($_POST['total'] ?? -1) !== $prep['total']) {
                    aviso('La lista de contactos cambió mientras revisabas: revisa otra vez antes de enviar.', 'info');
                } else {
                    $id = \TuWhatsApp\Campanias::crear(db(), $linea, $prep, (int) usuario()['id']);
                    unset($_SESSION['campania_form']);
                    aviso('Campaña creada para ' . $prep['total'] . ' contactos. Empieza a enviarse en el próximo minuto (' . $prep['por_minuto'] . ' por minuto).');
                    redirigir('campanias');
                }
            }
            vista('campania_revisar', 'Revisar campaña', ['linea' => $linea, 'prep' => $prep]);
        }
        $aprobadas = Meta::deLinea($linea)->plantillas();
        vista('campanias', 'Campañas', [
            'linea' => $linea, 'campanias' => \TuWhatsApp\Campanias::lista(db(), $lid),
            'plantillas' => $aprobadas['ok'] ? $aprobadas['plantillas'] : [], 'errorMeta' => $aprobadas['ok'] ? null : $aprobadas['error'],
            'etiquetas' => Contactos::etiquetasUsadas(db(), $lid), 'form' => $_SESSION['campania_form'] ?? [],
            'cuentas' => [
                'acepta' => \TuWhatsApp\Campanias::contar(db(), $lid, 'cliente_desconocido', '', true),
                'clientes' => \TuWhatsApp\Campanias::contar(db(), $lid, 'cliente', '', false),
            ],
        ]);

    case 'respuestas':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'borrar') {
                \TuWhatsApp\Respuestas::borrar(db(), $lid, (int) ($_POST['id'] ?? 0));
                aviso('Respuesta borrada.');
            } else {
                \TuWhatsApp\Respuestas::guardar(db(), $lid, (string) ($_POST['atajo'] ?? ''), (string) ($_POST['texto'] ?? ''), isset($_POST['id']) ? (int) $_POST['id'] : null);
                aviso('Respuesta guardada.');
            }
            redirigir('respuestas');
        }
        vista('respuestas', 'Respuestas rápidas', ['linea' => $linea, 'respuestas' => \TuWhatsApp\Respuestas::lista(db(), $lid)]);

    case 'embudo':
        if ($post) {
            \TuWhatsApp\Embudo::mover(db(), $lid, (int) ($_POST['c'] ?? 0), (string) ($_POST['etapa'] ?? ''), isset($_POST['valor']) ? (string) $_POST['valor'] : null);
            aviso('Movido a ' . (\TuWhatsApp\Embudo::ETAPAS[$_POST['etapa'] ?? ''] ?? 'fuera del embudo') . '.');
            redirigir('embudo', array_filter(['b' => (string) ($_GET['b'] ?? '')]));
        }
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('embudo', 'Embudo', [
            'linea' => $linea, 'buscar' => $buscar,
            'tablero' => \TuWhatsApp\Embudo::tablero(db(), $lid, $buscar), 'totales' => \TuWhatsApp\Embudo::totales(db(), $lid),
        ]);

    case 'seguimientos':
        if ($post) {
            aviso(\TuWhatsApp\Seguimientos::cancelar(db(), $lid, (int) ($_POST['id'] ?? 0)) ? 'Seguimiento cancelado.' : 'Ese seguimiento ya no se puede cancelar.', 'info');
            redirigir('seguimientos');
        }
        vista('seguimientos', 'Seguimientos', [
            'linea' => $linea, 'programados' => \TuWhatsApp\Seguimientos::lista(db(), $lid, true), 'hechos' => \TuWhatsApp\Seguimientos::lista(db(), $lid, false, 30),
        ]);

    case 'reportes':
        $rep = \TuWhatsApp\Reportes::resumen(db(), $lid, (int) ($_GET['d'] ?? 7));
        $st = db()->prepare("SELECT count(*), COALESCE(sum(total), 0) FROM pedidos WHERE linea_id = ? AND estado IN ('pagado', 'enviado', 'entregado') AND pagado_en >= ?");
        $st->execute([$lid, $rep['desde']->format('Y-m-d')]);
        [$np, $tp] = $st->fetch(PDO::FETCH_NUM);
        vista('reportes', 'Reportes', ['linea' => $linea, 'r' => $rep, 'anuncios' => \TuWhatsApp\Anuncios::reporte(db(), $lid, $rep['dias']),
                                       'pedidosRep' => ['n' => (int) $np, 'total' => (float) $tp]]);

    case 'ia':
        $emp = empresa_actual();
        if ($post) {
            if (($_POST['accion'] ?? '') === 'bot') {
                \TuWhatsApp\Bot::guardar(db(), $lid, $_POST);
                aviso(!empty($_POST['bot_activo']) ? 'Agente con IA encendido: desde ahora contesta solo.' : 'Agente con IA guardado (apagado).');
            } else {
                \TuWhatsApp\Asistente::guardarConocimiento(db(), $lid, (string) ($_POST['conocimiento'] ?? ''));
                if (isset($_POST['ia_tono'])) {
                    db()->prepare('UPDATE lineas SET ia_tono = ? WHERE id = ?')->execute([mb_substr(trim((string) $_POST['ia_tono']), 0, 300), $lid]);
                }
                aviso('Guardado. La IA usará esta información para contestar y sugerir respuestas.');
            }
            redirigir('ia');
        }
        vista('ia', 'Agente con IA', [
            'linea' => $linea, 'disponible' => \TuWhatsApp\Asistente::disponible(),
            'gastado' => \TuWhatsApp\Asistente::gastadoMes(db(), (int) $emp['id']), 'tope' => (float) ($emp['ia_tope'] ?? 3),
        ]);

    case 'asistente':   // "Pregúntale a la IA" (como Wati AI) y el resumen de cada mañana
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            $soloAdm = function (): void {
                if (!es_admin()) {
                    aviso('Solo el administrador de la cuenta puede hacer eso.', 'error');
                    redirigir('asistente');
                }
            };
            if ($accion === 'preguntar') {
                $q = \TuWhatsApp\Copiloto::preguntar(db(), $linea, (string) ($_POST['pregunta'] ?? ''), (int) usuario()['id']);
                redirigir('asistente', ['q' => $q['id']]);
            } elseif ($accion === 'etiqueta') {
                $soloAdm();
                $et = (string) ($_POST['etiqueta'] ?? '');
                $n = \TuWhatsApp\Copiloto::guardarEtiqueta(db(), $linea, (int) ($_POST['q'] ?? 0), $et);
                aviso($n . ' clientes guardados con la etiqueta "' . \TuWhatsApp\Copiloto::etiqueta($et) . '". En Campañas elige esa etiqueta para escribirles.');
                redirigir('asistente', ['q' => (int) ($_POST['q'] ?? 0)]);
            } elseif ($accion === 'campania') {
                $soloAdm();
                $cq = \TuWhatsApp\Copiloto::consulta(db(), $lid, (int) ($_POST['q'] ?? 0));
                if (!$cq || empty($cq['campania'])) {
                    throw new RuntimeException('Esa respuesta no trae una campaña.');
                }
                if (!empty($cq['contactos']) && !empty($cq['etiqueta'])) {
                    \TuWhatsApp\Copiloto::guardarEtiqueta(db(), $linea, (int) $cq['id'], (string) $cq['etiqueta']);
                    $_SESSION['campania_form'] = ['filtro_etiqueta' => $cq['etiqueta']];
                }
                $_SESSION['form_plantilla'] = ['nombre' => $cq['campania']['nombre'], 'categoria' => $cq['campania']['categoria'], 'idioma' => 'es', 'cuerpo' => $cq['campania']['texto']];
                aviso('La IA dejó lista la plantilla: revísala y tócala en "Crear plantilla". Cuando Meta la apruebe, ve a Campañas'
                      . (!empty($cq['etiqueta']) ? ' (ya está elegida la etiqueta "' . $cq['etiqueta'] . '")' : '') . '.', 'info');
                redirigir('plantillas');
            } elseif ($accion === 'resumen') {
                \TuWhatsApp\Copiloto::hacerResumen(db(), $linea);
                aviso('Resumen de hoy listo.');
                redirigir('asistente');
            } elseif ($accion === 'ajustes') {
                $soloAdm();
                $hora = (string) ($_POST['resumen_hora'] ?? '07:30');
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                    throw new RuntimeException('Escribe la hora así: 07:30.');
                }
                db()->prepare('UPDATE lineas SET resumen_diario = ?, resumen_hora = ? WHERE id = ?')->execute([!empty($_POST['resumen_diario']) ? 't' : 'f', $hora, $lid]);
                aviso(!empty($_POST['resumen_diario']) ? 'Listo: cada día a las ' . $hora . ' te llega el resumen al celular.' : 'Resumen de cada mañana apagado.');
                redirigir('asistente');
            }
            redirigir('asistente');
        }
        vista('asistente', 'Pregúntale a la IA', [
            'linea' => $linea, 'consulta' => isset($_GET['q']) ? \TuWhatsApp\Copiloto::consulta(db(), $lid, (int) $_GET['q']) : null,
            'historial' => \TuWhatsApp\Copiloto::historial(db(), $lid), 'resumen' => \TuWhatsApp\Copiloto::resumenDeHoy(db(), $lid),
        ]);

    case 'enlaces':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'borrar') {
                \TuWhatsApp\Enlaces::borrar(db(), $lid, (int) ($_POST['id'] ?? 0));
                aviso('Enlace borrado.');
            } else {
                \TuWhatsApp\Enlaces::crear(db(), $lid, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['texto'] ?? ''));
                aviso('Enlace creado.');
            }
            redirigir('enlaces');
        }
        vista('enlaces', 'Enlaces y QR', ['linea' => $linea, 'enlaces' => \TuWhatsApp\Enlaces::lista(db(), $lid)]);

    case 'integraciones':
        $bajar = (string) ($_GET['bajar'] ?? '');
        if (in_array($bajar, ['contactos', 'mensajes'], true)) {
            $csv = \TuWhatsApp\Integraciones::csv(\TuWhatsApp\Integraciones::filas(db(), $lid, $bajar, (int) ($_GET['d'] ?? 30)));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $bajar . '-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
            echo $csv;
            exit;
        }
        $secreto = null;
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'probar') {
                $err = \TuWhatsApp\Integraciones::probar($linea);
                aviso($err === '' ? 'Aviso de prueba enviado: el otro programa respondió bien.' : 'No se pudo avisar: ' . $err, $err === '' ? 'ok' : 'error');
                redirigir('integraciones');
            }
            $secreto = \TuWhatsApp\Integraciones::guardarAviso(db(), $lid, (string) ($_POST['url'] ?? ''), $accion === 'secreto');
            aviso(trim((string) ($_POST['url'] ?? '')) === '' ? 'Aviso a otro programa apagado.' : 'Dirección guardada. Copia la clave secreta (se muestra una sola vez).');
            $linea = Lineas::buscar(db(), $lid);
        }
        vista('integraciones', 'Integraciones', ['linea' => $linea, 'secreto' => $secreto]);

    case 'catalogo':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'borrar') {
                \TuWhatsApp\Catalogo::borrar(db(), $lid, (int) ($_POST['id'] ?? 0));
                aviso('Producto borrado.');
            } elseif ($accion === 'importar') {
                $f = $_FILES['archivo'] ?? null;
                if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('No llegó el archivo (máximo 5 MB). Vuelve a elegirlo.');
                }
                $r = \TuWhatsApp\Catalogo::importarCsv(db(), $lid, (string) file_get_contents($f['tmp_name']));
                aviso("Listo: {$r['nuevos']} productos nuevos, {$r['cambiados']} actualizados" . ($r['malos'] ? ", {$r['malos']} filas con errores se saltaron" : '') . '.',
                      $r['nuevos'] + $r['cambiados'] > 0 ? 'ok' : 'info');
            } else {
                $id = \TuWhatsApp\Catalogo::guardar(db(), $lid, $_POST + ['activo' => null], isset($_POST['id']) ? (int) $_POST['id'] : null);
                $foto = $_FILES['foto'] ?? null;
                if (is_array($foto) && ($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $a = \TuWhatsApp\Archivos::deSubida($foto, (int) $linea['empresa_id']);
                    if ($a['tipo'] !== 'imagen') {
                        throw new RuntimeException('La foto debe ser JPG o PNG.');
                    }
                    \TuWhatsApp\Catalogo::guardarFoto(db(), $lid, $id, $a['ruta']);
                }
                aviso('Producto guardado.');
            }
            redirigir('catalogo');
        }
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('catalogo', 'Catálogo', ['linea' => $linea, 'buscar' => $buscar, 'productos' => \TuWhatsApp\Catalogo::lista(db(), $lid, false, $buscar),
                                       'editar' => isset($_GET['editar']) ? \TuWhatsApp\Catalogo::buscar(db(), $lid, (int) $_GET['editar']) : null]);

    case 'foto_producto':
        $p = \TuWhatsApp\Catalogo::buscar(db(), $lid, (int) ($_GET['id'] ?? 0));
        $ruta = $p && $p['foto_ruta'] ? \TuWhatsApp\Archivos::ruta((string) $p['foto_ruta']) : null;
        if ($ruta === null) {
            http_response_code(404);
            exit('No existe');
        }
        header('Content-Type: ' . (str_ends_with($ruta, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: private, max-age=3600');
        readfile($ruta);
        exit;

    case 'cobros':
        if ($post) {
            \TuWhatsApp\Plux::guardar(db(), $linea, $_POST);   // primero: si las credenciales de Plux están mal, no se guarda nada
            $iva = \TuWhatsApp\Embudo::valor((string) ($_POST['iva_pct'] ?? '0'));
            if ($iva > 30) {
                throw new RuntimeException('El IVA no puede ser más de 30 %.');
            }
            $enlace = trim((string) ($_POST['pago_enlace'] ?? ''));
            if ($enlace !== '' && !preg_match('#^https://\S+$#', $enlace)) {
                throw new RuntimeException('El enlace de pago debe empezar con https://');
            }
            db()->prepare('UPDATE lineas SET negocio_ruc = ?, negocio_direccion = ?, iva_pct = ?, cotizacion_dias = ?, cotizacion_pie = ?, pago_transferencia = ?, pago_enlace = ?, payphone_store = ? WHERE id = ?')
                ->execute([mb_substr(trim((string) ($_POST['negocio_ruc'] ?? '')), 0, 20), mb_substr(trim((string) ($_POST['negocio_direccion'] ?? '')), 0, 200), $iva,
                           max(1, min(365, (int) ($_POST['cotizacion_dias'] ?? 15))), mb_substr(trim((string) ($_POST['cotizacion_pie'] ?? '')), 0, 1000),
                           mb_substr(trim((string) ($_POST['pago_transferencia'] ?? '')), 0, 1000), mb_substr($enlace, 0, 500),
                           mb_substr(trim((string) ($_POST['payphone_store'] ?? '')), 0, 80), $lid]);
            $tok = trim((string) ($_POST['payphone_token'] ?? ''));
            if (!empty($_POST['quitar_payphone'])) {
                db()->prepare("UPDATE lineas SET payphone_token = '' WHERE id = ?")->execute([$lid]);
            } elseif ($tok !== '') {
                db()->prepare('UPDATE lineas SET payphone_token = ? WHERE id = ?')->execute([\TuWhatsApp\Cifrado::cifrar($tok), $lid]);
            }
            $cobro = (string) ($_POST['cobro_tarjeta'] ?? '');
            db()->prepare('UPDATE lineas SET cobro_tarjeta = ? WHERE id = ?')->execute([in_array($cobro, ['payphone', 'plux'], true) ? $cobro : '', $lid]);
            aviso('Guardado.');
            redirigir('cobros');
        }
        vista('cobros', 'Cobros y cotizaciones', ['linea' => $linea, 'pluxAviso' => \TuWhatsApp\Plux::aviso(db(), $linea)]);

    case 'pedidos':
        $estado = (string) ($_GET['e'] ?? '');
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('pedidos', 'Pedidos', ['linea' => $linea, 'estado' => $estado, 'buscar' => $buscar,
                                     'pedidos' => \TuWhatsApp\Pedidos::lista(db(), $lid, $estado, $buscar), 'cuentas' => \TuWhatsApp\Pedidos::cuentas(db(), $lid)]);

    case 'pedido':
    case 'cotizacion':
        $p = \TuWhatsApp\Pedidos::buscar(db(), $lid, (int) ($_GET['id'] ?? 0));
        if (!$p) {
            aviso('Ese pedido no existe.', 'error');
            redirigir('pedidos');
        }
        $pid = (int) $p['id'];
        if ($ruta === 'cotizacion') {
            $pdf = \TuWhatsApp\Cotizacion::pdf($linea, $p, \TuWhatsApp\Pedidos::items(db(), $pid));
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($pdf));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(\TuWhatsApp\Cotizacion::nombreArchivo($p)));
            header('Cache-Control: no-store');
            echo $pdf;
            exit;
        }
        $cont = $p['contacto_id'] ? Contactos::buscar(db(), $lid, (int) $p['contacto_id']) : null;
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'cantidad') {
                \TuWhatsApp\Pedidos::cambiarCantidad(db(), $linea, $pid, (int) ($_POST['item'] ?? 0), (int) ($_POST['cantidad'] ?? 0));
                aviso('Cantidad cambiada.');
            } elseif ($accion === 'agregar') {
                if (!$p['contacto_id']) {
                    throw new RuntimeException('Este pedido no tiene cliente.');
                }
                if ($p['estado'] !== 'carrito') {
                    throw new RuntimeException('Solo se agregan productos mientras es carrito.');
                }
                \TuWhatsApp\Pedidos::agregar(db(), $linea, (int) $p['contacto_id'], (int) ($_POST['producto_id'] ?? 0), (int) ($_POST['cantidad'] ?? 1), 'chat', (int) usuario()['id']);
                aviso('Agregado.');
            } elseif ($accion === 'libre') {
                \TuWhatsApp\Pedidos::agregarLibre(db(), $linea, $pid, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['precio'] ?? '0'));
                aviso('Agregado.');
            } elseif (in_array($accion, ['enviar_resumen', 'enviar_cotizacion'], true)) {
                if (!$cont) {
                    throw new RuntimeException('Este pedido no tiene un número de WhatsApp.');
                }
                if (!\TuWhatsApp\Pedidos::items(db(), $pid)) {
                    throw new RuntimeException('El pedido no tiene productos.');
                }
                aviso_envio($accion === 'enviar_resumen' ? enviar_resumen($linea, $cont, $p) : enviar_cotizacion($linea, $cont, $p),
                            $accion === 'enviar_resumen' ? 'Resumen enviado' : 'Cotización enviada');
            } elseif ($accion === 'estado') {
                $nuevo = (string) ($_POST['estado'] ?? '');
                $p = \TuWhatsApp\Pedidos::cambiarEstado(db(), $linea, $pid, $nuevo);
                if ($cont && $cont['tipo'] !== 'personal' && in_array($nuevo, ['pagado', 'enviado', 'entregado'], true)) {
                    \TuWhatsApp\Embudo::mover(db(), $lid, (int) $cont['id'], 'ganado', number_format((float) $p['total'], 2, '.', ''));
                }
                aviso('Pedido: ' . \TuWhatsApp\Pedidos::ESTADOS[$nuevo] . '.');
            } elseif ($accion === 'notas') {
                \TuWhatsApp\Pedidos::guardarNotas(db(), $lid, $pid, (string) ($_POST['notas'] ?? ''));
                aviso('Notas guardadas.');
            }
            redirigir('pedido', ['id' => $pid]);
        }
        vista('pedido', 'Pedido #' . $p['numero'], ['linea' => $linea, 'p' => $p, 'items' => \TuWhatsApp\Pedidos::items(db(), $pid),
            'productos' => \TuWhatsApp\Catalogo::lista(db(), $lid, true, '', 300), 'ventana' => $cont ? Gasto::ventanaAbierta($cont, new DateTimeImmutable()) : false]);

    case 'flujos':
        if ($post) {
            $id = \TuWhatsApp\Flujos::crear(db(), $lid, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['disparador'] ?? 'palabras'), (string) ($_POST['palabras'] ?? ''));
            aviso('Chatbot creado con un ejemplo. Cámbialo a tu gusto y enciéndelo.');
            redirigir('flujo', ['id' => $id]);
        }
        vista('flujos', 'Chatbots', ['linea' => $linea, 'flujos' => \TuWhatsApp\Flujos::lista(db(), $lid)]);

    case 'flujo':
        $f = \TuWhatsApp\Flujos::buscar(db(), $lid, (int) ($_GET['id'] ?? 0));
        if (!$f) {
            aviso('Ese chatbot no existe.', 'error');
            redirigir('flujos');
        }
        $camposF = \TuWhatsApp\Campos::lista(db(), $lid);
        $prueba = null;
        $entradas = '';
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? 'guardar');
            if ($accion === 'borrar') {
                \TuWhatsApp\Flujos::borrar(db(), $lid, (int) $f['id']);
                aviso('Chatbot borrado.');
                redirigir('flujos');
            }
            if ($accion === 'probar') {
                $entradas = (string) ($_POST['entradas'] ?? '');
                $lineasEntrada = array_values(array_filter(array_map('trim', preg_split('/\R/', $entradas) ?: []), fn ($x) => $x !== ''));
                $prueba = $lineasEntrada ? \TuWhatsApp\Flujos::simular($f, $lineasEntrada, (string) $linea['nombre']) : [];
            } else {
                $datos = $_POST;
                if ($accion === 'agregar_paso') {
                    $actuales = \TuWhatsApp\Flujos::limpiarPasos((array) ($datos['paso'] ?? []), array_column($camposF, 'clave'), false);
                    $datos['paso'] = array_merge(array_map(fn ($x) => $x + ['op_titulo' => array_column($x['opciones'] ?? [], 'titulo'), 'op_ir' => array_column($x['opciones'] ?? [], 'ir_a')], $actuales),
                                                 [['id' => \TuWhatsApp\Flujos::idNuevo($actuales), 'tipo' => 'mensaje', 'texto' => '']]);
                    $datos['activo'] = '';   // mientras se arma el paso nuevo, queda apagado
                }
                \TuWhatsApp\Flujos::guardar(db(), $lid, (int) $f['id'], $datos, array_column($camposF, 'clave'));
                aviso($accion === 'agregar_paso' ? 'Paso agregado al final. El chatbot quedó apagado mientras lo completas: enciéndelo al guardar.' : 'Chatbot guardado.');
                redirigir('flujo', ['id' => $f['id']]);
            }
        }
        vista('flujo', $f['nombre'], ['linea' => $linea, 'f' => $f, 'pasos' => \TuWhatsApp\Flujos::pasos($f), 'campos' => $camposF, 'prueba' => $prueba, 'entradas' => $entradas]);

    case 'equipo':
        $emp = empresa_actual();
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'reparto') {
                \TuWhatsApp\Reparto::guardar(db(), $linea, $_POST);
                aviso('Reparto guardado.');
            } elseif ($accion === 'persona') {
                \TuWhatsApp\Reparto::cambiarPersona(db(), (int) $emp['id'], (int) ($_POST['id'] ?? 0), (string) ($_POST['que'] ?? ''), ($_POST['valor'] ?? '') === '1');
                aviso('Cambiado.');
            } elseif ($accion === 'recibe') {
                $si = array_map('intval', (array) ($_POST['recibe'] ?? []));
                foreach (Empresas::usuarios(db(), (int) $emp['id']) as $u) {
                    \TuWhatsApp\Reparto::cambiarPersona(db(), (int) $emp['id'], (int) $u['id'], 'recibe_chats', in_array((int) $u['id'], $si, true));
                }
                aviso('Guardado.');
            } elseif ($accion === 'pasar') {
                $a = (string) ($_POST['a'] ?? '') === '' ? null : (int) $_POST['a'];
                $n = \TuWhatsApp\Reparto::pasarTodas(db(), $lid, (int) $emp['id'], (int) ($_POST['de'] ?? 0), $a);
                aviso($n === 1 ? '1 conversación pasada.' : $n . ' conversaciones pasadas.');
            }
            redirigir('equipo');
        }
        $st = db()->prepare('SELECT id, nombre, rol, disponible, recibe_chats FROM usuarios WHERE empresa_id = ? AND activo AND NOT super ORDER BY nombre');
        $st->execute([(int) $emp['id']]);
        vista('equipo', 'Equipo y reparto', ['linea' => Lineas::buscar(db(), $lid), 't' => \TuWhatsApp\Reparto::tablero(db(), $linea), 'equipo' => $st->fetchAll()]);

    case 'campos':
        if ($post) {
            if (($_POST['accion'] ?? '') === 'borrar') {
                \TuWhatsApp\Campos::borrar(db(), $lid, (int) ($_POST['id'] ?? 0));
                aviso('Campo borrado.');
            } else {
                \TuWhatsApp\Campos::guardar(db(), $lid, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['tipo'] ?? 'texto'), (string) ($_POST['opciones'] ?? ''));
                aviso('Campo agregado. Ya aparece en cada chat, en "Datos y notas".');
            }
            redirigir('campos');
        }
        vista('campos', 'Campos personalizados', ['linea' => $linea, 'campos' => \TuWhatsApp\Campos::lista(db(), $lid)]);

    case 'tienda':
        if ($post) {
            \TuWhatsApp\Tiendas::guardar(db(), $linea, $_POST);
            aviso(($_POST['tienda_tipo'] ?? '') === '' ? 'Tienda desconectada.' : 'Guardado. Ahora pega la dirección de abajo en tu tienda.');
            redirigir('tienda');
        }
        $lt = Lineas::buscar(db(), $lid);
        $pl = Meta::deLinea($lt)->plantillas();
        $st = db()->prepare("SELECT p.*, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono FROM pedidos p
                             LEFT JOIN contactos c ON c.id = p.contacto_id WHERE p.linea_id = ? AND p.origen IN ('shopify', 'woocommerce') ORDER BY p.id DESC LIMIT 10");
        $st->execute([$lid]);
        vista('tienda', 'Tienda en línea', ['linea' => $lt, 'plantillas' => $pl['ok'] ? $pl['plantillas'] : [], 'errorMeta' => $pl['ok'] ? null : $pl['error'],
                                            'carritos' => \TuWhatsApp\Tiendas::carritos(db(), $lid), 'pedidosTienda' => $st->fetchAll()]);

    case 'app':
        vista('app', 'App en el celular', ['vapid' => \TuWhatsApp\Push::clavePublica(db()), 'suscripciones' => \TuWhatsApp\Push::cuantas(db(), (int) usuario()['id'])]);

    case 'push':   // el navegador activa, quita o prueba los avisos (JSON)
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$post) {
            http_response_code(405);
            exit;
        }
        $d = json_decode((string) ($_POST['datos'] ?? ''), true) ?: [];
        $accion = (string) ($_POST['accion'] ?? '');
        try {
            if ($accion === 'suscribir') {
                \TuWhatsApp\Push::suscribir(db(), (int) usuario()['id'], $d);
                $r = ['ok' => true, 'mensaje' => tukan_tr('Listo: te llegarán avisos en este celular.')];
            } elseif ($accion === 'quitar') {
                \TuWhatsApp\Push::quitar(db(), (int) usuario()['id'], (string) ($d['endpoint'] ?? ''));
                $r = ['ok' => true, 'mensaje' => tukan_tr('Avisos desactivados en este celular.')];
            } else {
                $n = \TuWhatsApp\Push::enviar(db(), [(int) usuario()['id']], ['titulo' => 'WhatsApp Tukán', 'texto' => tukan_tr('Así te llegarán los mensajes nuevos.'), 'url' => '/?r=bandeja', 'tag' => 'tuwa-prueba']);
                $r = ['ok' => $n > 0, 'mensaje' => $n > 0 ? tukan_tr('Aviso de prueba enviado.') : tukan_tr('No se pudo mandar el aviso. Vuelve a activar los avisos.')];
            }
        } catch (\RuntimeException $ex) {
            $r = ['ok' => false, 'mensaje' => tukan_tr($ex->getMessage())];
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;

    case 'llamada':   // llamadas de WhatsApp: lo que hace el navegador (JSON)
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $accion = (string) ($_REQUEST['accion'] ?? '');
        $uid = (int) usuario()['id'];
        $idL = (int) ($_REQUEST['id'] ?? 0);
        try {
            if ($accion === 'sonando') {
                $r = ['ok' => true, 'llamada' => !empty($linea['llamadas_activo']) ? \TuWhatsApp\Llamadas::sonando(db(), $lid) : null];
            } elseif ($accion === 'estado') {
                $l = \TuWhatsApp\Llamadas::buscar(db(), $lid, $idL);
                $r = $l ? ['ok' => true, 'estado' => $l['estado'], 'texto' => tukan_tr(\TuWhatsApp\Llamadas::ESTADOS[$l['estado']] ?? $l['estado']),
                           'respuesta' => $l['direccion'] === 'sale' ? (string) $l['sdp_respuesta'] : '', 'error' => (string) $l['error'],
                           'duracion' => $l['duracion'] !== null ? (int) $l['duracion'] : null]
                        : ['ok' => false, 'error' => tukan_tr('Esa llamada no existe.')];
            } elseif (!$post) {
                http_response_code(405);
                $r = ['ok' => false];
            } elseif ($accion === 'tomar') {
                $r = ['ok' => true] + \TuWhatsApp\Llamadas::tomar(db(), $lid, $idL, $uid);
            } elseif ($accion === 'contestar') {
                \TuWhatsApp\Llamadas::contestar(db(), $linea, $idL, $uid, (string) ($_POST['sdp'] ?? ''));
                $r = ['ok' => true];
            } elseif ($accion === 'aceptar') {
                \TuWhatsApp\Llamadas::aceptar(db(), $linea, $idL, $uid);
                $r = ['ok' => true];
            } elseif ($accion === 'rechazar') {
                \TuWhatsApp\Llamadas::rechazar(db(), $linea, $idL);
                $r = ['ok' => true];
            } elseif ($accion === 'colgar') {
                \TuWhatsApp\Llamadas::colgar(db(), $linea, $idL);
                $r = ['ok' => true];
            } elseif ($accion === 'llamar') {
                $cl = Contactos::buscar(db(), $lid, (int) ($_POST['c'] ?? 0));
                if (!$cl) {
                    throw new RuntimeException('Ese contacto no existe.');
                }
                $r = ['ok' => true, 'id' => \TuWhatsApp\Llamadas::llamar(db(), $linea, $cl, $uid, (string) ($_POST['sdp'] ?? ''))];
            } else {
                $r = ['ok' => false, 'error' => 'Acción desconocida.'];
            }
        } catch (RuntimeException $ex) {
            $r = ['ok' => false, 'error' => tukan_tr($ex->getMessage())];
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

    case 'llamar':   // ventanita de la llamada (sigue abierta aunque cambies de página en el panel)
        $lla = isset($_GET['id']) ? \TuWhatsApp\Llamadas::buscar(db(), $lid, (int) $_GET['id']) : null;
        $cl = isset($_GET['c']) ? Contactos::buscar(db(), $lid, (int) $_GET['c'])
            : ($lla && $lla['contacto_id'] !== null ? Contactos::buscar(db(), $lid, (int) $lla['contacto_id']) : null);
        if (!$lla && !$cl) {
            aviso('Esa llamada no existe.', 'error');
            redirigir('llamadas');
        }
        $titulo = $cl ? Contactos::nombre($cl) : 'Llamada';
        require RAIZ . '/app/vistas/llamar.php';
        exit;

    case 'llamadas':
        if ($post) {
            if (!es_admin()) {
                aviso('Solo el administrador de la cuenta puede cambiar eso.', 'error');
                redirigir('llamadas');
            }
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'encender' || $accion === 'apagar') {
                $r = \TuWhatsApp\Llamadas::encender(db(), $linea, $accion === 'encender');
                $r['ok'] ? aviso($accion === 'encender' ? 'Llamadas encendidas: tus clientes ya ven el botón de llamar.' : 'Llamadas apagadas.')
                         : aviso('Meta no dejó cambiarlo: ' . $r['error'], 'error');
            } else {
                db()->prepare('UPDATE lineas SET llamadas_perdida = ? WHERE id = ?')->execute([mb_substr(trim((string) ($_POST['llamadas_perdida'] ?? '')), 0, 1000), $lid]);
                aviso('Guardado.');
            }
            redirigir('llamadas');
        }
        vista('llamadas', 'Llamadas', ['linea' => $linea, 'llamadas' => \TuWhatsApp\Llamadas::lista(db(), $lid)]);

    case 'pendientes':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'agregar') {
                Pendientes::agregar(db(), $lid, (string) ($_POST['telefono'] ?? ''), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['texto'] ?? ''));
                aviso('Agregado a la lista.');
            } elseif (in_array($accion, ['enviado', 'descartado', 'pendiente'], true)) {
                Pendientes::marcar(db(), $lid, (int) ($_POST['id'] ?? 0), $accion);
                if (($_POST['ajax'] ?? '') === '1') {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true]);
                    exit;
                }
            }
            redirigir('pendientes');
        }
        vista('pendientes', 'Pendientes', [
            'linea' => $linea, 'abiertos' => Pendientes::abiertos(db(), $lid), 'recientes' => Pendientes::recientes(db(), $lid),
        ]);

    case 'contactos':
        if ($post) {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'agregar') {
                Contactos::guardar(db(), $lid, (string) ($_POST['telefono'] ?? ''), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['tipo'] ?? 'cliente'));
                aviso('Contacto guardado.');
            } elseif ($accion === 'importar') {
                $tipo = (string) ($_POST['tipo'] ?? 'personal');
                $soloNuevos = !empty($_POST['solo_nuevos']);
                $archivo = $_FILES['archivo'] ?? null;
                if (is_array($archivo) && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                        throw new RuntimeException('No se pudo subir el archivo (puede ser muy grande). Intenta otra vez.');
                    }
                    if ($archivo['size'] > 30 * 1024 * 1024) {
                        throw new RuntimeException('El archivo es muy grande (máximo 30 MB).');
                    }
                    $filas = \TuWhatsApp\LectorContactos::leer((string) file_get_contents($archivo['tmp_name']), (string) $archivo['name']);
                } elseif (trim((string) ($_POST['contenido'] ?? '')) !== '') {   // el archivo leído en el celular (sin fotos)
                    $filas = \TuWhatsApp\LectorContactos::leer((string) $_POST['contenido'], (string) ($_POST['nombre_archivo'] ?? ''));
                } else {
                    $filas = \TuWhatsApp\LectorContactos::texto((string) ($_POST['lista'] ?? ''));
                }
                if (!$filas) {
                    throw new RuntimeException('No se encontró ningún contacto con número. Revisa que el archivo sea de contactos (.vcf o .csv).');
                }
                $r = Contactos::importar(db(), $lid, $filas, $tipo, $soloNuevos, !empty($_POST['acepta_promos']) && $tipo !== 'personal');
                $msg = "Listo: {$r['nuevos']} nuevos como " . tipo_contacto($tipo) . ($r['cambiados'] ? ", {$r['cambiados']} actualizados" : '')
                     . ($r['iguales'] ? ", {$r['iguales']} ya estaban y no se tocaron" : '') . '.';
                if ($r['malos']) {
                    $msg .= ' ' . count($r['malos']) . ' no son celulares (fijos o incompletos), se saltaron'
                          . (count($r['malos']) <= 10 ? ': ' . implode(' · ', $r['malos']) : '') . '.';
                }
                aviso($msg, $r['nuevos'] + $r['cambiados'] > 0 ? 'ok' : 'info');
            } elseif ($accion === 'tipo') {
                Contactos::cambiarTipo(db(), $lid, (int) ($_POST['id'] ?? 0), (string) ($_POST['tipo'] ?? ''));
                aviso('Cambiado.');
            }
            redirigir('contactos', array_filter(['t' => $_GET['t'] ?? null, 'b' => $_GET['b'] ?? null]));
        }
        $tipo = (string) ($_GET['t'] ?? '');
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('contactos', 'Contactos', [
            'linea' => $linea, 'tipo' => $tipo, 'buscar' => $buscar,
            'contactos' => Contactos::lista(db(), $lid, $tipo, $buscar), 'cuenta' => Contactos::contar(db(), $lid),
        ]);

    case 'reglas':
        if ($post) {
            Lineas::guardarReglas(db(), $lid, $_POST);
            aviso('Reglas guardadas.');
            redirigir('reglas');
        }
        vista('reglas', 'Respuestas automáticas', ['linea' => $linea]);

    case 'gasto':
        if ($post) {
            if (($_POST['accion'] ?? '') === 'precios') {
                if (!es_super()) {
                    throw new RuntimeException('Los precios de Meta los actualiza Tukán.');
                }
                Gasto::guardarPrecios(db(), (array) ($_POST['precio'] ?? []));
                aviso('Precios guardados.');
            } else {
                Lineas::guardarTope(db(), $lid, (string) ($_POST['tope'] ?? ''), (string) ($_POST['aviso_pct'] ?? '80'));
                aviso('Tope guardado.');
            }
            redirigir('gasto');
        }
        vista('gasto', 'Gasto en Meta', ['linea' => $linea, 'resumen' => Gasto::resumen(db(), $linea), 'precios' => Gasto::precios(db())]);

    case 'lineas':
        vista('lineas', 'Números', ['lineas' => Lineas::todas(db(), (int) empresa_actual()['id']), 'empresa' => empresa_actual()]);

    case 'linea':
        $editar = isset($_GET['id']) ? Lineas::buscar(db(), (int) $_GET['id'], (int) empresa_actual()['id']) : null;
        if (isset($_GET['id']) && !$editar) {
            aviso('Ese número no existe en tu cuenta.', 'error');
            redirigir('lineas');
        }
        $prueba = null;
        if ($post) {
            if (($_POST['accion'] ?? '') === 'probar' && $editar) {
                $prueba = Meta::deLinea($editar)->probar();
            } elseif (in_array($_POST['accion'] ?? '', ['sync_contactos', 'sync_historial'], true) && $editar) {
                $cual = $_POST['accion'] === 'sync_contactos' ? 'contactos' : 'historial';
                $x = Registro::sincronizar(db(), $editar, $cual);
                aviso($x['ok'] ? 'Pedido a Meta: los ' . $cual . ' van llegando en unos minutos. Deja abierta la app WhatsApp Business en el celular.'
                               : 'No se pudo pedir los ' . $cual . ': ' . $x['error'], $x['ok'] ? 'ok' : 'error');
                redirigir('linea', ['id' => $editar['id']]);
            } else {
                $id = Lineas::guardarConexion(db(), $editar ? (int) $editar['id'] : null, $_POST, (int) empresa_actual()['id']);
                $_SESSION['linea_id'] = $id;
                $nueva = Lineas::buscar(db(), $id);
                $prueba = Meta::deLinea($nueva)->probar();
                aviso($prueba['ok'] ? 'Guardado y conectado con Meta: ' . $prueba['numero'] . ' (' . $prueba['nombre'] . ').'
                                    : 'Guardado, pero Meta no respondió bien: ' . $prueba['error'], $prueba['ok'] ? 'ok' : 'error');
                if ($prueba['ok']) {   // que Meta avise a este programa los mensajes que llegan a esa cuenta
                    $sus = Meta::deLinea($nueva)->suscribirApp();
                    if (!$sus['ok']) {
                        aviso('Meta todavía no avisará los mensajes que lleguen: ' . $sus['error'], 'error');
                    }
                }
                redirigir('linea', ['id' => $id]);
            }
        }
        vista('linea', $editar ? 'Número: ' . $editar['nombre'] : 'Conectar número', ['editar' => $editar, 'prueba' => $prueba]);
    }
} catch (\PDOException $e) {
    error_log('tuwhatsapp: ' . $e);
    aviso('Error del servidor. Intenta de nuevo.', 'error');
    redirigir('bandeja');
} catch (\RuntimeException $e) {
    aviso($e->getMessage(), 'error');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
