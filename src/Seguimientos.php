<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Módulo "Seguimientos": mensajes programados para una conversación ("si no responde en 2 días, escribirle").
 * Los manda bin/campanias.php (cron cada minuto).
 *  - Si la persona escribió después de programarlo y está marcado "solo si no responde", no se manda (omitido).
 *  - Si pasaron más de 24 horas desde su último mensaje, por el sistema solo se puede mandar plantilla:
 *    entonces queda en Pendientes para mandarlo GRATIS desde el celular con un toque.
 */
final class Seguimientos
{
    public const ESTADOS = [
        'programado' => 'Programado', 'enviado' => 'Enviado', 'pendiente' => 'En Pendientes (mándalo del celular)',
        'omitido' => 'No se mandó: ya respondió', 'cancelado' => 'Cancelado', 'fallido' => 'Falló',
    ];
    /** Atajos que se ofrecen en el chat */
    public const ATAJOS = ['2h' => 'En 2 horas', '1d' => 'Mañana', '2d' => 'En 2 días', '3d' => 'En 3 días', '7d' => 'En una semana'];

    /** "2h", "1d"… o una fecha "2026-10-25T09:00" -> cuándo. Los atajos en días se mandan a las 9:00 (horario de oficina). */
    public static function cuando(string $valor, DateTimeImmutable $ahora): DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2})h$/', $valor, $m)) {
            return $ahora->modify('+' . (int) $m[1] . ' hours');
        }
        if (preg_match('/^(\d{1,2})d$/', $valor, $m)) {
            return $ahora->modify('+' . (int) $m[1] . ' days')->setTime(9, 0);
        }
        $f = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $valor, $ahora->getTimezone());
        if (!$f) {
            throw new RuntimeException('Elige cuándo mandarlo.');
        }
        if ($f <= $ahora) {
            throw new RuntimeException('La fecha ya pasó: elige una más adelante.');
        }
        if ($f > $ahora->modify('+1 year')) {
            throw new RuntimeException('Elige una fecha dentro del próximo año.');
        }
        return $f;
    }

    public static function programar(PDO $db, int $lineaId, int $contactoId, ?int $usuarioId, string $texto, DateTimeImmutable $cuando, bool $soloSinRespuesta = true): int
    {
        $texto = trim($texto);
        if ($texto === '') {
            throw new RuntimeException('Escribe el mensaje del seguimiento.');
        }
        if (mb_strlen($texto) > 4000) {
            throw new RuntimeException('El mensaje es muy largo (máximo 4000 letras).');
        }
        $n = $db->prepare("SELECT count(*) FROM seguimientos WHERE contacto_id = ? AND estado = 'programado'");
        $n->execute([$contactoId]);
        if ((int) $n->fetchColumn() >= 10) {
            throw new RuntimeException('Esta conversación ya tiene 10 seguimientos programados.');
        }
        $st = $db->prepare('INSERT INTO seguimientos (linea_id, contacto_id, usuario_id, texto, cuando, solo_sin_respuesta) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
        $st->execute([$lineaId, $contactoId, $usuarioId, $texto, $cuando->format('c'), $soloSinRespuesta ? 't' : 'f']);
        return (int) $st->fetchColumn();
    }

    public static function cancelar(PDO $db, int $lineaId, int $id): bool
    {
        $st = $db->prepare("UPDATE seguimientos SET estado = 'cancelado', hecho_en = now() WHERE id = ? AND linea_id = ? AND estado = 'programado'");
        $st->execute([$id, $lineaId]);
        return $st->rowCount() > 0;
    }

    /** @return list<array> */
    public static function deContacto(PDO $db, int $contactoId): array
    {
        $st = $db->prepare("SELECT * FROM seguimientos WHERE contacto_id = ? AND (estado = 'programado' OR hecho_en > now() - interval '7 days') ORDER BY cuando");
        $st->execute([$contactoId]);
        return $st->fetchAll();
    }

    /** @return list<array> programados y los últimos hechos, con el nombre del contacto */
    public static function lista(PDO $db, int $lineaId, bool $programados = true, int $limite = 100): array
    {
        $st = $db->prepare('SELECT s.*, c.nombre, c.nombre_whatsapp, c.telefono, u.nombre AS quien FROM seguimientos s
                            JOIN contactos c ON c.id = s.contacto_id LEFT JOIN usuarios u ON u.id = s.usuario_id
                            WHERE s.linea_id = ? AND ' . ($programados ? "s.estado = 'programado' ORDER BY s.cuando" : "s.estado <> 'programado' ORDER BY s.hecho_en DESC NULLS LAST") . ' LIMIT ' . (int) $limite);
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    /**
     * Manda los que ya tocan. Devuelve cuántos de cada resultado.
     * @return array{enviado:int, pendiente:int, omitido:int, fallido:int}
     */
    public static function procesar(PDO $db, ?DateTimeImmutable $ahora = null, int $limite = 100): array
    {
        $ahora ??= new DateTimeImmutable();
        $r = ['enviado' => 0, 'pendiente' => 0, 'omitido' => 0, 'fallido' => 0];
        $st = $db->prepare("SELECT s.id FROM seguimientos s WHERE s.estado = 'programado' AND s.cuando <= ? ORDER BY s.cuando LIMIT " . (int) $limite);
        $st->execute([$ahora->format('c')]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            // Se toma uno por uno con bloqueo para que dos cron no manden el mismo
            $db->beginTransaction();
            $q = $db->prepare("SELECT * FROM seguimientos WHERE id = ? AND estado = 'programado' FOR UPDATE SKIP LOCKED");
            $q->execute([$id]);
            $s = $q->fetch();
            if (!$s) {
                $db->rollBack();
                continue;
            }
            $db->prepare("UPDATE seguimientos SET estado = 'fallido', resultado = 'Se cortó al enviar', hecho_en = now() WHERE id = ?")->execute([$id]);
            $db->commit();
            [$estado, $resultado] = self::mandar($db, $s, $ahora);
            $db->prepare('UPDATE seguimientos SET estado = ?, resultado = ?, hecho_en = ? WHERE id = ?')->execute([$estado, mb_substr($resultado, 0, 500), $ahora->format('c'), $id]);
            $r[$estado]++;
        }
        return $r;
    }

    /** @return array{0:string,1:string} estado y explicación */
    private static function mandar(PDO $db, array $s, DateTimeImmutable $ahora): array
    {
        $linea = Lineas::buscar($db, (int) $s['linea_id']);
        $contacto = $linea ? Contactos::buscar($db, (int) $s['linea_id'], (int) $s['contacto_id']) : null;
        if (!$linea || !$contacto) {
            return ['fallido', 'Ya no existe el número o el contacto.'];
        }
        $empresa = Empresas::buscar($db, (int) $linea['empresa_id']);
        if (!Modulos::activo($empresa, 'seguimientos')) {
            return ['fallido', 'El módulo de seguimientos no está activo en el plan.'];
        }
        if (self::si($s['solo_sin_respuesta']) && $contacto['ultimo_entrante'] !== null
            && new DateTimeImmutable((string) $contacto['ultimo_entrante']) > new DateTimeImmutable((string) $s['creado'])) {
            return ['omitido', 'La persona escribió después de programarlo.'];
        }
        if (!Gasto::ventanaAbierta($contacto, $ahora)) {
            Pendientes::agregar($db, (int) $linea['id'], (string) $contacto['telefono'], Contactos::nombre($contacto), (string) $s['texto'], 'seguimiento');
            return ['pendiente', 'Pasaron más de 24 horas: quedó en Pendientes para mandarlo gratis desde el celular.'];
        }
        $env = (new Mensajeria($db, $ahora))->texto($linea, $contacto, (string) $s['texto'], 'panel', $s['usuario_id'] !== null ? (int) $s['usuario_id'] : null);
        return $env['ok'] ? ['enviado', $env['costo'] > 0 ? 'Costo $' . number_format((float) $env['costo'], 4, ',', '.') : 'Gratis'] : ['fallido', (string) $env['error']];
    }

    private static function si(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }
}
