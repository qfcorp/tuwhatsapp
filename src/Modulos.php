<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * Módulos que se encienden o apagan por cliente (empresa) para cobrar más o menos.
 * Lo básico (bandeja, chat con fotos y audios, contactos, pendientes, respuestas automáticas con botones,
 * plantillas, respuestas rápidas, gasto) siempre está. Lo de abajo es extra.
 * La cuenta de Tukán (empresa 1) tiene todo siempre.
 */
final class Modulos
{
    /** clave => [nombre, para qué sirve, rutas que necesita] */
    public const LISTA = [
        'campanias'     => ['Campañas masivas', 'Enviar una plantilla a muchos clientes a la vez, con permiso de promociones y etiquetas.', ['campanias']],
        'agentes'       => ['Varios agentes', 'Asignar conversaciones a cada persona del equipo y ver "Míos" y "Sin asignar".', []],
        'embudo'        => ['Embudo de ventas', 'Tablero con etapas (nuevo, interesado, cotizado, ganado, perdido) y valor de cada venta.', ['embudo']],
        'seguimientos'  => ['Seguimientos automáticos', 'Mensajes programados y recordatorios: escribir solo a quien no respondió.', ['seguimientos']],
        'reportes'      => ['Reportes', 'Mensajes por día, tiempo de respuesta, conversaciones por agente y ventas del embudo.', ['reportes']],
        'ia'            => ['Asistente con IA', 'Respuestas sugeridas y contestar preguntas frecuentes con IA, con tope de gasto.', ['ia']],
        'enlaces'       => ['Enlaces, QR y botón web', 'Enlace corto, código QR y botón flotante de WhatsApp para la página web.', ['enlaces']],
        'integraciones' => ['Integraciones', 'Avisar a otros programas (webhook), exportar contactos y conversaciones a Excel.', ['integraciones']],
    ];

    /**
     * Planes de venta sugeridos (precio mensual en dólares, sin IVA, sin contrato; Meta cobra aparte directo a la tarjeta del cliente, sin recargo).
     * clave => [nombre, precio, números, usuarios, módulos, tope de IA en dólares]
     */
    public const PLANES = [
        'basico'  => ['Básico', 19, 1, 3, ['enlaces'], 0],
        'negocio' => ['Negocio', 35, 1, 5, ['enlaces', 'campanias', 'agentes', 'embudo', 'seguimientos', 'reportes'], 0],
        'pro'     => ['Pro', 55, 2, 10, ['enlaces', 'campanias', 'agentes', 'embudo', 'seguimientos', 'reportes', 'ia', 'integraciones'], 5],
    ];

    /** Pone los módulos, números, usuarios y tope de IA de un plan. */
    public static function aplicarPlan(PDO $db, int $empresaId, string $plan): array
    {
        if (!isset(self::PLANES[$plan])) {
            throw new \RuntimeException('Ese plan no existe.');
        }
        [, , $numeros, $usuarios, $modulos, $ia] = self::PLANES[$plan];
        $db->prepare('UPDATE empresas SET modulos = ?, max_numeros = GREATEST(max_numeros, ?), max_usuarios = GREATEST(max_usuarios, ?), ia_tope = ? WHERE id = ?')
           ->execute([implode(',', $modulos), $numeros, $usuarios, $ia, $empresaId]);
        return self::PLANES[$plan];
    }

    /** Lo que se enciende al crear una demo: todo, para que el cliente vea el programa completo. */
    public static function todos(): array
    {
        return array_keys(self::LISTA);
    }

    /** @return list<string> */
    public static function deEmpresa(?array $e): array
    {
        if (!$e) {
            return [];
        }
        if ((int) $e['id'] === Empresas::TUKAN) {
            return self::todos();
        }
        return self::limpiar((string) ($e['modulos'] ?? ''));
    }

    public static function activo(?array $e, string $clave): bool
    {
        return in_array($clave, self::deEmpresa($e), true);
    }

    /** Ruta del panel que pertenece a un módulo apagado. */
    public static function rutaBloqueada(?array $e, string $ruta): ?string
    {
        foreach (self::LISTA as $clave => [$nombre, , $rutas]) {
            if (in_array($ruta, $rutas, true) && !self::activo($e, $clave)) {
                return $nombre;
            }
        }
        return null;
    }

    /** @return list<string> solo claves válidas, sin repetir */
    public static function limpiar(string|array $v): array
    {
        $lista = is_array($v) ? $v : explode(',', $v);
        $r = [];
        foreach ($lista as $x) {
            $x = trim((string) $x);
            if (isset(self::LISTA[$x]) && !in_array($x, $r, true)) {
                $r[] = $x;
            }
        }
        return $r;
    }

    public static function guardar(PDO $db, int $empresaId, array $claves): void
    {
        $db->prepare('UPDATE empresas SET modulos = ? WHERE id = ?')->execute([implode(',', self::limpiar($claves)), $empresaId]);
    }
}
