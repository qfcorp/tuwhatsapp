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
          'cuenta', 'bloqueada', 'clientes', 'cliente', 'ver_cuenta', 'volver_tukan'];
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
$soloAdmin = ['reglas', 'lineas', 'linea', 'conectar_qr'];
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
}
if ($ruta === 'conectar_qr') {   // lo llama el botón "Conectar con WhatsApp Business" (responde JSON)
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$post || !csrf_ok()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La página estaba abierta hace mucho. Recárgala e intenta de nuevo.']);
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
if ($linea === null && in_array($ruta, ['bandeja', 'chat', 'nuevo_chat', 'pendientes', 'contactos', 'reglas', 'gasto', 'plantilla', 'nuevos', 'plantillas'], true)) {
    if (!es_admin()) {
        aviso('El administrador de tu cuenta todavía no conecta el número de WhatsApp.', 'info');
        redirigir('cuenta');
    }
    aviso('Primero conecta tu número de WhatsApp de Meta.', 'info');
    redirigir('linea');
}
$lid = $linea ? (int) $linea['id'] : 0;

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
                $st = db()->prepare('SELECT id, clave FROM usuarios WHERE lower(correo) = lower(?) AND activo');
                $st->execute([trim((string) ($_POST['correo'] ?? ''))]);
                $f = $st->fetch();
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
        echo json_encode(['sin_leer' => (int) $n, 'ultimo' => (int) $st->fetchColumn()]);
        exit;

    case 'bandeja':
        $filtro = (string) ($_GET['f'] ?? 'todos');
        $buscar = trim((string) ($_GET['b'] ?? ''));
        vista('bandeja', 'Bandeja', [
            'linea' => $linea,
            'filtro' => $filtro,
            'buscar' => $buscar,
            'conversaciones' => Contactos::bandeja(db(), $lid, $filtro, $buscar),
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
            if ($accion === 'tipo') {
                Contactos::cambiarTipo(db(), $lid, $cid, (string) ($_POST['tipo'] ?? ''));
                aviso('Ahora es: ' . tipo_contacto((string) $_POST['tipo']) . '.');
            } elseif ($accion === 'datos') {
                Contactos::guardar(db(), $lid, (string) $c['telefono'], (string) ($_POST['nombre'] ?? ''), (string) $c['tipo'], (string) ($_POST['notas'] ?? ''));
                aviso('Datos guardados.');
            } elseif ($accion === 'silencio') {
                $horas = (int) ($_POST['horas'] ?? 0);
                db()->prepare('UPDATE contactos SET silencio_hasta = ? WHERE id = ?')
                    ->execute([$horas > 0 ? (new DateTimeImmutable("+$horas hours"))->format('c') : null, $cid]);
                aviso($horas > 0 ? "No se le contestará solo durante $horas horas." : 'Se volvió a activar la respuesta automática para esta persona.');
            } elseif ($accion === 'enviar') {
                $r = (new Mensajeria(db()))->texto($linea, $c, (string) ($_POST['texto'] ?? ''), 'panel', (int) usuario()['id']);
                if ($r['ok']) {
                    aviso('Enviado' . ($r['costo'] > 0 ? ' (' . dinero($r['costo'], true) . ')' : ' (gratis)') . '.');
                } else {
                    $_SESSION['borrador'][$cid] = (string) ($_POST['texto'] ?? '');
                    aviso($r['error'], 'error');
                }
            } elseif ($accion === 'pendiente') {
                Pendientes::agregar(db(), $lid, (string) $c['telefono'], Contactos::nombre($c), (string) ($_POST['texto'] ?? ''), 'chat');
                aviso('Agregado a Pendientes.');
            }
            redirigir('chat', ['c' => $cid]);
        }
        db()->prepare('UPDATE mensajes SET leido = true WHERE contacto_id = ? AND NOT leido')->execute([$cid]);
        $st = db()->prepare('SELECT * FROM (SELECT * FROM mensajes WHERE contacto_id = ? ORDER BY creado DESC, id DESC LIMIT 200) x ORDER BY creado, id');
        $st->execute([$cid]);
        $borrador = $_SESSION['borrador'][$cid] ?? '';
        unset($_SESSION['borrador'][$cid]);
        vista('chat', Contactos::nombre($c), [
            'linea' => $linea, 'c' => $c, 'mensajes' => $st->fetchAll(), 'borrador' => $borrador,
            'ventana' => Gasto::ventanaAbierta($c, new DateTimeImmutable()),
            'precio' => Gasto::estimar(db(), 'servicio', $c, new DateTimeImmutable()),
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
            } else {
                Empresas::guardar(db(), (int) $e['id'], $_POST);
                aviso('Datos del cliente guardados.');
            }
            redirigir('cliente', ['id' => $e['id']]);
        }
        $clave = $_SESSION['clave_mostrar'] ?? null;
        unset($_SESSION['clave_mostrar']);
        vista('cliente', $e['nombre'], ['empresa' => $e, 'usuarios' => Empresas::usuarios(db(), (int) $e['id']),
            'numeros' => Lineas::todas(db(), (int) $e['id']), 'claveNueva' => $clave]);

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
                $r = Contactos::importar(db(), $lid, $filas, $tipo, $soloNuevos);
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
