<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * Precio TODO INCLUIDO (decisión de Dimitry, 28 oct 2026): un solo plan con todo el programa por unos $20 al mes,
 * para romper el mercado (Mercately cobra desde $299). El ÚNICO extra que se cobra aparte es la IA
 * (asistente que sugiere y resume + agente IA que contesta solo 24/7), unos $5 más.
 *
 * Todo lo demás (campañas, varios agentes, reparto automático, embudo, seguimientos, reportes, enlaces y QR, integraciones,
 * catálogo, pedidos, cobros, cotizaciones en PDF, chatbots por pasos, campos personalizados, anuncios, tiendas Shopify y
 * WooCommerce, app en el celular) viene siempre: Modulos::activo() devuelve true para cualquier clave que no sea un extra.
 * La cuenta de Tukán (empresa 1) tiene todo siempre.
 */
final class Modulos
{
    /** Extras que se encienden o apagan por cliente: clave => [nombre, para qué sirve, rutas que necesita] */
    public const LISTA = [
        'ia' => ['Asistente con IA y agente que contesta solo',
                 'Agente con IA que contesta solo las 24 horas con lo que sabe tu negocio y tu catálogo, y pasa a una persona cuando hace falta. Además sugiere respuestas y resume conversaciones.',
                 ['ia']],
    ];

    /**
     * Planes de venta (precio mensual en dólares, sin IVA, sin contrato; Meta cobra aparte directo a la tarjeta del cliente, sin recargo).
     * clave => [nombre, precio, números, usuarios, extras incluidos, tope de IA en dólares]
     */
    public const PLANES = [
        'todo'    => ['Todo incluido', 20, 1, 50, [], 3],
        'todo_ia' => ['Todo incluido + IA', 25, 1, 50, ['ia'], 3],
    ];

    /** Precio mensual de cada extra (dólares, sin IVA): se suma si se enciende fuera del plan y se descuenta si se apaga. */
    public const PRECIOS = ['ia' => 5];
    /** Ícono y grupo de cada extra (solo para mostrar en el Panel Tukán Central). */
    public const GRUPOS = ['ia' => ['✨', 'Extras']];
    /** Lo que todo cliente tiene siempre (no se apaga). */
    public const BASE = [
        'Bandeja y chat con fotos, audios, notas de voz y documentos', 'Contactos (traer del celular) con campos personalizados',
        'Pendientes para enviar gratis desde el celular', 'Respuestas automáticas con botones', 'Chatbots por pasos (sin programar)',
        'Plantillas de Meta', 'Respuestas rápidas', 'Gasto en Meta con tope', 'Varios agentes con reparto automático y vista del supervisor',
        'Campañas masivas', 'Embudo de ventas', 'Seguimientos automáticos', 'Reportes y origen de cada cliente por anuncio',
        'Catálogo, carrito y pedidos en el chat', 'Enlaces de pago (Payphone, DeUna, transferencia)', 'Cotizaciones en PDF',
        'Tienda en línea: Shopify y WooCommerce', 'Enlaces, QR y botón para la web', 'Integraciones (Excel, Make, Zapier, n8n)',
        'App en el celular con avisos',
    ];

    /**
     * Pone el plan: números, usuarios y tope de IA, y sus extras.
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
     * El plan de una empresa: el guardado; si no hay o es uno viejo (básico, negocio, pro), el que tiene esos mismos extras.
     */
    public static function planDe(array $e): string
    {
        if (isset(self::PLANES[(string) ($e['plan'] ?? '')])) {
            return (string) $e['plan'];
        }
        return self::activo($e, 'ia') ? 'todo_ia' : 'todo';
    }

    /**
     * Precio del mes: plan + extras encendidos fuera del plan − extras del plan apagados (mínimo 0).
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

    /** Lo que se enciende al crear una demo: todo, para que el cliente vea el programa completo (incluida la IA). */
    public static function todos(): array
    {
        return array_keys(self::LISTA);
    }

    /** @return list<string> extras encendidos */
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

    /** ¿Tiene esta función? Todo lo que no es un extra viene siempre incluido. */
    public static function activo(?array $e, string $clave): bool
    {
        if (!isset(self::LISTA[$clave])) {
            return $e !== null;
        }
        return in_array($clave, self::deEmpresa($e), true);
    }

    /** Ruta del panel que pertenece a un extra apagado. */
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
