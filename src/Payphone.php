<?php
declare(strict_types=1);

namespace TuWhatsApp;

/**
 * Link de cobro de Payphone por API (https://docs.payphone.app/links-de-pago):
 *   POST https://pay.payphonetodoesposible.com/api/Links   Authorization: Bearer <token de la app de Payphone Developer>
 *   montos en CENTAVOS: amount = amountWithoutTax + amountWithTax + tax; clientTransactionId único (máx. 15 letras).
 *   Responde la URL del link como texto (o un JSON con "message" si hay error).
 * El token se guarda cifrado en el número. PAYPHONE_URL (opcional, para pruebas) cambia la dirección.
 */
final class Payphone
{
    /**
     * @param int $sinIva centavos que no pagan IVA · $conIva centavos que pagan IVA (sin el IVA) · $iva centavos del IVA
     * @return array{ok:bool, url?:string, error?:string}
     */
    public static function crearLink(string $token, string $storeId, int $sinIva, int $conIva, int $iva, string $transaccion, string $referencia): array
    {
        if ($token === '') {
            return ['ok' => false, 'error' => 'Falta el token de Payphone.'];
        }
        $total = $sinIva + $conIva + $iva;
        if ($total <= 0) {
            return ['ok' => false, 'error' => 'El total debe ser mayor a cero.'];
        }
        $cuerpo = ['amount' => $total, 'currency' => 'USD', 'clientTransactionId' => mb_substr($transaccion, 0, 15),
                   'reference' => mb_substr($referencia, 0, 100), 'oneTime' => true, 'expireIn' => 72];
        if ($sinIva > 0) {
            $cuerpo['amountWithoutTax'] = $sinIva;
        }
        if ($conIva > 0) {
            $cuerpo['amountWithTax'] = $conIva;
            $cuerpo['tax'] = $iva;
        }
        if (trim($storeId) !== '') {
            $cuerpo['storeId'] = trim($storeId);
        }
        $url = rtrim((string) (getenv('PAYPHONE_URL') ?: 'https://pay.payphonetodoesposible.com'), '/') . '/api/Links';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($cuerpo),
        ]);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'No se pudo conectar con Payphone (' . $falla . ').'];
        }
        $texto = trim((string) $resp);
        $json = json_decode($texto, true);
        if (is_string($json)) {
            $texto = $json;   // a veces viene como texto entre comillas
        }
        if ($codigo >= 200 && $codigo < 300 && preg_match('#^https://\S+$#', $texto)) {
            return ['ok' => true, 'url' => $texto];
        }
        $msg = is_array($json) ? (string) ($json['message'] ?? ($json['errors'][0]['message'] ?? '')) : '';
        return ['ok' => false, 'error' => 'Payphone no creó el link' . ($codigo === 401 ? ': el token no sirve o venció.' : ($msg !== '' ? ': ' . $msg : ' (código ' . $codigo . ').'))];
    }
}
