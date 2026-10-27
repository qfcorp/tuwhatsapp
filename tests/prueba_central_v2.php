<?php
declare(strict_types=1);

/**
 * Pruebas del conector con el Panel Tukán Central, protocolo v2 (PROTOCOLO_V2.md §11 del Central), contra PostgreSQL real:
 * token v2 (firma, bh, nonce, vida, versión, códigos), transporte (POST, JSON, tamaño), capacidades, clientes (mismas cifras
 * que "estado" v1), cliente, planes, módulos (Anexo B), ia_uso (Anexo C), cada operación de "ejecutar" con su efecto real
 * en la base, idempotencia (operacion_id), claves de un solo uso, registro y límite por IP.
 * Uso: bash tests/correr.sh   (crea la base tuwhatsapp_prueba vacía y corre esto)
 */

foreach (['TUWA_LLAVE' => str_repeat('ab', 32), 'TUWA_URL' => 'https://whatsapp.ejemplo.com', 'TUKAN_IA_ENV' => '/no/existe'] as $k => $v) {
    putenv("$k=$v");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\{Empresas, Modulos, TukanCentral, TukanCentralError, TukanCentralV2};

$bien = 0;
$mal = 0;
function ok(bool $c, string $que): void
{
    global $bien, $mal;
    if ($c) {
        $bien++;
    } else {
        $mal++;
        echo "  FALLA: $que\n";
    }
}
function igual(mixed $a, mixed $b, string $que): void
{
    ok($a === $b, $que . ' (esperaba ' . var_export($b, true) . ', salió ' . var_export($a, true) . ')');
}

$db = db();
if (!str_contains((string) $db->query('SELECT current_database()')->fetchColumn(), 'prueba')) {
    exit("Por seguridad, estas pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
}
$SEC = str_repeat('c3', 32);
putenv('TUKAN_CENTRAL_ID=tuwhatsapp');
putenv("TUKAN_CENTRAL_SECRET=$SEC");
$b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
const QUITAR = '__quitar__';
const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
$hoy = new DateTimeImmutable('today');
$d = fn (string $m) => $hoy->modify($m)->format('Y-m-d');
$cuenta = fn (string $sql) => (int) $db->query($sql)->fetchColumn();

/** Token v2 como lo arma el Central (§13.1). $cambios con QUITAR borra la clave. */
$token = function (string $tipo, string $cuerpo, array $cambios = [], ?string $secreto = null) use ($SEC, $b64): string {
    $t = time();
    $p = array_merge(['v' => 2, 'tipo' => $tipo, 'inst' => 'tuwhatsapp', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno',
                      'iat' => $t, 'exp' => $t + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)),
                      'bh' => $b64(hash('sha256', $cuerpo, true))], $cambios);
    $p = array_filter($p, fn ($v) => $v !== QUITAR);
    $a = $b64(json_encode($p, FLAGS));
    return $a . '.' . $b64(hash_hmac('sha256', $a, $secreto ?? $SEC, true));
};
$ipN = 0;
/** Un pedido completo. Devuelve [http, json, cabeceras]. */
$pedir = function (string $accion, array|string $cuerpo = [], array $cambios = [], array $op = []) use ($db, $token, &$ipN): array {
    $c = is_string($cuerpo) ? $cuerpo : json_encode($cuerpo === [] ? new stdClass() : $cuerpo, FLAGS);
    $t = $op['token'] ?? $token($accion, $c, $cambios, $op['secreto'] ?? null);
    $ipN++;
    return (new TukanCentralV2($db))->procesar($accion, $op['metodo'] ?? 'POST', $op['tipo'] ?? 'application/json; charset=utf-8', $c, $t,
                                               $op['ip'] ?? '10.1.' . intdiv($ipN, 200) . '.' . ($ipN % 200));
};
$ejecutar = fn (string $accion, array $datos, ?string $cliente = null, ?string $opId = null) =>
    $pedir('ejecutar', array_filter(['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $accion, 'cliente_id' => $cliente,
                                     'datos' => $datos === [] ? new stdClass() : $datos], fn ($v) => $v !== null));
$codigo = function (array $r, int $http, string $codigo, string $que, ?string $campo = null): void {
    $okCodigo = $r[0] === $http && ($r[1]['ok'] ?? null) === false && ($r[1]['codigo'] ?? null) === $codigo
        && in_array($r[1]['codigo'], TukanCentralError::CODIGOS, true) && is_string($r[1]['error'] ?? null) && $r[1]['error'] !== ''
        && ($r[1]['protocolo'] ?? null) === 2 && ($campo === null || ($r[1]['campo'] ?? null) === $campo);
    ok($okCodigo, "$que (esperaba $http $codigo" . ($campo ? " $campo" : '') . ', salió ' . $r[0] . ' ' . json_encode($r[1], FLAGS) . ')');
};

// Datos: Tukán (empresa 1, no es cliente) + un cliente en cada estado
$crear = function (string $nombre, string $estado, ?string $vence, string $correo) use ($db): int {
    return Empresas::crear($db, ['nombre' => $nombre, 'estado' => $estado, 'vence' => $vence ?? '', 'contacto' => 'Ana Pérez', 'telefono' => '0991234567',
                                 'correo' => '', 'admin_correo' => $correo, 'admin_nombre' => 'Ana Pérez'])['empresa_id'];
};
$eDemo = $crear('Salón Demo', 'demo', $d('+5 days'), 'demo@cliente.com');
$eDemoFin = $crear('Demo Terminada', 'demo', $d('-3 days'), 'fin@cliente.com');
$eActiva = $crear('Ferretería Al Día', 'activa', $d('+40 days'), 'activa@cliente.com');
$ePorVencer = $crear('Vence En Siete', 'activa', $d('+7 days'), 'siete@cliente.com');
$eVencida = $crear('Vencida SA', 'activa', $d('-1 day'), 'vencida@cliente.com');
$eCortesia = $crear('Cortesía Sin Fecha', 'activa', null, 'cortesia@cliente.com');
$eSusp = $crear('Suspendida Ltda', 'activa', $d('+30 days'), 'susp@cliente.com');
Empresas::guardar($db, $eSusp, array_merge(Empresas::buscar($db, $eSusp), ['estado' => 'suspendida', 'motivo_suspension' => 'Falta de pago']));
Modulos::aplicarPlan($db, $eActiva, 'negocio');

echo "== capacidades\n";
$r = $pedir('capacidades');
$cap = $r[1];
ok($r[0] === 200 && $cap['ok'] === true && $cap['protocolo'] === 2 && $cap['producto'] === 'tuwhatsapp' && $cap['accion'] === 'capacidades', 'capacidades: cabecera v2');
ok(json_decode(json_encode($cap, FLAGS), true) === $cap, 'capacidades: JSON válido');
igual($cap['unico'], false, 'no es de una sola empresa');
igual($cap['acciones'], ['capacidades', 'estado', 'sso', 'clientes', 'cliente', 'planes', 'ejecutar', 'modulos', 'ia_uso'], 'acciones que de verdad contesta');
foreach (['crear_demo', 'extender_prueba', 'nueva_clave_dueno', 'suspender', 'reactivar', 'cambiar_plan', 'cambiar_modulos'] as $o) {
    ok(in_array($o, $cap['ejecutar'], true), "ejecutar tiene $o");
}
ok(!array_intersect(['registrar_pago', 'anular_pago', 'aprobar_comprobante', 'pausar', 'borrar_cliente'], $cap['ejecutar']), 'no declara operaciones que no tiene');
igual($cap['limites']['dias_prueba_max'], 90, 'límite de días de demo');
igual($cap['formularios']['crear_demo'], [], 'crear_demo sin campos propios');
ok($cap['modulos']['crear_demo'] === true && $cap['modulos']['editable'] === false, 'capacidades.modulos (Anexo B)');
ok($cap['sso_ir'] === true && $cap['rutas_sso'] === ['/?r=cliente'], 'SSO con enlace directo');

echo "== Token v2 y transporte (§3.1)\n";
$codigo($pedir('capacidades', [], ['bh' => QUITAR]), 401, 'firma', 'sin bh');
$r = $pedir('capacidades', [], ['bh' => $b64(hash('sha256', '{"otro":1}', true))]);
$codigo($r, 401, 'firma', 'bh de otro cuerpo');
igual($r[1]['detalle'] ?? null, ['parte' => 'cuerpo'], 'firma del cuerpo dice la parte');
$cuerpoBueno = '{"pagina":1}';
$codigo($pedir('clientes', '{"pagina":2}', [], ['token' => $token('clientes', $cuerpoBueno)]), 401, 'firma', 'cuerpo cambiado un byte');
$codigo($pedir('capacidades', [], [], ['secreto' => str_repeat('zz', 32)]), 401, 'firma', 'firma mala (otro secreto)');
$bueno = $token('capacidades', '{}');
$codigo($pedir('capacidades', '{}', [], ['token' => substr($bueno, 0, -3) . 'abc']), 401, 'firma', 'firma cambiada');
$codigo($pedir('capacidades', '{}', [], ['token' => 'basura']), 401, 'firma', 'token ilegible');
$codigo($pedir('capacidades', [], ['v' => 1]), 401, 'version', 'v:1 en una acción v2');
$codigo($pedir('capacidades', [], ['tipo' => 'clientes']), 401, 'tipo', 'tipo distinto de la acción');
$codigo($pedir('capacidades', [], ['inst' => 'tusalon']), 401, 'inst', 'otra instancia');
$t = time();
$codigo($pedir('capacidades', [], ['iat' => $t - 200, 'exp' => $t - 150]), 401, 'vencido', 'token vencido');
$codigo($pedir('capacidades', [], ['iat' => $t + 200, 'exp' => $t + 250]), 401, 'futuro', 'token con fecha futura');
$codigo($pedir('capacidades', [], ['exp' => $t + 61]), 401, 'invalido', 'lectura que dura 61 s');
$r = $ejecutar('nota_interna', ['texto' => 'x'], (string) $eActiva);   // control: vida 30 sí sirve
igual($r[0], 200, 'ejecutar con vida de 30 s');
$cu = json_encode(['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'nota_interna', 'cliente_id' => (string) $eActiva, 'datos' => ['texto' => 'y']], FLAGS);
$codigo($pedir('ejecutar', $cu, ['exp' => $t + 31, 'iat' => $t]), 401, 'invalido', 'ejecutar con exp - iat = 31');
$codigo($pedir('capacidades', [], ['email' => 'no-es-correo']), 401, 'invalido', 'correo inválido en el token');
$repetido = $token('capacidades', '{}');
igual($pedir('capacidades', '{}', [], ['token' => $repetido])[0], 200, 'token bueno la primera vez');
$codigo($pedir('capacidades', '{}', [], ['token' => $repetido]), 401, 'nonce', 'token repetido (nonce)');
$codigo($pedir('capacidades', [], ['nonce' => 'abc']), 401, 'nonce', 'nonce mal formado');
// El nonce se gasta después de firma, tiempo y cuerpo: un token con el cuerpo cambiado no gasta su nonce
$tk = $token('clientes', $cuerpoBueno);
$pedir('clientes', '{"pagina":3}', [], ['token' => $tk]);
igual($pedir('clientes', $cuerpoBueno, [], ['token' => $tk])[0], 200, 'un token roto no gasta su nonce');
$r = $pedir('capacidades', [], [], ['metodo' => 'GET']);
$codigo($r, 405, 'invalido', 'GET a una acción v2');
igual($r[2]['Allow'] ?? null, 'POST', 'GET: cabecera Allow');
$codigo($pedir('capacidades', 'esto no es json'), 400, 'invalido', 'cuerpo que no es JSON');
$codigo($pedir('capacidades', '[1,2]'), 400, 'invalido', 'cuerpo que es una lista');
$codigo($pedir('capacidades', [], [], ['tipo' => 'text/plain']), 415, 'invalido', 'Content-Type que no es JSON');
$codigo($pedir('capacidades', '{"x":"' . str_repeat('a', 65540) . '"}'), 413, 'invalido', 'cuerpo de más de 64 KiB');
$codigo($pedir('borrar'), 400, 'invalido', 'acción que no existe en el protocolo');
$r = $pedir('finanzas');
$codigo($r, 403, 'no_permitido', 'acción del protocolo que WhatsApp Tukán no tiene');
igual($r[1]['detalle']['motivo'] ?? null, 'no_disponible', 'no_disponible');
$r = $ejecutar('registrar_pago', ['monto' => 19.0], (string) $eActiva);
$codigo($r, 403, 'no_permitido', 'operación que no tiene (registrar_pago)');
$codigo($pedir('ejecutar', ['accion' => 'nota_interna', 'cliente_id' => (string) $eActiva, 'datos' => []]), 422, 'invalido', 'ejecutar sin operacion_id', 'operacion_id');
putenv('TUKAN_CENTRAL_SECRET=corto');
$codigo($pedir('capacidades'), 404, 'sin_configurar', 'sin configurar');
putenv("TUKAN_CENTRAL_SECRET=$SEC");

echo "== clientes, cliente y estado v1 (mismas cifras)\n";
$r = $pedir('clientes', ['por_pagina' => 200]);
ok($r[0] === 200 && $r[1]['total'] === 7 && count($r[1]['clientes']) === 7, 'clientes: los 7 (sin Tukán)');
$cont = $r[1]['contadores'];
igual($cont, ['total' => 7, 'prueba' => 1, 'activo' => 2, 'por_vencer' => 1, 'vencido' => 2, 'suspendido' => 1], 'contadores con los 5 estados');
$est = (new TukanCentral($db))->estado();
igual([$est['negocios']['total'], $est['negocios']['prueba'], $est['negocios']['activos'], $est['negocios']['por_vencer'], $est['negocios']['vencidos'], $est['negocios']['suspendidos']],
      array_values($cont), 'clientes.contadores = estado.negocios');
ok($est['protocolo'] === 1 && $est['protocolo_max'] === 2, 'estado v1 sigue con protocolo 1 y dice protocolo_max 2');
$porId = array_column($r[1]['clientes'], null, 'id');
igual($porId[(string) $ePorVencer]['estado'], 'por_vencer', 'vence en 7 días exactos = por_vencer');
igual($porId[(string) $ePorVencer]['fechas']['dias_para_vencer'], 7, 'días para vencer');
igual($porId[(string) $eDemoFin]['estado_detalle'], 'demo_terminada', 'demo terminada = vencido');
igual($porId[(string) $eCortesia]['estado_detalle'], 'sin_limite', 'sin fecha = activo sin límite');
ok($porId[(string) $eSusp]['bloqueado'] && $porId[(string) $eSusp]['estado'] === 'suspendido', 'suspendida bloqueada');
igual(array_slice(array_column($r[1]['clientes'], 'id'), 0, 2), [(string) $eDemoFin, (string) $eVencida], 'orden por vencimiento (lo más vencido primero)');
igual(end($r[1]['clientes'])['id'], (string) $eCortesia, 'sin fecha al final');
$c = $porId[(string) $eActiva];
ok($c['plan'] === ['codigo' => 'negocio', 'nombre' => 'Negocio'] && $c['mensual_estimado'] === 35.0 && $c['dueno']['whatsapp'] === '593991234567'
   && $c['dueno']['email'] === 'activa@cliente.com' && $c['ir'] === '/?r=cliente&id=' . $eActiva && $c['origen'] === 'super', 'cliente: plan, mensual, dueño, ir y origen');
igual($porId[(string) $eDemo]['mensual_estimado'], 0.0, 'una demo no suma al mes');
igual(count($c['uso']), 4, 'uso: 4 cifras');
$r = $pedir('clientes', ['estados' => ['prueba', 'por_vencer']]);
igual(array_column($r[1]['clientes'], 'id'), [(string) $eDemo, (string) $ePorVencer], 'filtro por estados');
igual($pedir('clientes', ['buscar' => 'ferret'])[1]['total'], 1, 'buscar por nombre (sin mayúsculas)');
igual($pedir('clientes', ['buscar' => 'ACTIVA@cliente'])[1]['total'], 1, 'buscar por el correo del dueño');
igual($pedir('clientes', ['buscar' => '099 123'])[1]['total'], 7, 'buscar por teléfono');
igual($pedir('clientes', ['ids' => [(string) $eSusp, (string) $eDemo]])[1]['total'], 2, 'filtro por ids');
$r = $pedir('clientes', ['por_pagina' => 3, 'pagina' => 3, 'orden' => 'nombre']);
ok($r[1]['paginas'] === 3 && count($r[1]['clientes']) === 1 && $r[1]['contadores']['total'] === 7, 'paginar (los contadores cuentan todos)');
$codigo($pedir('clientes', ['estados' => ['raro']]), 422, 'invalido', 'estado desconocido', 'estados');
$codigo($pedir('clientes', ['por_pagina' => 500]), 422, 'invalido', 'por_pagina de más', 'por_pagina');
$r = $pedir('cliente', ['id' => (string) $eDemo]);
$f = $r[1]['cliente'];
ok($r[0] === 200 && $f['id'] === (string) $eDemo && $f['estado'] === 'prueba' && is_array($f['registro']) && is_array($f['mensajes_sugeridos']), 'ficha del cliente');
$acc = array_column($f['acciones_posibles'], 'accion');
ok(in_array('extender_prueba', $acc, true) && in_array('suspender', $acc, true) && in_array('whatsapp', $acc, true) && in_array('nueva_clave_dueno', $acc, true)
   && in_array('abrir', $acc, true), 'acciones posibles de una demo');
ok(is_string($f['mensajes_sugeridos']['recordar_prueba']) && $f['mensajes_sugeridos']['vencido'] === null, 'mensaje sugerido de la demo');
ok(!in_array('extender_prueba', array_column($pedir('cliente', ['id' => (string) $eActiva])[1]['cliente']['acciones_posibles'], 'accion'), true), 'una cuenta de pago no ofrece alargar demo');
$codigo($pedir('cliente', ['id' => '99999']), 404, 'no_encontrado', 'cliente que no existe');
$codigo($pedir('cliente', ['id' => '1']), 404, 'no_encontrado', 'Tukán no es un cliente');
$codigo($pedir('cliente', []), 422, 'invalido', 'cliente sin id', 'id');

echo "== planes y módulos\n";
$r = $pedir('planes');
igual(array_column($r[1]['planes'], 'codigo'), array_keys(Modulos::PLANES), 'planes desde Modulos::PLANES');
igual($r[1]['planes'][1]['precios'][0]['precio'], 35.0, 'precio del plan Negocio');
igual($r[1]['ajustes']['dias_demo'], 7, 'días de demo por defecto');
$m = $pedir('modulos')[1];
igual(array_column($m['modulos'], 'id'), array_keys(Modulos::LISTA), 'catálogo de módulos');
ok($m['planes'][2]['todos'] === true && $m['planes'][0]['todos'] === false && $m['editable'] === false, 'planes con sus módulos (Pro = todos)');

echo "== crear_demo\n";
$op = bin2hex(random_bytes(16));
$datosDemo = ['nombre' => 'Clínica Sonrisa', 'contacto_nombre' => 'Juan Pérez', 'email' => 'Juan@Sonrisa.ec', 'telefono' => '0998887777',
              'plan' => null, 'dias' => 10, 'enviar_correo' => true, 'extra' => new stdClass()];
$antes = $cuenta('SELECT count(*) FROM empresas');
$r = $ejecutar('crear_demo', $datosDemo, null, $op);
$res = $r[1]['resultado'] ?? [];
ok($r[0] === 200 && $r[1]['ok'] && $r[1]['operacion'] === 'crear_demo' && $r[1]['repetida'] === false, 'crear_demo correcto');
$nueva = (int) ($res['cliente_id'] ?? 0);
$emp = Empresas::buscar($db, $nueva);
ok($emp && $emp['estado'] === 'demo' && $emp['vence'] === $d('+10 days') && $emp['origen'] === 'central' && $emp['plan'] === 'pro', 'empresa en demo, vence hoy + días, origen central');
igual($res['prueba_hasta'], $d('+10 days'), 'prueba_hasta');
igual(Modulos::deEmpresa($emp), Modulos::todos(), 'la demo tiene todos los módulos');
$u = $db->query("SELECT * FROM usuarios WHERE empresa_id = $nueva")->fetchAll();
ok(count($u) === 1 && $u[0]['rol'] === 'admin' && $u[0]['correo'] === 'juan@sonrisa.ec' && !$u[0]['super'], 'su dueño es administrador de esa empresa');
$clave = (string) ($res['acceso']['clave'] ?? '');
ok(strlen($clave) >= 12 && password_verify($clave, $u[0]['clave']), 'la clave temporal sirve para entrar');
ok($res['acceso']['usuario'] === 'juan@sonrisa.ec' && $res['acceso']['url_panel'] === 'https://whatsapp.ejemplo.com/?r=login', 'acceso: usuario y dirección');
ok(str_contains($res['mensaje_whatsapp'], $clave) && str_starts_with((string) $res['whatsapp_url'], 'https://wa.me/593998887777?text='), 'mensaje y enlace de WhatsApp');
igual($res['correo_enviado'], false, 'sin correo (el programa no envía correos)');
ok($r[1]['cliente']['estado'] === 'prueba' && $r[1]['cliente']['origen'] === 'central', 'cliente actualizado en la respuesta');
$g = $db->query("SELECT * FROM tukan_central_operaciones WHERE operacion_id = '$op'")->fetch();
ok($g['estado'] === 'ok' && (int) $g['http'] === 200 && !str_contains((string) $g['respuesta'], $clave), 'la operación queda guardada SIN la clave');
igual($cuenta("SELECT count(*) FROM tukan_central_registro WHERE empresa_id = $nueva AND quien LIKE 'Panel Tukán Central · Dimitry <dimitryfierro@gmail.com>' AND texto LIKE 'Demo creada:%(op " . substr($op, 0, 8) . ")'"), 1,
      'queda en el registro con el correo del Central');
$r2 = $ejecutar('crear_demo', $datosDemo, null, $op);
ok($r2[0] === 200 && $r2[1]['repetida'] === true && $r2[1]['resultado']['cliente_id'] === (string) $nueva, 'crear_demo repetido: repetida');
ok($r2[1]['resultado']['acceso']['clave'] === null && $r2[1]['resultado']['acceso']['clave_oculta'] === true && $r2[1]['resultado']['mensaje_whatsapp'] === null
   && $r2[1]['resultado']['whatsapp_url'] === null, 'crear_demo repetido: clave null y clave_oculta');
igual($cuenta('SELECT count(*) FROM empresas'), $antes + 1, 'crear_demo repetido no crea dos cuentas');
$codigo($ejecutar('crear_demo', ['nombre' => 'Otra'] + $datosDemo, null, $op), 409, 'conflicto', 'mismo operacion_id con otros datos');
$opMail = bin2hex(random_bytes(16));
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Repetido', 'email' => 'ACTIVA@cliente.com']), null, $opMail);
$codigo($r, 422, 'invalido', 'correo que ya existe', 'datos.email');
igual($cuenta('SELECT count(*) FROM empresas'), $antes + 1, 'con el correo repetido no se crea nada');
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Repetido', 'email' => 'ACTIVA@cliente.com']), null, $opMail);
ok($r[0] === 422 && ($r[1]['repetida'] ?? null) === true && $r[1]['campo'] === 'datos.email', 'el error queda guardado (el reintento recibe lo mismo)');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'telefono' => '12345'])), 422, 'invalido', 'teléfono corto', 'datos.telefono');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'dias' => 91])), 422, 'invalido', 'más de 90 días', 'datos.dias');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'plan' => 'oro'])), 422, 'invalido', 'plan que no existe', 'datos.plan');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'nombre' => 'ab'])), 422, 'invalido', 'nombre corto', 'datos.nombre');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'solicitud_id' => 'demo_pedida:3'])), 422, 'invalido', 'sin pedidos de demo', 'datos.solicitud_id');
$codigo($ejecutar('crear_demo', array_merge($datosDemo, ['email' => 'otro@x.ec', 'modulos' => ['ia', 'cohete']])), 422, 'invalido', 'módulo que no existe', 'datos.modulos');
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Demo Básica', 'email' => 'basica@x.ec', 'plan' => 'basico', 'dias' => null, 'modulos' => 'ninguno']));
$eb = Empresas::buscar($db, (int) $r[1]['resultado']['cliente_id']);
ok($r[0] === 200 && Modulos::deEmpresa($eb) === [] && $eb['vence'] === $d('+7 days') && $eb['plan'] === 'basico', 'demo con "ninguno" = solo lo básico, 7 días por defecto');
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Demo Elegida', 'email' => 'elegida@x.ec', 'modulos' => ['ia', 'embudo']]));
igual(Modulos::deEmpresa(Empresas::buscar($db, (int) $r[1]['resultado']['cliente_id'])), ['ia', 'embudo'], 'demo con los módulos elegidos');
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Demo Todo', 'email' => 'todo@x.ec', 'modulos' => []]));
igual(Modulos::deEmpresa(Empresas::buscar($db, (int) $r[1]['resultado']['cliente_id'])), Modulos::todos(), 'lista vacía = todos');

echo "== extender_prueba\n";
$r = $ejecutar('extender_prueba', ['dias' => 5], (string) $nueva);
ok($r[0] === 200 && $r[1]['resultado']['prueba_hasta'] === $d('+15 days') && Empresas::buscar($db, $nueva)['vence'] === $d('+15 days'), 'suma desde el fin de la demo');
$r = $ejecutar('extender_prueba', ['dias' => 4], (string) $eDemoFin);
ok($r[0] === 200 && Empresas::buscar($db, $eDemoFin)['vence'] === $d('+4 days') && $r[1]['cliente']['estado'] === 'prueba', 'demo terminada: suma desde hoy y vuelve a prueba');
$r = $ejecutar('extender_prueba', ['dias' => 7], (string) $eActiva);
$codigo($r, 403, 'no_permitido', 'alargar la demo de un cliente que paga');
igual($r[1]['detalle']['motivo'] ?? null, 'ya_paga', 'motivo ya_paga');
$codigo($ejecutar('extender_prueba', ['dias' => 0], (string) $nueva), 422, 'invalido', 'cero días', 'datos.dias');
$codigo($ejecutar('extender_prueba', ['dias' => 3], '99999'), 404, 'no_encontrado', 'cliente que no existe');
$codigo($ejecutar('extender_prueba', ['dias' => 3], '1'), 404, 'no_encontrado', 'no se toca la cuenta de Tukán');
$codigo($ejecutar('extender_prueba', ['dias' => 3]), 422, 'invalido', 'sin cliente', 'cliente_id');
$r = $ejecutar('extender_prueba', ['dias' => 1, 'cliente_id' => (string) $nueva]);
igual($r[0], 200, 'cliente_id dentro de datos (datos fijos del botón)');

echo "== suspender y reactivar\n";
$r = $ejecutar('suspender', ['motivo' => 'Pidió pausar el servicio'], (string) $nueva);
$emp = Empresas::buscar($db, $nueva);
ok($r[0] === 200 && $emp['estado'] === 'suspendida' && $emp['motivo_suspension'] === 'Pidió pausar el servicio' && $r[1]['cliente']['estado'] === 'suspendido', 'suspender');
ok(!Empresas::alDia($emp), 'suspendido ya no puede usar el programa');
$r = $ejecutar('suspender', ['motivo' => 'Otra vez'], (string) $nueva);
ok($r[0] === 200 && $r[1]['sin_cambios'] === true, 'suspender a un suspendido: sin_cambios');
$codigo($ejecutar('suspender', ['motivo' => 'no'], (string) $eActiva), 422, 'invalido', 'motivo corto', 'datos.motivo');
$codigo($ejecutar('extender_prueba', ['dias' => 3], (string) $nueva), 403, 'no_permitido', 'no se alarga la demo de un suspendido');
$r = $ejecutar('reactivar', [], (string) $nueva);
$emp = Empresas::buscar($db, $nueva);
ok($r[0] === 200 && $emp['estado'] === 'demo' && $emp['motivo_suspension'] === '' && $r[1]['resultado']['estado'] === 'prueba', 'reactivar vuelve a la demo');
$r = $ejecutar('reactivar', [], (string) $nueva);
ok($r[0] === 200 && $r[1]['sin_cambios'] === true, 'reactivar a uno activo: sin_cambios');
$r = $ejecutar('reactivar', [], (string) $eSusp);
ok($r[0] === 200 && Empresas::buscar($db, $eSusp)['estado'] === 'activa' && $r[1]['resultado']['estado'] === 'activo', 'reactivar una cuenta suspendida de antes: activa');

echo "== nueva_clave_dueno\n";
$opC = bin2hex(random_bytes(16));
$r = $ejecutar('nueva_clave_dueno', [], (string) $nueva, $opC);
$hash = (string) $db->query("SELECT clave FROM usuarios WHERE empresa_id = $nueva")->fetchColumn();
ok($r[0] === 200 && $r[1]['resultado']['usuario'] === 'juan@sonrisa.ec' && password_verify($r[1]['resultado']['clave'], $hash), 'clave nueva del dueño sirve');
ok(!password_verify($clave, $hash), 'la clave anterior ya no sirve');
ok(str_contains($r[1]['resultado']['mensaje_whatsapp'], $r[1]['resultado']['clave']), 'mensaje de WhatsApp con la clave');
$r2 = $ejecutar('nueva_clave_dueno', [], (string) $nueva, $opC);
ok($r2[1]['repetida'] === true && $r2[1]['resultado']['clave'] === null && $r2[1]['resultado']['clave_oculta'] === true
   && password_verify($r[1]['resultado']['clave'], (string) $db->query("SELECT clave FROM usuarios WHERE empresa_id = $nueva")->fetchColumn()), 'repetida: no cambia otra vez y no muestra la clave');
$sinDueno = $crear('Sin Dueño', 'activa', $d('+30 days'), 'sindueno@cliente.com');
$db->exec("UPDATE usuarios SET activo = false WHERE empresa_id = $sinDueno");
$codigo($ejecutar('nueva_clave_dueno', [], (string) $sinDueno), 403, 'no_permitido', 'sin administrador activo');

echo "== módulos, plan, nota e IA\n";
$r = $ejecutar('cambiar_modulos', ['apagar' => 'ia,integraciones', 'encender' => []], (string) $nueva);
$mods = $r[1]['resultado']['modulos'] ?? [];
ok($r[0] === 200 && !in_array('ia', Modulos::deEmpresa(Empresas::buscar($db, $nueva)), true) && $mods['quitados'] === ['ia', 'integraciones'], 'apagar módulos');
igual($mods['mensual'], 55.0 - Modulos::PRECIOS['ia'] - Modulos::PRECIOS['integraciones'], 'el precio baja con lo apagado');
igual($r[1]['resultado']['apagados'], ['ia', 'integraciones'], 'dice qué se apagó');
$r = $ejecutar('cambiar_modulos', ['apagar' => ['ia']], (string) $nueva);
ok($r[0] === 200 && $r[1]['sin_cambios'] === true, 'apagar lo ya apagado: sin_cambios');
$codigo($ejecutar('cambiar_modulos', ['encender' => 'cohete'], (string) $nueva), 422, 'invalido', 'módulo desconocido', 'datos.encender');
$codigo($ejecutar('cambiar_modulos', ['precio_acordado' => 20.0], (string) $nueva), 422, 'invalido', 'sin precio acordado', 'datos.precio_acordado');
$r = $ejecutar('cambiar_modulos', ['encender' => ['ia']], (string) $eActiva);
ok($r[0] === 200 && $r[1]['resultado']['modulos']['extra'] === ['ia'] && $r[1]['resultado']['modulos']['mensual'] === 35.0 + Modulos::PRECIOS['ia'], 'encender un módulo fuera del plan se cobra aparte');
igual($pedir('cliente', ['id' => (string) $eActiva])[1]['cliente']['mensual_estimado'], 35.0 + Modulos::PRECIOS['ia'], 'mensual_estimado con el extra');
$r = $ejecutar('cambiar_plan', ['plan' => 'pro'], (string) $eActiva);
$ea = Empresas::buscar($db, $eActiva);
$activosPro = Modulos::deEmpresa($ea);
sort($activosPro);
$todosOrden = Modulos::todos();
sort($todosOrden);
ok($r[0] === 200 && $ea['plan'] === 'pro' && $activosPro === $todosOrden && (int) $ea['max_numeros'] === 2, 'cambiar plan (como el botón de la ficha)');
ok($ejecutar('cambiar_plan', ['plan' => 'pro'], (string) $eActiva)[1]['sin_cambios'] === true, 'mismo plan: sin_cambios');
$codigo($ejecutar('cambiar_plan', ['plan' => 'oro'], (string) $eActiva), 422, 'invalido', 'plan que no existe', 'datos.plan');
$r = $ejecutar('nota_interna', ['texto' => 'Pidió factura con RUC'], (string) $eActiva);
ok($r[0] === 200 && Empresas::buscar($db, $eActiva)['notas'] === 'Pidió factura con RUC'
   && $pedir('cliente', ['id' => (string) $eActiva])[1]['cliente']['notas_admin'] === 'Pidió factura con RUC', 'nota interna');
$r = $ejecutar('ia_tope', ['tope_usd' => 7.5], (string) $eActiva);
ok($r[0] === 200 && (float) Empresas::buscar($db, $eActiva)['ia_tope'] === 7.5, 'tope de IA');
$codigo($ejecutar('ia_tope', ['tope_usd' => -1], (string) $eActiva), 422, 'invalido', 'tope negativo', 'datos.tope_usd');
$db->exec("INSERT INTO tukan_ia_gasto (negocio_id, mes, costo_usd, llamadas) VALUES ('$eActiva', '" . $hoy->format('Y-m') . "', 0.25, 9)");
$r = $pedir('ia_uso', ['mes' => $hoy->format('Y-m')]);
ok($r[0] === 200 && $r[1]['mes'] === $hoy->format('Y-m') && $r[1]['negocios'][0]['cliente_id'] === (string) $eActiva
   && $r[1]['negocios'][0]['nombre'] === 'Ferretería Al Día' && $r[1]['negocios'][0]['tope'] === 7.5 && $r[1]['negocios'][0]['modulo'] === true
   && $r[1]['totales']['llamadas'] === 9 && $r[1]['configurada'] === false, 'ia_uso (Anexo C)');
$codigo($pedir('ia_uso', ['mes' => '2026-13']), 422, 'invalido', 'ia_uso con mes malo', 'mes');
$codigo($pedir('ia_uso', ['otro' => 1]), 422, 'invalido', 'ia_uso con un campo desconocido', 'otro');
$reg = $pedir('cliente', ['id' => (string) $eActiva])[1]['cliente']['registro'];
ok(count($reg) >= 4 && str_starts_with($reg[0]['quien'], 'Panel Tukán Central · Dimitry'), 'la ficha muestra lo que hizo el panel');

echo "== Error interno\n";
$log = ini_set('error_log', '/dev/null');   // el error interno va al registro de PHP, no a la pantalla de las pruebas
$db->exec('ALTER TABLE tukan_central_registro RENAME TO tukan_central_registro_x');
$opI = bin2hex(random_bytes(16));
$antesE = $cuenta('SELECT count(*) FROM empresas');
$r = $ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Falla Interna', 'email' => 'interna@x.ec']), null, $opI);
$codigo($r, 500, 'interno', 'excepción interna');
ok(!str_contains($r[1]['error'], 'registro') && !str_contains($r[1]['error'], 'SQL'), 'interno no muestra detalles técnicos');
ok($cuenta('SELECT count(*) FROM empresas') === $antesE && $cuenta("SELECT count(*) FROM tukan_central_operaciones WHERE operacion_id = '$opI'") === 0, 'se deshace todo y se puede reintentar');
$db->exec('ALTER TABLE tukan_central_registro_x RENAME TO tukan_central_registro');
ini_set('error_log', (string) $log);
igual($ejecutar('crear_demo', array_merge($datosDemo, ['nombre' => 'Falla Interna', 'email' => 'interna@x.ec']), null, $opI)[0], 200, 'el reintento sí funciona');

echo "== Límite por IP\n";
$okLim = true;
for ($i = 0; $i < 120; $i++) {
    $okLim = $okLim && (new TukanCentral($db))->dentroDelLimite('9.8.7.6');
}
ok($okLim, '120 pedidos en un minuto sí');
$r = $pedir('capacidades', [], [], ['ip' => '9.8.7.6']);
$codigo($r, 429, 'limite', 'el 121 → límite');
igual($r[2]['Retry-After'] ?? null, '60', 'Retry-After');
for ($i = 0; $i < 30; $i++) {
    $pedir('capacidades', [], ['inst' => 'otro'], ['ip' => '9.8.7.5']);
}
$codigo($pedir('capacidades', [], [], ['ip' => '9.8.7.5']), 429, 'limite', '30 rechazados en un minuto → límite');

echo "== v1 sigue igual (errores con codigo)\n";
$tc = new TukanCentral($db);
try {
    $tc->validar('basura', 'estado');
    ok(false, 'v1 firma mala');
} catch (TukanCentralError $e) {
    ok($e->codigo === 'firma' && $e->http === 401 && $e->getCode() === 401, 'v1: firma mala con codigo');
}
$t1 = TukanCentral::firmar(['v' => 1, 'tipo' => 'estado', 'inst' => 'tuwhatsapp', 'email' => 'a@b.com', 'iat' => time(), 'exp' => time() + 60, 'nonce' => bin2hex(random_bytes(16))], $SEC);
igual($tc->validar($t1, 'estado')['email'], 'a@b.com', 'v1: token bueno');
try {
    $tc->validar($t1, 'estado');
    ok(false, 'v1 nonce');
} catch (TukanCentralError $e) {
    ok($e->codigo === 'nonce' && str_contains($e->getMessage(), 'ya se usó'), 'v1: repetido con codigo nonce');
}
ok(TukanCentral::irValido('/?r=cliente&id=3') && !TukanCentral::irValido('//malo.com') && !TukanCentral::irValido('https://malo.com/?r=cliente')
   && !TukanCentral::irValido('/?r=bandeja') && !TukanCentral::irValido("/?r=cliente\n"), 'ir del SSO: solo rutas seguras');

putenv('TUKAN_CENTRAL_ID=');
putenv('TUKAN_CENTRAL_SECRET=');
echo "\nResultado: $bien bien, $mal mal\n";
echo $mal === 0 ? "TODO BIEN\n" : "HAY FALLAS\n";
exit($mal === 0 ? 0 : 1);
