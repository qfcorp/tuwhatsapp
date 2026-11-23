<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Cotización en PDF de un pedido o carrito: datos del negocio (nombre, RUC, dirección, teléfono), del cliente,
 * productos con cantidad y precio, subtotal, IVA y total, validez en días, formas de pago y una nota al pie.
 * Se manda por WhatsApp como documento (con la ventana de 24 horas abierta) o se descarga para mandarla a mano.
 */
final class Cotizacion
{
    /** (23 nov 2026) Textos del PDF en el idioma del cliente. */
    public const TEXTOS = [
        'es' => ['COTIZACIÓN', 'Fecha', 'Válida hasta', 'Para:', 'Cant.', 'Descripción', 'Precio', 'Total', 'Subtotal', 'IVA', 'TOTAL', 'Pago en línea', 'Transferencia', 'Notas', 'Condiciones', 'Cotización enviada por WhatsApp', 'Cotización', 'N.º'],
        'en' => ['QUOTE', 'Date', 'Valid until', 'To:', 'Qty', 'Description', 'Price', 'Total', 'Subtotal', 'VAT', 'TOTAL', 'Pay online', 'Bank transfer', 'Notes', 'Terms', 'Quote sent by WhatsApp', 'Quote', 'No.'],
        'pt' => ['ORÇAMENTO', 'Data', 'Válido até', 'Para:', 'Qtd.', 'Descrição', 'Preço', 'Total', 'Subtotal', 'IVA', 'TOTAL', 'Pagamento online', 'Transferência', 'Observações', 'Condições', 'Orçamento enviado por WhatsApp', 'Orçamento', 'N.º'],
        'fr' => ['DEVIS', 'Date', "Valable jusqu'au", 'Pour :', 'Qté', 'Description', 'Prix', 'Total', 'Sous-total', 'TVA', 'TOTAL', 'Paiement en ligne', 'Virement', 'Notes', 'Conditions', 'Devis envoyé par WhatsApp', 'Devis', 'N°'],
        'it' => ['PREVENTIVO', 'Data', 'Valido fino al', 'Per:', 'Qtà', 'Descrizione', 'Prezzo', 'Totale', 'Subtotale', 'IVA', 'TOTALE', 'Pagamento online', 'Bonifico', 'Note', 'Condizioni', 'Preventivo inviato via WhatsApp', 'Preventivo', 'N.'],
    ];

    /** Textos de la cotización en un idioma (para el PDF y el mensaje que lo acompaña). */
    public static function textos(string $idioma): array
    {
        $t = self::TEXTOS[$idioma] ?? self::TEXTOS['es'];
        return array_combine(['titulo', 'fecha', 'valida', 'para', 'cant', 'desc', 'precio', 'total', 'subtotal', 'iva', 'gran_total', 'pago', 'transferencia', 'notas', 'condiciones', 'pie', 'nombre', 'numero'], $t);
    }

    public static function pdf(array $linea, array $pedido, array $items, ?DateTimeImmutable $hoy = null, string $idioma = 'es'): string
    {
        $hoy ??= new DateTimeImmutable();
        $tx = self::textos($idioma);
        $azul = Pdf::hex('#14213D');
        $naranja = Pdf::hex('#FF8A1F');
        $gris = [0.42, 0.45, 0.5];
        $d = fn (float $v) => '$' . number_format($v, 2, ',', '.');
        $pdf = new Pdf();
        $x0 = 48.0;
        $x1 = Pdf::ANCHO - 48;

        // Cabecera
        $pdf->rect(0, 0, Pdf::ANCHO, 8, $naranja);
        $pdf->texto($x0, 62, (string) $linea['nombre'], 20, true, $azul);
        $y = 80;
        foreach (array_filter([
            trim((string) ($linea['negocio_ruc'] ?? '')) !== '' ? 'RUC ' . trim((string) $linea['negocio_ruc']) : '',
            trim((string) ($linea['negocio_direccion'] ?? '')),
            trim((string) ($linea['telefono'] ?? '')) !== '' ? 'WhatsApp ' . Telefono::bonito((string) $linea['telefono']) : '',
        ]) as $l) {
            $pdf->texto($x0, $y, $l, 9.5, false, $gris);
            $y += 13;
        }
        $pdf->texto($x1, 58, $tx['titulo'], 13, true, $naranja, 'der');
        $pdf->texto($x1, 76, $tx['numero'] . ' ' . str_pad((string) $pedido['numero'], 5, '0', STR_PAD_LEFT), 11, true, $azul, 'der');
        $pdf->texto($x1, 92, $tx['fecha'] . ': ' . $hoy->format('d/m/Y'), 9.5, false, $gris, 'der');
        $dias = max(1, (int) ($linea['cotizacion_dias'] ?? 15));
        $pdf->texto($x1, 105, $tx['valida'] . ': ' . $hoy->modify("+$dias days")->format('d/m/Y'), 9.5, false, $gris, 'der');

        // Cliente
        $y = max($y, 118) + 14;
        $pdf->rect($x0, $y, $x1 - $x0, 40, [0.968, 0.968, 0.957]);
        $pdf->texto($x0 + 12, $y + 16, $tx['para'], 9, true, $gris);
        $pdf->texto($x0 + 46, $y + 16, Pedidos::nombreCliente($pedido), 11, true, $azul);
        if (!empty($pedido['c_telefono'])) {
            $pdf->texto($x0 + 46, $y + 31, 'WhatsApp ' . Telefono::bonito((string) $pedido['c_telefono']), 9, false, $gris);
        }
        $y += 62;

        // Tabla
        $cCant = $x0 + 6;
        $cDesc = $x0 + 50;
        $cPrec = $x1 - 96;
        $cTot = $x1 - 6;
        $pdf->rect($x0, $y - 14, $x1 - $x0, 22, $azul);
        $blanco = [1, 1, 1];
        $pdf->texto($cCant, $y, $tx['cant'], 9, true, $blanco);
        $pdf->texto($cDesc, $y, $tx['desc'], 9, true, $blanco);
        $pdf->texto($cPrec, $y, $tx['precio'], 9, true, $blanco, 'der');
        $pdf->texto($cTot, $y, $tx['total'], 9, true, $blanco, 'der');
        $y += 24;
        foreach ($items as $i) {
            $lineas = Pdf::partir((string) $i['nombre'], 10, $cPrec - $cDesc - 70);
            $alto = 14 * count($lineas) + 6;
            if ($y + $alto > Pdf::ALTO - 170) {
                $pdf->paginaNueva();
                $y = 60;
            }
            $pdf->texto($cCant + 8, $y, (string) (int) $i['cantidad'], 10, false, [0, 0, 0], 'centro');
            foreach ($lineas as $k => $l) {
                $pdf->texto($cDesc, $y + 14 * $k, $l, 10);
            }
            $pdf->texto($cPrec, $y, $d((float) $i['precio']), 10, false, [0, 0, 0], 'der');
            $pdf->texto($cTot, $y, $d((float) $i['precio'] * (int) $i['cantidad']), 10, false, [0, 0, 0], 'der');
            $y += $alto;
            $pdf->linea($x0, $y - 10, $x1, $y - 10, [0.88, 0.88, 0.88]);
        }

        // Totales
        $y += 6;
        $iva = (float) ($linea['iva_pct'] ?? 0);
        if ((float) $pedido['iva'] > 0) {
            $pdf->texto($cPrec, $y, $tx['subtotal'], 10, false, $gris, 'der');
            $pdf->texto($cTot, $y, $d((float) $pedido['subtotal']), 10, false, [0, 0, 0], 'der');
            $y += 15;
            $pdf->texto($cPrec, $y, $tx['iva'] . ' ' . rtrim(rtrim(number_format($iva, 2, ',', ''), '0'), ',') . ' %', 10, false, $gris, 'der');
            $pdf->texto($cTot, $y, $d((float) $pedido['iva']), 10, false, [0, 0, 0], 'der');
            $y += 15;
        }
        $pdf->rect($cPrec - 70, $y - 13, $cTot - $cPrec + 76, 22, [1, 0.95, 0.88]);
        $pdf->texto($cPrec, $y + 2, $tx['gran_total'], 11, true, $azul, 'der');
        $pdf->texto($cTot, $y + 2, $d((float) $pedido['total']), 12, true, $azul, 'der');
        $y += 36;

        // Formas de pago y notas
        $bloques = [];
        if (trim((string) $pedido['enlace_pago']) !== '') {
            $bloques[] = [$tx['pago'], (string) $pedido['enlace_pago']];
        }
        if (trim((string) ($linea['pago_transferencia'] ?? '')) !== '') {
            $bloques[] = [$tx['transferencia'], trim((string) $linea['pago_transferencia'])];
        }
        if (trim((string) $pedido['notas']) !== '') {
            $bloques[] = [$tx['notas'], trim((string) $pedido['notas'])];
        }
        if (trim((string) ($linea['cotizacion_pie'] ?? '')) !== '') {
            $bloques[] = [$tx['condiciones'], trim((string) $linea['cotizacion_pie'])];
        }
        foreach ($bloques as [$titulo, $texto]) {
            $partes = Pdf::partir($texto, 9.5, $x1 - $x0);
            if ($y + 16 + 13 * count($partes) > Pdf::ALTO - 50) {
                $pdf->paginaNueva();
                $y = 60;
            }
            $pdf->texto($x0, $y, $titulo, 10, true, $azul);
            $y += 15;
            foreach ($partes as $l) {
                $pdf->texto($x0, $y, $l, 9.5, false, [0.2, 0.2, 0.2]);
                $y += 13;
            }
            $y += 8;
        }
        $pdf->texto(Pdf::ANCHO / 2, Pdf::ALTO - 28, $tx['pie'] . ' · ' . $linea['nombre'], 8, false, $gris, 'centro');
        return $pdf->salida($tx['nombre'] . ' ' . $pedido['numero'] . ' - ' . $linea['nombre']);
    }

    public static function nombreArchivo(array $pedido): string
    {
        return 'Cotizacion-' . str_pad((string) $pedido['numero'], 5, '0', STR_PAD_LEFT) . '.pdf';
    }

    /**
     * Arma el PDF y lo guarda en la carpeta de archivos de la cuenta (para mandarlo por WhatsApp).
     * @return array{tipo:string, mime:string, nombre:string, bytes:int, ruta:string}
     */
    public static function archivo(PDO $db, array $linea, array $pedido, string $idioma = 'es'): array
    {
        $bytes = self::pdf($linea, $pedido, Pedidos::items($db, (int) $pedido['id']), null, $idioma);
        return ['tipo' => 'documento', 'mime' => 'application/pdf', 'nombre' => self::nombreArchivo($pedido), 'bytes' => strlen($bytes),
                'ruta' => Archivos::guardarBytes($bytes, 'application/pdf', (int) $linea['empresa_id'])];
    }
}
