<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conector con el Panel Tukán Central, protocolo v2 (PROTOCOLO_V2.md del Central; contrato de WhatsApp Tukán en
 * DEMO_INSTALACION.md §3). Todas las acciones v2 son POST a /tukan_central.php?accion=<accion> con el token en la cabecera
 * X-Tukan-Token (mismo secreto y misma firma que v1, con "v":2 y "bh" = hash del cuerpo) y un cuerpo JSON.
 *
 * Lectura: capacidades, clientes, cliente, planes, modulos (Anexo B) e ia_uso (Anexo C).
 * Escritura: ejecutar (crear_demo, extender_prueba, suspender, reactivar, cambiar_plan, nueva_clave_dueno, nota_interna,
 *            cambiar_modulos e ia_tope).
 * Los clientes son las empresas (la empresa 1, Tukán, no es un cliente). Las reglas son las mismas de "Clientes de Tukán"
 * (Empresas::crear, Empresas::guardar, Empresas::cambiarUsuario, Modulos): aquí solo se validan los datos, se arma la
 * respuesta y se garantiza que una operación repetida no se haga dos veces (tabla tukan_central_operaciones).
 * WhatsApp Tukán no cobra suscripciones dentro del programa (no hay tabla de pagos): sin cotizar, finanzas ni registrar_pago.
 * Tampoco tiene pedidos de demo ni tickets: sin solicitudes.
 */
final class TukanCentralV2
{
    public const PROTOCOLO = 2;
    public const REVISION = 3;   // 3: con módulos (Anexo B) e IA (Anexo C)
    public const MAX_CUERPO = 65536;
    public const VIDA_LECTURA = 60;
    public const VIDA_EJECUTAR = 30;
    /** Acciones que existen en el protocolo v2 (otro nombre es "Acción no válida", 400). */
    public const ACCIONES_PROTOCOLO = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'modulos', 'ia_uso'];
    /** Las que WhatsApp Tukán contesta. Las demás pasan por toda la validación y terminan en 403 no_permitido. */
    public const ACCIONES = ['capacidades', 'clientes', 'cliente', 'planes', 'ejecutar', 'modulos', 'ia_uso'];
    public const OPERACIONES = ['crear_demo', 'extender_prueba', 'suspender', 'reactivar', 'cambiar_plan', 'nueva_clave_dueno',
                                'nota_interna', 'cambiar_modulos', 'ia_tope'];
    /** Operaciones que no son sobre un cliente que ya existe. */
    public const SIN_CLIENTE = ['crear_demo'];
    public const ESTADOS = ['prueba', 'activo', 'por_vencer', 'vencido', 'suspendido'];
    public const ESTADOS_DETALLE = ['demo' => 'Demo', 'demo_terminada' => 'Demo terminada', 'activa' => 'Activa', 'sin_limite' => 'Sin vencimiento',
                                    'vencida' => 'Vencida', 'suspendida' => 'Suspendida'];
    public const ESTADOS_TEXTO = ['prueba' => 'en demo', 'activo' => 'activa', 'por_vencer' => 'activa (vence pronto)', 'vencido' => 'vencida', 'suspendido' => 'suspendida'];
    public const ORDENES = ['vence', 'alta', 'nombre', 'actividad'];
    public const DIAS_DEMO = 7;          // como "Crear cliente nuevo" de Clientes de Tukán
    public const DIAS_PRUEBA_MAX = 90;
    public const PLAN_DEMO = 'todo_ia';   // la demo ve todo (incluida la IA) salvo que se elijan otros
    public const ID = '/^[A-Za-z0-9_.:-]{1,80}$/';

    private TukanCentral $v1;
    private DateTimeImmutable $hoy;
    /** Quién hace la operación en curso: "Nombre <correo>" del token. */
    private string $quien = 'Panel Tukán Central';

    public function __construct(private PDO $db, ?DateTimeImmutable $hoy = null)
    {
        $this->hoy = ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $this->v1 = new TukanCentral($db);
    }

    // ------------------------------------------------------------------
    // Transporte: validación del pedido (§3.1) y respuesta
    // ------------------------------------------------------------------

    /**
     * Atiende un pedido v2 completo (lo usan public/tukan_central.php y las pruebas).
     * @return array{0:int, 1:array, 2:array<string,string>} [http, json, cabeceras extra]
     */
    public function procesar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, string $ip, ?int $ahora = null): array
    {
        try {
            if (!TukanCentral::config()) {
                throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
            }
            if (!$this->v1->dentroDelLimite($ip, $ahora)) {
                throw new TukanCentralError('limite', 'Demasiados pedidos. Espera un minuto y vuelve a intentar.', 429);
            }
            if (!in_array($accion, self::ACCIONES_PROTOCOLO, true)) {
                throw new TukanCentralError('invalido', 'Acción no válida.', 400, 'accion');
            }
            [$p, $datos] = $this->validar($accion, $metodo, $tipoContenido, $cuerpo, $token, $ahora);
            [$http, $json] = $this->atender($accion, $p, $datos, self::hashCuerpo($cuerpo));
            return [$http, $json, []];
        } catch (TukanCentralError $e) {
            if ($e->http === 401) {
                try {
                    $this->v1->anotarRechazo($ip, $ahora);
                } catch (Throwable) {   // el límite no debe tapar el error
                }
            }
            $cab = match (true) {
                $e->codigo === 'limite' => ['Retry-After' => '60'],
                $e->http === 405 => ['Allow' => 'POST'],
                default => [],
            };
            return [$e->http, $e->json(), $cab];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('tuwhatsapp central v2: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return [500, (new TukanCentralError('interno', 'WhatsApp Tukán tuvo un error interno. Intenta en un momento.', 500))->json(), []];
        }
    }

    public static function hashCuerpo(string $cuerpo): string
    {
        return TukanCentral::b64(hash('sha256', $cuerpo, true));
    }

    /** Revisa el pedido en el orden del protocolo (§3.1, pasos 3 a 15). Devuelve [payload del token, datos del cuerpo]. */
    public function validar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, ?int $ahora = null): array
    {
        $conf = TukanCentral::config();
        if (!$conf) {
            throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
        }
        $ahora ??= time();
        // 3. Método, tipo de contenido y tamaño
        if (strtoupper($metodo) !== 'POST') {
            throw new TukanCentralError('invalido', 'Esta acción se pide con POST.', 405);
        }
        if (!preg_match('#^application/json\s*(;|$)#i', trim($tipoContenido))) {
            throw new TukanCentralError('invalido', 'El contenido debe ser JSON (Content-Type: application/json).', 415);
        }
        if (strlen($cuerpo) > self::MAX_CUERPO) {
            throw new TukanCentralError('invalido', 'El pedido es demasiado grande (máximo 64 KiB).', 413);
        }
        // 4. Firma
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === ''
            || !hash_equals(TukanCentral::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true)), $partes[1])) {
            throw new TukanCentralError('firma', 'La firma del pedido no es correcta.', 401);
        }
        $json = TukanCentral::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) {
            throw new TukanCentralError('firma', 'El pedido no es válido.', 401);
        }
        // 5 a 7. Versión, tipo e instancia
        if (($p['v'] ?? null) !== 2) {
            throw new TukanCentralError('version', 'Versión de protocolo no soportada.', 401);
        }
        if (($p['tipo'] ?? null) !== $accion) {
            throw new TukanCentralError('tipo', 'El pedido no es para esta acción.', 401);
        }
        if (!is_string($p['inst'] ?? null) || !hash_equals($conf['id'], $p['inst'])) {
            throw new TukanCentralError('inst', 'El pedido es de otra instalación.', 401);
        }
        // 8 a 10. Tiempo
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        $max = $accion === 'ejecutar' ? self::VIDA_EJECUTAR : self::VIDA_LECTURA;
        if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) {
            throw new TukanCentralError('invalido', 'El pedido dura más de lo permitido.', 401);
        }
        if ($ahora > $exp + TukanCentral::TOLERANCIA) {
            throw new TukanCentralError('vencido', 'El pedido venció. Intenta otra vez desde el Panel Tukán.', 401);
        }
        if ($iat > $ahora + TukanCentral::TOLERANCIA) {
            throw new TukanCentralError('futuro', 'El pedido tiene fecha futura (revisa la hora del servidor).', 401);
        }
        // 11. Hash del cuerpo
        if (!is_string($p['bh'] ?? null) || !hash_equals(self::hashCuerpo($cuerpo), $p['bh'])) {
            throw new TukanCentralError('firma', 'El contenido del pedido cambió en el camino.', 401, null, ['parte' => 'cuerpo']);
        }
        // 12. Nonce de un solo uso (después de firma, tiempo y cuerpo: un token roto no gasta su nonce)
        if (!$this->v1->usarNonce($p['nonce'] ?? null, $exp, $ahora)) {
            throw new TukanCentralError('nonce', 'El pedido se repitió (nonce ya usado o no válido).', 401);
        }
        // 13. Correo
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralError('invalido', 'Correo inválido en el pedido.', 401);
        }
        $p['email'] = mb_strtolower(trim($email));
        $p['nombre'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_string($p['nombre'] ?? null) ? $p['nombre'] : '')), 0, 100);
        // 14. Cuerpo: objeto JSON
        $datos = json_decode($cuerpo, true, 8);
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            throw new TukanCentralError('invalido', 'El contenido no es un objeto JSON.', 400);
        }
        // 15. Acción disponible en WhatsApp Tukán
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new TukanCentralError('no_permitido', 'WhatsApp Tukán no tiene esta acción.', 403, null, ['motivo' => 'no_disponible']);
        }
        return [$p, $datos];
    }

    private function atender(string $accion, array $p, array $d, string $bh): array
    {
        if ($accion === 'ejecutar') {
            return $this->ejecutar($p, $d, $bh);
        }
        $r = match ($accion) {
            'capacidades' => $this->capacidades(),
            'clientes' => $this->clientes($d),
            'cliente' => ['cliente' => $this->clienteFicha($this->idCliente($d['id'] ?? null, 'id'))],
            'planes' => $this->planes(),
            'modulos' => $this->modulos(),
            'ia_uso' => $this->iaUso($d),
        };
        return [200, $this->sobre($accion) + $r];
    }

    private function sobre(string $accion): array
    {
        return ['ok' => true, 'protocolo' => self::PROTOCOLO, 'accion' => $accion, 'producto' => TukanCentral::PRODUCTO,
                'generado' => (new DateTimeImmutable('now'))->format('c')];
    }

    // ------------------------------------------------------------------
    // capacidades (§5.1)
    // ------------------------------------------------------------------

    public function capacidades(): array
    {
        return [
            'revision' => self::REVISION,
            'nombre' => TukanCentral::NOMBRE,
            'version' => TukanCentral::version(),
            'unico' => false,
            'moneda' => 'USD',
            'zona_horaria' => 'America/Guayaquil',
            'url_publica' => self::urlBase() ?: null,
            'acciones' => array_merge(['capacidades', 'estado', 'sso'], array_values(array_diff(self::ACCIONES, ['capacidades']))),
            'sso_ir' => true,
            'rutas_sso' => TukanCentral::RUTAS_SSO,
            'ejecutar' => self::OPERACIONES,
            'tipos_solicitud' => [],
            'estados_detalle' => self::ESTADOS_DETALLE,
            'periodos' => [['periodo' => 'mensual', 'meses' => 1]],
            'meses_libres' => false,
            'metodos_pago' => [],
            'planes_editar' => false,
            'filtros_clientes' => ['buscar', 'estados', 'plan', 'vence_desde', 'vence_hasta', 'alta_desde', 'alta_hasta', 'sin_uso_dias', 'origen', 'ids'],
            // Anexo B: los precios están en el código (src/Modulos.php), no se editan desde el panel; la demo acepta datos.modulos
            'modulos' => ['editable' => false, 'por_instalacion' => false, 'cambia_plan' => true, 'precio_acordado' => false, 'crear_demo' => true],
            'formularios' => [
                'crear_demo' => [],   // solo los campos comunes (§6.1); los módulos van en datos.modulos
                'cambiar_modulos' => self::camposModulos(null),
                'ia_tope' => self::camposIaTope(null),
            ],
            'limites' => ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => self::DIAS_PRUEBA_MAX],
        ];
    }

    // ------------------------------------------------------------------
    // Clientes (§4.2, §4.3, §5.4, §5.5)
    // ------------------------------------------------------------------

    private const SQL_EMPRESAS = "SELECT e.*,
            (SELECT count(*) FROM lineas l WHERE l.empresa_id = e.id) AS numeros,
            (SELECT count(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.activo) AS usuarios_activos,
            (SELECT count(*) FROM contactos c JOIN lineas l ON l.id = c.linea_id WHERE l.empresa_id = e.id) AS contactos,
            (SELECT count(*) FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE l.empresa_id = e.id AND m.creado >= now() - interval '30 days') AS mensajes_30,
            (SELECT max(m.creado) FROM mensajes m JOIN lineas l ON l.id = m.linea_id WHERE l.empresa_id = e.id) AS ultimo_mensaje,
            (SELECT max(r.momento) FROM tukan_central_registro r WHERE r.empresa_id = e.id) AS ultimo_cambio
        FROM empresas e WHERE e.id <> 1";

    /** Todas las empresas cliente (sin Tukán), por id. */
    private function filas(): array
    {
        $r = [];
        foreach ($this->db->query(self::SQL_EMPRESAS . ' ORDER BY e.id')->fetchAll() as $e) {
            $r[(int) $e['id']] = $e;
        }
        return $r;
    }

    /** Una empresa cliente (null si no existe o es Tukán). */
    private function fila(int $id): ?array
    {
        $st = $this->db->prepare(self::SQL_EMPRESAS . ' AND e.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Cuántos clientes hay en cada estado v2 (las mismas cifras que "estado" v1, que usa TukanCentral::clasificar). */
    public function contadores(?array $todos = null): array
    {
        $c = ['total' => 0, 'prueba' => 0, 'activo' => 0, 'por_vencer' => 0, 'vencido' => 0, 'suspendido' => 0];
        foreach ($todos ?? $this->filas() as $e) {
            $c['total']++;
            $c[TukanCentral::clasificar($e, $this->hoy)['estado']]++;
        }
        return $c;
    }

    /** El dueño: el administrador activo más antiguo de la empresa (sin personal de Tukán). */
    private function dueno(int $empresaId): ?array
    {
        $st = $this->db->prepare("SELECT id, nombre, correo FROM usuarios WHERE empresa_id = ? AND rol = 'admin' AND activo AND NOT super
                                    AND NOT creado_por_central ORDER BY id LIMIT 1");
        $st->execute([$empresaId]);
        return $st->fetch() ?: null;
    }

    /** Cliente con la forma de §4.3. */
    private function cliente(array $e): array
    {
        $c = TukanCentral::clasificar($e, $this->hoy);
        $vence = $e['vence'] ?: null;
        $dias = $vence ? $this->diasHasta($vence) : null;
        $mods = $this->modulosCliente($e);
        $dueno = $this->dueno((int) $e['id']);
        $tel = (string) $e['telefono'] !== '' ? (string) $e['telefono'] : null;
        $actividad = max((string) ($e['ultimo_mensaje'] ?? ''), (string) ($e['ultimo_cambio'] ?? '')) ?: null;
        return [
            'id' => (string) $e['id'],
            'nombre' => (string) $e['nombre'],
            'tipo' => null,
            'tipo_nombre' => null,
            'ciudad' => null,
            'plan' => $mods['plan'],
            'estado' => $c['estado'],
            'estado_detalle' => $c['detalle'],
            'estado_texto' => $this->textoEstado($c, $e, $vence, $dias),
            'en_gracia' => false,   // WhatsApp Tukán no tiene días de gracia
            'bloqueado' => $c['bloqueado'],
            'fechas' => [
                'alta' => substr((string) $e['creado'], 0, 10),
                'prueba_hasta' => $e['estado'] === 'demo' ? $vence : null,
                'activo_hasta' => $e['estado'] === 'demo' ? null : $vence,
                'vence' => $vence,
                'dias_para_vencer' => $dias,
            ],
            'dueno' => [
                'nombre' => ($e['contacto'] ?: ($dueno['nombre'] ?? null)) ?: null,
                'telefono' => $tel ? Telefono::bonito($tel) : null,
                'whatsapp' => Telefono::normalizar($tel),
                'email' => ($dueno['correo'] ?? null) ?: ($e['correo'] ?: null),
            ],
            'ultima_actividad' => self::momento($actividad),
            'pagos' => ['total' => 0.0, 'cantidad' => 0, 'primer_pago' => null, 'ultimo_pago' => null, 'ultimo_monto' => null],
            // Lo que vale al mes si está pagando (activa con fecha y al día): plan + módulos (Anexo B). Demo, cortesía o vencida: 0
            'mensual_estimado' => $e['estado'] === 'activa' && $vence !== null && in_array($c['estado'], ['activo', 'por_vencer'], true) ? $mods['mensual'] : 0.0,
            'modulos' => $mods,
            'origen' => in_array($e['origen'] ?? '', Empresas::ORIGENES, true) ? $e['origen'] : 'desconocido',
            'vendedor' => null,
            'uso' => ['Números' => (int) $e['numeros'], 'Usuarios' => (int) $e['usuarios_activos'], 'Mensajes (30 días)' => (int) $e['mensajes_30'],
                      'Contactos' => (int) $e['contactos']],
            'ir' => '/?r=cliente&id=' . (int) $e['id'],
            'ir_trabajar' => null,   // "Entrar a su cuenta" es un botón con POST en la ficha
            'actualizado' => self::momento(($e['ultimo_cambio'] ?? null) ?: (string) $e['creado']),
        ];
    }

    private function textoEstado(array $c, array $e, ?string $vence, ?int $dias): string
    {
        return match ($c['detalle']) {
            'demo' => 'Demo hasta el ' . self::fechaTexto($vence) . ($dias === 0 ? ' (termina hoy)' : ' (faltan ' . self::dias((int) $dias) . ')'),
            'demo_terminada' => 'La demo terminó el ' . self::fechaTexto($vence),
            'sin_limite' => 'Activa, sin fecha de vencimiento',
            'activa' => 'Activa hasta el ' . self::fechaTexto($vence)
                        . ($c['estado'] === 'por_vencer' ? ($dias === 0 ? ' (vence hoy)' : ' (vence en ' . self::dias((int) $dias) . ')') : ''),
            'vencida' => 'Venció el ' . self::fechaTexto($vence),
            default => 'Suspendida por Tukán' . ((string) $e['motivo_suspension'] !== '' ? ': ' . $e['motivo_suspension'] : ''),
        };
    }

    public function clientes(array $d): array
    {
        $pagina = $this->entero($d, 'pagina', 1, 1, 1000000);
        $porPagina = $this->entero($d, 'por_pagina', 50, 1, 200);
        $buscar = mb_strtolower($this->texto($d, 'buscar', 80));
        $estados = $this->lista($d, 'estados', self::ESTADOS);
        $plan = $this->texto($d, 'plan', 40);
        $tipo = $this->texto($d, 'tipo', 40);
        $origen = $this->texto($d, 'origen', 20);
        $venceDesde = $this->fecha($d, 'vence_desde');
        $venceHasta = $this->fecha($d, 'vence_hasta');
        $altaDesde = $this->fecha($d, 'alta_desde');
        $altaHasta = $this->fecha($d, 'alta_hasta');
        $sinUso = ($d['sin_uso_dias'] ?? null) === null ? null : $this->entero($d, 'sin_uso_dias', 0, 0, 3650);
        $ids = $d['ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) {
            throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.');
        }
        $ids = array_map(fn ($x) => is_int($x) || (is_string($x) && preg_match(self::ID, $x)) ? (string) $x
            : throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.'), $ids);
        $orden = $this->opcion($d, 'orden', self::ORDENES, 'vence');

        $todos = $this->filas();
        $limiteUso = $sinUso === null ? null : (new DateTimeImmutable('now'))->modify("-$sinUso days")->format('c');
        $lista = [];
        foreach ($todos as $e) {
            $c = $this->cliente($e);
            if ($buscar !== '') {   // nombre, contacto, correos (del negocio y del dueño) y teléfono
                $donde = mb_strtolower(implode(' ', [$e['nombre'], $e['contacto'], $e['correo'], (string) $c['dueno']['email'], $e['telefono']]));
                $dig = (string) preg_replace('/\D+/', '', $buscar);
                if (!str_contains($donde, $buscar) && !(strlen($dig) >= 4 && str_contains((string) $e['telefono'], ltrim($dig, '0')))) {
                    continue;
                }
            }
            if ($estados && !in_array($c['estado'], $estados, true)) {
                continue;
            }
            if ($plan !== '' && $c['plan']['codigo'] !== $plan) {
                continue;
            }
            if ($tipo !== '' && $c['tipo'] !== $tipo) {
                continue;
            }
            if ($origen !== '' && $c['origen'] !== $origen) {
                continue;
            }
            if ($ids && !in_array($c['id'], $ids, true)) {
                continue;
            }
            $v = $c['fechas']['vence'];
            if (($venceDesde !== null && ($v === null || $v < $venceDesde)) || ($venceHasta !== null && ($v === null || $v > $venceHasta))) {
                continue;
            }
            if (($altaDesde !== null && $c['fechas']['alta'] < $altaDesde) || ($altaHasta !== null && $c['fechas']['alta'] > $altaHasta)) {
                continue;
            }
            if ($limiteUso !== null && $c['ultima_actividad'] !== null && $c['ultima_actividad'] >= $limiteUso) {
                continue;
            }
            $lista[] = $c;
        }
        usort($lista, match ($orden) {
            'alta' => fn ($a, $b) => [$b['fechas']['alta'], (int) $b['id']] <=> [$a['fechas']['alta'], (int) $a['id']],
            'nombre' => fn ($a, $b) => [mb_strtolower($a['nombre']), (int) $a['id']] <=> [mb_strtolower($b['nombre']), (int) $b['id']],
            'actividad' => fn ($a, $b) => [$a['ultima_actividad'] !== null, (string) $a['ultima_actividad'], (int) $a['id']]
                                          <=> [$b['ultima_actividad'] !== null, (string) $b['ultima_actividad'], (int) $b['id']],
            default => fn ($a, $b) => [$a['fechas']['vence'] === null, $a['fechas']['vence'], mb_strtolower($a['nombre'])]
                                     <=> [$b['fechas']['vence'] === null, $b['fechas']['vence'], mb_strtolower($b['nombre'])],
        });
        $total = count($lista);
        return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contadores' => $this->contadores($todos), 'clientes' => array_slice($lista, ($pagina - 1) * $porPagina, $porPagina)];
    }

    public function clienteFicha(int $id): array
    {
        $e = $this->fila($id);
        if (!$e) {
            throw new TukanCentralError('no_encontrado', 'Ese cliente ya no existe en WhatsApp Tukán.', 404);
        }
        $c = $this->cliente($e);
        $st = $this->db->prepare('SELECT momento, quien, texto FROM tukan_central_registro WHERE empresa_id = ? ORDER BY momento DESC, id DESC LIMIT 20');
        $st->execute([$id]);
        $registro = array_map(fn ($r) => ['momento' => self::momento($r['momento']), 'quien' => (string) $r['quien'], 'texto' => (string) $r['texto']], $st->fetchAll());
        return $c + [
            'notas_admin' => (string) $e['notas'],
            'usuarios' => (int) $e['usuarios_activos'],
            'recomendado_por' => null,
            'combo_con' => null,
            'dominio' => '',
            'enlace_publico' => null,
            'pagos_detalle' => [],
            'solicitudes' => [],
            'registro' => $registro,
            'mensajes_sugeridos' => $this->mensajes($e, $c),
            'acciones_posibles' => $this->accionesCliente($e, $c),
        ];
    }

    /** Textos de WhatsApp para el dueño, o null si no aplican. */
    private function mensajes(array $e, array $c): array
    {
        $primer = trim(explode(' ', trim((string) ($c['dueno']['nombre'] ?? '')))[0]);
        $hola = $primer !== '' ? "Hola $primer" : 'Hola';
        $nombre = (string) $e['nombre'];
        $precio = self::dinero($this->modulosCliente($e)['mensual']);
        return [
            'bienvenida' => "$hola, te escribo de Tukán Software. La cuenta de $nombre en WhatsApp Tukán ya está lista: entra en " . self::urlLogin()
                . ($c['dueno']['email'] ? ' con tu correo ' . $c['dueno']['email'] : '') . '. ¿Te ayudo a conectar tu número?',
            'recordar_prueba' => $c['estado'] === 'prueba'
                ? "$hola, soy de Tukán Software. Tu demo de WhatsApp Tukán para $nombre " . ($c['fechas']['dias_para_vencer'] === 0 ? 'termina hoy' : 'termina el ' . self::fechaCorta($c['fechas']['vence']))
                  . '. ¿Te ayudo a elegir tu plan para que sigas usándolo?' : null,
            'recordar_renovacion' => $c['estado'] === 'por_vencer'
                ? "$hola, soy de Tukán Software. El plan de WhatsApp Tukán de $nombre vence el " . self::fechaCorta($c['fechas']['vence'])
                  . " ($precio al mes). ¿Te paso los datos para renovarlo?" : null,
            'vencido' => $c['estado'] === 'vencido'
                ? "$hola, soy de Tukán Software. Vi que $nombre ya no tiene WhatsApp Tukán activo. Tus conversaciones siguen guardadas. ¿Te ayudo a renovarlo?" : null,
        ];
    }

    private function puedeExtender(array $c): bool
    {
        return $c['estado_detalle'] === 'demo' || $c['estado_detalle'] === 'demo_terminada';
    }

    // --- Formularios genéricos (§4.4) ---

    private function accionesCliente(array $e, array $c): array
    {
        $datos = ['cliente_id' => $c['id']];
        $m = $this->mensajes($e, $c);
        $a = [];
        if ($c['dueno']['whatsapp']) {
            $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $c['dueno']['whatsapp'],
                    'texto' => $m['vencido'] ?? $m['recordar_prueba'] ?? $m['recordar_renovacion'] ?? $m['bienvenida']];
        }
        if ($this->puedeExtender($c)) {
            $a[] = ['accion' => 'extender_prueba', 'etiqueta' => 'Alargar demo', 'estilo' => 'principal', 'confirmar' => '¿Alargar la demo de ' . $c['nombre'] . '?',
                    'datos' => $datos, 'campos' => [['campo' => 'dias', 'etiqueta' => 'Días más', 'tipo' => 'dias', 'requerido' => true, 'valor' => 7, 'min' => 1, 'max' => self::DIAS_PRUEBA_MAX]]];
        }
        $a[] = ['accion' => 'cambiar_modulos', 'etiqueta' => 'Módulos', 'estilo' => 'normal',
                'confirmar' => '¿Guardar los módulos de ' . $c['nombre'] . '? Apagar un módulo no borra sus datos.', 'datos' => $datos,
                'campos' => self::camposModulos($c['modulos'])];
        $a[] = ['accion' => 'cambiar_plan', 'etiqueta' => 'Cambiar plan', 'estilo' => 'normal', 'confirmar' => '¿Cambiar el plan de ' . $c['nombre'] . ' sin cobrar?',
                'datos' => $datos, 'campos' => [['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $c['plan']['codigo'],
                                                 'opciones' => self::opcionesPlan(), 'ayuda' => 'Pone sus números, usuarios y módulos (en demo conserva los módulos encendidos).']]];
        if ($c['estado'] === 'suspendido') {
            $a[] = ['accion' => 'reactivar', 'etiqueta' => 'Reactivar', 'estilo' => 'principal', 'confirmar' => '¿Reactivar ' . $c['nombre'] . '?', 'datos' => $datos, 'campos' => []];
        } else {
            $a[] = ['accion' => 'suspender', 'etiqueta' => 'Suspender', 'estilo' => 'peligro',
                    'confirmar' => '¿Suspender ' . $c['nombre'] . '? No podrá entrar ni se contestará solo; los mensajes que lleguen se guardan.', 'datos' => $datos,
                    'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo (el cliente lo ve al entrar)', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 200]]];
        }
        if ($this->dueno((int) $e['id'])) {
            $a[] = ['accion' => 'nueva_clave_dueno', 'etiqueta' => 'Nueva clave del dueño', 'estilo' => 'normal',
                    'confirmar' => '¿Crear una contraseña nueva para el dueño? La anterior deja de servir.', 'datos' => $datos, 'campos' => []];
        }
        $a[] = ['accion' => 'ia_tope', 'etiqueta' => 'Tope de IA', 'estilo' => 'normal', 'confirmar' => null, 'datos' => $datos,
                'campos' => self::camposIaTope((float) $e['ia_tope'])];
        $a[] = ['accion' => 'nota_interna', 'etiqueta' => 'Nota interna', 'estilo' => 'normal', 'confirmar' => null, 'datos' => $datos,
                'campos' => [['campo' => 'texto', 'etiqueta' => 'Notas de Tukán (el cliente no las ve)', 'tipo' => 'texto_largo', 'requerido' => false,
                              'valor' => (string) $e['notas'], 'max' => 4000]]];
        $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en WhatsApp Tukán', 'ir' => $c['ir']];
        return $a;
    }

    private static function opcionesPlan(): array
    {
        $r = [];
        foreach (Modulos::PLANES as $k => $p) {
            $r[] = ['valor' => $k, 'etiqueta' => $p[0] . ' (' . self::dinero((float) $p[1]) . ' al mes)'];
        }
        return $r;
    }

    private static function camposModulos(?array $mods): array
    {
        $ids = implode(', ', array_keys(Modulos::LISTA));
        return [
            ['campo' => 'encender', 'etiqueta' => 'Encender (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 400,
             'ayuda' => 'Se cobran aparte si no son del plan. Ids: ' . $ids . '.'],
            ['campo' => 'apagar', 'etiqueta' => 'Apagar (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 400,
             'ayuda' => 'Apagar no borra datos. Encendidos hoy: ' . ($mods ? (implode(', ', $mods['activos']) ?: 'solo lo básico') : '—') . '.'],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
        ];
    }

    /** Campo de "ia_tope" (Anexo C.2): tope del mes de la IA en USD; vacío = el del plan. */
    public static function camposIaTope(?float $actual): array
    {
        return [['campo' => 'tope_usd', 'etiqueta' => 'Tope del mes de la IA (USD)', 'tipo' => 'dinero', 'requerido' => false, 'valor' => $actual, 'min' => 0,
                 'ayuda' => 'Vacío = el del plan ($' . number_format((float) Modulos::PLANES[self::PLAN_DEMO][5], 2, ',', '.') . '). El cliente nunca lo ve.']];
    }

    // ------------------------------------------------------------------
    // planes (§5.7) y módulos (Anexo B)
    // ------------------------------------------------------------------

    public function planes(): array
    {
        $cuantos = [];
        foreach ($this->filas() as $e) {
            $k = Modulos::planDe($e);
            $cuantos[$k] = ($cuantos[$k] ?? 0) + 1;
        }
        $planes = [];
        foreach (Modulos::PLANES as $k => [$nombre, $precio, $numeros, $usuarios, $mods]) {
            $planes[] = [
                'codigo' => $k, 'nombre' => $nombre, 'activo' => true, 'para' => 'Negocios que atienden por WhatsApp',
                'descripcion' => 'Lo básico' . ($mods ? ' + ' . implode(', ', array_map(fn ($m) => Modulos::LISTA[$m][0], $mods)) : '') . '.',
                'precios' => [['periodo' => 'mensual', 'meses' => 1, 'precio' => (float) $precio, 'meses_gratis' => 0]],
                'limites' => ['Números' => (string) $numeros, 'Usuarios' => (string) $usuarios, 'Contactos' => 'Ilimitados', 'Recargo sobre Meta' => '0 %'],
                'clientes' => $cuantos[$k] ?? 0,
            ];
        }
        return ['editable' => false, 'planes' => $planes,
                'ajustes' => ['dias_prueba' => self::DIAS_DEMO, 'dias_demo' => self::DIAS_DEMO, 'dias_gracia' => 0, 'dias_gracia_demo' => 0, 'registro_abierto' => false]];
    }

    public function modulos(): array
    {
        $mods = [];
        $orden = 0;
        foreach (Modulos::LISTA as $id => [$nombre, $texto]) {
            $mods[] = ['id' => $id, 'nombre' => $nombre, 'icono' => Modulos::GRUPOS[$id][0] ?? null, 'descripcion' => $texto,
                       'precio_mensual' => (float) (Modulos::PRECIOS[$id] ?? 0), 'depende_de' => [], 'grupo' => Modulos::GRUPOS[$id][1] ?? 'Módulos', 'orden' => ++$orden];
        }
        $planes = [];
        foreach (Modulos::PLANES as $k => $p) {
            $planes[] = ['codigo' => $k, 'nombre' => $p[0], 'precio_mensual' => (float) $p[1], 'modulos' => $p[4],
                         'todos' => !array_diff(array_keys(Modulos::LISTA), $p[4])];
        }
        return ['base' => Modulos::BASE, 'modulos' => $mods, 'planes' => $planes, 'editable' => false,
                'reglas' => 'Activos = (módulos del plan − apagados) + encendidos aparte. Demo: todos (o los elegidos al crearla). '
                          . 'Mensual = precio del plan + Σ precio de los encendidos aparte − Σ precio de los apagados del plan. Apagar no borra datos.'];
    }

    /** cliente.modulos (Anexo B.2). */
    private function modulosCliente(array $e, ?string $plan = null): array
    {
        $x = Modulos::detalle($e, $plan);
        return ['plan' => ['codigo' => $x['plan'], 'nombre' => Modulos::PLANES[$x['plan']][0]],
                'del_plan' => $x['del_plan'], 'extra' => $x['extra'], 'quitados' => $x['quitados'], 'activos' => $x['activos'],
                'precio_plan' => $x['precio_plan'], 'precio_extra' => $x['precio_extra'], 'descuento' => $x['descuento'],
                'precio_acordado' => null, 'mensual' => $x['mensual'], 'calculado' => $x['mensual']];
    }

    // ------------------------------------------------------------------
    // IA (Anexo C)
    // ------------------------------------------------------------------

    /** ia_uso: solo lectura; cuerpo {"mes": "2026-10"} (opcional). Gasto de tukan_ia_gasto (lo registra Asistente). */
    public function iaUso(array $d): array
    {
        foreach (array_keys($d) as $k) {
            if ($k !== 'mes') {
                throw TukanCentralError::campo((string) $k, 'Campo desconocido: ' . mb_substr((string) $k, 0, 40) . '.');
            }
        }
        $mes = $d['mes'] ?? $this->hoy->format('Y-m');
        if (!is_string($mes) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            throw TukanCentralError::campo('mes', 'Mes no válido (ej. 2026-10).');
        }
        require_once __DIR__ . '/tukan_ia.php';
        $nombres = function (array $ids): array {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) {
                return [];
            }
            $st = $this->db->prepare('SELECT * FROM empresas WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $r = [];
            foreach ($st->fetchAll() as $e) {
                $r[(string) $e['id']] = ['nombre' => (string) $e['nombre'], 'tope' => (float) $e['ia_tope'], 'modulo' => Modulos::activo($e, 'ia')];
            }
            return $r;
        };
        return tukan_ia_uso_pdo($this->db, $mes, $nombres, 'tukan_ia_gasto', new \TukanIaFaqPdo($this->db), Asistente::PROGRAMA);
    }

    // ------------------------------------------------------------------
    // ejecutar (§6) con idempotencia (§7.1) y registro (§7.3)
    // ------------------------------------------------------------------

    private function ejecutar(array $p, array $d, string $bh): array
    {
        $op = $d['operacion_id'] ?? null;
        if (!is_string($op) || !preg_match('/^[0-9a-f]{32}$/', $op)) {
            throw TukanCentralError::campo('operacion_id', 'Falta la clave de la operación (32 letras hex).');
        }
        $accion = $d['accion'] ?? null;
        if (!is_string($accion) || $accion === '') {
            throw TukanCentralError::campo('accion', 'Falta la operación.');
        }
        if (!in_array($accion, self::OPERACIONES, true)) {
            throw new TukanCentralError('no_permitido', 'WhatsApp Tukán no tiene la operación "' . mb_substr($accion, 0, 40) . '".', 403, null, ['motivo' => 'no_disponible']);
        }
        $datos = $d['datos'] ?? [];
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            throw TukanCentralError::campo('datos', 'Los datos de la operación no son válidos.');
        }
        $this->quien = 'Panel Tukán Central · ' . trim(($p['nombre'] ?: 'Tukán') . ' <' . $p['email'] . '>');
        $clienteId = $d['cliente_id'] ?? ($datos['cliente_id'] ?? null);   // arriba o en los datos fijos del botón
        if ($clienteId !== null && !(is_int($clienteId) || (is_string($clienteId) && preg_match(self::ID, $clienteId)))) {
            throw TukanCentralError::campo('cliente_id', 'El cliente no es válido.');
        }

        // 1. ¿Ya se hizo (o se está haciendo) esta operación?
        $this->db->exec("DELETE FROM tukan_central_operaciones WHERE creado < now() - interval '90 days'");
        $st = $this->db->prepare("INSERT INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, email)
                                  VALUES (?, ?, ?, ?, 'en_curso', ?) ON CONFLICT (operacion_id) DO NOTHING");
        $st->execute([$op, $accion, $clienteId === null ? null : (string) $clienteId, $bh, $p['email']]);
        if ($st->rowCount() !== 1) {
            $st = $this->db->prepare('SELECT * FROM tukan_central_operaciones WHERE operacion_id = ?');
            $st->execute([$op]);
            $g = $st->fetch();
            if (!$g || $g['estado'] === 'en_curso' && hash_equals((string) $g['cuerpo_hash'], $bh)) {
                throw new TukanCentralError('conflicto', 'La operación está en curso. Intenta en unos segundos.', 409, null, ['motivo' => 'en_curso']);
            }
            if (!hash_equals((string) $g['cuerpo_hash'], $bh)) {
                throw new TukanCentralError('conflicto', 'Esa operación ya se usó con otros datos.', 409, null, ['motivo' => 'otros_datos']);
            }
            $json = json_decode((string) $g['respuesta'], true) ?: [];
            $json['repetida'] = true;
            return [(int) $g['http'], $json];
        }

        // 2. Hacerla en una transacción (con la fila del cliente bloqueada) y guardar la respuesta sin claves
        try {
            $this->db->beginTransaction();
            $id = null;
            if (!in_array($accion, self::SIN_CLIENTE, true)) {
                if ($clienteId === null) {
                    throw TukanCentralError::campo('cliente_id', 'Falta el cliente.');
                }
                $id = $this->bloquear($clienteId);
            }
            $r = $this->operar($accion, $id, $datos);
            $id = $r['cliente_id'] ?? $id;
            $this->anotar((int) $id, $r['registro'] . (!empty($r['sin_cambios']) ? ' (sin cambios)' : '') . ' (op ' . substr($op, 0, 8) . ')');
            $fila = $this->fila((int) $id);
            $json = $this->sobre('ejecutar') + [
                'operacion' => $accion, 'operacion_id' => $op, 'repetida' => false, 'sin_cambios' => (bool) ($r['sin_cambios'] ?? false),
                'mensaje' => $r['mensaje'], 'resultado' => $r['resultado'], 'cliente' => $fila ? $this->cliente($fila) : null, 'solicitud' => null,
            ] + ($r['arriba'] ?? []);
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'ok', http = 200, respuesta = ?, cliente_id = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([json_encode(self::sinClaves($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), (string) $id, $op]);
            $this->db->commit();
            return [200, $json];
        } catch (TukanCentralError $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->codigo === 'interno') {
                $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);
                throw $e;
            }
            // 3. Error de validación: queda guardado (el reintento recibe lo mismo)
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'error', http = ?, respuesta = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([$e->http, json_encode($e->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $op]);
            throw $e;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);   // se puede reintentar
            throw $e;
        }
    }

    /** La respuesta que se guarda: sin contraseñas ni mensajes que las lleven (§6.2 "Claves de un solo uso"). */
    public static function sinClaves(array $json): array
    {
        $r = $json['resultado'] ?? null;
        if (!is_array($r)) {
            return $json;
        }
        $hay = false;
        if (isset($r['acceso']) && is_array($r['acceso']) && array_key_exists('clave', $r['acceso'])) {
            $r['acceso']['clave'] = null;
            $r['acceso']['clave_oculta'] = true;
            $hay = true;
        }
        if (array_key_exists('clave', $r)) {
            $r['clave'] = null;
            $r['clave_oculta'] = true;
            $hay = true;
        }
        if ($hay) {
            if (array_key_exists('mensaje_whatsapp', $r)) {
                $r['mensaje_whatsapp'] = null;
            }
            if (array_key_exists('whatsapp_url', $r)) {
                $r['whatsapp_url'] = null;
            }
        }
        $json['resultado'] = $r;
        return $json;
    }

    private function bloquear(int|string $clienteId): int
    {
        if (!preg_match('/^\d{1,10}$/', (string) $clienteId)) {
            throw new TukanCentralError('no_encontrado', 'Ese cliente no existe en WhatsApp Tukán.', 404);
        }
        $st = $this->db->prepare('SELECT id FROM empresas WHERE id = ? AND id <> ' . Empresas::TUKAN . ' FOR UPDATE');
        $st->execute([(int) $clienteId]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new TukanCentralError('no_encontrado', 'Ese cliente ya no existe en WhatsApp Tukán.', 404);
        }
        return (int) $id;
    }

    /** Una línea en el registro de la empresa: quién (del token), qué y la clave de operación. */
    private function anotar(int $empresaId, string $texto): void
    {
        $this->db->prepare('INSERT INTO tukan_central_registro (empresa_id, quien, texto) VALUES (?, ?, ?)')
                 ->execute([$empresaId, mb_substr($this->quien, 0, 200), mb_substr($texto, 0, 500)]);
    }

    private function operar(string $accion, ?int $id, array $d): array
    {
        try {
            return match ($accion) {
                'crear_demo' => $this->opCrearDemo($d),
                'extender_prueba' => $this->opExtenderPrueba($id, $d),
                'suspender' => $this->opSuspender($id, $d),
                'reactivar' => $this->opReactivar($id),
                'cambiar_plan' => $this->opCambiarPlan($id, $d),
                'nueva_clave_dueno' => $this->opNuevaClave($id),
                'nota_interna' => $this->opNota($id, $d),
                'cambiar_modulos' => $this->opCambiarModulos($id, $d),
                'ia_tope' => $this->opIaTope($id, $d),
            };
        } catch (TukanCentralError | PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {   // reglas de Empresas y Modulos: mensaje en español para mostrar
            throw new TukanCentralError('invalido', $e->getMessage(), 422);
        }
    }

    /** Guarda cambios de la empresa con Empresas::guardar (las mismas validaciones que la ficha de Clientes de Tukán). */
    private function guardarEmpresa(int $id, array $cambios): void
    {
        $e = Empresas::buscar($this->db, $id);
        Empresas::guardar($this->db, $id, array_merge($e, $cambios));
    }

    private function opCrearDemo(array $d): array
    {
        $nombre = $this->textoReq($d, 'nombre', 3, 100);
        $contacto = $this->textoReq($d, 'contacto_nombre', 2, 80);
        $email = $d['email'] ?? null;
        if (!is_string($email) || mb_strlen(trim($email)) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw TukanCentralError::campo('datos.email', 'El correo no es válido.');
        }
        $email = mb_strtolower(trim($email));
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE lower(correo) = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            throw TukanCentralError::campo('datos.email', 'Ya existe un usuario de WhatsApp Tukán con ese correo.');
        }
        $telefono = $this->textoReq($d, 'telefono', 9, 30);
        if (strlen((string) preg_replace('/\D+/', '', $telefono)) < 9) {
            throw TukanCentralError::campo('datos.telefono', 'El teléfono debe tener al menos 9 dígitos.');
        }
        $plan = $d['plan'] ?? null;
        if ($plan === null || $plan === '') {
            $plan = self::PLAN_DEMO;
        }
        if (!is_string($plan) || !isset(Modulos::PLANES[$plan])) {
            throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en WhatsApp Tukán (' . implode(', ', array_keys(Modulos::PLANES)) . ').');
        }
        $dias = $this->entero($d, 'dias', self::DIAS_DEMO, 1, self::DIAS_PRUEBA_MAX, 'datos.');
        $sol = $d['solicitud_id'] ?? null;
        if ($sol !== null && $sol !== '') {
            throw TukanCentralError::campo('datos.solicitud_id', 'WhatsApp Tukán no tiene pedidos de demo.');
        }
        $extra = $d['extra'] ?? [];
        if (!is_array($extra) || ($extra !== [] && array_is_list($extra))) {
            throw TukanCentralError::campo('datos.extra', 'Los datos propios no son válidos.');
        }
        // Anexo B: la demo ve todos los módulos, salvo que se elijan (lista vacía = todos; "ninguno" = solo lo básico)
        $mods = $this->listaModulos(array_key_exists('modulos', $d) ? $d['modulos'] : ($extra['modulos'] ?? null), 'datos.modulos', true);
        $hasta = $this->hoy->modify("+$dias days")->format('Y-m-d');

        // Las mismas funciones que "Crear cliente nuevo" de Clientes de Tukán
        $alta = Empresas::crear($this->db, ['nombre' => $nombre, 'estado' => 'demo', 'vence' => $hasta, 'max_numeros' => 1, 'max_usuarios' => 3,
                                            'contacto' => $contacto, 'telefono' => $telefono, 'correo' => $email, 'notas' => '',
                                            'admin_correo' => $email, 'admin_nombre' => $contacto, 'origen' => 'central']);
        $id = $alta['empresa_id'];
        $clave = $alta['clave'];
        Modulos::aplicarPlan($this->db, $id, $plan, $mods ?? Modulos::todos());
        $activos = Modulos::deEmpresa(Empresas::buscar($this->db, $id));

        $primer = explode(' ', $contacto)[0];
        $msg = "¡Hola $primer! La cuenta de *$nombre* ya está lista en WhatsApp Tukán, con " . ($mods === null ? 'todos los módulos' : count($activos) . ' módulos') . ".\n\n"
             . '🔐 Entrar: ' . self::urlLogin() . "\n👤 Usuario: $email\n🔑 Contraseña: $clave\n\n"
             . 'Al entrar toca «Conectar con WhatsApp Business» para conectar tu número (sigue funcionando en tu celular). '
             . 'Te recomendamos cambiar la contraseña en Mi cuenta. La demo dura hasta el ' . self::fechaTexto($hasta) . '.';
        $wa = Telefono::normalizar($telefono);
        return [
            'cliente_id' => $id,
            'mensaje' => "Demo creada: $nombre, hasta el $hasta" . ($mods === null ? ', con todos los módulos.' : ', con ' . count($activos) . ' módulos.'),
            'resultado' => ['cliente_id' => (string) $id, 'prueba_hasta' => $hasta,
                            'acceso' => ['url_panel' => self::urlLogin(), 'usuario' => $email, 'clave' => $clave],
                            'enlace_publico' => null, 'mensaje_whatsapp' => $msg,
                            'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null,
                            'correo_enviado' => false,   // WhatsApp Tukán no envía correos
                            'modulos' => $activos],
            'registro' => "Demo creada: $nombre (plan $plan, hasta $hasta, módulos: " . (implode(',', $activos) ?: 'solo lo básico') . ')',
        ];
    }

    private function opExtenderPrueba(int $id, array $d): array
    {
        $dias = $this->entero($d, 'dias', null, 1, self::DIAS_PRUEBA_MAX, 'datos.');
        $e = $this->fila($id);
        $c = $this->cliente($e);
        if ($c['estado'] === 'suspendido') {
            throw new TukanCentralError('no_permitido', "{$e['nombre']} está suspendido: reactívalo primero.", 403, null, ['motivo' => 'suspendido']);
        }
        if (!$this->puedeExtender($c)) {
            throw new TukanCentralError('no_permitido', 'Solo se alarga la demo de quien está en demo (o la terminó). Esta cuenta ya es de pago.', 403, null,
                                        ['motivo' => 'ya_paga', 'estado' => $c['estado']]);
        }
        // Suma desde el fin de la demo si todavía no termina, o desde hoy
        $base = $e['vence'] && (string) $e['vence'] >= $this->hoy->format('Y-m-d') ? new DateTimeImmutable((string) $e['vence']) : $this->hoy;
        $hasta = $base->modify("+$dias days")->format('Y-m-d');
        $this->guardarEmpresa($id, ['vence' => $hasta]);
        return ['mensaje' => "Demo de {$e['nombre']} hasta el " . self::fechaTexto($hasta) . '.', 'resultado' => ['prueba_hasta' => $hasta],
                'registro' => "Demo alargada $dias días, hasta $hasta"];
    }

    private function opSuspender(int $id, array $d): array
    {
        $motivo = $this->textoReq($d, 'motivo', 5, 200);
        $e = $this->fila($id);
        if ($e['estado'] === 'suspendida') {
            return ['mensaje' => "{$e['nombre']} ya estaba suspendido.", 'sin_cambios' => true, 'resultado' => ['estado' => 'suspendido'],
                    'registro' => "Suspender: $motivo"];
        }
        $this->guardarEmpresa($id, ['estado' => 'suspendida', 'motivo_suspension' => $motivo]);
        $this->db->prepare('UPDATE empresas SET estado_antes = ? WHERE id = ?')->execute([$e['estado'], $id]);
        return ['mensaje' => "{$e['nombre']} quedó suspendido: no puede entrar ni se contesta solo. Los mensajes que lleguen se guardan.",
                'resultado' => ['estado' => 'suspendido'], 'registro' => "Suspendido: $motivo"];
    }

    private function opReactivar(int $id): array
    {
        $e = $this->fila($id);
        if ($e['estado'] !== 'suspendida') {
            return ['mensaje' => "{$e['nombre']} no estaba suspendido.", 'sin_cambios' => true,
                    'resultado' => ['estado' => TukanCentral::clasificar($e, $this->hoy)['estado']], 'registro' => 'Reactivar'];
        }
        // Vuelve a como estaba antes de suspenderlo (demo o activa); queda en el estado que le toca por su fecha
        $antes = in_array($e['estado_antes'] ?? '', ['demo', 'activa'], true) ? $e['estado_antes'] : 'activa';
        if ($antes === 'demo' && !$e['vence']) {
            $antes = 'activa';
        }
        $this->guardarEmpresa($id, ['estado' => $antes, 'motivo_suspension' => '']);
        $this->db->prepare('UPDATE empresas SET estado_antes = NULL WHERE id = ?')->execute([$id]);
        $nuevo = TukanCentral::clasificar($this->fila($id), $this->hoy)['estado'];
        return ['mensaje' => "{$e['nombre']} reactivado: queda " . self::ESTADOS_TEXTO[$nuevo] . '.', 'resultado' => ['estado' => $nuevo],
                'registro' => "Reactivado ($antes)"];
    }

    private function opCambiarPlan(int $id, array $d): array
    {
        $plan = $d['plan'] ?? null;
        if (!is_string($plan) || !isset(Modulos::PLANES[$plan])) {
            throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en WhatsApp Tukán (' . implode(', ', array_keys(Modulos::PLANES)) . ').');
        }
        $e = $this->fila($id);
        $antes = Modulos::planDe($e);
        if ($antes === $plan && ($e['plan'] ?? null) === $plan) {
            return ['mensaje' => "{$e['nombre']} ya tenía el plan " . Modulos::PLANES[$plan][0] . '.', 'sin_cambios' => true, 'resultado' => ['plan' => $plan],
                    'registro' => "Plan $plan"];
        }
        // Igual que el botón del plan en la ficha de Clientes de Tukán; en demo se conservan los módulos encendidos
        Modulos::aplicarPlan($this->db, $id, $plan, $e['estado'] === 'demo' ? Modulos::deEmpresa($e) : null);
        $mods = $this->modulosCliente($this->fila($id));
        return ['mensaje' => 'Plan cambiado a ' . Modulos::PLANES[$plan][0] . ' (sin cobrar): ' . self::dinero($mods['mensual']) . ' al mes.',
                'resultado' => ['plan' => $plan, 'modulos' => $mods], 'registro' => "Plan cambiado de $antes a $plan"];
    }

    private function opNuevaClave(int $id): array
    {
        $u = $this->dueno($id);
        if (!$u) {
            throw new TukanCentralError('no_permitido', 'Esta cuenta no tiene un administrador activo. Créalo en Clientes de Tukán.', 403, null, ['motivo' => 'sin_dueno']);
        }
        $clave = (string) Empresas::cambiarUsuario($this->db, $id, (int) $u['id'], 'clave', 0);   // la misma función de "Clientes de Tukán"
        $primer = explode(' ', (string) $u['nombre'])[0];
        $msg = "Hola $primer, tu acceso a WhatsApp Tukán: " . self::urlLogin() . " · Correo: {$u['correo']} · Contraseña nueva: $clave (cámbiala al entrar, en Mi cuenta)";
        $e = $this->fila($id);
        $wa = Telefono::normalizar((string) $e['telefono']);
        return ['mensaje' => 'Contraseña nueva creada. La anterior ya no funciona.',
                'resultado' => ['usuario' => (string) $u['correo'], 'clave' => $clave, 'mensaje_whatsapp' => $msg,
                                'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null],
                'registro' => 'Nueva contraseña para ' . $u['correo']];
    }

    private function opNota(int $id, array $d): array
    {
        $texto = $d['texto'] ?? '';
        if (!is_string($texto) || mb_strlen($texto) > 4000) {
            throw TukanCentralError::campo('datos.texto', 'La nota puede tener hasta 4000 letras.');
        }
        $antes = (string) $this->fila($id)['notas'];
        if (trim($texto) === trim($antes)) {
            return ['mensaje' => 'La nota no cambió.', 'sin_cambios' => true, 'resultado' => ['notas_admin' => $antes], 'registro' => 'Notas internas'];
        }
        $this->guardarEmpresa($id, ['notas' => $texto]);
        return ['mensaje' => trim($texto) === '' ? 'Nota borrada.' : 'Nota guardada.', 'resultado' => ['notas_admin' => trim($texto)],
                'registro' => 'Notas internas actualizadas'];
    }

    private function opCambiarModulos(int $id, array $d): array
    {
        $encender = $this->listaModulos($d['encender'] ?? null, 'datos.encender') ?? [];
        $apagar = $this->listaModulos($d['apagar'] ?? null, 'datos.apagar') ?? [];
        if (array_intersect($encender, $apagar)) {
            throw TukanCentralError::campo('datos.apagar', 'Un módulo no se puede encender y apagar a la vez: ' . implode(', ', array_intersect($encender, $apagar)) . '.');
        }
        if (($d['precio_acordado'] ?? null) !== null) {
            throw TukanCentralError::campo('datos.precio_acordado', 'WhatsApp Tukán no guarda precio acordado: el precio sale del plan y los módulos.');
        }
        $sol = $d['solicitud_id'] ?? null;
        if ($sol !== null && $sol !== '') {
            throw TukanCentralError::campo('datos.solicitud_id', 'WhatsApp Tukán no tiene pedidos de módulos.');
        }
        $plan = $d['plan'] ?? null;
        if ($plan === '') {
            $plan = null;
        }
        if ($plan !== null && (!is_string($plan) || !isset(Modulos::PLANES[$plan]))) {
            throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en WhatsApp Tukán.');
        }
        $motivo = $this->texto($d, 'motivo', 300, 'datos.');
        $e = $this->fila($id);
        $antes = $this->modulosCliente($e);
        $activos = Modulos::limpiar(array_values(array_diff(array_merge($antes['activos'], $encender), $apagar)));
        $planAntes = Modulos::planDe($e);
        if ($plan !== null && $plan !== $planAntes) {
            Modulos::aplicarPlan($this->db, $id, $plan, $activos);   // cambia el plan conservando lo encendido (Anexo B nota 5)
        } else {
            Modulos::guardar($this->db, $id, $activos);
        }
        $mods = $this->modulosCliente($this->fila($id));
        $encendidos = array_values(array_diff($mods['activos'], $antes['activos']));
        $apagados = array_values(array_diff($antes['activos'], $mods['activos']));
        $sinCambios = !$encendidos && !$apagados && ($plan === null || $plan === $planAntes);
        $texto = trim(($encendidos ? 'Encendidos: ' . implode(', ', $encendidos) . '. ' : '') . ($apagados ? 'Apagados: ' . implode(', ', $apagados) . '. ' : '')
                      . ($plan !== null && $plan !== $planAntes ? "Plan: $plan. " : ''));
        return ['mensaje' => $sinCambios ? "Los módulos de {$e['nombre']} ya estaban así." : "Módulos de {$e['nombre']} guardados. $texto Paga " . self::dinero($mods['mensual']) . ' al mes.',
                'sin_cambios' => $sinCambios,
                'resultado' => ['modulos' => $mods, 'encendidos' => $encendidos, 'apagados' => $apagados, 'por_dependencia' => [],
                                'mensual_antes' => $antes['mensual'], 'mensual' => $mods['mensual']],
                'arriba' => ['modulos' => $mods],
                'registro' => 'Módulos: ' . ($texto !== '' ? $texto : 'sin cambios') . ($motivo !== '' ? " · $motivo" : '')];
    }

    /** ejecutar ia_tope (Anexo C.2): {"tope_usd": número | null}; null = el tope del plan. */
    private function opIaTope(int $id, array $d): array
    {
        if (!array_key_exists('tope_usd', $d)) {
            throw TukanCentralError::campo('datos.tope_usd', 'Falta el tope (un número o vacío).');
        }
        $v = $d['tope_usd'];
        if ($v === '') {
            $v = null;
        }
        if (is_string($v) && is_numeric(str_replace(',', '.', $v))) {
            $v = (float) str_replace(',', '.', $v);
        }
        if ($v !== null && !(is_int($v) || is_float($v))) {
            throw TukanCentralError::campo('datos.tope_usd', 'El tope debe ser un número (USD) o vacío.');
        }
        if ($v !== null && ($v < 0 || $v > 1000)) {
            throw TukanCentralError::campo('datos.tope_usd', 'El tope debe estar entre 0 y 1.000 USD.');
        }
        $e = $this->fila($id);
        $nuevo = round($v === null ? (float) Modulos::PLANES[Modulos::planDe($e)][5] : (float) $v, 2);
        if (abs((float) $e['ia_tope'] - $nuevo) < 0.005) {
            return ['mensaje' => 'El tope de IA ya era ' . self::dinero($nuevo) . ' al mes.', 'sin_cambios' => true,
                    'resultado' => ['tope_usd' => $nuevo, 'modulo' => Modulos::activo($e, 'ia')], 'registro' => 'Tope de IA ' . self::dinero($nuevo)];
        }
        $this->db->prepare('UPDATE empresas SET ia_tope = ? WHERE id = ?')->execute([$nuevo, $id]);
        return ['mensaje' => 'Tope de IA del mes: ' . self::dinero($nuevo) . '.', 'resultado' => ['tope_usd' => $nuevo, 'modulo' => Modulos::activo($e, 'ia')],
                'registro' => 'Tope de IA: ' . self::dinero((float) $e['ia_tope']) . ' → ' . self::dinero($nuevo)];
    }

    // ------------------------------------------------------------------
    // Validación de datos
    // ------------------------------------------------------------------

    /**
     * Lista de módulos (array o "a,b"). null si no vino (o vino vacía y $vaciaEsTodos). "ninguno" = solo lo básico.
     * Un id que no existe → 422 con $campo.
     */
    private function listaModulos(mixed $v, string $campo, bool $vaciaEsTodos = false): ?array
    {
        if ($v === null || (is_string($v) && trim($v) === '') || ($vaciaEsTodos && $v === [])) {
            return null;
        }
        if (is_string($v) && mb_strtolower(trim($v)) === 'ninguno') {
            return [];
        }
        if (is_string($v)) {
            $v = array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
        }
        if (!is_array($v) || !array_is_list($v) || count($v) > 50) {
            throw TukanCentralError::campo($campo, 'La lista de módulos no es válida.');
        }
        foreach ($v as $x) {
            if (!is_string($x) || !isset(Modulos::LISTA[$x])) {
                throw TukanCentralError::campo($campo, 'Ese módulo no existe en WhatsApp Tukán: ' . mb_substr(is_scalar($x) ? (string) $x : '?', 0, 40)
                                                       . '. Módulos: ' . implode(', ', array_keys(Modulos::LISTA)) . '.');
            }
        }
        return Modulos::limpiar($v);
    }

    private function idCliente(mixed $v, string $campo): int
    {
        if (!(is_int($v) || (is_string($v) && preg_match(self::ID, $v)))) {
            throw TukanCentralError::campo($campo, 'Falta el cliente.');
        }
        if (!preg_match('/^\d{1,10}$/', (string) $v) || !$this->fila((int) $v)) {
            throw new TukanCentralError('no_encontrado', 'Ese cliente ya no existe en WhatsApp Tukán.', 404);
        }
        return (int) $v;
    }

    private function entero(array $d, string $k, ?int $defecto, int $min, int $max, string $ruta = ''): int
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            if ($defecto === null) {
                throw TukanCentralError::campo($ruta . $k, "Falta el valor de \"$k\".");
            }
            return $defecto;
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,9}$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser un número de $min a $max.");
        }
        return $v;
    }

    private function texto(array $d, string $k, int $max, string $ruta = ''): string
    {
        $v = $d[$k] ?? null;
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser texto.");
        }
        $v = trim($v);
        if (mb_strlen($v) > $max) {
            throw TukanCentralError::campo($ruta . $k, "\"$k\" puede tener hasta $max letras.");
        }
        return $v;
    }

    private function textoReq(array $d, string $k, int $min, int $max): string
    {
        $v = $this->texto($d, $k, $max, 'datos.');
        if (mb_strlen($v) < $min) {
            throw TukanCentralError::campo('datos.' . $k, "Escribe \"$k\" (de $min a $max letras).");
        }
        return $v;
    }

    private function lista(array $d, string $k, array $permitidos): array
    {
        $v = $d[$k] ?? [];
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn ($x) => !is_string($x) || !in_array($x, $permitidos, true))) {
            throw TukanCentralError::campo($k, "\"$k\" debe ser una lista con: " . implode(', ', $permitidos) . '.');
        }
        return $v;
    }

    private function opcion(array $d, string $k, array $permitidos, string $defecto): string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            return $defecto;
        }
        if (!is_string($v) || !in_array($v, $permitidos, true)) {
            throw TukanCentralError::campo($k, "\"$k\" debe ser uno de: " . implode(', ', $permitidos) . '.');
        }
        return $v;
    }

    private function fecha(array $d, string $k): ?string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $f = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$f || $f->format('Y-m-d') !== $v) {
            throw TukanCentralError::campo($k, "\"$k\" debe ser una fecha como 2026-10-13.");
        }
        return $v;
    }

    // ------------------------------------------------------------------
    // Ayudas: fechas, dinero y direcciones
    // ------------------------------------------------------------------

    private function diasHasta(string $fecha): int
    {
        return (int) $this->hoy->diff(new DateTimeImmutable(substr($fecha, 0, 10)))->format('%r%a');
    }

    public static function momento(?string $ts): ?string
    {
        return $ts ? (new DateTimeImmutable($ts))->format('c') : null;
    }

    private static function fechaTexto(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m/Y') : '';
    }

    private static function fechaCorta(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m') : '';
    }

    private static function dias(int $n): string
    {
        return $n === 1 ? '1 día' : "$n días";
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    /** Dirección del programa (TUWA_URL de config/.env). */
    public static function urlBase(): string
    {
        return rtrim((string) (getenv('TUWA_URL') ?: 'https://whatsapp.tukanec.com'), '/');
    }

    public static function urlLogin(): string
    {
        return self::urlBase() . '/?r=login';
    }
}
