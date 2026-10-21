<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Todo lo que sale por la API (pagado) pasa por aquí, para respetar el tope del mes:
 *  1. Se calcula cuánto costará (Gasto::estimar).
 *  2. Con el número bloqueado para que dos envíos a la vez no se pasen del tope, se suma lo gastado en el mes.
 *     Si se pasa del tope, no se envía (queda "bloqueado") y se ofrece mandarlo gratis desde el celular.
 *  3. Se envía a Meta. Si falla, no cuenta como gasto.
 */
final class Mensajeria
{
    public function __construct(private PDO $db, private ?DateTimeImmutable $ahora = null) {}

    private function ahora(): DateTimeImmutable
    {
        return $this->ahora ?? new DateTimeImmutable();
    }

    /** @return array{ok:bool, error?:string, bloqueado?:bool, ventana?:bool, costo?:float, id?:int} */
    public function texto(array $linea, array $contacto, string $texto, string $origen, ?int $usuarioId = null): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['ok' => false, 'error' => 'Escribe el mensaje.'];
        }
        if (mb_strlen($texto) > 4096) {
            return ['ok' => false, 'error' => 'El mensaje es muy largo (máximo 4096 letras).'];
        }
        if (!Gasto::ventanaAbierta($contacto, $this->ahora())) {
            return ['ok' => false, 'ventana' => true,
                    'error' => 'Pasaron más de 24 horas desde el último mensaje de esta persona: Meta solo deja enviar una plantilla aprobada. Usa "Enviar plantilla" o mándalo gratis desde el celular.'];
        }
        return $this->enviar($linea, $contacto, 'servicio', ['tipo' => 'texto', 'texto' => $texto, 'plantilla' => null], $origen, $usuarioId,
            fn (Meta $m) => $m->enviarTexto((string) $contacto['telefono'], $texto));
    }

    /** @param array{nombre:string,idioma:string,categoria:string,cuerpo:string} $p */
    public function plantilla(array $linea, array $contacto, array $p, array $datos, string $origen, ?int $usuarioId = null): array
    {
        $cuerpo = (string) $p['cuerpo'];
        foreach (array_values($datos) as $i => $v) {
            if (trim((string) $v) === '') {
                return ['ok' => false, 'error' => 'Completa el dato {{' . ($i + 1) . '}} de la plantilla.'];
            }
            $cuerpo = str_replace('{{' . ($i + 1) . '}}', (string) $v, $cuerpo);
        }
        $categoria = isset(Gasto::CATEGORIAS[$p['categoria']]) ? $p['categoria'] : 'marketing';
        return $this->enviar($linea, $contacto, $categoria, ['tipo' => 'plantilla', 'texto' => $cuerpo, 'plantilla' => $p['nombre'] . ' (' . $p['idioma'] . ')'],
            $origen, $usuarioId, fn (Meta $m) => $m->enviarPlantilla((string) $contacto['telefono'], (string) $p['nombre'], (string) $p['idioma'], $datos));
    }

    private function enviar(array $linea, array $contacto, string $categoria, array $msg, string $origen, ?int $usuarioId, callable $llamar): array
    {
        $ahora = $this->ahora();
        $costo = Gasto::estimar($this->db, $categoria, $contacto, $ahora);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT pg_advisory_xact_lock(?)')->execute([(int) $linea['id']]);
            $gastado = Gasto::delMes($this->db, (int) $linea['id'], $ahora);
            $tope = (float) $linea['tope_mensual'];
            $bloqueado = $costo > 0 && ($gastado + $costo) > $tope + 1e-9;
            $st = $this->db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, tipo, texto, plantilla, estado, error, categoria, costo, usuario_id, creado)
                                      VALUES (?, ?, 'sale', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
            $st->execute([
                $linea['id'], $contacto['id'], $origen, $msg['tipo'], $msg['texto'], $msg['plantilla'],
                $bloqueado ? 'bloqueado' : 'enviando',
                $bloqueado ? 'Tope del mes alcanzado (' . self::d($gastado) . ' de ' . self::d($tope) . ')' : null,
                $categoria, $bloqueado ? 0 : $costo, $usuarioId, $ahora->format('c'),
            ]);
            $id = (int) $st->fetchColumn();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($bloqueado) {
            return ['ok' => false, 'bloqueado' => true, 'id' => $id,
                    'error' => 'No se envió: llegaste al tope del mes (' . self::d($gastado) . ' de ' . self::d($tope) . '). Mándalo gratis desde el celular o sube el tope en Gasto.'];
        }
        $r = $llamar(Meta::deLinea($linea));
        if (!$r['ok']) {
            $this->db->prepare("UPDATE mensajes SET estado = 'fallido', error = ?, costo = 0 WHERE id = ?")->execute([$r['error'], $id]);
            return ['ok' => false, 'error' => $r['error'], 'id' => $id, 'ventana' => ($r['codigo'] ?? 0) === 131047];
        }
        $this->db->prepare("UPDATE mensajes SET estado = 'enviado', wamid = NULLIF(?, '') WHERE id = ? AND estado = 'enviando'")->execute([$r['wamid'], $id]);
        if ($costo > 0) {
            Avisos::revisar($this->db, $linea, $ahora);
        }
        return ['ok' => true, 'costo' => $costo, 'id' => $id];
    }

    private static function d(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }
}
