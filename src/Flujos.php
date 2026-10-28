<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Chatbots por pasos, sin programar. Un chatbot (flujo) es una lista de pasos:
 *   mensaje  -> manda un texto y sigue con el siguiente paso
 *   botones  -> manda una pregunta con hasta 10 opciones (botones o lista); cada opción lleva a un paso
 *   pregunta -> pregunta algo y guarda la respuesta (nombre, nota o un campo personalizado) y sigue
 *   persona  -> manda un texto y pasa la conversación a una persona del equipo (reparto automático)
 *   fin      -> manda un texto y termina
 * Cada paso puede además poner una etiqueta y mover la conversación a una etapa del embudo.
 * "siguiente" vacío = el paso que sigue en la lista; "fin" = terminar.
 *
 * Arranca cuando el cliente escribe una palabra clave (disparador "palabras") o en su primer mensaje (disparador "nuevo").
 * Mientras espera una respuesta, el estado queda en el contacto (flujo_id, flujo_paso) por 24 horas.
 * Tiene prioridad sobre el agente con IA y sobre el menú de respuestas automáticas. Nunca contesta a contactos personales
 * ni a quien está siendo atendido por una persona.
 */
final class Flujos
{
    public const TIPOS = ['mensaje' => 'Mensaje', 'botones' => 'Pregunta con botones', 'pregunta' => 'Pregunta abierta (guarda la respuesta)',
                          'persona' => 'Pasar a una persona', 'fin' => 'Terminar'];
    public const MAX_PASOS = 40;
    private const MAX_SEGUIDOS = 8;

    // ------------------------------------------------------------------ guardar y leer

    /** @return list<array> */
    public static function lista(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT * FROM flujos WHERE linea_id = ? ORDER BY activo DESC, id');
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT * FROM flujos WHERE id = ? AND linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> pasos guardados */
    public static function pasos(array $flujo): array
    {
        $p = json_decode((string) $flujo['pasos'], true);
        return is_array($p) ? array_values(array_filter($p, 'is_array')) : [];
    }

    /** Chatbot nuevo con un ejemplo de 3 pasos para que se entienda cómo funciona. */
    public static function crear(PDO $db, int $lineaId, string $nombre, string $disparador = 'palabras', string $palabras = ''): int
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new RuntimeException('Escribe un nombre para el chatbot (ejemplo: Bienvenida).');
        }
        $ejemplo = [
            ['id' => 'p1', 'tipo' => 'botones', 'texto' => '¡Hola! 👋 Soy el asistente de ' . '{negocio}' . '. ¿En qué te ayudo?',
             'opciones' => [['titulo' => 'Ver productos', 'ir_a' => 'p2'], ['titulo' => 'Hablar con alguien', 'ir_a' => 'p3']]],
            ['id' => 'p2', 'tipo' => 'fin', 'texto' => 'Escribe "catálogo" o dime qué buscas y te ayudo.'],
            ['id' => 'p3', 'tipo' => 'persona', 'texto' => 'Perfecto, en un momento te atiende una persona del equipo.'],
        ];
        $st = $db->prepare('INSERT INTO flujos (linea_id, nombre, disparador, palabras, pasos) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $st->execute([$lineaId, mb_substr($nombre, 0, 80), in_array($disparador, ['palabras', 'nuevo'], true) ? $disparador : 'palabras',
                      implode(', ', Reglas::listaPalabras($palabras)), json_encode($ejemplo, JSON_UNESCAPED_UNICODE)]);
        return (int) $st->fetchColumn();
    }

    /**
     * Guarda el chatbot con lo que viene del editor. $d['paso'][i] = [id, tipo, texto, siguiente, guardar, etiqueta, etapa, op_titulo[], op_ir[]].
     */
    public static function guardar(PDO $db, int $lineaId, int $id, array $d, array $camposValidos = []): void
    {
        $f = self::buscar($db, $lineaId, $id);
        if (!$f) {
            throw new RuntimeException('Ese chatbot no existe.');
        }
        $nombre = trim((string) ($d['nombre'] ?? $f['nombre']));
        $disparador = in_array($d['disparador'] ?? '', ['palabras', 'nuevo'], true) ? (string) $d['disparador'] : 'palabras';
        $palabras = implode(', ', Reglas::listaPalabras((string) ($d['palabras'] ?? '')));
        $activo = !empty($d['activo']);
        $pasos = self::limpiarPasos((array) ($d['paso'] ?? []), $camposValidos, $activo);
        if ($nombre === '') {
            throw new RuntimeException('Escribe un nombre para el chatbot.');
        }
        if ($activo && $disparador === 'palabras' && $palabras === '') {
            throw new RuntimeException('Escribe al menos una palabra que lo active (ejemplo: hola, info, precios) o elige "Primer mensaje".');
        }
        if ($activo && !$pasos) {
            throw new RuntimeException('El chatbot no tiene pasos.');
        }
        $db->prepare('UPDATE flujos SET nombre = ?, disparador = ?, palabras = ?, pasos = ?, activo = ? WHERE id = ? AND linea_id = ?')
           ->execute([mb_substr($nombre, 0, 80), $disparador, $palabras, json_encode($pasos, JSON_UNESCAPED_UNICODE), $activo ? 't' : 'f', $id, $lineaId]);
    }

    /**
     * Valida los pasos del editor y los deja limpios. Lanza errores claros (en qué paso está el problema).
     * $estricto (chatbot encendido): cada paso necesita su mensaje y cada pregunta con botones, al menos una opción.
     */
    public static function limpiarPasos(array $crudos, array $camposValidos = [], bool $estricto = true): array
    {
        $pasos = [];
        $ids = [];
        foreach (array_values($crudos) as $p) {
            if (!is_array($p) || !empty($p['borrar'])) {
                continue;
            }
            $id = preg_match('/^p\d{1,4}$/', (string) ($p['id'] ?? '')) ? (string) $p['id'] : '';
            $tipo = (string) ($p['tipo'] ?? 'mensaje');
            $texto = trim((string) ($p['texto'] ?? ''));
            if ($id === '' || !isset(self::TIPOS[$tipo])) {
                continue;
            }
            $ids[$id] = true;
            $paso = ['id' => $id, 'tipo' => $tipo, 'texto' => mb_substr($texto, 0, 1000)];
            if (in_array($tipo, ['mensaje', 'pregunta'], true)) {
                $sig = (string) ($p['siguiente'] ?? '');
                $paso['siguiente'] = $sig === 'fin' || preg_match('/^p\d{1,4}$/', $sig) ? $sig : '';
            }
            if ($tipo === 'pregunta') {
                $g = (string) ($p['guardar'] ?? 'notas');
                $paso['guardar'] = in_array($g, ['nombre', 'notas', 'correo'], true) || (str_starts_with($g, 'campo:') && in_array(substr($g, 6), $camposValidos, true)) ? $g : 'notas';
            }
            if ($tipo === 'botones') {
                $ops = [];
                foreach ((array) ($p['op_titulo'] ?? []) as $k => $t) {
                    $t = trim((string) $t);
                    if ($t === '') {
                        continue;
                    }
                    $ir = (string) ($p['op_ir'][$k] ?? '');
                    $ops[] = ['titulo' => mb_substr($t, 0, 24), 'ir_a' => $ir === 'fin' || preg_match('/^p\d{1,4}$/', $ir) ? $ir : ''];
                }
                $paso['opciones'] = array_slice($ops, 0, 10);
            }
            $etq = Contactos::limpiarEtiquetas((string) ($p['etiqueta'] ?? ''));
            if ($etq !== '') {
                $paso['etiqueta'] = $etq;
            }
            $etapa = (string) ($p['etapa'] ?? '');
            if (isset(Embudo::ETAPAS[$etapa])) {
                $paso['etapa'] = $etapa;
            }
            $pasos[] = $paso;
        }
        if (count($pasos) > self::MAX_PASOS) {
            throw new RuntimeException('Máximo ' . self::MAX_PASOS . ' pasos por chatbot.');
        }
        foreach ($pasos as $n => $p) {
            $donde = 'Paso ' . ($n + 1);
            if ($estricto && $p['texto'] === '' && $p['tipo'] !== 'persona') {
                throw new RuntimeException($donde . ': escribe el mensaje (o apaga el chatbot mientras lo armas).');
            }
            if ($estricto && $p['tipo'] === 'botones' && count($p['opciones']) < 1) {
                throw new RuntimeException($donde . ': pon al menos una opción (o apaga el chatbot mientras lo armas).');
            }
            foreach (array_merge([$p['siguiente'] ?? ''], array_column($p['opciones'] ?? [], 'ir_a')) as $destino) {
                if ($destino !== '' && $destino !== 'fin' && !isset($ids[$destino])) {
                    throw new RuntimeException($donde . ': lleva a un paso que ya no existe. Elige otro.');
                }
            }
        }
        return $pasos;
    }

    /** Id para un paso nuevo (p1, p2…, el siguiente libre). */
    public static function idNuevo(array $pasos): string
    {
        $max = 0;
        foreach ($pasos as $p) {
            $max = max($max, (int) substr((string) ($p['id'] ?? 'p0'), 1));
        }
        return 'p' . ($max + 1);
    }

    public static function borrar(PDO $db, int $lineaId, int $id): void
    {
        $db->prepare('DELETE FROM flujos WHERE id = ? AND linea_id = ?')->execute([$id, $lineaId]);
    }

    // ------------------------------------------------------------------ el motor (sin base ni Meta: fácil de probar)

    /**
     * Avanza el chatbot.
     * @param ?string $esperando paso que esperaba respuesta (null = empezar)
     * @return array{salidas:list<array>, esperando:?string, guardar:list<array{0:string,1:string}>, etiquetas:list<string>, etapa:?string, persona:bool, fin:bool}
     */
    public static function avanzar(int $flujoId, array $pasos, ?string $esperando, string $texto, string $idBoton = '', ?callable $validar = null, string $negocio = ''): array
    {
        $r = ['salidas' => [], 'esperando' => null, 'guardar' => [], 'etiquetas' => [], 'etapa' => null, 'persona' => false, 'fin' => false];
        $porId = [];
        foreach ($pasos as $i => $p) {
            $porId[$p['id']] = $i;
        }
        if (!$pasos) {
            $r['fin'] = true;
            return $r;
        }
        $ir = null;
        if ($esperando === null || !isset($porId[$esperando])) {
            $ir = $pasos[0]['id'];
        } else {
            $p = $pasos[$porId[$esperando]];
            if ($p['tipo'] === 'botones') {
                $k = self::opcionTocada($flujoId, $p, $texto, $idBoton);
                if ($k === null) {   // no eligió una opción: se repite la pregunta
                    $r['salidas'][] = self::salidaBotones($flujoId, $p, 'Elige una de las opciones 👇', $negocio);
                    $r['esperando'] = $p['id'];
                    return $r;
                }
                $ir = self::destino($pasos, $porId, $p['id'], (string) ($p['opciones'][$k]['ir_a'] ?? ''));
            } elseif ($p['tipo'] === 'pregunta') {
                $valor = trim($texto);
                $error = $validar ? $validar((string) $p['guardar'], $valor) : null;
                if ($valor === '' || $error !== null) {
                    $r['salidas'][] = ['tipo' => 'texto', 'texto' => ($error ?? 'No te entendí.') . ' ' . self::rellenar((string) $p['texto'], $negocio)];
                    $r['esperando'] = $p['id'];
                    return $r;
                }
                $r['guardar'][] = [(string) $p['guardar'], mb_substr($valor, 0, 300)];
                $ir = self::destino($pasos, $porId, $p['id'], (string) ($p['siguiente'] ?? ''));
            } else {
                $ir = self::destino($pasos, $porId, $p['id'], (string) ($p['siguiente'] ?? ''));
            }
        }
        for ($n = 0; $n < self::MAX_SEGUIDOS && $ir !== null; $n++) {
            $p = $pasos[$porId[$ir]];
            if (!empty($p['etiqueta'])) {
                $r['etiquetas'][] = (string) $p['etiqueta'];
            }
            if (!empty($p['etapa'])) {
                $r['etapa'] = (string) $p['etapa'];
            }
            $t = self::rellenar((string) $p['texto'], $negocio);
            $tipo = $p['tipo'] === 'botones' && empty($p['opciones']) ? 'mensaje' : $p['tipo'];
            switch ($tipo) {
                case 'botones':
                    $r['salidas'][] = self::salidaBotones($flujoId, $p, $t, $negocio);
                    $r['esperando'] = $p['id'];
                    return $r;
                case 'pregunta':
                    $r['salidas'][] = ['tipo' => 'texto', 'texto' => $t];
                    $r['esperando'] = $p['id'];
                    return $r;
                case 'persona':
                    if ($t !== '') {
                        $r['salidas'][] = ['tipo' => 'texto', 'texto' => $t];
                    }
                    $r['persona'] = true;
                    $r['fin'] = true;
                    return $r;
                case 'fin':
                    $r['salidas'][] = ['tipo' => 'texto', 'texto' => $t];
                    $r['fin'] = true;
                    return $r;
                default:   // mensaje (si está vacío, se salta)
                    if (trim($t) !== '') {
                        $r['salidas'][] = ['tipo' => 'texto', 'texto' => $t];
                    }
                    $ir = self::destino($pasos, $porId, $p['id'], (string) ($p['siguiente'] ?? ''));
            }
        }
        $r['fin'] = true;
        return $r;
    }

    /** A dónde va después de un paso: el elegido, el siguiente de la lista, o null (fin). */
    private static function destino(array $pasos, array $porId, string $desde, string $elegido): ?string
    {
        if ($elegido === 'fin') {
            return null;
        }
        if ($elegido !== '' && isset($porId[$elegido])) {
            return $elegido;
        }
        $i = $porId[$desde] + 1;
        return isset($pasos[$i]) ? (string) $pasos[$i]['id'] : null;
    }

    private static function rellenar(string $t, string $negocio): string
    {
        return str_replace('{negocio}', $negocio, $t);
    }

    private static function salidaBotones(int $flujoId, array $p, string $texto, string $negocio): array
    {
        $ops = [];
        foreach ($p['opciones'] as $k => $o) {
            $ops[] = ['id' => 'f' . $flujoId . ':' . $p['id'] . ':' . $k, 'titulo' => (string) $o['titulo']];
        }
        return ['tipo' => 'botones', 'texto' => self::rellenar($texto, $negocio), 'opciones' => $ops];
    }

    /** Qué opción eligió: por el botón tocado, por el número o por el nombre escrito. */
    public static function opcionTocada(int $flujoId, array $paso, string $texto, string $idBoton): ?int
    {
        if (preg_match('/^f(\d+):(p\d+):(\d+)$/', $idBoton, $m) && (int) $m[1] === $flujoId && $m[2] === $paso['id'] && isset($paso['opciones'][(int) $m[3]])) {
            return (int) $m[3];
        }
        $n = Reglas::opcionElegida($texto, count($paso['opciones']), $paso['opciones']);
        return $n === null ? null : $n - 1;
    }

    // ------------------------------------------------------------------ conectado con WhatsApp

    /**
     * Si un chatbot debe contestar este mensaje, lo hace (manda los mensajes, guarda los datos y el estado).
     * @return ?array{enviados:int, persona:bool, motivo:string} null = ningún chatbot se encarga
     */
    public static function responder(PDO $db, array $linea, array $contacto, string $texto, string $idBoton, DateTimeImmutable $ahora, bool $primerMensaje): ?array
    {
        $lid = (int) $linea['id'];
        $flujo = null;
        $esperando = null;
        if (!empty($contacto['flujo_id']) && !empty($contacto['flujo_hasta']) && new DateTimeImmutable($contacto['flujo_hasta']) > $ahora) {
            $flujo = self::buscar($db, $lid, (int) $contacto['flujo_id']);
            $esperando = $flujo && $flujo['activo'] ? (string) $contacto['flujo_paso'] : null;
            if (!$flujo || !$flujo['activo']) {
                $flujo = null;
            }
        }
        if ($flujo === null && preg_match('/^f(\d+):(p\d+):\d+$/', $idBoton, $m)) {   // tocó un botón de un chatbot viejo
            $f = self::buscar($db, $lid, (int) $m[1]);
            if ($f && $f['activo']) {
                $flujo = $f;
                $esperando = $m[2];
            }
        }
        if ($flujo === null) {
            foreach (self::lista($db, $lid) as $f) {
                if (!$f['activo']) {
                    continue;
                }
                if (($f['disparador'] === 'nuevo' && $primerMensaje)
                    || ($f['disparador'] === 'palabras' && Reglas::tienePalabra($texto, Reglas::listaPalabras((string) $f['palabras'])))) {
                    $flujo = $f;
                    $db->prepare('UPDATE flujos SET usos = usos + 1 WHERE id = ?')->execute([$f['id']]);
                    break;
                }
            }
        }
        if ($flujo === null) {
            return null;
        }
        $campos = [];
        foreach (Campos::lista($db, $lid) as $c) {
            $campos[$c['clave']] = $c;
        }
        $validar = function (string $guardar, string $valor) use ($campos): ?string {
            if ($guardar === 'correo' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                return 'Ese correo no parece correcto.';
            }
            if (str_starts_with($guardar, 'campo:') && isset($campos[substr($guardar, 6)])) {
                try {
                    Campos::valor($campos[substr($guardar, 6)], $valor);
                } catch (RuntimeException $e) {
                    return $e->getMessage();
                }
            }
            return null;
        };
        $r = self::avanzar((int) $flujo['id'], self::pasos($flujo), $esperando, $texto, $idBoton, $validar, (string) $linea['nombre']);
        $cid = (int) $contacto['id'];

        foreach ($r['guardar'] as [$donde, $valor]) {
            if ($donde === 'nombre') {
                $db->prepare('UPDATE contactos SET nombre = ? WHERE id = ?')->execute([mb_substr($valor, 0, 100), $cid]);
            } elseif ($donde === 'correo' || $donde === 'notas') {
                $db->prepare("UPDATE contactos SET notas = left(trim(notas || CASE WHEN notas = '' THEN '' ELSE E'\\n' END || ?), 2000) WHERE id = ?")
                   ->execute([($donde === 'correo' ? 'Correo: ' : '') . $valor, $cid]);
            } elseif (str_starts_with($donde, 'campo:')) {
                try {
                    Campos::guardarUno($db, $lid, $cid, substr($donde, 6), $valor);
                } catch (RuntimeException) {
                    // ya se validó; si el campo se borró en medio, no pasa nada
                }
            }
        }
        if ($r['etiquetas']) {
            $c = Contactos::buscar($db, $lid, $cid);
            Contactos::guardarEtiquetas($db, $lid, $cid, trim((string) $c['etiquetas'] . ', ' . implode(', ', $r['etiquetas']), ', '));
        }
        if ($r['etapa'] !== null && ($contacto['tipo'] ?? '') !== 'personal') {
            Embudo::mover($db, $lid, $cid, $r['etapa']);
        }
        $db->prepare('UPDATE contactos SET flujo_id = ?, flujo_paso = ?, flujo_hasta = ? WHERE id = ?')
           ->execute([$r['esperando'] !== null ? $flujo['id'] : null, $r['esperando'], $r['esperando'] !== null ? $ahora->modify('+24 hours')->format('c') : null, $cid]);

        $contacto = Contactos::buscar($db, $lid, $cid);
        $mens = new Mensajeria($db, $ahora);
        $enviados = 0;
        foreach ($r['salidas'] as $s) {
            $res = $s['tipo'] === 'botones'
                ? $mens->botones($linea, $contacto, $s['texto'], $s['opciones'], 'flujo')
                : $mens->texto($linea, $contacto, $s['texto'], 'flujo');
            if (!$res['ok']) {
                return ['enviados' => $enviados, 'persona' => $r['persona'], 'motivo' => 'Chatbot "' . $flujo['nombre'] . '": ' . (!empty($res['bloqueado']) ? 'llegaste al tope del mes' : $res['error'])];
            }
            $enviados++;
        }
        if ($r['persona']) {
            $db->prepare('UPDATE contactos SET silencio_hasta = ? WHERE id = ?')->execute([$ahora->modify('+' . (int) $linea['horas_silencio'] . ' hours')->format('c'), $cid]);
            Reparto::alLlegar($db, $linea, Contactos::buscar($db, $lid, $cid), $texto, $ahora);
        }
        return ['enviados' => $enviados, 'persona' => $r['persona'],
                'motivo' => 'Chatbot "' . $flujo['nombre'] . '"' . ($r['persona'] ? ': pasó a una persona' : ($r['esperando'] !== null ? ': esperando respuesta' : ': terminó'))];
    }

    /**
     * Probador: simula una conversación sin mandar nada.
     * @param list<string> $entradas lo que escribiría el cliente (la primera arranca el chatbot)
     * @return list<array{de:string, texto:string}>
     */
    public static function simular(array $flujo, array $entradas, string $negocio = ''): array
    {
        $pasos = self::pasos($flujo);
        $esperando = null;
        $r = [];
        foreach (array_slice($entradas, 0, 15) as $i => $e) {
            $r[] = ['de' => 'cliente', 'texto' => $e];
            $x = self::avanzar((int) $flujo['id'], $pasos, $i === 0 ? null : $esperando, $e, '', null, $negocio);
            foreach ($x['salidas'] as $s) {
                $r[] = ['de' => 'bot', 'texto' => $s['texto'] . ($s['tipo'] === 'botones' ? "\n" . implode("\n", array_map(fn ($o, $k) => '[' . ($k + 1) . '] ' . $o['titulo'], $s['opciones'], array_keys($s['opciones']))) : '')];
            }
            if ($x['persona']) {
                $r[] = ['de' => 'sistema', 'texto' => 'Aquí pasa a una persona del equipo.'];
            }
            if ($x['fin'] && $x['esperando'] === null) {
                $r[] = ['de' => 'sistema', 'texto' => 'Fin del chatbot.'];
                break;
            }
            $esperando = $x['esperando'];
        }
        return $r;
    }
}
