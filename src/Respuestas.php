<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/** Respuestas rápidas: textos guardados que el equipo pega en el chat con un toque. */
final class Respuestas
{
    public static function lista(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT * FROM respuestas_rapidas WHERE linea_id = ? ORDER BY atajo');
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function guardar(PDO $db, int $lineaId, string $atajo, string $texto, ?int $id = null): void
    {
        $atajo = mb_substr(trim(mb_strtolower(ltrim(trim($atajo), '/'))), 0, 40);
        $texto = trim($texto);
        if ($atajo === '') {
            throw new RuntimeException('Escribe un nombre corto (ejemplo: precios).');
        }
        if ($texto === '' || mb_strlen($texto) > 4000) {
            throw new RuntimeException('Escribe el texto de la respuesta (máximo 4000 letras).');
        }
        $st = $db->prepare('SELECT id FROM respuestas_rapidas WHERE linea_id = ? AND atajo = ?');
        $st->execute([$lineaId, $atajo]);
        $otra = $st->fetchColumn();
        if ($otra !== false && (int) $otra !== (int) $id) {
            throw new RuntimeException('Ya tienes una respuesta llamada "' . $atajo . '".');
        }
        if ($id) {
            $db->prepare('UPDATE respuestas_rapidas SET atajo = ?, texto = ? WHERE id = ? AND linea_id = ?')->execute([$atajo, $texto, $id, $lineaId]);
        } else {
            $db->prepare('INSERT INTO respuestas_rapidas (linea_id, atajo, texto) VALUES (?, ?, ?)')->execute([$lineaId, $atajo, $texto]);
        }
    }

    public static function borrar(PDO $db, int $lineaId, int $id): void
    {
        $db->prepare('DELETE FROM respuestas_rapidas WHERE id = ? AND linea_id = ?')->execute([$id, $lineaId]);
    }
}
