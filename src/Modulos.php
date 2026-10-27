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
        'ia'            => ['Asistente con IA', 'Sugiere la respuesta y resume la conversación con IA (siempre la manda una persona).', ['ia']],
        'enlaces'       => ['Enlaces, QR y botón web', 'Enlace corto, código QR y botón flotante de WhatsApp para la página web.', ['enlaces']],
        'integraciones' => ['Integraciones', 'Avisar a otros programas (webhook), exportar contactos y conversaciones a Excel.', ['integraciones']],
    ];

    /**
     * Planes de venta sugeridos (precio mensual en dólares, sin IVA, sin contrato; Meta cobra aparte directo a la tarjeta del cliente, sin recargo).
     * clave => [nombre, precio, números, usuarios, módulos, tope de IA en dólares]
     */
    public const PLANES = [
        'basico'  => ['Básico', 19, 1, 3, ['enlaces'], 3],
        'negocio' => ['Negocio', 35, 1, 5, ['enlaces', 'campanias', 'agentes', 'embudo', 'seguimientos', 'reportes'], 3],
        'pro'     => ['Pro', 55, 2, 10, ['enlaces', 'campanias', 'agentes', 'embudo', 'seguimientos', 'reportes', 'ia', 'integraciones'], 3],
    ];

    /**
     * Precio mensual de cada módulo suelto (dólares, sin IVA), para el Panel Tukán Central (Anexo B):
     * un módulo encendido fuera del plan se suma y uno del plan que se apaga se descuenta.
     * PROVISIONALES: los decide Dimitry (los planes de arriba siguen siendo el precio normal).
     */
    public const PRECIOS = ['enlaces' => 3, 'campanias' => 6, 'agentes' => 5, 'embudo' => 4, 'seguimientos' => 4, 'reportes' => 3, 'ia' => 12, 'integraciones' => 8];
    /** Ícono y grupo de cada módulo (solo para mostrar en el Panel Tukán Central). */
    public const GRUPOS = [
        'campanias' => ['📣', 'Ventas'], 'embudo' => ['📊', 'Ventas'], 'seguimientos' => ['⏰', 'Ventas'],
        'agentes' => ['👥', 'Equipo'], 'reportes' => ['📈', 'Equipo'],
        'ia' => ['✨', 'Extras'], 'enlaces' => ['🔗', 'Extras'], 'integraciones' => ['🔌', 'Extras'],
    ];
    /** Lo que todo cliente tiene siempre (no se apaga). */
    public const BASE = ['Bandeja y chat con fotos, audios y documentos', 'Contactos (traer del celular)', 'Pendientes para enviar gratis desde el celular',
                         'Respuestas automáticas con botones', 'Plantillas de Meta', 'Respuestas rápidas', 'Gasto en Meta con tope'];

    /**
     * Pone el plan: números, usuarios y tope de IA, y sus módulos.
     * $modulos: si viene, se encienden esos en vez de los del plan (demo con todo, o cambiar de plan conservando lo encendido).
     */
    public static function aplicarPlan(PDO $db, int $empresaId, string $plan, ?array $modulos = null): array
    {
        if (!isset(self::PLANES[$plan])) {
            throw new \RuntimeException('Ese plan no existe.');
        }
        [, , $numeros, $usuarios, $delPlan, $ia] = self::PLANES[$plan];
        $db->prepare('UPDATE empresas SET plan = ?, modulos = ?, max_numeros = GREATEST(max_numeros, ?), max_usuarios = GREATEST(max_usuarios, ?), ia_tope = ? WHERE id = ?')
           ->execute([$plan, implode(',', self::limpiar($modulos ?? $delPlan)), $numeros, $usuarios, $ia, $empresaId]);
        return self::PLANES[$plan];
    }

    /**
     * El plan de una empresa: el guardado; si no hay (cuentas de antes), el plan con esos mismos módulos,
     * o el más barato que los tenga todos (el Pro los tiene todos).
     */
    public static function planDe(array $e): string
    {
        if (isset(self::PLANES[(string) ($e['plan'] ?? '')])) {
            return (string) $e['plan'];
        }
        $activos = self::deEmpresa($e);
        sort($activos);
        foreach (self::PLANES as $k => $p) {
            $m = $p[4];
            sort($m);
            if ($m === $activos) {
                return $k;
            }
        }
        $planes = self::PLANES;
        uasort($planes, fn ($a, $b) => $a[1] <=> $b[1]);
        foreach ($planes as $k => $p) {
            if (!array_diff($activos, $p[4])) {
                return $k;
            }
        }
        return array_key_last($planes);
    }

    /**
     * Precio del mes según los módulos (Anexo B): plan + módulos encendidos fuera del plan − módulos del plan apagados (mínimo 0).
     * @return array{plan:string, del_plan:list<string>, extra:list<string>, quitados:list<string>, activos:list<string>,
     *               precio_plan:float, precio_extra:float, descuento:float, mensual:float}
     */
    public static function detalle(array $e, ?string $plan = null): array
    {
        $plan ??= self::planDe($e);
        $delPlan = self::PLANES[$plan][4];
        $activos = self::deEmpresa($e);
        $extra = array_values(array_diff($activos, $delPlan));
        $quitados = array_values(array_diff($delPlan, $activos));
        $pp = (float) self::PLANES[$plan][1];
        $pe = (float) array_sum(array_map(fn ($m) => self::PRECIOS[$m] ?? 0, $extra));
        $de = (float) array_sum(array_map(fn ($m) => self::PRECIOS[$m] ?? 0, $quitados));
        return ['plan' => $plan, 'del_plan' => array_values(self::limpiar($delPlan)), 'extra' => $extra, 'quitados' => $quitados, 'activos' => $activos,
                'precio_plan' => round($pp, 2), 'precio_extra' => round($pe, 2), 'descuento' => round($de, 2), 'mensual' => round(max(0, $pp + $pe - $de), 2)];
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
