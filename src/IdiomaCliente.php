<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * En qué idioma escribe cada cliente, para contestarle en su idioma (menú, chatbots, agente con IA, mensajes automáticos).
 * Idiomas: es (por defecto), en, pt, fr, it — los mismos del panel.
 *
 * Se detecta SIN IA (gratis) con las palabras del mensaje. Reglas (revisión del 23 nov 2026):
 *  - Si el texto dice español, es español (el país nunca lo cambia: "Hola" desde +1 es español).
 *  - Mensajes sin señal ("ok", "1", emojis, correos, enlaces, números) no cambian nada: queda el idioma que tenía (o español).
 *  - Para pasar a otro idioma hace falta señal: 2 palabras propias de ese idioma, o 1 si el código de país es de ese idioma
 *    (+1 Estados Unidos/Canadá, +44, +55, +33, +39…; el Caribe hispano +1 809/829/849/787/939 es español).
 *  - Para CAMBIAR un idioma ya guardado hacen falta siempre 2 palabras propias del idioma nuevo (no salta con una palabra suelta).
 *  - Contacto sin idioma guardado = español.
 * Se guarda en contactos.idioma. Si una persona del equipo lo eligió a mano en la ficha (contactos.idioma_fijo), no se cambia solo.
 * No se detecta con botones tocados, archivos, ubicaciones ni cuando el chatbot espera un dato (lo decide Webhook).
 */
final class IdiomaCliente
{
    public const IDIOMAS = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português', 'fr' => 'Français', 'it' => 'Italiano'];
    public const BANDERAS = ['es' => '🇪🇸', 'en' => '🇬🇧', 'pt' => '🇧🇷', 'fr' => '🇫🇷', 'it' => '🇮🇹'];

    /** Palabras muy frecuentes de cada idioma (sin tildes, en minúscula). Una palabra en varias listas vale menos. "info" es neutra. */
    private const PALABRAS = [
        'es' => ['hola', 'buenas', 'buenos', 'buen', 'dia', 'dias', 'tardes', 'noches', 'gracias', 'quiero', 'quisiera', 'necesito', 'precio', 'cuanto',
                 'cuesta', 'tienen', 'tiene', 'para', 'por', 'favor', 'que', 'como', 'donde', 'el', 'la', 'los', 'las', 'una', 'informacion',
                 'saludos', 'estoy', 'puedo', 'usted', 'ustedes', 'mi', 'con', 'del', 'es', 'esta', 'si', 'pero', 'muy', 'bien', 'ayuda',
                 'mas', 'me', 'ver', 'tengo', 'interesa', 'disculpa', 'pregunta', 'duda', 'ubicacion', 'direccion', 'envios', 'pedido',
                 'comprar', 'talla', 'porfa', 'valor', 'cuando', 'manana', 'listo', 'dale', 'bueno', 'chevere', 'perfecto', 'estan', 'tal',
                 'acuerdo', 'das', 'pasa', 'promociones', 'disponible', 'camisa', 'yo', 'estas'],
        'en' => ['hi', 'hello', 'hey', 'good', 'morning', 'afternoon', 'evening', 'thanks', 'thank', 'thx', 'you', 'please', 'pls', 'want', 'would',
                 'like', 'need', 'price', 'how', 'much', 'what', 'where', 'when', 'the', 'is', 'are', 'do', 'does', 'can', 'could', 'have',
                 'information', 'my', 'with', 'for', 'and', 'this', 'that', 'your', 'i', 'im', 'it', 'about', 'there', 'yes', 'help',
                 'available', 'today', 'buy', 'order'],
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

    /** Código de país => idioma (los demás: español). Se busca primero el prefijo más largo (Caribe hispano dentro del +1). */
    private const PAISES = ['1809' => 'es', '1829' => 'es', '1849' => 'es', '1787' => 'es', '1939' => 'es',
                            '1' => 'en', '44' => 'en', '61' => 'en', '64' => 'en', '353' => 'en', '27' => 'en',
                            '55' => 'pt', '351' => 'pt', '33' => 'fr', '32' => 'fr', '39' => 'it'];

    public static function valido(string $idioma): string
    {
        return isset(self::IDIOMAS[$idioma]) ? $idioma : 'es';
    }

    /** Palabras del texto (sin tildes, minúscula), sin correos, enlaces ni números. @return list<string> */
    private static function palabras(string $texto): array
    {
        $texto = (string) preg_replace(['~^\s*\[[^\]]*\]~u', '~https?://\S+|www\.\S+~iu', '~\S+@\S+\.\S+~u', '~\S+\.(com|ec|net|org|pdf|jpg|png)\b\S*~iu'], ' ', $texto);
        $t = mb_strtolower($texto);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ã' => 'a', 'õ' => 'o',
                        'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', '’' => '', "'" => '']);
        return preg_split('/[^a-z]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Puntos por idioma y cuántas palabras PROPIAS (que no están en otra lista) distintas tiene cada uno.
     * @return array{0: array<string,float>, 1: array<string,int>}
     */
    public static function puntos(string $texto): array
    {
        $puntos = array_fill_keys(array_keys(self::PALABRAS), 0.0);
        $propias = array_fill_keys(array_keys(self::PALABRAS), []);
        foreach (self::palabras($texto) as $p) {
            $en = [];
            foreach (self::PALABRAS as $idioma => $lista) {
                if (in_array($p, $lista, true)) {
                    $en[] = $idioma;
                }
            }
            foreach ($en as $idioma) {
                $puntos[$idioma] += 1 / count($en);   // palabra compartida (ej. "para") vale menos
            }
            if (count($en) === 1) {
                $propias[$en[0]][$p] = true;
            }
        }
        $fuertes = array_map('count', $propias);
        // Señales fuertes por letras propias
        if (preg_match('/[ñÑ¿¡]/u', $texto)) {
            $puntos['es'] += 2;
            $fuertes['es'] += 2;
        }
        if (preg_match('/[ãõ]|ção|ções/u', $texto)) {
            $puntos['pt'] += 2;
            $fuertes['pt'] += 2;
        }
        return [$puntos, $fuertes];
    }

    /**
     * Idioma del texto, o '' si no se puede saber (sin señal o empate).
     * Exige al menos 1 punto y ventaja clara sobre el segundo.
     */
    public static function delTexto(string $texto): string
    {
        [$puntos] = self::puntos($texto);
        arsort($puntos);
        $orden = array_keys($puntos);
        $primero = $puntos[$orden[0]];
        $segundo = $puntos[$orden[1]];
        if ($primero < 1 || $primero - $segundo < 0.75) {
            return '';
        }
        return $orden[0];
    }

    /**
     * Idioma del texto SOLO si es muy claro (3 palabras o más, 2 propias y mucha ventaja), o ''.
     * Lo usa el traductor del chat para no pedir confirmación cuando el mensaje ya está en el idioma del cliente.
     */
    public static function seguro(string $texto): string
    {
        if (count(self::palabras($texto)) < 3) {
            return '';
        }
        [$puntos, $fuertes] = self::puntos($texto);
        arsort($puntos);
        $orden = array_keys($puntos);
        $l = $orden[0];
        return $puntos[$l] >= 2 && $fuertes[$l] >= 2 && $puntos[$l] - $puntos[$orden[1]] >= 1.5 ? $l : '';
    }

    /** Idioma probable por el número (sin el +). Solo sirve para desempatar: nunca impone idioma. */
    public static function delTelefono(string $telefono): string
    {
        $n = preg_replace('/\D/', '', $telefono) ?? '';
        foreach ([4, 3, 2, 1] as $largo) {
            $cod = substr($n, 0, $largo);
            if (isset(self::PAISES[$cod])) {
                return self::PAISES[$cod];
            }
        }
        return 'es';
    }

    /**
     * Idioma a usar desde este mensaje, sabiendo el que ya tenía el contacto ('' = ninguno todavía).
     */
    public static function detectar(string $texto, string $telefono, string $actual = ''): string
    {
        $actual = isset(self::IDIOMAS[$actual]) ? $actual : '';
        $queda = $actual !== '' ? $actual : 'es';
        [$puntos, $fuertes] = self::puntos($texto);
        arsort($puntos);
        $orden = array_keys($puntos);
        $primero = $puntos[$orden[0]];
        if ($primero < 1) {
            return $queda;   // sin señal: "ok", "1", emojis, correos, enlaces
        }
        $pais = self::delTelefono($telefono);
        $cand = '';
        if ($primero - $puntos[$orden[1]] >= 0.75) {
            $cand = $orden[0];
        } elseif ($puntos['es'] > 0 && $puntos['es'] >= $primero - 0.5) {
            $cand = 'es';    // empate con español: la señal de español nunca se descarta
        } elseif ($pais !== 'es' && $puntos[$pais] > 0 && $puntos[$pais] >= $primero - 0.5) {
            $cand = $pais;   // empate entre otros idiomas: desempata el país, solo si su idioma tiene señal en el texto
        }
        if ($cand === '' || $cand === $actual) {
            return $queda;
        }
        if ($actual === '') {   // primera vez: español con cualquier señal; otro idioma con 2 palabras propias, o 1 si el país es de ese idioma
            return $cand === 'es' || $fuertes[$cand] >= 2 || ($fuertes[$cand] >= 1 && $pais === $cand) ? $cand : 'es';
        }
        return $fuertes[$cand] >= 2 ? $cand : $actual;   // cambiar un idioma guardado pide más señal
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

    /** Idioma guardado del contacto; sin idioma guardado = español (el número no impone idioma). */
    public static function de(array $contacto): string
    {
        $i = (string) ($contacto['idioma'] ?? '');
        return isset(self::IDIOMAS[$i]) ? $i : 'es';
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
