<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Reparto automático de conversaciones entre el equipo y vista del supervisor.
 *
 * Cuándo se reparte: cuando escribe alguien que no tiene a nadie asignado (los contactos personales nunca),
 * y cuando el agente con IA o un chatbot pasan la conversación a una persona.
 * A quién (en este orden):
 *  1. Reglas por palabras: "factura, pago" -> Ana. La primera que coincide gana (si Ana está disponible).
 *  2. Según el modo del número:
 *     - turnos: uno por uno, en orden (el siguiente al último que recibió);
 *     - menos_carga: a quien tiene menos conversaciones esperando respuesta.
 *     - manual: no se reparte solo.
 * Solo entran las personas activas que "reciben chats" y están "Disponibles". Si no hay nadie, queda sin asignar.
 */
final class Reparto
{
    public const MODOS = ['manual' => 'A mano (cada uno toma los suyos)', 'turnos' => 'Por turnos (uno a cada uno)', 'menos_carga' => 'Al que tenga menos chats esperando'];

    /** Personas que pueden recibir conversaciones de este número ahora. */
    public static function candidatos(PDO $db, array $linea): array
    {
        $st = $db->prepare('SELECT id, nombre FROM usuarios WHERE empresa_id = ? AND activo AND recibe_chats AND disponible AND NOT super ORDER BY id');
        $st->execute([(int) $linea['empresa_id']]);
        return $st->fetchAll();
    }

    /** @return list<array{palabras:string, usuario_id:int}> */
    public static function reglas(array $linea): array
    {
        $r = [];
        foreach ((array) json_decode((string) ($linea['reparto_reglas'] ?? '[]'), true) as $x) {
            if (is_array($x) && trim((string) ($x['palabras'] ?? '')) !== '' && (int) ($x['usuario_id'] ?? 0) > 0) {
                $r[] = ['palabras' => (string) $x['palabras'], 'usuario_id' => (int) $x['usuario_id']];
            }
        }
        return array_slice($r, 0, 20);
    }

    /**
     * Elige a quién darle la conversación. No toca la base (fácil de probar).
     * @param list<array{id:int|string}> $candidatos
     * @param array<int,int> $carga conversaciones esperando por persona
     */
    public static function elegir(string $modo, array $candidatos, array $reglas, string $texto, ?int $ultimo, array $carga): ?int
    {
        $ids = array_map(fn ($c) => (int) $c['id'], $candidatos);
        if (!$ids) {
            return null;
        }
        foreach ($reglas as $r) {
            if (in_array($r['usuario_id'], $ids, true) && Reglas::tienePalabra($texto, Reglas::listaPalabras($r['palabras']))) {
                return $r['usuario_id'];
            }
        }
        if ($modo === 'manual') {
            return null;
        }
        if ($modo === 'menos_carga') {
            $mejor = null;
            foreach ($ids as $id) {
                if ($mejor === null || ($carga[$id] ?? 0) < ($carga[$mejor] ?? 0)) {
                    $mejor = $id;
                }
            }
            return $mejor;
        }
        // turnos: el siguiente al último (si el último ya no está, el primero)
        if ($ultimo !== null) {
            foreach ($ids as $id) {
                if ($id > $ultimo) {
                    return $id;
                }
            }
        }
        return $ids[0];
    }

    /** Conversaciones esperando respuesta por persona (el último mensaje es del cliente). */
    public static function carga(PDO $db, int $lineaId): array
    {
        $st = $db->prepare("SELECT c.asignado_a, count(*) FROM contactos c
                            JOIN LATERAL (SELECT direccion FROM mensajes WHERE contacto_id = c.id ORDER BY creado DESC, id DESC LIMIT 1) m ON true
                            WHERE c.linea_id = ? AND c.asignado_a IS NOT NULL AND m.direccion = 'entra' GROUP BY c.asignado_a");
        $st->execute([$lineaId]);
        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$u, $n]) {
            $r[(int) $u] = (int) $n;
        }
        return $r;
    }

    /**
     * Si la conversación no tiene a nadie, la reparte. Devuelve el id asignado (o null).
     * $texto: lo que escribió (para las reglas por palabras).
     */
    public static function alLlegar(PDO $db, array $linea, array $contacto, string $texto, ?DateTimeImmutable $ahora = null): ?int
    {
        if (!empty($contacto['asignado_a']) || ($contacto['tipo'] ?? '') === 'personal') {
            return isset($contacto['asignado_a']) ? (int) $contacto['asignado_a'] ?: null : null;
        }
        $modo = (string) ($linea['reparto'] ?? 'manual');
        $reglas = self::reglas($linea);
        if ($modo === 'manual' && !$reglas) {
            return null;
        }
        $db->beginTransaction();
        try {
            $db->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([7, (int) $linea['id']]);   // dos mensajes a la vez no se reparten mal
            $st = $db->prepare('SELECT asignado_a, reparto_ultimo FROM contactos c JOIN lineas l ON l.id = c.linea_id WHERE c.id = ?');
            $st->execute([(int) $contacto['id']]);
            [$ya, $ultimo] = $st->fetch(PDO::FETCH_NUM) ?: [null, null];
            if ($ya !== null) {
                $db->commit();
                return (int) $ya;
            }
            $id = self::elegir($modo, self::candidatos($db, $linea), $reglas, $texto, $ultimo !== null ? (int) $ultimo : null,
                               $modo === 'menos_carga' ? self::carga($db, (int) $linea['id']) : []);
            if ($id !== null) {
                $db->prepare('UPDATE contactos SET asignado_a = ?, asignado_en = ? WHERE id = ? AND asignado_a IS NULL')
                   ->execute([$id, ($ahora ?? new DateTimeImmutable())->format('c'), (int) $contacto['id']]);
                $db->prepare('UPDATE lineas SET reparto_ultimo = ? WHERE id = ?')->execute([$id, (int) $linea['id']]);
            }
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Guarda el modo y las reglas del formulario de Equipo. */
    public static function guardar(PDO $db, array $linea, array $d): void
    {
        $modo = (string) ($d['reparto'] ?? 'manual');
        if (!isset(self::MODOS[$modo])) {
            throw new RuntimeException('Modo de reparto no válido.');
        }
        $equipo = [];
        foreach (Empresas::usuarios($db, (int) $linea['empresa_id']) as $u) {
            $equipo[(int) $u['id']] = true;
        }
        $reglas = [];
        foreach ((array) ($d['regla_palabras'] ?? []) as $i => $p) {
            $p = implode(', ', Reglas::listaPalabras((string) $p));
            $u = (int) ($d['regla_usuario'][$i] ?? 0);
            if ($p === '' || $u === 0) {
                continue;
            }
            if (!isset($equipo[$u])) {
                throw new RuntimeException('Esa persona no es de tu equipo.');
            }
            $reglas[] = ['palabras' => mb_substr($p, 0, 300), 'usuario_id' => $u];
        }
        $db->prepare('UPDATE lineas SET reparto = ?, reparto_reglas = ? WHERE id = ?')
           ->execute([$modo, json_encode(array_slice($reglas, 0, 20), JSON_UNESCAPED_UNICODE), (int) $linea['id']]);
    }

    /** Disponible / Ausente y si recibe chats (cada uno lo suyo; el administrador, de todos). */
    public static function cambiarPersona(PDO $db, int $empresaId, int $usuarioId, string $que, bool $valor): void
    {
        if (!in_array($que, ['disponible', 'recibe_chats'], true)) {
            throw new RuntimeException('Cambio no válido.');
        }
        $db->prepare("UPDATE usuarios SET $que = ? WHERE id = ? AND (empresa_id = ? OR super)")->execute([$valor ? 't' : 'f', $usuarioId, $empresaId]);
    }

    /** Pasa todas las conversaciones abiertas de una persona a otra (o a nadie). Devuelve cuántas. */
    public static function pasarTodas(PDO $db, int $lineaId, int $empresaId, int $de, ?int $a): int
    {
        if ($a !== null) {
            $st = $db->prepare('SELECT 1 FROM usuarios WHERE id = ? AND activo AND empresa_id = ?');
            $st->execute([$a, $empresaId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Esa persona no es de tu equipo.');
            }
        }
        $st = $db->prepare('UPDATE contactos SET asignado_a = ?, asignado_en = now() WHERE linea_id = ? AND asignado_a = ?');
        $st->execute([$a, $lineaId, $de]);
        return $st->rowCount();
    }

    /**
     * Vista del supervisor: por persona, conversaciones asignadas, esperando respuesta (y desde cuándo la más vieja),
     * sin leer, respondidas hoy y tiempo de primera respuesta de los últimos 7 días.
     */
    public static function tablero(PDO $db, array $linea, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $lid = (int) $linea['id'];
        $hoy = $ahora->setTime(0, 0)->format('c');
        $semana = $ahora->modify('-7 days')->format('c');
        $st = $db->prepare("SELECT u.id, u.nombre, u.rol, u.disponible, u.recibe_chats,
                (SELECT count(*) FROM contactos c WHERE c.linea_id = :l AND c.asignado_a = u.id) AS asignadas,
                (SELECT count(*) FROM contactos c JOIN LATERAL (SELECT direccion FROM mensajes WHERE contacto_id = c.id ORDER BY creado DESC, id DESC LIMIT 1) m ON true
                    WHERE c.linea_id = :l AND c.asignado_a = u.id AND m.direccion = 'entra') AS esperando,
                (SELECT min(x.creado) FROM contactos c JOIN LATERAL (SELECT direccion, creado FROM mensajes WHERE contacto_id = c.id ORDER BY creado DESC, id DESC LIMIT 1) x ON true
                    WHERE c.linea_id = :l AND c.asignado_a = u.id AND x.direccion = 'entra') AS espera_desde,
                (SELECT count(*) FROM mensajes m JOIN contactos c ON c.id = m.contacto_id WHERE c.linea_id = :l AND c.asignado_a = u.id AND NOT m.leido) AS sin_leer,
                (SELECT count(*) FROM mensajes m WHERE m.linea_id = :l AND m.usuario_id = u.id AND m.direccion = 'sale' AND m.creado >= :hoy) AS enviados_hoy,
                (SELECT count(DISTINCT m.contacto_id) FROM mensajes m WHERE m.linea_id = :l AND m.usuario_id = u.id AND m.direccion = 'sale' AND m.creado >= :hoy) AS atendidos_hoy
            FROM usuarios u WHERE u.empresa_id = :e AND u.activo AND NOT u.super ORDER BY u.nombre");
        $st->execute(['l' => $lid, 'e' => (int) $linea['empresa_id'], 'hoy' => $hoy]);
        $personas = $st->fetchAll();
        // Tiempo de primera respuesta (7 días): desde un mensaje del cliente sin respuesta hasta la primera respuesta de esa persona
        $st = $db->prepare("SELECT s.usuario_id, avg(EXTRACT(EPOCH FROM (s.creado - e.creado)))::int
                            FROM mensajes e JOIN LATERAL (SELECT usuario_id, creado FROM mensajes WHERE contacto_id = e.contacto_id AND direccion = 'sale'
                                 AND creado > e.creado ORDER BY creado LIMIT 1) s ON true
                            WHERE e.linea_id = ? AND e.direccion = 'entra' AND e.creado >= ? AND s.usuario_id IS NOT NULL
                              AND NOT EXISTS (SELECT 1 FROM mensajes p WHERE p.contacto_id = e.contacto_id AND p.direccion = 'entra' AND p.creado < e.creado AND p.creado > e.creado - interval '30 minutes')
                            GROUP BY s.usuario_id");
        $st->execute([$lid, $semana]);
        $tiempos = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$u, $seg]) {
            $tiempos[(int) $u] = (int) $seg;
        }
        foreach ($personas as &$p) {
            $p['primera_respuesta'] = $tiempos[(int) $p['id']] ?? null;
        }
        unset($p);
        $st = $db->prepare("SELECT count(*) FROM contactos c JOIN LATERAL (SELECT direccion FROM mensajes WHERE contacto_id = c.id ORDER BY creado DESC, id DESC LIMIT 1) m ON true
                            WHERE c.linea_id = ? AND c.asignado_a IS NULL AND c.tipo <> 'personal' AND m.direccion = 'entra'");
        $st->execute([$lid]);
        return ['personas' => $personas, 'sin_asignar' => (int) $st->fetchColumn()];
    }
}
