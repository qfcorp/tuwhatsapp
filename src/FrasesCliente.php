<?php
declare(strict_types=1);

namespace TuWhatsApp;

/**
 * (23 nov 2026) Textos FIJOS que el sistema le manda solo al CLIENTE por WhatsApp, en sus 5 idiomas (es, en, pt, fr, it).
 * No usa IA ni internet: está todo escrito aquí. Lo que escribe el negocio (menú, chatbots) va en src/Traducir.php.
 * Variables entre llaves: {total}, {n}, {producto}, {horario}…  Si una clave no existe en un idioma, sale en español.
 */
final class FrasesCliente
{
    private const T = [
        'elige_opcion' => ['es' => 'Elige una opción:', 'en' => 'Choose an option:', 'pt' => 'Escolha uma opção:',
                           'fr' => 'Choisissez une option :', 'it' => "Scegli un'opzione:"],
        'elige_boton' => ['es' => 'Elige una de las opciones 👇', 'en' => 'Please choose one of the options 👇', 'pt' => 'Escolha uma das opções 👇',
                          'fr' => 'Choisissez une des options 👇', 'it' => 'Scegli una delle opzioni 👇'],
        'no_entendi' => ['es' => 'No te entendí.', 'en' => "Sorry, I didn't understand.", 'pt' => 'Não entendi.',
                         'fr' => "Je n'ai pas compris.", 'it' => 'Non ho capito.'],
        'correo_mal' => ['es' => 'Ese correo no parece correcto.', 'en' => "That email doesn't look right.", 'pt' => 'Esse e-mail não parece correto.',
                         'fr' => 'Cet e-mail ne semble pas correct.', 'it' => 'Questa email non sembra corretta.'],
        // Llamadas fuera de horario
        'fuera_llamada' => [
            'es' => 'Hola, gracias por llamar. En este momento estamos fuera de horario. Atendemos {horario}. Escríbenos por aquí y te respondemos apenas abramos.',
            'en' => "Hi, thanks for calling. We're closed right now. Our hours are {horario}. Message us here and we'll reply as soon as we open.",
            'pt' => 'Olá, obrigado por ligar. No momento estamos fora do horário. Atendemos {horario}. Escreva para nós por aqui e respondemos assim que abrirmos.',
            'fr' => "Bonjour, merci de votre appel. Nous sommes actuellement fermés. Nous sommes ouverts {horario}. Écrivez-nous ici et nous vous répondrons dès l'ouverture.",
            'it' => "Ciao, grazie per aver chiamato. In questo momento siamo chiusi. Siamo aperti {horario}. Scrivici qui e ti risponderemo appena apriamo.",
        ],
        'horario_generico' => ['es' => 'en nuestro horario de atención', 'en' => 'during our business hours', 'pt' => 'no nosso horário de atendimento',
                               'fr' => "pendant nos heures d'ouverture", 'it' => 'nel nostro orario di apertura'],
        // Pagos y pedidos
        'pago_recibido' => [
            'es' => '✅ Recibimos tu pago de {total} del pedido #{n}. ¡Gracias por tu compra!',
            'en' => '✅ We received your payment of {total} for order #{n}. Thank you for your purchase!',
            'pt' => '✅ Recebemos seu pagamento de {total} do pedido #{n}. Obrigado pela sua compra!',
            'fr' => '✅ Nous avons reçu votre paiement de {total} pour la commande n° {n}. Merci pour votre achat !',
            'it' => "✅ Abbiamo ricevuto il tuo pagamento di {total} per l'ordine n. {n}. Grazie per il tuo acquisto!",
        ],
        'pedido' => ['es' => 'Pedido', 'en' => 'Order', 'pt' => 'Pedido', 'fr' => 'Commande', 'it' => 'Ordine'],
        'subtotal' => ['es' => 'Subtotal', 'en' => 'Subtotal', 'pt' => 'Subtotal', 'fr' => 'Sous-total', 'it' => 'Subtotale'],
        'iva' => ['es' => 'IVA', 'en' => 'VAT', 'pt' => 'IVA', 'fr' => 'TVA', 'it' => 'IVA'],
        'total' => ['es' => 'Total', 'en' => 'Total', 'pt' => 'Total', 'fr' => 'Total', 'it' => 'Totale'],
        'paga_aqui' => ['es' => 'Paga aquí:', 'en' => 'Pay here:', 'pt' => 'Pague aqui:', 'fr' => 'Payez ici :', 'it' => 'Paga qui:'],
        'transferencia' => ['es' => 'Transferencia:', 'en' => 'Bank transfer:', 'pt' => 'Transferência:', 'fr' => 'Virement :', 'it' => 'Bonifico:'],
        'manda_comprobante' => [
            'es' => 'Cuando pagues, mándanos el comprobante por aquí. ¡Gracias!',
            'en' => 'Once you pay, send us the receipt here. Thank you!',
            'pt' => 'Quando pagar, envie o comprovante por aqui. Obrigado!',
            'fr' => 'Une fois le paiement effectué, envoyez-nous le reçu ici. Merci !',
            'it' => 'Quando paghi, inviaci la ricevuta qui. Grazie!',
        ],
        // Carrito y catálogo
        'un_producto' => ['es' => '1 producto', 'en' => '1 item', 'pt' => '1 produto', 'fr' => '1 article', 'it' => '1 prodotto'],
        'n_productos' => ['es' => '{n} productos', 'en' => '{n} items', 'pt' => '{n} produtos', 'fr' => '{n} articles', 'it' => '{n} prodotti'],
        'el_producto' => ['es' => 'el producto', 'en' => 'the item', 'pt' => 'o produto', 'fr' => "l'article", 'it' => 'il prodotto'],
        'agregado' => [
            'es' => "✅ Agregué *{producto}* a tu carrito.\n🛒 {cuenta} · total {total}\n\nEscribe *comprar* para terminar, o *catálogo* para elegir más.",
            'en' => "✅ I added *{producto}* to your cart.\n🛒 {cuenta} · total {total}\n\nType *buy* to finish, or *catalog* to choose more.",
            'pt' => "✅ Adicionei *{producto}* ao seu carrinho.\n🛒 {cuenta} · total {total}\n\nEscreva *comprar* para finalizar, ou *catálogo* para escolher mais.",
            'fr' => "✅ J'ai ajouté *{producto}* à votre panier.\n🛒 {cuenta} · total {total}\n\nÉcrivez *acheter* pour terminer, ou *catalogue* pour en choisir d'autres.",
            'it' => "✅ Ho aggiunto *{producto}* al tuo carrello.\n🛒 {cuenta} · totale {total}\n\nScrivi *acquista* per finire, o *catalogo* per sceglierne altri.",
        ],
        'ver_disponible' => ['es' => 'Escribe *catálogo* para ver lo disponible.', 'en' => 'Type *catalog* to see what is available.',
                             'pt' => 'Escreva *catálogo* para ver o que está disponível.', 'fr' => 'Écrivez *catalogue* pour voir ce qui est disponible.',
                             'it' => 'Scrivi *catalogo* per vedere cosa è disponibile.'],
        'catalogo_lista' => [
            'es' => 'Estos son nuestros productos. Toca uno para agregarlo a tu carrito 👇',
            'en' => 'These are our products. Tap one to add it to your cart 👇',
            'pt' => 'Estes são nossos produtos. Toque em um para adicioná-lo ao carrinho 👇',
            'fr' => 'Voici nos produits. Touchez-en un pour l\'ajouter à votre panier 👇',
            'it' => 'Questi sono i nostri prodotti. Tocca uno per aggiungerlo al carrello 👇',
        ],
        'boton_ver' => ['es' => 'Ver productos', 'en' => 'See products', 'pt' => 'Ver produtos', 'fr' => 'Voir les produits', 'it' => 'Vedi prodotti'],
        'tu_carrito' => ['es' => '🛒 Tu carrito: {cuenta}, total {total}', 'en' => '🛒 Your cart: {cuenta}, total {total}',
                         'pt' => '🛒 Seu carrinho: {cuenta}, total {total}', 'fr' => '🛒 Votre panier : {cuenta}, total {total}',
                         'it' => '🛒 Il tuo carrello: {cuenta}, totale {total}'],
        'mas_iva' => ['es' => ' + IVA', 'en' => ' + VAT', 'pt' => ' + IVA', 'fr' => ' + TVA', 'it' => ' + IVA'],
        // Horario en palabras
        'h_dos' => ['es' => '{a} y {b}', 'en' => '{a} and {b}', 'pt' => '{a} e {b}', 'fr' => '{a} et {b}', 'it' => '{a} e {b}'],
        'h_rango' => ['es' => '{a} a {b}', 'en' => '{a} to {b}', 'pt' => '{a} a {b}', 'fr' => 'du {a} au {b}', 'it' => 'da {a} a {b}'],
        'h_horas' => ['es' => '{dias} de {desde} a {hasta}', 'en' => '{dias} from {desde} to {hasta}', 'pt' => '{dias} das {desde} às {hasta}',
                      'fr' => '{dias} de {desde} à {hasta}', 'it' => '{dias} dalle {desde} alle {hasta}'],
    ];

    public const DIAS = [
        'es' => [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'],
        'en' => [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'pt' => [1 => 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'],
        'fr' => [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
        'it' => [1 => 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'],
    ];

    /** Frase fija en el idioma pedido (o en español), con las variables reemplazadas. */
    public static function t(string $clave, string $idioma = 'es', array $vars = []): string
    {
        $f = self::T[$clave] ?? null;
        if ($f === null) {
            return $clave;
        }
        $t = $f[$idioma] ?? $f['es'];
        foreach ($vars as $k => $v) {
            $t = str_replace('{' . $k . '}', (string) $v, $t);
        }
        return $t;
    }

    /** "1 producto" / "3 productos" en el idioma del cliente. */
    public static function productos(int $n, string $idioma = 'es'): string
    {
        return $n === 1 ? self::t('un_producto', $idioma) : self::t('n_productos', $idioma, ['n' => $n]);
    }

    /** Todas las claves (para las pruebas: cada una debe tener los 5 idiomas). */
    public static function claves(): array
    {
        return self::T;
    }
}
