<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Módulo "Reportes": números de un período para un número de WhatsApp.
 * Tiempo de respuesta = desde que la persona escribe hasta la primera respuesta de una persona del equipo
 * (panel o celular), sin contar las respuestas automáticas.
 */
final class Reportes
{
    public const PERIODOS = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'];

    public static function resumen(PDO $db, int $lineaId, int $dias, ?DateTimeImmutable $ahora = null): array
    {
        $dias = isset(self::PERIODOS[$dias]) ? $dias : 7;
        $ahora ??= new DateTimeImmutable();
        $desde = $ahora->setTime(0, 0)->modify('-' . ($dias - 1) . ' days');
        $p = [$lineaId, $desde->format('c'), $ahora->format('c')];

        $st = $db->prepare("SELECT
                count(*) FILTER (WHERE direccion = 'entra') AS entran,
                count(*) FILTER (WHERE direccion = 'sale') AS salen,
                count(*) FILTER (WHERE direccion = 'sale' AND origen IN ('auto', 'bot', 'flujo')) AS automaticos,
                count(*) FILTER (WHERE direccion = 'sale' AND origen IN ('panel', 'celular', 'pendiente')) AS equipo,
                count(DISTINCT contacto_id) FILTER (WHERE direccion = 'entra') AS conversaciones,
                COALESCE(sum(costo) FILTER (WHERE direccion = 'sale'), 0) AS gasto
            FROM mensajes WHERE linea_id = ? AND creado >= ? AND creado <= ?");
        $st->execute($p);
        $tot = array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, $st->fetch());

        $st = $db->prepare('SELECT count(*) FROM contactos WHERE linea_id = ? AND creado >= ? AND creado <= ?');
        $st->execute($p);
        $tot['nuevos'] = (int) $st->fetchColumn();

        // Por día (para las barras)
        $st = $db->prepare("SELECT to_char(creado AT TIME ZONE 'America/Guayaquil', 'YYYY-MM-DD') AS dia,
                count(*) FILTER (WHERE direccion = 'entra') AS entran, count(*) FILTER (WHERE direccion = 'sale') AS salen
            FROM mensajes WHERE linea_id = ? AND creado >= ? AND creado <= ? GROUP BY 1");
        $st->execute($p);
        $porDia = [];
        for ($d = $desde; $d <= $ahora; $d = $d->modify('+1 day')) {
            $porDia[$d->format('Y-m-d')] = ['entran' => 0, 'salen' => 0];
        }
        foreach ($st->fetchAll() as $f) {
            $porDia[$f['dia']] = ['entran' => (int) $f['entran'], 'salen' => (int) $f['salen']];
        }

        // Tiempo de primera respuesta: cada mensaje que entra y que es el primero desde la última respuesta
        $st = $db->prepare("WITH hilo AS (   -- lo que escribe la persona y lo que contesta el equipo (sin automáticos)
                SELECT m.contacto_id, m.creado, m.direccion,
                       lag(m.direccion) OVER (PARTITION BY m.contacto_id ORDER BY m.creado, m.id) AS antes
                FROM mensajes m WHERE m.linea_id = ? AND (m.direccion = 'entra' OR m.origen IN ('panel', 'celular', 'pendiente'))
                    AND m.creado >= ? AND m.creado <= ?
            ), primeros AS (
                SELECT h.contacto_id, h.creado,
                       (SELECT min(s.creado) FROM mensajes s WHERE s.contacto_id = h.contacto_id AND s.direccion = 'sale'
                            AND s.origen IN ('panel', 'celular', 'pendiente') AND s.creado >= h.creado) AS respuesta
                FROM hilo h WHERE h.direccion = 'entra' AND h.antes IS DISTINCT FROM 'entra'
            )
            SELECT count(*) AS total, count(respuesta) AS contestados,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM respuesta - creado)) FILTER (WHERE respuesta IS NOT NULL) AS mediana,
                   avg(extract(epoch FROM respuesta - creado)) FILTER (WHERE respuesta IS NOT NULL) AS promedio
            FROM primeros");
        $st->execute($p);
        $resp = $st->fetch();

        // Por persona del equipo (lo que mandó desde el panel)
        $st = $db->prepare("SELECT COALESCE(u.nombre, 'Sin usuario (celular o API)') AS nombre, count(*) AS mensajes, count(DISTINCT m.contacto_id) AS conversaciones
            FROM mensajes m LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.linea_id = ? AND m.creado >= ? AND m.creado <= ? AND m.direccion = 'sale' AND m.origen IN ('panel', 'celular', 'pendiente')
            GROUP BY 1 ORDER BY 2 DESC");
        $st->execute($p);
        $agentes = $st->fetchAll();

        // Embudo: ventas ganadas en el período
        $st = $db->prepare("SELECT count(*) FILTER (WHERE etapa = 'ganado') AS ganados, COALESCE(sum(valor) FILTER (WHERE etapa = 'ganado'), 0) AS vendido,
                count(*) FILTER (WHERE etapa = 'perdido') AS perdidos
            FROM contactos WHERE linea_id = ? AND etapa_cambio >= ? AND etapa_cambio <= ?");
        $st->execute($p);
        $ventas = $st->fetch();

        return [
            'dias' => $dias, 'desde' => $desde, 'hasta' => $ahora,
            'totales' => $tot, 'por_dia' => $porDia,
            'respuesta' => [
                'total' => (int) $resp['total'], 'contestados' => (int) $resp['contestados'],
                'mediana' => $resp['mediana'] !== null ? (int) round((float) $resp['mediana']) : null,
                'promedio' => $resp['promedio'] !== null ? (int) round((float) $resp['promedio']) : null,
            ],
            'agentes' => $agentes,
            'ventas' => ['ganados' => (int) $ventas['ganados'], 'perdidos' => (int) $ventas['perdidos'], 'vendido' => (float) $ventas['vendido']],
        ];
    }

    /** 75 -> "1 min" · 5400 -> "1 h 30 min" · null -> "—" */
    public static function duracion(?int $seg): string
    {
        if ($seg === null) {
            return '—';
        }
        if ($seg < 60) {
            return $seg . ' s';
        }
        $min = intdiv($seg, 60);
        if ($min < 60) {
            return $min . ' min';
        }
        $h = intdiv($min, 60);
        if ($h < 48) {
            return $h . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '');
        }
        return intdiv($h, 24) . ' días';
    }
}
