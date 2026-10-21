<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\Contactos;
use TuWhatsApp\Gasto;
use TuWhatsApp\Lineas;
use TuWhatsApp\Mensajeria;
use TuWhatsApp\Meta;
use TuWhatsApp\Pendientes;

$rutas = ['login', 'salir', 'bandeja', 'chat', 'nuevo_chat', 'pendientes', 'contactos', 'reglas', 'gasto', 'lineas', 'linea', 'plantilla', 'cambiar_linea', 'nuevos'];
$ruta = (string) ($_GET['r'] ?? 'bandeja');
if (!in_array($ruta, $rutas, true)) {
    http_response_code(404);
    $ruta = 'bandeja';
}
if ($ruta !== 'login' && !usuario()) {
    redirigir('login');
}
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
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
if ($linea === null && in_array($ruta, ['bandeja', 'chat', 'nuevo_chat', 'pendientes', 'contactos', 'reglas', 'gasto', 'plantilla', 'nuevos'], true)) {
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
                $st = db()->prepare('SELECT id, clave FROM usuarios WHERE lower(correo) = lower(?)');
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
        if ($post && Lineas::buscar(db(), (int) ($_POST['linea_id'] ?? 0))) {
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
        vista('lineas', 'Números', ['lineas' => Lineas::todas(db())]);

    case 'linea':
        $editar = isset($_GET['id']) ? Lineas::buscar(db(), (int) $_GET['id']) : null;
        $prueba = null;
        if ($post) {
            if (($_POST['accion'] ?? '') === 'probar' && $editar) {
                $prueba = Meta::deLinea($editar)->probar();
            } else {
                $id = Lineas::guardarConexion(db(), $editar ? (int) $editar['id'] : null, $_POST);
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
