<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Pantalla de Inicio: las cifras de hoy del número y los "Primeros pasos" para dejar la cuenta lista.
 * Cada paso se marca solo cuando el dato existe (no hay que tocar nada para tacharlo).
 */
final class Inicio
{
    /** @return array{escribieron:int, nuevos:int, sin_responder:int, sin_leer:int, pagados_n:int, pagados_total:float, pendientes_n:int, perdidas:int, en_juego:float} */
    public static function cifras(PDO $db, array $linea, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $lid = (int) $linea['id'];
        $hoy = $ahora->setTime(0, 0)->format('c');
        $q = function (string $sql, array $p) use ($db) {
            $st = $db->prepare($sql);
            $st->execute($p);
            return $st->fetchColumn();
        };
        return [
            'escribieron' => (int) $q("SELECT count(DISTINCT contacto_id) FROM mensajes WHERE linea_id = ? AND direccion = 'entra' AND creado >= ?", [$lid, $hoy]),
            'nuevos' => (int) $q('SELECT count(*) FROM contactos WHERE linea_id = ? AND creado >= ?', [$lid, $hoy]),
            'sin_responder' => (int) $q("SELECT count(*) FROM contactos c WHERE c.linea_id = ? AND c.tipo <> 'personal' AND c.ultimo_entrante > ?
                    AND NOT EXISTS (SELECT 1 FROM mensajes m WHERE m.contacto_id = c.id AND m.direccion = 'sale' AND m.creado >= c.ultimo_entrante AND m.estado NOT IN ('fallido', 'bloqueado'))",
                    [$lid, $ahora->modify('-24 hours')->format('c')]),
            'sin_leer' => (int) $q('SELECT count(*) FROM mensajes WHERE linea_id = ? AND NOT leido', [$lid]),
            'pagados_n' => (int) $q('SELECT count(*) FROM pedidos WHERE linea_id = ? AND pagado_en >= ?', [$lid, $hoy]),
            'pagados_total' => (float) $q('SELECT COALESCE(sum(total), 0) FROM pedidos WHERE linea_id = ? AND pagado_en >= ?', [$lid, $hoy]),
            'pendientes_n' => (int) $q("SELECT count(*) FROM pedidos WHERE linea_id = ? AND estado = 'pendiente'", [$lid]),
            'perdidas' => (int) $q("SELECT count(*) FROM llamadas WHERE linea_id = ? AND estado IN ('perdida', 'fuera_horario') AND creado >= ?", [$lid, $hoy]),
            'en_juego' => (float) $q("SELECT COALESCE(sum(valor), 0) FROM contactos WHERE linea_id = ? AND etapa IN ('nuevo', 'interesado', 'cotizado')", [$lid]),
        ];
    }

    /**
     * Primeros pasos para dejar la cuenta lista (solo los de los módulos que tiene).
     * @return list<array{clave:string, titulo:string, texto:string, ruta:string, hecho:bool}>
     */
    public static function pasos(PDO $db, array $linea, array $empresa, callable $tieneModulo): array
    {
        $lid = (int) $linea['id'];
        $eid = (int) $empresa['id'];
        $cuenta = function (string $sql, array $p) use ($db): int {
            $st = $db->prepare($sql);
            $st->execute($p);
            return (int) $st->fetchColumn();
        };
        $pasos = [
            ['clave' => 'numero', 'titulo' => 'Conectar tu WhatsApp', 'texto' => 'Con el botón de Meta, en 10 minutos.', 'ruta' => 'lineas',
             'hecho' => trim((string) $linea['phone_number_id']) !== '' && !str_starts_with((string) $linea['phone_number_id'], 'muestra')],
            ['clave' => 'automatico', 'titulo' => 'Tu horario y la respuesta automática', 'texto' => 'Para que nadie se quede sin respuesta.', 'ruta' => 'reglas',
             'hecho' => !empty($linea['auto_activo']) && $linea['auto_activo'] !== 'f'],
        ];
        if ($tieneModulo('catalogo')) {
            $pasos[] = ['clave' => 'catalogo', 'titulo' => 'Subir tus productos', 'texto' => 'Con foto y precio, para mandarlos por el chat.', 'ruta' => 'catalogo',
                        'hecho' => $cuenta('SELECT count(*) FROM productos WHERE linea_id = ?', [$lid]) > 0];
        }
        if ($tieneModulo('cobros')) {
            $pasos[] = ['clave' => 'cobros', 'titulo' => 'Cómo te pagan', 'texto' => 'Transferencia, Plux o Payphone.', 'ruta' => 'cobros',
                        'hecho' => trim((string) ($linea['pago_transferencia'] ?? '')) !== '' || trim((string) ($linea['plux_cliente'] ?? '')) !== ''
                                   || trim((string) ($linea['payphone_token'] ?? '')) !== ''];
        }
        $pasos[] = ['clave' => 'equipo', 'titulo' => 'Invitar a tu equipo', 'texto' => 'Cada persona con su usuario.', 'ruta' => 'equipo',
                    'hecho' => $cuenta('SELECT count(*) FROM usuarios WHERE empresa_id = ? AND activo', [$eid]) > 1];
        if ($tieneModulo('ia')) {
            $pasos[] = ['clave' => 'ia', 'titulo' => 'Enseñarle tu negocio a la IA', 'texto' => 'Lo que vendes, precios y horarios. Pruébala antes de encenderla.', 'ruta' => 'ia',
                        'hecho' => trim((string) ($linea['ia_conocimiento'] ?? '')) !== ''];
        }
        $pasos[] = ['clave' => 'app', 'titulo' => 'Instalar la app en tu celular', 'texto' => 'Para que te avise cuando llega un mensaje.', 'ruta' => 'app',
                    'hecho' => $cuenta('SELECT count(*) FROM push_suscripciones p JOIN usuarios u ON u.id = p.usuario_id WHERE u.empresa_id = ?', [$eid]) > 0];
        return $pasos;
    }
}
