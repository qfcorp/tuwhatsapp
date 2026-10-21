<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Campañas masivas: una plantilla aprobada enviada a muchos contactos, poco a poco.
 *  - Nunca a contactos Personales ni a quien pidió la baja.
 *  - Por defecto solo a quien aceptó recibir promociones (lo pide Meta para marketing; si no, bloquean el número).
 *  - Cada envío pasa por Mensajeria: respeta el tope del mes. Si se llega al tope, la campaña se pausa sola.
 *  - Se envía desde bin/campanias.php (cada minuto, por cron), unos cuantos por minuto para cuidar la calidad del número.
 */
final class Campanias
{
    public const ESTADOS = ['enviando' => 'Enviando', 'pausada' => 'Pausada', 'terminada' => 'Terminada', 'cancelada' => 'Cancelada'];
    public const FILTROS = ['cliente' => 'Solo clientes', 'cliente_desconocido' => 'Clientes y desconocidos'];
    /** Palabras con las que un contacto pide no recibir más promociones. */
    public const PALABRAS_BAJA = ['baja', 'stop', 'no mas', 'no mas mensajes', 'no quiero mas mensajes', 'no me escriban', 'cancelar suscripcion', 'darme de baja', 'unsubscribe'];

    /** ¿El mensaje pide la baja? Solo si es corto y es justo eso (no "dame de baja el pedido de ayer"). */
    public static function pideBaja(string $texto): bool
    {
        $t = Reglas::simple($texto);
        return $t !== '' && mb_strlen($t) <= 40 && in_array($t, self::PALABRAS_BAJA, true);
    }

    /** @return array{sql:string, params:array} contactos a los que puede llegar la campaña */
    private static function filtro(int $lineaId, string $tipo, string $etiqueta, bool $soloAcepta): array
    {
        $donde = "c.linea_id = :l AND c.tipo <> 'personal' AND c.marketing <> 'baja'";
        $p = ['l' => $lineaId];
        $donde .= $tipo === 'cliente_desconocido' ? " AND c.tipo IN ('cliente', 'desconocido')" : " AND c.tipo = 'cliente'";
        if ($soloAcepta) {
            $donde .= " AND c.marketing = 'acepta'";
        }
        $etiqueta = Reglas::simple($etiqueta);
        if ($etiqueta !== '') {
            $donde .= " AND (',' || lower(replace(c.etiquetas, ' ', '')) || ',') LIKE :e";
            $p['e'] = '%,' . str_replace(' ', '', $etiqueta) . ',%';
        }
        return ['sql' => $donde, 'params' => $p];
    }

    public static function contar(PDO $db, int $lineaId, string $tipo, string $etiqueta, bool $soloAcepta): int
    {
        $f = self::filtro($lineaId, $tipo, $etiqueta, $soloAcepta);
        $st = $db->prepare('SELECT count(*) FROM contactos c WHERE ' . $f['sql']);
        $st->execute($f['params']);
        return (int) $st->fetchColumn();
    }

    /**
     * Valida el formulario y arma la campaña (sin guardarla): para la pantalla de "revisar antes de enviar".
     * @param list<array> $plantillas aprobadas (Meta::plantillas)
     */
    public static function preparar(PDO $db, array $linea, array $f, array $plantillas): array
    {
        $p = null;
        foreach ($plantillas as $x) {
            if ($x['nombre'] . '|' . $x['idioma'] === (string) ($f['plantilla'] ?? '')) {
                $p = $x;
            }
        }
        if (!$p) {
            throw new RuntimeException('Elige una plantilla aprobada.');
        }
        $nombre = trim((string) ($f['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = $p['nombre'] . ' ' . (new DateTimeImmutable())->format('d/m');
        }
        $datos = [];
        for ($n = 1; $n <= (int) $p['espacios']; $n++) {
            $tipo = (string) ($f['dato_tipo'][$n] ?? 'fijo');
            $valor = trim((string) ($f['dato_valor'][$n] ?? ''));
            if ($tipo === 'nombre') {
                $datos[] = ['tipo' => 'nombre', 'valor' => $valor !== '' ? mb_substr($valor, 0, 60) : 'amigo'];
            } else {
                if ($valor === '') {
                    throw new RuntimeException("Escribe qué va en {{{$n}}} o elige \"Nombre del contacto\".");
                }
                $datos[] = ['tipo' => 'fijo', 'valor' => mb_substr($valor, 0, 500)];
            }
        }
        $tipo = isset(self::FILTROS[(string) ($f['filtro_tipo'] ?? '')]) ? (string) $f['filtro_tipo'] : 'cliente';
        $etiqueta = mb_substr(trim((string) ($f['filtro_etiqueta'] ?? '')), 0, 40);
        $soloAcepta = !empty($f['solo_acepta']);
        $porMinuto = max(1, min(600, (int) ($f['por_minuto'] ?? 30)));
        $total = self::contar($db, (int) $linea['id'], $tipo, $etiqueta, $soloAcepta);
        $precio = Gasto::precios($db)[$p['categoria']] ?? 0.0;
        $gastado = Gasto::delMes($db, (int) $linea['id']);
        $queda = max(0.0, (float) $linea['tope_mensual'] - $gastado);
        return [
            'nombre' => mb_substr($nombre, 0, 100), 'plantilla' => $p, 'datos' => $datos, 'filtro_tipo' => $tipo,
            'filtro_etiqueta' => $etiqueta, 'solo_acepta' => $soloAcepta, 'por_minuto' => $porMinuto,
            'total' => $total, 'precio' => $precio, 'costo' => round($total * $precio, 4), 'queda' => $queda,
            'alcanza' => $precio > 0 ? (int) floor($queda / $precio + 1e-9) : $total,
            'minutos' => (int) ceil($total / $porMinuto),
            'ejemplo' => self::cuerpo($p['cuerpo'], $datos, ['nombre' => 'Ana López', 'nombre_whatsapp' => '', 'telefono' => '593991234567']),
        ];
    }

    /** Guarda la campaña y la lista de destinatarios (se toma la foto de la lista en este momento). */
    public static function crear(PDO $db, array $linea, array $prep, ?int $usuarioId): int
    {
        if ($prep['total'] < 1) {
            throw new RuntimeException('No hay contactos que cumplan esas condiciones.');
        }
        $p = $prep['plantilla'];
        $db->beginTransaction();
        try {
            $st = $db->prepare('INSERT INTO campanias (linea_id, nombre, plantilla, idioma, categoria, cuerpo, datos, filtro_tipo, filtro_etiqueta,
                                solo_acepta, por_minuto, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $st->execute([$linea['id'], $prep['nombre'], $p['nombre'], $p['idioma'], $p['categoria'], $p['cuerpo'],
                json_encode($prep['datos'], JSON_UNESCAPED_UNICODE), $prep['filtro_tipo'], $prep['filtro_etiqueta'],
                $prep['solo_acepta'] ? 't' : 'f', $prep['por_minuto'], $usuarioId]);
            $id = (int) $st->fetchColumn();
            $f = self::filtro((int) $linea['id'], $prep['filtro_tipo'], $prep['filtro_etiqueta'], $prep['solo_acepta']);
            $st = $db->prepare('INSERT INTO campania_envios (campania_id, contacto_id) SELECT :campania, c.id FROM contactos c WHERE ' . $f['sql']);
            $st->execute($f['params'] + ['campania' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function cuerpo(string $cuerpo, array $datos, array $contacto): string
    {
        foreach (self::valores($datos, $contacto) as $i => $v) {
            $cuerpo = str_replace('{{' . ($i + 1) . '}}', $v, $cuerpo);
        }
        return $cuerpo;
    }

    /** Lo que va en {{1}}, {{2}}... para un contacto: el primer nombre o el texto fijo. */
    public static function valores(array $datos, array $contacto): array
    {
        $r = [];
        foreach ($datos as $d) {
            if (($d['tipo'] ?? '') === 'nombre') {
                $n = trim((string) ($contacto['nombre'] ?? ''));
                if ($n === '') {
                    $n = trim((string) ($contacto['nombre_whatsapp'] ?? ''));
                }
                $primero = $n !== '' ? (preg_split('/\s+/u', $n)[0] ?? '') : '';
                $r[] = $primero !== '' && !preg_match('/^\+?\d/', $primero) ? mb_convert_case(mb_strtolower($primero), MB_CASE_TITLE) : (string) ($d['valor'] ?? 'amigo');
            } else {
                $r[] = (string) ($d['valor'] ?? '');
            }
        }
        return $r;
    }

    public static function lista(PDO $db, int $lineaId): array
    {
        $st = $db->prepare("SELECT k.*,
                (SELECT count(*) FROM campania_envios e WHERE e.campania_id = k.id) AS total,
                (SELECT count(*) FROM campania_envios e WHERE e.campania_id = k.id AND e.estado = 'enviado') AS enviados,
                (SELECT count(*) FROM campania_envios e WHERE e.campania_id = k.id AND e.estado = 'fallido') AS fallidos,
                (SELECT count(*) FROM campania_envios e WHERE e.campania_id = k.id AND e.estado = 'omitido') AS omitidos,
                (SELECT count(*) FROM campania_envios e WHERE e.campania_id = k.id AND e.estado = 'pendiente') AS pendientes,
                (SELECT COALESCE(sum(m.costo), 0) FROM campania_envios e JOIN mensajes m ON m.id = e.mensaje_id WHERE e.campania_id = k.id) AS costo,
                (SELECT count(*) FROM campania_envios e JOIN mensajes m ON m.id = e.mensaje_id WHERE e.campania_id = k.id AND m.estado = 'leido') AS leidos,
                (SELECT count(DISTINCT e.contacto_id) FROM campania_envios e JOIN mensajes m ON m.id = e.mensaje_id
                    JOIN mensajes r ON r.contacto_id = e.contacto_id
                    WHERE e.campania_id = k.id AND e.estado = 'enviado' AND r.direccion = 'entra' AND r.creado > m.creado) AS respondieron
            FROM campanias k WHERE k.linea_id = ? ORDER BY k.creado DESC LIMIT 50");
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function errores(PDO $db, int $campaniaId): array
    {
        $st = $db->prepare("SELECT e.error, count(*) AS n FROM campania_envios e WHERE e.campania_id = ? AND e.estado IN ('fallido', 'omitido')
                            AND e.error IS NOT NULL GROUP BY e.error ORDER BY n DESC LIMIT 5");
        $st->execute([$campaniaId]);
        return $st->fetchAll();
    }

    /** pausar | reanudar | cancelar, solo sobre campañas de ese número. */
    public static function cambiar(PDO $db, int $lineaId, int $id, string $accion): void
    {
        $st = $db->prepare('SELECT * FROM campanias WHERE id = ? AND linea_id = ?');
        $st->execute([$id, $lineaId]);
        $k = $st->fetch();
        if (!$k) {
            throw new RuntimeException('Esa campaña no existe.');
        }
        if ($accion === 'pausar' && $k['estado'] === 'enviando') {
            $db->prepare("UPDATE campanias SET estado = 'pausada', motivo_pausa = 'Pausada a mano' WHERE id = ?")->execute([$id]);
        } elseif ($accion === 'reanudar' && $k['estado'] === 'pausada') {
            $db->prepare("UPDATE campanias SET estado = 'enviando', motivo_pausa = '' WHERE id = ?")->execute([$id]);
        } elseif ($accion === 'cancelar' && in_array($k['estado'], ['enviando', 'pausada'], true)) {
            $db->prepare("UPDATE campania_envios SET estado = 'omitido', error = 'Campaña cancelada' WHERE campania_id = ? AND estado = 'pendiente'")->execute([$id]);
            $db->prepare("UPDATE campanias SET estado = 'cancelada', terminado = now() WHERE id = ?")->execute([$id]);
        } else {
            throw new RuntimeException('No se puede hacer eso con una campaña ' . mb_strtolower(self::ESTADOS[$k['estado']]) . '.');
        }
    }

    /**
     * Envía el siguiente grupo de cada campaña activa (lo llama bin/campanias.php cada minuto).
     * @return array{enviados:int, fallidos:int, pausadas:int}
     */
    public static function procesar(PDO $db, ?DateTimeImmutable $ahora = null): array
    {
        $r = ['enviados' => 0, 'fallidos' => 0, 'pausadas' => 0];
        if (!$db->query('SELECT pg_try_advisory_lock(424242)')->fetchColumn()) {
            return $r;   // otra corrida sigue trabajando
        }
        try {
            $campanias = $db->query("SELECT * FROM campanias WHERE estado = 'enviando' ORDER BY creado")->fetchAll();
            foreach ($campanias as $k) {
                $linea = Lineas::buscar($db, (int) $k['linea_id']);
                if (!$linea) {
                    continue;
                }
                $p = ['nombre' => $k['plantilla'], 'idioma' => $k['idioma'], 'categoria' => $k['categoria'], 'cuerpo' => $k['cuerpo']];
                $datos = json_decode((string) $k['datos'], true) ?: [];
                $st = $db->prepare("SELECT e.id AS envio_id, c.* FROM campania_envios e JOIN contactos c ON c.id = e.contacto_id
                                    WHERE e.campania_id = ? AND e.estado = 'pendiente' ORDER BY e.id LIMIT " . (int) $k['por_minuto']);
                $st->execute([$k['id']]);
                $grupo = $st->fetchAll();
                $mensajeria = new Mensajeria($db, $ahora);
                foreach ($grupo as $c) {
                    if ($c['marketing'] === 'baja' || $c['tipo'] === 'personal') {
                        $db->prepare("UPDATE campania_envios SET estado = 'omitido', error = 'Pidió no recibir más mensajes o es personal' WHERE id = ?")->execute([$c['envio_id']]);
                        continue;
                    }
                    $x = $mensajeria->plantilla($linea, $c, $p, self::valores($datos, $c), 'campania', $k['usuario_id'] !== null ? (int) $k['usuario_id'] : null);
                    if ($x['ok']) {
                        $db->prepare("UPDATE campania_envios SET estado = 'enviado', mensaje_id = ?, enviado_en = now(), error = NULL WHERE id = ?")
                           ->execute([$x['id'], $c['envio_id']]);
                        $r['enviados']++;
                        continue;
                    }
                    if (!empty($x['bloqueado']) || str_contains((string) $x['error'], 'no está al día')) {
                        // El intento bloqueado no se cuenta como enviado: queda pendiente para cuando se reanude
                        if (!empty($x['id'])) {
                            $db->prepare('DELETE FROM mensajes WHERE id = ? AND estado = \'bloqueado\'')->execute([$x['id']]);
                        }
                        $db->prepare("UPDATE campanias SET estado = 'pausada', motivo_pausa = ? WHERE id = ?")
                           ->execute([!empty($x['bloqueado']) ? 'Se llegó al tope de gasto del mes. Sube el tope en Gasto y reanuda.' : 'La cuenta no está al día.', $k['id']]);
                        $r['pausadas']++;
                        continue 2;
                    }
                    $db->prepare("UPDATE campania_envios SET estado = 'fallido', mensaje_id = ?, error = ? WHERE id = ?")
                       ->execute([$x['id'] ?? null, mb_substr((string) $x['error'], 0, 300), $c['envio_id']]);
                    $r['fallidos']++;
                }
                $st = $db->prepare("SELECT count(*) FROM campania_envios WHERE campania_id = ? AND estado = 'pendiente'");
                $st->execute([$k['id']]);
                if ((int) $st->fetchColumn() === 0) {
                    $db->prepare("UPDATE campanias SET estado = 'terminada', terminado = now() WHERE id = ? AND estado = 'enviando'")->execute([$k['id']]);
                }
            }
        } finally {
            $db->query('SELECT pg_advisory_unlock(424242)');
        }
        return $r;
    }

    /** Permiso de promociones de un contacto (desde el panel, la API o el webhook). */
    public static function marcarMarketing(PDO $db, int $lineaId, int $contactoId, string $estado): void
    {
        if (!in_array($estado, ['sin_dato', 'acepta', 'baja'], true)) {
            throw new RuntimeException('Estado de promociones no válido.');
        }
        $db->prepare('UPDATE contactos SET marketing = ?, marketing_en = now() WHERE id = ? AND linea_id = ?')->execute([$estado, $contactoId, $lineaId]);
    }
}
