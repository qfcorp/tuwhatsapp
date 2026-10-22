<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Módulo "Enlaces, QR y botón web".
 * Un enlace corto (https://whatsapp.tukanec.com/w.php?c=abc123) abre el chat del negocio con un texto listo
 * y cuenta cuántas veces lo tocaron. Sirve para Instagram, Facebook, tarjetas, volantes (con su QR) y la página web.
 */
final class Enlaces
{
    private const LETRAS = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function crear(PDO $db, int $lineaId, string $nombre, string $texto): array
    {
        $nombre = trim($nombre);
        $texto = trim($texto);
        if ($nombre === '') {
            throw new RuntimeException('Ponle un nombre al enlace (por ejemplo "Instagram" o "Volante octubre").');
        }
        if (mb_strlen($nombre) > 60 || mb_strlen($texto) > 500) {
            throw new RuntimeException('El nombre (60) o el texto (500 letras) es muy largo.');
        }
        $n = $db->prepare('SELECT count(*) FROM enlaces WHERE linea_id = ?');
        $n->execute([$lineaId]);
        if ((int) $n->fetchColumn() >= 50) {
            throw new RuntimeException('Ya tienes 50 enlaces: borra alguno que no uses.');
        }
        for ($i = 0; $i < 5; $i++) {
            $codigo = '';
            for ($j = 0; $j < 6; $j++) {
                $codigo .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            }
            $st = $db->prepare('INSERT INTO enlaces (linea_id, codigo, nombre, texto) VALUES (?, ?, ?, ?) ON CONFLICT (codigo) DO NOTHING RETURNING *');
            $st->execute([$lineaId, $codigo, $nombre, $texto]);
            if ($fila = $st->fetch()) {
                return $fila;
            }
        }
        throw new RuntimeException('No se pudo crear el enlace. Intenta de nuevo.');
    }

    /** @return list<array> */
    public static function lista(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT * FROM enlaces WHERE linea_id = ? ORDER BY creado DESC, id DESC');
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function borrar(PDO $db, int $lineaId, int $id): void
    {
        $db->prepare('DELETE FROM enlaces WHERE id = ? AND linea_id = ?')->execute([$id, $lineaId]);
    }

    /** Lo usa w.php: suma el clic y devuelve a dónde mandar (o null si no existe o el módulo está apagado). */
    public static function abrir(PDO $db, string $codigo): ?string
    {
        if (!preg_match('/^[a-z2-9]{6}$/', $codigo)) {
            return null;
        }
        $st = $db->prepare('UPDATE enlaces e SET clics = clics + 1 FROM lineas l
                            WHERE e.codigo = ? AND e.activo AND l.id = e.linea_id AND l.telefono <> \'\'
                            RETURNING e.texto, l.telefono, l.empresa_id');
        $st->execute([$codigo]);
        $f = $st->fetch();
        if (!$f || !Modulos::activo(Empresas::buscar($db, (int) $f['empresa_id']), 'enlaces')) {
            return null;
        }
        return Telefono::enlace((string) $f['telefono'], (string) $f['texto']);
    }

    public static function url(string $codigo): string
    {
        return rtrim((string) (getenv('TUWA_URL') ?: ''), '/') . '/w.php?c=' . $codigo;
    }

    /** Código para pegar en la página web: botón flotante que abre WhatsApp. */
    public static function codigoBoton(string $destino, string $color = '#25D366', string $texto = 'Escríbenos'): string
    {
        $base = rtrim((string) (getenv('TUWA_URL') ?: ''), '/');
        return '<script src="' . $base . '/boton.js" data-enlace="' . htmlspecialchars($destino, ENT_QUOTES) . '" data-color="'
            . htmlspecialchars($color, ENT_QUOTES) . '" data-texto="' . htmlspecialchars($texto, ENT_QUOTES) . '" async></script>';
    }
}
