<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/** Los números de WhatsApp conectados (líneas) y su configuración. */
final class Lineas
{
    public const MAX_OPCIONES = 6;

    /** @return list<array> */
    /** Los números de una empresa (null = todos, solo para tareas internas). */
    public static function todas(PDO $db, ?int $empresaId = null): array
    {
        if ($empresaId === null) {
            return $db->query('SELECT * FROM lineas ORDER BY id')->fetchAll();
        }
        $st = $db->prepare('SELECT * FROM lineas WHERE empresa_id = ? ORDER BY id');
        $st->execute([$empresaId]);
        return $st->fetchAll();
    }

    /** Un número; con $empresaId solo si es de esa empresa (lo que usa el panel). */
    public static function buscar(PDO $db, int $id, ?int $empresaId = null): ?array
    {
        $st = $db->prepare('SELECT * FROM lineas WHERE id = ?' . ($empresaId !== null ? ' AND empresa_id = ' . (int) $empresaId : ''));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function porPhoneNumberId(PDO $db, string $pnid): ?array
    {
        $st = $db->prepare('SELECT * FROM lineas WHERE phone_number_id = ?');
        $st->execute([$pnid]);
        return $st->fetch() ?: null;
    }

    public static function token(array $linea): string
    {
        return Cifrado::descifrar((string) $linea['token_cifrado']);
    }

    /** @return list<array{titulo:string,respuesta:string,humano:bool}> */
    public static function opciones(array $linea): array
    {
        $o = json_decode((string) $linea['opciones'], true);
        if (!is_array($o)) {
            return [];
        }
        $r = [];
        foreach ($o as $x) {
            if (is_array($x) && trim((string) ($x['titulo'] ?? '')) !== '') {
                $r[] = ['titulo' => trim((string) $x['titulo']), 'respuesta' => trim((string) ($x['respuesta'] ?? '')), 'humano' => !empty($x['humano'])];
            }
        }
        return array_slice($r, 0, self::MAX_OPCIONES);
    }

    /** @return array<int, ?array{0:string,1:string}> 1 = lunes ... 7 = domingo */
    public static function horario(array $linea): array
    {
        $h = json_decode((string) $linea['horario'], true);
        $r = [];
        for ($d = 1; $d <= 7; $d++) {
            $x = is_array($h) ? ($h[(string) $d] ?? null) : null;
            $r[$d] = (is_array($x) && count($x) === 2 && self::hora($x[0]) && self::hora($x[1]) && $x[0] < $x[1]) ? [$x[0], $x[1]] : null;
        }
        return $r;
    }

    public static function hora(mixed $h): bool
    {
        return is_string($h) && (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
    }

    /** Crea o cambia los datos de conexión con Meta. El token solo se cambia si se escribe uno nuevo. */
    public static function guardarConexion(PDO $db, ?int $id, array $d, int $empresaId = Empresas::TUKAN): int
    {
        if ($id !== null && !self::buscar($db, $id, $empresaId)) {
            throw new RuntimeException('Ese número no es de tu cuenta.');
        }
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $pnid = preg_replace('/\D+/', '', (string) ($d['phone_number_id'] ?? ''));
        $waba = preg_replace('/\D+/', '', (string) ($d['waba_id'] ?? ''));
        $tel = Telefono::normalizar((string) ($d['telefono'] ?? '')) ?? '';
        $token = trim((string) ($d['token'] ?? ''));
        if ($nombre === '') {
            throw new RuntimeException('Escribe un nombre para este número (ejemplo: Tukán ventas).');
        }
        if ($pnid === '') {
            throw new RuntimeException('Falta el "Identificador de número de teléfono" (Phone number ID) de Meta.');
        }
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,}$/', $token)) {
            throw new RuntimeException('El token no parece correcto: cópialo completo, sin espacios.');
        }
        $otra = self::porPhoneNumberId($db, $pnid);
        if ($otra && (int) $otra['id'] !== (int) $id) {
            throw new RuntimeException((int) $otra['empresa_id'] === $empresaId
                ? 'Ese Phone number ID ya está en otro número: "' . $otra['nombre'] . '".'
                : 'Ese número ya está conectado en otra cuenta. Si es tuyo, escribe a Tukán.');
        }
        if ($id === null && !Empresas::puedeAgregarNumero($db, $empresaId)) {
            throw new RuntimeException('Tu plan no permite más números. Pide a Tukán que te agregue uno.');
        }
        if ($id === null) {
            if ($token === '') {
                throw new RuntimeException('Falta el token de acceso de Meta.');
            }
            $st = $db->prepare('INSERT INTO lineas (empresa_id, nombre, telefono, phone_number_id, waba_id, token_cifrado) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
            $st->execute([$empresaId, $nombre, $tel, $pnid, $waba, Cifrado::cifrar($token)]);
            return (int) $st->fetchColumn();
        }
        $db->prepare('UPDATE lineas SET nombre = ?, telefono = ?, phone_number_id = ?, waba_id = ? WHERE id = ?')
           ->execute([$nombre, $tel, $pnid, $waba, $id]);
        if ($token !== '') {
            $db->prepare('UPDATE lineas SET token_cifrado = ? WHERE id = ?')->execute([Cifrado::cifrar($token), $id]);
        }
        return $id;
    }

    /** Guarda las reglas de respuesta automática que vienen del formulario. */
    public static function guardarReglas(PDO $db, int $id, array $d): void
    {
        $horario = [];
        for ($dia = 1; $dia <= 7; $dia++) {
            $abre = (string) ($d['abre'][$dia] ?? '');
            $cierra = (string) ($d['cierra'][$dia] ?? '');
            if (!empty($d['trabaja'][$dia])) {
                if (!self::hora($abre) || !self::hora($cierra) || $abre >= $cierra) {
                    throw new RuntimeException('Revisa el horario: en un día que trabajas la hora de cierre debe ser después de la de apertura.');
                }
                $horario[(string) $dia] = [$abre, $cierra];
            } else {
                $horario[(string) $dia] = null;
            }
        }
        $opciones = [];
        foreach ((array) ($d['op_titulo'] ?? []) as $i => $titulo) {
            $titulo = trim((string) $titulo);
            $resp = trim((string) ($d['op_respuesta'][$i] ?? ''));
            if ($titulo === '') {
                continue;
            }
            if ($resp === '') {
                throw new RuntimeException('La opción "' . $titulo . '" no tiene respuesta.');
            }
            $opciones[] = ['titulo' => mb_substr($titulo, 0, 60), 'respuesta' => mb_substr($resp, 0, 1000), 'humano' => !empty($d['op_humano'][$i])];
        }
        if (count($opciones) > self::MAX_OPCIONES) {
            throw new RuntimeException('Máximo ' . self::MAX_OPCIONES . ' opciones.');
        }
        $menu = trim((string) ($d['mensaje_menu'] ?? ''));
        if ($menu === '') {
            throw new RuntimeException('Escribe el saludo del menú.');
        }
        $palabras = implode(', ', Reglas::listaPalabras((string) ($d['palabras'] ?? '')));
        $horas = max(1, min(168, (int) ($d['horas_silencio'] ?? 12)));
        $max = max(1, min(10, (int) ($d['max_auto'] ?? 3)));
        $db->prepare('UPDATE lineas SET auto_activo = ?, regla_solo_clientes = ?, regla_palabras = ?, palabras = ?, regla_horario = ?,
                      horario = ?, regla_una_vez = ?, horas_silencio = ?, max_auto = ?, mensaje_menu = ?, opciones = ? WHERE id = ?')
           ->execute([
               self::b($d, 'auto_activo'), self::b($d, 'regla_solo_clientes'), self::b($d, 'regla_palabras'), $palabras,
               self::b($d, 'regla_horario'), json_encode($horario), self::b($d, 'regla_una_vez'), $horas, $max,
               mb_substr($menu, 0, 1000), json_encode($opciones, JSON_UNESCAPED_UNICODE), $id,
           ]);
    }

    public static function guardarTope(PDO $db, int $id, string $tope, string $avisoPct): void
    {
        $t = (float) str_replace(',', '.', trim($tope));
        $a = (int) $avisoPct;
        if ($t < 0 || $t > 10000) {
            throw new RuntimeException('El tope debe estar entre $0 y $10.000.');
        }
        if ($a < 10 || $a > 100) {
            throw new RuntimeException('El aviso debe estar entre 10 % y 100 %.');
        }
        $db->prepare('UPDATE lineas SET tope_mensual = ?, aviso_pct = ? WHERE id = ?')->execute([round($t, 2), $a, $id]);
    }

    private static function b(array $d, string $k): string
    {
        return !empty($d[$k]) ? 't' : 'f';
    }
}
