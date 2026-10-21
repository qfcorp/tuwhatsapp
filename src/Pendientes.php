<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Mensajes para enviar a mano desde el celular (gratis): se tocan uno por uno y WhatsApp se abre con el texto listo.
 * Si el número está conectado también al celular (coexistencia), al enviarlo desde el celular se marca solo como enviado.
 */
final class Pendientes
{
    public static function agregar(PDO $db, int $lineaId, string $telefono, string $nombre, string $texto, string $origen = 'manual'): int
    {
        $tel = Telefono::normalizar($telefono);
        if ($tel === null) {
            throw new RuntimeException('El número "' . $telefono . '" no es válido. Escríbelo como 0991234567 o con el código del país.');
        }
        $texto = trim($texto);
        if ($texto === '') {
            throw new RuntimeException('Escribe el mensaje.');
        }
        if (mb_strlen($texto) > 4000) {
            throw new RuntimeException('El mensaje es muy largo (máximo 4000 letras).');
        }
        $st = $db->prepare('INSERT INTO pendientes (linea_id, telefono, nombre, texto, origen) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $st->execute([$lineaId, $tel, mb_substr(trim($nombre), 0, 100), $texto, mb_substr($origen, 0, 40)]);
        return (int) $st->fetchColumn();
    }

    public static function abiertos(PDO $db, int $lineaId): array
    {
        $st = $db->prepare("SELECT * FROM pendientes WHERE linea_id = ? AND estado = 'pendiente' ORDER BY creado, id");
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function recientes(PDO $db, int $lineaId): array
    {
        $st = $db->prepare("SELECT * FROM pendientes WHERE linea_id = ? AND estado <> 'pendiente' ORDER BY hecho_en DESC NULLS LAST LIMIT 20");
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function contar(PDO $db, int $lineaId): int
    {
        $st = $db->prepare("SELECT count(*) FROM pendientes WHERE linea_id = ? AND estado = 'pendiente'");
        $st->execute([$lineaId]);
        return (int) $st->fetchColumn();
    }

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT * FROM pendientes WHERE id = ? AND linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    public static function marcar(PDO $db, int $lineaId, int $id, string $estado): void
    {
        if (!in_array($estado, ['enviado', 'descartado', 'pendiente'], true)) {
            throw new RuntimeException('Estado no válido.');
        }
        $db->prepare("UPDATE pendientes SET estado = ?, hecho_en = CASE WHEN ? = 'pendiente' THEN NULL ELSE now() END WHERE id = ? AND linea_id = ?")
           ->execute([$estado, $estado, $id, $lineaId]);
    }

    /** Un mensaje que salió del celular (eco de Meta): si coincide con un pendiente, lo marca enviado. */
    public static function marcarPorEco(PDO $db, int $lineaId, string $telefono, string $texto): ?int
    {
        $st = $db->prepare("SELECT id, texto FROM pendientes WHERE linea_id = ? AND telefono = ? AND estado = 'pendiente' ORDER BY creado");
        $st->execute([$lineaId, $telefono]);
        $limpio = fn (string $t) => preg_replace('/\s+/u', ' ', trim($t));
        foreach ($st as $p) {
            if ($limpio($p['texto']) === $limpio($texto)) {
                self::marcar($db, $lineaId, (int) $p['id'], 'enviado');
                return (int) $p['id'];
            }
        }
        return null;
    }
}
