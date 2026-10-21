<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuentas por cliente. Cada empresa tiene sus usuarios y sus números; todo lo demás (contactos, mensajes,
 * pendientes, reglas, gasto) cuelga del número, así que queda separado solo.
 * Estados: demo (hasta "vence"), activa (si tiene "vence", hasta esa fecha) y suspendida.
 * Una cuenta que no está al día no entra al panel y no contesta sola; los mensajes que llegan igual se guardan.
 */
final class Empresas
{
    public const ESTADOS = ['demo' => 'Demo', 'activa' => 'Activa', 'suspendida' => 'Suspendida'];
    public const ROLES = ['admin' => 'Administrador', 'agente' => 'Agente'];
    public const TUKAN = 1;

    public static function buscar(PDO $db, int $id): ?array
    {
        $st = $db->prepare('SELECT * FROM empresas WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** ¿Puede usar el programa hoy? */
    public static function alDia(?array $e, ?DateTimeImmutable $hoy = null): bool
    {
        if (!$e || $e['estado'] === 'suspendida') {
            return false;
        }
        if (empty($e['vence'])) {
            return $e['estado'] === 'activa';   // una demo siempre tiene fecha
        }
        return new DateTimeImmutable($e['vence']) >= ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /** Explicación para quien no puede entrar. */
    public static function motivoBloqueo(array $e): string
    {
        if ($e['estado'] === 'suspendida') {
            return $e['motivo_suspension'] !== '' ? $e['motivo_suspension'] : 'Tu cuenta está suspendida.';
        }
        return $e['estado'] === 'demo' ? 'Tu demo terminó.' : 'Tu plan venció el ' . (new DateTimeImmutable((string) $e['vence']))->format('d/m/Y') . '.';
    }

    public static function alDiaPorLinea(PDO $db, array $linea): bool
    {
        return self::alDia(self::buscar($db, (int) $linea['empresa_id']));
    }

    /** Para el panel de Tukán: todas las empresas con sus números, usuarios y movimiento del mes. */
    public static function lista(PDO $db): array
    {
        return $db->query("SELECT e.*,
                (SELECT count(*) FROM lineas l WHERE l.empresa_id = e.id) AS numeros,
                (SELECT count(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.activo) AS usuarios,
                (SELECT count(*) FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE l.empresa_id = e.id
                    AND m.creado >= date_trunc('month', now())) AS mensajes_mes,
                (SELECT COALESCE(sum(m.costo), 0) FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE l.empresa_id = e.id
                    AND m.direccion = 'sale' AND m.estado NOT IN ('fallido', 'bloqueado') AND m.creado >= date_trunc('month', now())) AS gasto_mes,
                (SELECT max(m.creado) FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE l.empresa_id = e.id) AS ultimo_mensaje
            FROM empresas e ORDER BY e.id = 1 DESC, e.creado DESC")->fetchAll();
    }

    /** Valida y limpia los datos del formulario de empresa. */
    private static function datos(array $d): array
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '') {
            throw new RuntimeException('Escribe el nombre del negocio.');
        }
        $estado = (string) ($d['estado'] ?? 'demo');
        if (!isset(self::ESTADOS[$estado])) {
            throw new RuntimeException('Estado no válido.');
        }
        $vence = trim((string) ($d['vence'] ?? ''));
        if ($vence !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) {
            throw new RuntimeException('La fecha de vencimiento no es válida.');
        }
        if ($estado === 'demo' && $vence === '') {
            throw new RuntimeException('Una demo necesita fecha de vencimiento.');
        }
        $correo = trim((string) ($d['correo'] ?? ''));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo del negocio no es válido.');
        }
        return [
            'nombre' => mb_substr($nombre, 0, 100),
            'estado' => $estado,
            'vence' => $vence !== '' ? $vence : null,
            'max_numeros' => max(0, min(100, (int) ($d['max_numeros'] ?? 1))),
            'max_usuarios' => max(1, min(500, (int) ($d['max_usuarios'] ?? 3))),
            'contacto' => mb_substr(trim((string) ($d['contacto'] ?? '')), 0, 100),
            'telefono' => Telefono::normalizar((string) ($d['telefono'] ?? '')) ?? '',
            'correo' => mb_substr($correo, 0, 150),
            'notas' => mb_substr(trim((string) ($d['notas'] ?? '')), 0, 4000),
            'motivo_suspension' => mb_substr(trim((string) ($d['motivo_suspension'] ?? '')), 0, 300),
        ];
    }

    /**
     * Crea la empresa con su primer usuario (administrador).
     * @return array{empresa_id:int, clave:string} la clave se muestra una sola vez
     */
    public static function crear(PDO $db, array $d): array
    {
        $x = self::datos($d);
        $correoAdmin = mb_strtolower(trim((string) ($d['admin_correo'] ?? '')));
        $nombreAdmin = trim((string) ($d['admin_nombre'] ?? ''));
        if (!filter_var($correoAdmin, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Escribe el correo con el que va a entrar el cliente.');
        }
        $db->beginTransaction();
        try {
            $st = $db->prepare('INSERT INTO empresas (nombre, estado, vence, max_numeros, max_usuarios, contacto, telefono, correo, notas)
                                VALUES (:nombre, :estado, :vence, :max_numeros, :max_usuarios, :contacto, :telefono, :correo, :notas) RETURNING id');
            $st->execute(array_diff_key($x, ['motivo_suspension' => 1]));
            $id = (int) $st->fetchColumn();
            $clave = self::agregarUsuario($db, $id, $correoAdmin, $nombreAdmin !== '' ? $nombreAdmin : $x['contacto'], 'admin', false);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['empresa_id' => $id, 'clave' => $clave];
    }

    public static function guardar(PDO $db, int $id, array $d): void
    {
        $x = self::datos($d);
        if ($id === self::TUKAN && $x['estado'] !== 'activa') {
            throw new RuntimeException('La cuenta de Tukán no se puede suspender ni pasar a demo.');
        }
        $x['id'] = $id;
        $db->prepare('UPDATE empresas SET nombre = :nombre, estado = :estado, vence = :vence, max_numeros = :max_numeros, max_usuarios = :max_usuarios,
                      contacto = :contacto, telefono = :telefono, correo = :correo, notas = :notas, motivo_suspension = :motivo_suspension WHERE id = :id')
           ->execute($x);
    }

    public static function usuarios(PDO $db, int $empresaId): array
    {
        $st = $db->prepare('SELECT id, nombre, correo, rol, super, activo, creado FROM usuarios WHERE empresa_id = ? ORDER BY activo DESC, rol, nombre');
        $st->execute([$empresaId]);
        return $st->fetchAll();
    }

    /** Agrega un usuario y devuelve su contraseña inicial (se muestra una sola vez). */
    public static function agregarUsuario(PDO $db, int $empresaId, string $correo, string $nombre, string $rol, bool $revisarLimite = true): string
    {
        $correo = mb_strtolower(trim($correo));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo no es válido.');
        }
        if (!isset(self::ROLES[$rol])) {
            throw new RuntimeException('Rol no válido.');
        }
        $e = self::buscar($db, $empresaId);
        if (!$e) {
            throw new RuntimeException('La empresa no existe.');
        }
        if ($revisarLimite) {
            $st = $db->prepare('SELECT count(*) FROM usuarios WHERE empresa_id = ? AND activo');
            $st->execute([$empresaId]);
            if ((int) $st->fetchColumn() >= (int) $e['max_usuarios']) {
                throw new RuntimeException('Tu plan permite ' . $e['max_usuarios'] . ' usuarios. Pide más a Tukán.');
            }
        }
        $st = $db->prepare('SELECT 1 FROM usuarios WHERE lower(correo) = ?');
        $st->execute([$correo]);
        if ($st->fetchColumn()) {
            throw new RuntimeException('Ya existe un usuario con el correo ' . $correo . '.');
        }
        $clave = self::claveNueva();
        $db->prepare('INSERT INTO usuarios (empresa_id, correo, nombre, rol, clave) VALUES (?, ?, ?, ?, ?)')
           ->execute([$empresaId, $correo, mb_substr(trim($nombre) !== '' ? trim($nombre) : $correo, 0, 100), $rol, password_hash($clave, PASSWORD_DEFAULT)]);
        return $clave;
    }

    /** Cambios del administrador de la empresa sobre su equipo. Nunca sobre usuarios de otra empresa. */
    public static function cambiarUsuario(PDO $db, int $empresaId, int $usuarioId, string $accion, int $yo): ?string
    {
        $st = $db->prepare('SELECT * FROM usuarios WHERE id = ? AND empresa_id = ?');
        $st->execute([$usuarioId, $empresaId]);
        $u = $st->fetch();
        if (!$u) {
            throw new RuntimeException('Ese usuario no es de tu cuenta.');
        }
        if ($usuarioId === $yo && $accion !== 'clave') {
            throw new RuntimeException('No puedes cambiarte el rol ni desactivarte a ti mismo.');
        }
        switch ($accion) {
            case 'clave':
                $clave = self::claveNueva();
                $db->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
                return $clave;
            case 'admin':
            case 'agente':
                $db->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$accion, $usuarioId]);
                return null;
            case 'desactivar':
                $db->prepare('UPDATE usuarios SET activo = false WHERE id = ?')->execute([$usuarioId]);
                return null;
            case 'activar':
                $e = self::buscar($db, $empresaId);
                $c = $db->prepare('SELECT count(*) FROM usuarios WHERE empresa_id = ? AND activo');
                $c->execute([$empresaId]);
                if ((int) $c->fetchColumn() >= (int) $e['max_usuarios']) {
                    throw new RuntimeException('Tu plan permite ' . $e['max_usuarios'] . ' usuarios activos.');
                }
                $db->prepare('UPDATE usuarios SET activo = true WHERE id = ?')->execute([$usuarioId]);
                return null;
        }
        throw new RuntimeException('Acción no válida.');
    }

    public static function cambiarMiClave(PDO $db, int $usuarioId, string $actual, string $nueva, string $repetir): void
    {
        $st = $db->prepare('SELECT clave FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        if (!password_verify($actual, (string) $st->fetchColumn())) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }
        if (mb_strlen($nueva) < 8) {
            throw new RuntimeException('La contraseña nueva debe tener al menos 8 letras o números.');
        }
        if ($nueva !== $repetir) {
            throw new RuntimeException('Las dos contraseñas nuevas no son iguales.');
        }
        $db->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
    }

    /** Crea un token de API nuevo para la empresa (se muestra una sola vez; se guarda solo su huella). */
    public static function nuevoTokenApi(PDO $db, int $empresaId): string
    {
        $t = 'tw_' . $empresaId . '_' . bin2hex(random_bytes(24));
        $db->prepare('UPDATE empresas SET api_token_hash = ? WHERE id = ?')->execute([hash('sha256', $t), $empresaId]);
        return $t;
    }

    public static function porTokenApi(PDO $db, string $token): ?array
    {
        if (!preg_match('/^tw_\d+_[0-9a-f]{48}$/', $token)) {
            return null;
        }
        $st = $db->prepare("SELECT * FROM empresas WHERE api_token_hash = ? AND api_token_hash <> ''");
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    public static function puedeAgregarNumero(PDO $db, int $empresaId): bool
    {
        $e = self::buscar($db, $empresaId);
        $st = $db->prepare('SELECT count(*) FROM lineas WHERE empresa_id = ?');
        $st->execute([$empresaId]);
        return $e !== null && (int) $st->fetchColumn() < (int) $e['max_numeros'];
    }

    /** Contraseña fácil de dictar por teléfono: 3 grupos sin letras que se confunden (0/O, 1/l). */
    public static function claveNueva(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz23456789';
        $c = '';
        for ($i = 0; $i < 12; $i++) {
            $c .= $letras[random_int(0, strlen($letras) - 1)];
            if ($i === 3 || $i === 7) {
                $c .= '-';
            }
        }
        return $c;
    }
}
