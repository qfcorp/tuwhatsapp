<?php
declare(strict_types=1);

namespace TuWhatsApp;

use RuntimeException;

/**
 * Error de un pedido del Panel Tukán Central (PROTOCOLO_V2.md §3.2), también para sso y estado (v1 con "codigo").
 * Se convierte tal cual en {"ok":false,"protocolo":2,"codigo":…,"error":…,"campo":…,"detalle":{…}}.
 * getCode() es el HTTP (así las pruebas y el código viejo que miraban getCode() siguen igual).
 */
final class TukanCentralError extends RuntimeException
{
    public const CODIGOS = ['firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'sin_configurar', 'limite',
                            'no_encontrado', 'invalido', 'no_permitido', 'conflicto', 'interno'];

    public function __construct(public readonly string $codigo, string $mensaje, public readonly int $http = 400,
                                public readonly ?string $campo = null, public readonly array $detalle = [])
    {
        parent::__construct($mensaje, $http);
    }

    /** Dato mal en el cuerpo: 422 con la ruta del campo (datos.email, pagina…). */
    public static function campo(string $campo, string $mensaje, array $detalle = []): self
    {
        return new self('invalido', $mensaje, 422, $campo, $detalle);
    }

    public function json(): array
    {
        $j = ['ok' => false, 'protocolo' => 2, 'codigo' => $this->codigo, 'error' => $this->getMessage()];
        if ($this->campo !== null) {
            $j['campo'] = $this->campo;
        }
        if ($this->detalle) {
            $j['detalle'] = $this->detalle;
        }
        return $j;
    }
}
