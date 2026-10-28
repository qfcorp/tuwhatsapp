<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuenta de MUESTRA para que Google revise la app de Android «Tukán Mensajes» sin ver datos reales (deploy/demo_google.php crear | borrar).
 * Arma la empresa «Tienda Muestra Tukán» (empresas.muestra = true, demo por 180 días, plan Todo incluido SIN IA) con un número que
 * no es de verdad (phone_number_id 'muestra-…', sin token): src/Meta.php no llama nunca a Meta con ese número y public/index.php
 * no deja conectar números, cambiar la contraseña ni poner claves de cobro. Datos inventados: 8 contactos con números de ficción
 * (+1 202 555 01xx, rango reservado para ejemplos), conversaciones, catálogo, pedidos, pendientes y un seguimiento.
 * El administrador es revisor.google@tukanec.com. Nunca toca otra empresa: si ese correo es de una cuenta que no es de muestra, se niega.
 */
final class DemoGoogle
{
    public const CORREO = 'revisor.google@tukanec.com';
    public const EMPRESA = 'Tienda Muestra Tukán';
    public const DIAS = 180;

    /** Contactos de ficción: [nombre, teléfono, tipo, etapa, valor, etiquetas, conversación [[entra|sale, minutos atrás, texto]...]] */
    private const CONTACTOS = [
        ['Camila Ortega', '12025550101', 'cliente', 'cotizado', 85, 'quito, mayorista', [
            ['entra', 190, 'Hola, buenas tardes. ¿Tienen el set de café para regalo?'],
            ['sale', 185, '¡Hola Camila! Sí, tenemos el Set de café de altura a $28. ¿Para cuántas personas sería?'],
            ['entra', 180, 'Para 3 regalos de fin de año. ¿Me pueden enviar la cotización?'],
            ['sale', 170, 'Claro, te envío la cotización en PDF con el envío incluido.'],
            ['entra', 12, 'Perfecto, la reviso hoy y te confirmo 🙌'],
        ]],
        ['Mateo Rivas', '12025550102', 'cliente', 'ganado', 46, 'frecuente', [
            ['entra', 1500, '¿Ya salió mi pedido?'],
            ['sale', 1490, 'Hola Mateo, sí: tu pedido N.º 2 salió hoy y llega mañana en la mañana.'],
            ['entra', 1485, 'Gracias, excelente servicio.'],
        ]],
        ['Valeria Núñez', '12025550103', 'desconocido', 'nuevo', 0, '', [
            ['entra', 6, '¿Cuál es el horario de atención?'],
        ]],
        ['Sebastián Lara', '12025550104', 'cliente', 'interesado', 32, '', [
            ['entra', 300, 'Hola, quiero el catálogo'],
            ['sale', 299, 'Hola Sebastián, aquí tienes nuestro catálogo. Toca un producto para agregarlo a tu carrito.'],
            ['entra', 240, 'Me interesa la taza artesanal y la miel de abeja'],
        ]],
        ['Daniela Paz', '12025550105', 'cliente', 'perdido', 0, '', [
            ['entra', 4400, '¿Hacen envíos a Cuenca?'],
            ['sale', 4390, 'Sí, enviamos a todo el país en 24 a 48 horas.'],
        ]],
        ['Andrés Molina', '12025550106', 'cliente', null, 0, '', [
            ['entra', 50, 'Buenos días, ¿aceptan pago con tarjeta?'],
            ['sale', 45, 'Buenos días Andrés. Sí: al confirmar tu pedido te llega el enlace para pagar con tarjeta.'],
        ]],
        ['Lucía Herrera', '12025550107', 'desconocido', null, 0, '', [
            ['entra', 3, 'Hola, vi su anuncio. ¿Tienen chocolate sin azúcar?'],
        ]],
        ['Proveedor de cajas', '12025550108', 'personal', null, 0, '', [
            ['entra', 2900, 'Las cajas de regalo llegan el jueves.'],
            ['sale', 2890, 'Perfecto, gracias.'],
        ]],
    ];

    private const PRODUCTOS = [
        ['CAF-01', 'Set de café de altura', 'Café de altura en grano (250 g) con taza y filtro.', '28.00', 12],
        ['TAZ-02', 'Taza artesanal', 'Taza de cerámica pintada a mano, 300 ml.', '9.50', 30],
        ['MIE-03', 'Miel de abeja 500 g', 'Miel pura de flores silvestres.', '7.00', 25],
        ['CHO-04', 'Chocolate fino 70 %', 'Barra de 100 g de cacao fino de aroma.', '4.25', 60],
        ['CAJ-05', 'Caja de regalo mediana', 'Caja de cartón kraft con lazo y tarjeta.', '3.00', null],
        ['TE-06', 'Té de hierbas andinas', 'Caja de 20 sobres de té de hierbas.', '5.75', 18],
    ];

    /** Id de la empresa de muestra (o null). Lanza error si el correo es de una cuenta que NO es de muestra. */
    public static function empresa(PDO $db): ?int
    {
        $st = $db->prepare('SELECT e.id, e.muestra FROM usuarios u JOIN empresas e ON e.id = u.empresa_id WHERE lower(u.correo) = ?');
        $st->execute([self::CORREO]);
        $e = $st->fetch();
        if (!$e) {
            return null;
        }
        if (!$e['muestra'] || (int) $e['id'] === Empresas::TUKAN) {
            throw new RuntimeException('El correo ' . self::CORREO . ' es de una cuenta que NO es de muestra (N.º ' . $e['id'] . '). No se toca.');
        }
        return (int) $e['id'];
    }

    /** Crea (o vuelve a crear con fechas de hoy y contraseña nueva) la cuenta de muestra. Devuelve la contraseña y un resumen. */
    public static function crear(PDO $db, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        self::borrar($db);
        $db->beginTransaction();
        try {
            $r = Empresas::crear($db, ['nombre' => self::EMPRESA, 'estado' => 'demo', 'vence' => $ahora->modify('+' . self::DIAS . ' days')->format('Y-m-d'),
                                       'max_numeros' => 1, 'max_usuarios' => 50, 'contacto' => 'Equipo de revisión', 'correo' => self::CORREO,
                                       'notas' => 'Cuenta de muestra para la revisión de Google Play (deploy/demo_google.php). Datos inventados.',
                                       'admin_correo' => self::CORREO, 'admin_nombre' => 'Equipo de revisión']);
            $eid = (int) $r['empresa_id'];
            Modulos::aplicarPlan($db, $eid, 'todo', []);   // sin IA: la cuenta de muestra no manda nada a servicios de IA
            $db->prepare('UPDATE empresas SET muestra = true, ia_tope = 0, terminos_version = ?, terminos_aceptados_en = now() WHERE id = ?')
               ->execute([Terminos::VERSION, $eid]);
            $clave = 'Demo-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $db->prepare('UPDATE usuarios SET clave = ? WHERE lower(correo) = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), self::CORREO]);
            $uid = (int) $db->query("SELECT id FROM usuarios WHERE lower(correo) = '" . self::CORREO . "'")->fetchColumn();
            // Un agente de ejemplo (nadie conoce su contraseña)
            $db->prepare("INSERT INTO usuarios (empresa_id, correo, nombre, rol, clave) VALUES (?, ?, 'Sofía (agente de ejemplo)', 'agente', ?)")
               ->execute([$eid, 'agente.' . $eid . '@revision.demo.tukanec.com', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $agente = (int) $db->lastInsertId();

            $st = $db->prepare("INSERT INTO lineas (empresa_id, nombre, telefono, phone_number_id, waba_id, token_cifrado, conectado_por, conectado_en,
                                       auto_activo, mensaje_menu, opciones, palabras)
                                VALUES (?, 'Tienda Muestra', '+1 202 555 0100', ?, 'muestra', '', 'manual', now(), true, ?, ?, ?) RETURNING id");
            $st->execute([$eid, Meta::PREFIJO_MUESTRA . $eid, 'Hola, gracias por escribir a Tienda Muestra. Responde con el número de lo que necesitas:',
                          json_encode([['titulo' => 'Ver el catálogo', 'respuesta' => 'Escribe "catálogo" y te mostramos los productos.', 'humano' => false],
                                       ['titulo' => 'Horario y envíos', 'respuesta' => 'Atendemos de lunes a sábado de 9:00 a 18:00. Enviamos a todo el país.', 'humano' => false],
                                       ['titulo' => 'Hablar con una persona', 'respuesta' => 'En un momento te atiende una persona del equipo.', 'humano' => true]],
                                      JSON_UNESCAPED_UNICODE),
                          'precio, catálogo, catalogo, pedido, envío, envio, pago, horario']);
            $lid = (int) $st->fetchColumn();

            $prod = [];
            foreach (self::PRODUCTOS as [$cod, $nom, $desc, $precio, $stock]) {
                $prod[$cod] = Catalogo::guardar($db, $lid, ['codigo' => $cod, 'nombre' => $nom, 'descripcion' => $desc, 'precio' => $precio,
                                                            'stock' => $stock === null ? '' : (string) $stock]);
            }

            $cid = [];
            $ins = $db->prepare("INSERT INTO contactos (linea_id, telefono, nombre, nombre_whatsapp, tipo, etapa, valor, etapa_cambio, etiquetas, marketing,
                                        ultimo_entrante, asignado_a, creado)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, CASE WHEN ? THEN now() END, ?, ?, ?, ?, ?) RETURNING id");
            $msg = $db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, tipo, texto, estado, categoria, costo, leido, usuario_id, creado, motivo)
                                 VALUES (?, ?, ?, ?, 'texto', ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach (self::CONTACTOS as $i => [$nombre, $tel, $tipo, $etapa, $valor, $etiquetas, $conv]) {
                $primero = $ahora->modify('-' . ($conv[0][1] + 60) . ' minutes');
                $ultEntra = null;
                foreach ($conv as [$dir, $min]) {
                    if ($dir === 'entra') {
                        $ultEntra = $ahora->modify("-$min minutes");
                    }
                }
                $ins->execute([$lid, $tel, $nombre, $nombre, $tipo, $etapa, $valor, $etapa !== null ? 't' : 'f', $etiquetas,
                               $i % 2 === 0 ? 'acepta' : 'sin_dato', $ultEntra?->format('c'), $i % 3 === 1 ? $agente : $uid, $primero->format('c')]);
                $c = (int) $ins->fetchColumn();
                $cid[$i] = $c;
                $n = count($conv);
                foreach ($conv as $k => [$dir, $min, $texto]) {
                    $cuando = $ahora->modify("-$min minutes")->format('c');
                    $ultimo = $k === $n - 1;
                    if ($dir === 'entra') {
                        $msg->execute([$lid, $c, 'entra', 'cliente', $texto, 'recibido', null, 0, $ultimo && $min < 60 ? 'f' : 't', null, $cuando,
                                       $tipo === 'personal' ? 'Personal: nunca se contesta solo' : 'Ya lo atiende una persona del equipo']);
                    } else {
                        $msg->execute([$lid, $c, 'sale', 'panel', $texto, 'leido', 'servicio', 0, 't', $uid, $cuando, null]);
                    }
                }
            }

            // Pedidos: uno pagado y enviado, uno pendiente de pago y un carrito abierto
            $linea = Lineas::buscar($db, $lid);
            $pedidos = 0;
            foreach ([[1, ['CAF-01' => 1, 'CAJ-05' => 1, 'MIE-03' => 1], 'pendiente'], [0, ['TAZ-02' => 2, 'CHO-04' => 4], 'pagado'],
                      [3, ['TAZ-02' => 1, 'MIE-03' => 1], 'carrito']] as [$i, $items, $estado]) {
                $pid = Pedidos::crear($db, $lid, $cid[$i], 'carrito', 'chat', $uid);
                $add = $db->prepare('INSERT INTO pedido_items (pedido_id, producto_id, nombre, precio, cantidad) SELECT ?, id, nombre, precio, ? FROM productos WHERE id = ?');
                foreach ($items as $cod => $cant) {
                    $add->execute([$pid, $cant, $prod[$cod]]);
                }
                Pedidos::recalcular($db, $linea, $pid);
                $db->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$estado, $pid]);
                $pedidos++;
            }

            Pendientes::agregar($db, $lid, '12025550105', 'Daniela Paz', 'Hola Daniela, ya tenemos envíos a Cuenca en 24 horas. ¿Te ayudo con tu pedido?', 'manual');
            Pendientes::agregar($db, $lid, '12025550103', 'Valeria Núñez', 'Hola Valeria, atendemos de lunes a sábado de 9:00 a 18:00.', 'manual');
            Respuestas::guardar($db, $lid, 'horario', 'Atendemos de lunes a sábado de 9:00 a 18:00.');
            Respuestas::guardar($db, $lid, 'envios', 'Enviamos a todo el país en 24 a 48 horas.');
            Respuestas::guardar($db, $lid, 'gracias', '¡Gracias por tu compra! Cualquier duda, aquí estamos.');
            $db->prepare('INSERT INTO seguimientos (linea_id, contacto_id, usuario_id, texto, cuando) VALUES (?, ?, ?, ?, ?)')
               ->execute([$lid, $cid[0], $uid, 'Hola Camila, ¿pudiste revisar la cotización?', $ahora->modify('+2 days')->format('c')]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return ['empresa_id' => $eid, 'clave' => $clave, 'vence' => $ahora->modify('+' . self::DIAS . ' days')->format('Y-m-d'),
                'contactos' => count($cid), 'productos' => count($prod), 'pedidos' => $pedidos];
    }

    /** Borra la cuenta de muestra (empresa, número, contactos, mensajes, pedidos y usuarios). Devuelve true si había una. */
    public static function borrar(PDO $db): bool
    {
        $eid = self::empresa($db);
        if ($eid === null) {
            return false;
        }
        $db->prepare('DELETE FROM empresas WHERE id = ? AND muestra')->execute([$eid]);
        return true;
    }
}
