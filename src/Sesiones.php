<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * "Recordar este celular" (14 nov 2026): la app se queda abierta como WhatsApp y no vuelve a pedir la contraseña.
 *  - Al entrar se guarda una cookie tuwa_recordar = selector:validador (400 días, lo máximo que permite Chrome).
 *  - En la base solo queda el selector y el sha256 del validador (si roban la base, las cookies no sirven).
 *  - Si la sesión de PHP se pierde (servidor reiniciado, limpieza de sesiones), la cookie la vuelve a abrir sola.
 *  - El validador NO cambia en cada uso: así dos pestañas o pedidos al mismo tiempo no sacan al usuario.
 *  - Salir borra el de ese celular; cambiar la contraseña o desactivar al usuario borra todos.
 */
final class Sesiones
{
    public const COOKIE = 'tuwa_recordar';
    public const DIAS = 400;

    /** Crea el recuerdo de este celular y devuelve el valor de la cookie. */
    public static function recordar(PDO $db, int $usuarioId, string $userAgent = '', ?string $nombre = null): string
    {
        $selector = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO sesiones_recordadas (usuario_id, selector, validador_hash, dispositivo, expira)
                      VALUES (?, ?, ?, ?, now() + make_interval(days => ?))")
           ->execute([$usuarioId, $selector, hash('sha256', $validador), $nombre ?? self::dispositivo($userAgent), self::DIAS]);
        // Se guardan como mucho 20 por persona: se borran los más viejos
        $db->prepare('DELETE FROM sesiones_recordadas WHERE usuario_id = ? AND id NOT IN
                      (SELECT id FROM sesiones_recordadas WHERE usuario_id = ? ORDER BY ultimo_uso DESC LIMIT 20)')
           ->execute([$usuarioId, $usuarioId]);
        return $selector . ':' . $validador;
    }

    /** @return array{0:string,1:string}|null selector y validador de la cookie */
    private static function partes(string $cookie): ?array
    {
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /**
     * Revisa la cookie. Devuelve el id del usuario (activo) o null. Si sirve, alarga su vencimiento.
     * Una cookie con el selector bueno y el validador malo (robada o vieja) borra ese recuerdo.
     */
    public static function usuarioDeCookie(PDO $db, string $cookie): ?int
    {
        $p = self::partes($cookie);
        if (!$p) {
            return null;
        }
        $st = $db->prepare('SELECT s.id, s.usuario_id, s.validador_hash, s.expira > now() AS vigente, s.ultimo_uso < now() - interval \'1 hour\' AS viejo, u.activo
                            FROM sesiones_recordadas s JOIN usuarios u ON u.id = s.usuario_id WHERE s.selector = ?');
        $st->execute([$p[0]]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        if (!hash_equals((string) $f['validador_hash'], hash('sha256', $p[1])) || !$f['vigente'] || !$f['activo']) {
            $db->prepare('DELETE FROM sesiones_recordadas WHERE id = ?')->execute([$f['id']]);
            return null;
        }
        if ($f['viejo']) {   // no se escribe en la base en cada clic
            $db->prepare("UPDATE sesiones_recordadas SET ultimo_uso = now(), expira = now() + make_interval(days => ?) WHERE id = ?")
               ->execute([self::DIAS, $f['id']]);
        }
        return (int) $f['usuario_id'];
    }

    /** Id del recuerdo de la cookie (para marcar "este celular" en Mi cuenta). */
    public static function idDeCookie(PDO $db, string $cookie): ?int
    {
        $p = self::partes($cookie);
        if (!$p) {
            return null;
        }
        $st = $db->prepare('SELECT id, validador_hash FROM sesiones_recordadas WHERE selector = ?');
        $st->execute([$p[0]]);
        $f = $st->fetch();
        return $f && hash_equals((string) $f['validador_hash'], hash('sha256', $p[1])) ? (int) $f['id'] : null;
    }

    /** Borra el recuerdo de esta cookie (al tocar Salir). */
    public static function olvidar(PDO $db, string $cookie): void
    {
        $p = self::partes($cookie);
        if ($p) {
            $db->prepare('DELETE FROM sesiones_recordadas WHERE selector = ?')->execute([$p[0]]);
        }
    }

    /**
     * Borra todos los recuerdos de esa persona (contraseña cambiada, usuario desactivado, "cerrar en todos"). Puede dejar uno.
     * Además sube su generación de sesión: las sesiones de PHP que siguen abiertas en otros aparatos se cortan en su próximo clic
     * (ver usuario() en app/bootstrap.php). El aparato que lo hizo se vuelve a sellar con sellar_sesion().
     * Devuelve cuántos recuerdos borró.
     */
    public static function olvidarUsuario(PDO $db, int $usuarioId, ?int $menos = null): int
    {
        $st = $db->prepare('DELETE FROM sesiones_recordadas WHERE usuario_id = ? AND id <> ?');
        $st->execute([$usuarioId, $menos ?? 0]);
        $n = $st->rowCount();
        $db->prepare('UPDATE usuarios SET sesion_gen = sesion_gen + 1 WHERE id = ?')->execute([$usuarioId]);
        return $n;
    }

    /** Generación de sesión actual de esa persona. */
    public static function generacion(PDO $db, int $usuarioId): int
    {
        $st = $db->prepare('SELECT sesion_gen FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    /** Cerrar la sesión de un celular desde Mi cuenta (solo los propios). */
    public static function cerrar(PDO $db, int $usuarioId, int $id): bool
    {
        $st = $db->prepare('DELETE FROM sesiones_recordadas WHERE id = ? AND usuario_id = ?');
        $st->execute([$id, $usuarioId]);
        return $st->rowCount() > 0;
    }

    /** @return list<array> celulares con la sesión abierta (el más usado primero) */
    public static function lista(PDO $db, int $usuarioId): array
    {
        $st = $db->prepare('SELECT id, dispositivo, creado, ultimo_uso FROM sesiones_recordadas WHERE usuario_id = ? AND expira > now() ORDER BY ultimo_uso DESC');
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    /** Nombre corto del aparato para la lista ("App Tukán Mensajes · Android", "Chrome · Windows"...). */
    public static function dispositivo(string $ua): string
    {
        $so = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'TukanIOS') => 'iPhone',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        $nav = match (true) {
            str_contains($ua, 'TukanIOS') => 'App de iPhone',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador',
        };
        return mb_substr(trim($nav . ($so !== '' ? ' · ' . $so : '')), 0, 60);
    }

    /** Borra los recuerdos vencidos. */
    public static function limpiar(PDO $db): int
    {
        return $db->exec('DELETE FROM sesiones_recordadas WHERE expira < now()') ?: 0;
    }

    /**
     * Limpieza una vez al día (la llama bin/campanias.php cada minuto; solo trabaja la primera vez de cada día).
     * La marca 'sesiones_limpiadas' en ajustes evita que dos corridas del cron la hagan dos veces. Devuelve cuántos borró, o null si ya se hizo hoy.
     */
    public static function limpiarAlDia(PDO $db, ?string $hoy = null): ?int
    {
        $hoy ??= date('Y-m-d');
        $st = $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('sesiones_limpiadas', ?)
                            ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor WHERE ajustes.valor <> EXCLUDED.valor RETURNING clave");
        $st->execute([$hoy]);
        return $st->fetchColumn() === false ? null : self::limpiar($db);
    }
}
