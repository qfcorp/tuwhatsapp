<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Módulo "Embudo de ventas": cada conversación (contacto) está en una etapa y puede tener el valor de la venta.
 * Las conversaciones nuevas de clientes y desconocidos entran solas a "Nuevo"; los contactos personales nunca.
 */
final class Embudo
{
    public const ETAPAS = [
        'nuevo'      => 'Nuevo',
        'interesado' => 'Interesado',
        'cotizado'   => 'Cotizado',
        'ganado'     => 'Ganado',
        'perdido'    => 'Perdido',
    ];

    /** Entra a "Nuevo" si todavía no está en el embudo (lo llama el webhook cuando escribe alguien). */
    public static function entrar(PDO $db, int $contactoId): void
    {
        $db->prepare("UPDATE contactos SET etapa = 'nuevo', etapa_cambio = now() WHERE id = ? AND etapa IS NULL AND tipo <> 'personal'")
           ->execute([$contactoId]);
    }

    /**
     * Cambia la etapa (vacío = sacar del embudo) y, si viene, el valor.
     * @param ?string $valor texto como lo escribe la persona: "1.250,50", "1250.50", "$300"
     */
    public static function mover(PDO $db, int $lineaId, int $contactoId, string $etapa, ?string $valor = null): void
    {
        if ($etapa !== '' && !isset(self::ETAPAS[$etapa])) {
            throw new RuntimeException('Esa etapa no existe.');
        }
        $sets = ['etapa = ?', 'etapa_cambio = CASE WHEN etapa IS DISTINCT FROM ? THEN now() ELSE etapa_cambio END'];
        $params = [$etapa === '' ? null : $etapa, $etapa === '' ? null : $etapa];
        if ($valor !== null) {
            $sets[] = 'valor = ?';
            $params[] = self::valor($valor);
        }
        $params[] = $contactoId;
        $params[] = $lineaId;
        $st = $db->prepare('UPDATE contactos SET ' . implode(', ', $sets) . ' WHERE id = ? AND linea_id = ?');
        $st->execute($params);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Esa conversación no existe.');
        }
    }

    /** "1.250,50" -> 1250.5 · "1250.50" -> 1250.5 · "$ 300" -> 300 · vacío -> 0 */
    public static function valor(string $t): float
    {
        $t = preg_replace('/[^\d,.\-]/', '', $t) ?? '';
        if ($t === '' || $t === '-') {
            return 0.0;
        }
        $coma = strrpos($t, ',');
        $punto = strrpos($t, '.');
        if ($coma !== false && $punto !== false) {          // los dos: el último es el decimal (1.250,50 o 1,250.50)
            $t = $coma > $punto ? str_replace(['.', ','], ['', '.'], $t) : str_replace(',', '', $t);
        } elseif ($coma !== false) {                        // solo coma: 1,250 son miles; 12,5 es decimal
            $t = preg_match('/^-?\d{1,3}(,\d{3})+$/', $t) ? str_replace(',', '', $t) : str_replace(',', '.', $t);
        } elseif ($punto !== false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t)) {   // 1.250 son miles
            $t = str_replace('.', '', $t);
        }
        $v = round((float) $t, 2);
        if ($v < 0 || $v > 9999999999) {
            throw new RuntimeException('El valor no es válido.');
        }
        return $v;
    }

    /**
     * Tablero: por etapa, las conversaciones con su último mensaje (las más movidas primero).
     * @return array<string, list<array>>
     */
    public static function tablero(PDO $db, int $lineaId, string $buscar = '', int $porEtapa = 60): array
    {
        $r = array_fill_keys(array_keys(self::ETAPAS), []);
        $filtro = '';
        $params = [$lineaId];
        if ($buscar !== '') {
            $b = '%' . $buscar . '%';
            $digitos = (string) preg_replace('/\D+/', '', $buscar);
            $filtro = ' AND (c.nombre ILIKE ? OR c.nombre_whatsapp ILIKE ? OR c.etiquetas ILIKE ?' . ($digitos !== '' ? ' OR c.telefono LIKE ?' : '') . ')';
            array_push($params, $b, $b, $b);
            if ($digitos !== '') {
                $params[] = '%' . $digitos . '%';
            }
        }
        $st = $db->prepare("SELECT * FROM (
                SELECT c.*, row_number() OVER (PARTITION BY c.etapa ORDER BY COALESCE(c.etapa_cambio, c.creado) DESC, c.id DESC) AS n,
                       (SELECT m.texto FROM mensajes m WHERE m.contacto_id = c.id ORDER BY m.creado DESC, m.id DESC LIMIT 1) AS ultimo_texto,
                       (SELECT max(m.creado) FROM mensajes m WHERE m.contacto_id = c.id) AS ultimo_en
                FROM contactos c WHERE c.linea_id = ? AND c.etapa IS NOT NULL $filtro) x
            WHERE n <= " . (int) $porEtapa . ' ORDER BY COALESCE(etapa_cambio, creado) DESC, id DESC');
        $st->execute($params);
        foreach ($st->fetchAll() as $c) {
            $r[$c['etapa']][] = $c;
        }
        return $r;
    }

    /** @return array<string, array{n:int, valor:float}> */
    public static function totales(PDO $db, int $lineaId): array
    {
        $r = array_map(fn () => ['n' => 0, 'valor' => 0.0], self::ETAPAS);
        $st = $db->prepare('SELECT etapa, count(*) AS n, COALESCE(sum(valor), 0) AS valor FROM contactos WHERE linea_id = ? AND etapa IS NOT NULL GROUP BY etapa');
        $st->execute([$lineaId]);
        foreach ($st->fetchAll() as $f) {
            $r[$f['etapa']] = ['n' => (int) $f['n'], 'valor' => (float) $f['valor']];
        }
        return $r;
    }
}
