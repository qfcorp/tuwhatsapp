<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * En qué idioma escribe cada cliente, para contestarle en su idioma (menú, chatbots, agente con IA, mensajes automáticos).
 * Idiomas: es (por defecto), en, pt, fr, it — los mismos del panel.
 *
 * Se detecta SIN IA (gratis): palabras frecuentes de cada idioma en el mensaje; si el mensaje es muy corto o dudoso,
 * se usa el código de país del número (+1/+44 inglés, +55 portugués, +33 francés, +39 italiano; Ecuador y el resto, español).
 * Se guarda en contactos.idioma. Si una persona del equipo lo eligió a mano en la ficha (contactos.idioma_fijo), no se cambia solo.
 */
final class IdiomaCliente
{
    public const IDIOMAS = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português', 'fr' => 'Français', 'it' => 'Italiano'];
    public const BANDERAS = ['es' => '🇪🇸', 'en' => '🇬🇧', 'pt' => '🇧🇷', 'fr' => '🇫🇷', 'it' => '🇮🇹'];

    /** Palabras muy frecuentes y propias de cada idioma (sin tildes, en minúscula). */
    private const PALABRAS = [
        'es' => ['hola', 'buenas', 'buenos', 'dias', 'tardes', 'noches', 'gracias', 'quiero', 'quisiera', 'necesito', 'precio', 'cuanto',
                 'cuesta', 'tienen', 'tiene', 'para', 'por', 'favor', 'que', 'como', 'donde', 'el', 'la', 'los', 'las', 'una', 'informacion',
                 'saludos', 'estoy', 'puedo', 'usted', 'ustedes', 'mi', 'con', 'del', 'es', 'esta', 'si', 'pero', 'muy', 'bien', 'ayuda'],
        'en' => ['hi', 'hello', 'hey', 'good', 'morning', 'afternoon', 'evening', 'thanks', 'thank', 'you', 'please', 'want', 'would',
                 'like', 'need', 'price', 'how', 'much', 'what', 'where', 'when', 'the', 'is', 'are', 'do', 'does', 'can', 'could', 'have',
                 'information', 'info', 'my', 'with', 'for', 'and', 'this', 'that', 'your', 'i', 'im', 'it', 'about', 'there', 'yes', 'help'],
        'pt' => ['ola', 'oi', 'bom', 'boa', 'dia', 'tarde', 'noite', 'obrigado', 'obrigada', 'quero', 'gostaria', 'preciso', 'preco',
                 'quanto', 'custa', 'voces', 'voce', 'tem', 'para', 'por', 'favor', 'que', 'como', 'onde', 'uma', 'informacao', 'nao',
                 'sim', 'meu', 'minha', 'com', 'do', 'da', 'muito', 'ajuda', 'tudo'],
        'fr' => ['bonjour', 'bonsoir', 'salut', 'merci', 'je', 'voudrais', 'veux', 'besoin', 'prix', 'combien', 'coute', 'vous', 'avez',
                 'pour', 'sil', 'plait', 'quoi', 'comment', 'ou', 'le', 'les', 'une', 'des', 'est', 'oui', 'non', 'mon', 'ma', 'avec',
                 'informations', 'aide', 'tres', 'bien', 'cest'],
        'it' => ['ciao', 'buongiorno', 'buonasera', 'grazie', 'vorrei', 'voglio', 'bisogno', 'prezzo', 'quanto', 'costa', 'avete',
                 'per', 'favore', 'cosa', 'come', 'dove', 'il', 'gli', 'una', 'sono', 'si', 'mio', 'mia', 'con', 'della', 'molto',
                 'aiuto', 'informazioni', 'salve'],
    ];

    /** Código de país => idioma (los demás: español). */
    private const PAISES = ['1' => 'en', '44' => 'en', '61' => 'en', '64' => 'en', '353' => 'en', '27' => 'en',
                            '55' => 'pt', '351' => 'pt', '33' => 'fr', '32' => 'fr', '39' => 'it'];

    public static function valido(string $idioma): string
    {
        return isset(self::IDIOMAS[$idioma]) ? $idioma : 'es';
    }

    /**
     * Idioma del texto, o '' si no se puede saber (muy corto o empate).
     * Exige al menos 1 palabra distintiva y ventaja clara sobre el segundo.
     */
    public static function delTexto(string $texto): string
    {
        $t = strtolower(strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ã' => 'a', 'õ' => 'o',
                                       'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'à' => 'a', 'è' => 'e', 'ç' => 'c', 'Á' => 'a', 'É' => 'e', 'Í' => 'i',
                                       'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', '’' => '', "'" => '']));
        $t = mb_strtolower($t);
        $palabras = preg_split('/[^a-z]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$palabras) {
            return '';
        }
        $puntos = array_fill_keys(array_keys(self::PALABRAS), 0.0);
        foreach ($palabras as $p) {
            $en = [];
            foreach (self::PALABRAS as $idioma => $lista) {
                if (in_array($p, $lista, true)) {
                    $en[] = $idioma;
                }
            }
            foreach ($en as $idioma) {
                $puntos[$idioma] += 1 / count($en);   // palabra compartida (ej. "para") vale menos
            }
        }
        // Señales fuertes por letras propias
        if (preg_match('/[ñ¿¡]/u', $texto)) {
            $puntos['es'] += 2;
        }
        if (preg_match('/[ãõ]|ção|ções/u', $texto)) {
            $puntos['pt'] += 2;
        }
        arsort($puntos);
        $orden = array_keys($puntos);
        $primero = $puntos[$orden[0]];
        $segundo = $puntos[$orden[1]];
        if ($primero < 1 || $primero - $segundo < 0.75) {
            return '';
        }
        return $orden[0];
    }

    /** Idioma probable por el número (sin el +). */
    public static function delTelefono(string $telefono): string
    {
        $n = preg_replace('/\D/', '', $telefono) ?? '';
        foreach ([3, 2, 1] as $largo) {
            $cod = substr($n, 0, $largo);
            if (isset(self::PAISES[$cod])) {
                return self::PAISES[$cod];
            }
        }
        return 'es';
    }

    /**
     * Idioma a usar con este mensaje: el del texto si se nota; si no, el que ya tenía el contacto; si no, el del número.
     */
    public static function detectar(string $texto, string $telefono, string $actual = ''): string
    {
        $delTexto = self::delTexto($texto);
        // Un saludo de una sola palabra ("Hi", "ok") no basta para cambiar de idioma si el número dice otra cosa
        $nPalabras = count(preg_split('/[^\p{L}]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($delTexto !== '' && $nPalabras < 2 && $delTexto !== self::delTelefono($telefono) && $delTexto !== $actual) {
            $delTexto = '';
        }
        if ($delTexto !== '') {
            return $delTexto;
        }
        if (isset(self::IDIOMAS[$actual])) {
            return $actual;
        }
        return self::delTelefono($telefono);
    }

    /** Actualiza el idioma del contacto con el mensaje que llegó y lo devuelve. Respeta el idioma elegido a mano. */
    public static function alLlegar(PDO $db, array $contacto, string $texto): string
    {
        $actual = (string) ($contacto['idioma'] ?? '');
        if (!empty($contacto['idioma_fijo']) && isset(self::IDIOMAS[$actual])) {
            return $actual;
        }
        $nuevo = self::detectar($texto, (string) ($contacto['telefono'] ?? ''), $actual);
        if ($nuevo !== $actual) {
            $db->prepare('UPDATE contactos SET idioma = ? WHERE id = ?')->execute([$nuevo, (int) $contacto['id']]);
        }
        return $nuevo;
    }

    /** Idioma guardado del contacto (o el del número si todavía no escribió). */
    public static function de(array $contacto): string
    {
        $i = (string) ($contacto['idioma'] ?? '');
        return isset(self::IDIOMAS[$i]) ? $i : self::delTelefono((string) ($contacto['telefono'] ?? ''));
    }

    /** Elegido a mano en la ficha ('' = volver a detectar solo). */
    public static function fijar(PDO $db, int $lineaId, int $contactoId, string $idioma): void
    {
        if ($idioma === '') {
            $db->prepare('UPDATE contactos SET idioma_fijo = false WHERE id = ? AND linea_id = ?')->execute([$contactoId, $lineaId]);
            return;
        }
        $db->prepare('UPDATE contactos SET idioma = ?, idioma_fijo = true WHERE id = ? AND linea_id = ?')
           ->execute([self::valido($idioma), $contactoId, $lineaId]);
    }
}
