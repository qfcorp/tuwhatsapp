<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * De qué anuncio (o publicación) de Facebook o Instagram llegó cada cliente.
 * Meta manda "referral" en el mensaje que el cliente escribe desde un anuncio "Enviar mensaje de WhatsApp":
 *   source_type (ad | post), source_id, source_url, headline, body, ctwa_clid…
 * El contacto guarda el PRIMER anuncio (de dónde vino); cada llegada queda en anuncios_llegadas para el reporte.
 * Reporte por anuncio: personas que escribieron, cuántas se volvieron clientes, ventas ganadas del embudo y pedidos pagados.
 */
final class Anuncios
{
    public static function registrar(PDO $db, int $lineaId, array $contacto, array $referral, DateTimeImmutable $cuando): void
    {
        $id = trim((string) ($referral['source_id'] ?? ''));
        $url = mb_substr(trim((string) ($referral['source_url'] ?? '')), 0, 500);
        if ($id === '') {
            $id = $url !== '' ? 'url:' . substr(md5($url), 0, 12) : 'sin-id';
        }
        $titulo = trim((string) ($referral['headline'] ?? ''));
        if ($titulo === '') {
            $titulo = mb_strimwidth(trim((string) ($referral['body'] ?? '')), 0, 80, '…');
        }
        $titulo = mb_substr($titulo, 0, 200);
        $tipo = ($referral['source_type'] ?? '') === 'post' ? 'publicacion' : 'anuncio';
        $db->prepare('INSERT INTO anuncios_llegadas (linea_id, contacto_id, anuncio_id, titulo, url, tipo, creado) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$lineaId, (int) $contacto['id'], mb_substr($id, 0, 100), $titulo, $url, $tipo, $cuando->format('c')]);
        $db->prepare('UPDATE contactos SET origen_tipo = ?, origen_id = ?, origen_titulo = ?, origen_url = ?, origen_en = ? WHERE id = ? AND origen_id IS NULL')
           ->execute([$tipo, mb_substr($id, 0, 100), $titulo, $url, $cuando->format('c'), (int) $contacto['id']]);
    }

    /**
     * Resultados por anuncio en los últimos $dias días.
     * @return list<array{anuncio_id:string, titulo:string, url:string, tipo:string, llegadas:int, personas:int, clientes:int, ganadas:int, valor_ganado:float, pedidos:int, vendido:float}>
     */
    public static function reporte(PDO $db, int $lineaId, int $dias = 30, ?DateTimeImmutable $ahora = null): array
    {
        $desde = ($ahora ?? new DateTimeImmutable())->modify('-' . max(1, min(365, $dias)) . ' days')->format('c');
        $st = $db->prepare("SELECT a.anuncio_id, max(a.titulo) AS titulo, max(a.url) AS url, max(a.tipo) AS tipo,
                                   count(*) AS llegadas, count(DISTINCT a.contacto_id) AS personas,
                                   count(DISTINCT a.contacto_id) FILTER (WHERE c.tipo = 'cliente') AS clientes,
                                   count(DISTINCT a.contacto_id) FILTER (WHERE c.etapa = 'ganado') AS ganadas
                            FROM anuncios_llegadas a JOIN contactos c ON c.id = a.contacto_id
                            WHERE a.linea_id = ? AND a.creado >= ? GROUP BY a.anuncio_id ORDER BY count(DISTINCT a.contacto_id) DESC LIMIT 50");
        $st->execute([$lineaId, $desde]);
        $filas = $st->fetchAll();
        foreach ($filas as &$f) {
            // valor de lo ganado y pedidos pagados de las personas que llegaron por ese anuncio (cada persona cuenta una vez)
            $q = $db->prepare("SELECT COALESCE(sum(c.valor) FILTER (WHERE c.etapa = 'ganado'), 0) FROM contactos c
                               WHERE c.id IN (SELECT DISTINCT contacto_id FROM anuncios_llegadas WHERE linea_id = ? AND anuncio_id = ? AND creado >= ?)");
            $q->execute([$lineaId, $f['anuncio_id'], $desde]);
            $f['valor_ganado'] = (float) $q->fetchColumn();
            $q = $db->prepare("SELECT count(*), COALESCE(sum(total), 0) FROM pedidos WHERE linea_id = ? AND estado IN ('pagado', 'enviado', 'entregado')
                               AND contacto_id IN (SELECT DISTINCT contacto_id FROM anuncios_llegadas WHERE linea_id = ? AND anuncio_id = ? AND creado >= ?)");
            $q->execute([$lineaId, $lineaId, $f['anuncio_id'], $desde]);
            [$n, $v] = $q->fetch(PDO::FETCH_NUM);
            $f['pedidos'] = (int) $n;
            $f['vendido'] = (float) $v;
            foreach (['llegadas', 'personas', 'clientes', 'ganadas'] as $k) {
                $f[$k] = (int) $f[$k];
            }
        }
        unset($f);
        return $filas;
    }
}
