<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * (23 nov 2026, pedido de Dimitry) Los chatbots de ventas del número de Tukán Software, listos y en 5 idiomas
 * (español, inglés, portugués, francés e italiano), sin gastar IA:
 *   - Pedir demo       (demo, quiero una demo…)   → elige su tipo de negocio y le da el enlace de la demo de su programa
 *   - Precios y planes (precio, cuánto cuesta…)   → precios de cada programa (sin IVA) y promociones
 *   - Soporte          (soporte, problema, error…) → toma el problema y lo pasa al equipo, o el grupo de Telegram
 *   - Hablar con un asesor (asesor, humano…)      → pasa directo a una persona
 *   - Tienda gráficos vMix (vmix, tienda tukan…)  → (24 nov 2026) qué trae y precio, cómo comprar, ayuda con una compra,
 *                                                  cómo se instala; arranca con el botón «¿Dudas? Escríbenos» de tienda.tukansoftware.com
 * Se arrancan solo con sus palabras (no con el primer mensaje), así no tapan el menú de respuestas automáticas.
 * WhatsApp Tukán, TuDron y TuBroadcast no ofrecen demo; TuFactura no sale (todavía no se vende).
 * (23 nov 2026) Al elegir un programa se manda su PDF de planes y precios (deploy/pdf_planes, hecho con su generador).
 * Instalar o volver a poner los textos de fábrica: php deploy/chatbots_tukan.php
 */
final class ChatbotsTukan
{
    /** Nombre de cada chatbot (con esto se reconocen al volver a instalarlos). */
    public const NOMBRES = ['Pedir demo', 'Precios y planes', 'Soporte', 'Hablar con un asesor', 'Tienda gráficos vMix'];

    /**
     * Crea los chatbots en el número o, si ya existen con ese nombre, les vuelve a poner los textos de fábrica. Quedan encendidos.
     * @return array{creados:list<string>, actualizados:list<string>, pdf:int, faltan:list<string>}
     */
    public static function instalar(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT empresa_id FROM lineas WHERE id = ?');
        $st->execute([$lineaId]);
        $empresaId = $st->fetchColumn();
        if ($empresaId === false) {
            throw new RuntimeException('Ese número no existe.');
        }
        $r = ['creados' => [], 'actualizados' => [], 'pdf' => 0, 'faltan' => []];
        $db->beginTransaction();
        try {
            foreach (self::definiciones() as $d) {
                $st = $db->prepare('SELECT * FROM flujos WHERE linea_id = ? AND nombre = ? ORDER BY id LIMIT 1');
                $st->execute([$lineaId, $d['nombre']]);
                $antes = $st->fetch() ?: null;
                $d['pasos'] = self::conPdf($d['pasos'], $antes, (int) $empresaId, $r);
                $pasos = Flujos::limpiarPasos(self::alEditor($d['pasos']), [], true);
                $tr = Traducir::limpiarFlujo($d['tr'], $pasos);
                $palabras = implode(', ', Reglas::listaPalabras($d['palabras']));
                $id = $antes['id'] ?? null;
                $json = [json_encode($pasos, JSON_UNESCAPED_UNICODE), json_encode($tr, JSON_UNESCAPED_UNICODE)];
                if ($id) {
                    $db->prepare("UPDATE flujos SET disparador = 'palabras', palabras = ?, pasos = ?, traducciones = ?, activo = true WHERE id = ?")
                       ->execute([$palabras, $json[0], $json[1], $id]);
                    $r['actualizados'][] = $d['nombre'];
                } else {
                    $db->prepare("INSERT INTO flujos (linea_id, nombre, activo, disparador, palabras, pasos, traducciones) VALUES (?, ?, true, 'palabras', ?, ?, ?)")
                       ->execute([$lineaId, $d['nombre'], $palabras, $json[0], $json[1]]);
                    $r['creados'][] = $d['nombre'];
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $r;
    }

    /** Pasos sencillos → el formato del editor que valida Flujos::limpiarPasos. */
    private static function alEditor(array $pasos): array
    {
        $r = [];
        foreach ($pasos as $p) {
            $e = ['id' => $p['id'], 'tipo' => $p['tipo'], 'texto' => $p['texto'], 'siguiente' => $p['siguiente'] ?? '',
                  'guardar' => $p['guardar'] ?? 'notas', 'etiqueta' => $p['etiqueta'] ?? '', 'etapa' => $p['etapa'] ?? '', 'archivo' => $p['archivo'] ?? null];
            foreach ($p['opciones'] ?? [] as [$titulo, $ir]) {
                $e['op_titulo'][] = $titulo;
                $e['op_ir'][] = $ir;
            }
            $r[] = $e;
        }
        return $r;
    }

    /** Carpeta con los PDF de planes y precios de cada programa (salen del generador de deploy/pdf_planes/generador). */
    public static function carpetaPdf(): string
    {
        return dirname(__DIR__) . '/deploy/pdf_planes';
    }

    /**
     * Cambia 'pdf' => 'archivo.pdf' de cada paso por el archivo guardado en la carpeta de archivos de la cuenta.
     * Si el chatbot ya tenía ese mismo PDF (mismo nombre y tamaño, y sigue en el disco), lo reutiliza: reinstalar no duplica.
     */
    private static function conPdf(array $pasos, ?array $antes, int $empresaId, array &$r): array
    {
        $viejos = [];
        foreach ($antes ? Flujos::pasos($antes) : [] as $p) {
            if (!empty($p['archivo']['nombre'])) {
                $viejos[$p['archivo']['nombre'] . '|' . (int) $p['archivo']['bytes']] = $p['archivo'];
            }
        }
        foreach ($pasos as $k => $p) {
            if (empty($p['pdf'])) {
                continue;
            }
            $ruta = self::carpetaPdf() . '/' . basename((string) $p['pdf']);
            unset($pasos[$k]['pdf']);
            if (!is_file($ruta)) {
                $r['faltan'][] = basename($ruta);
                continue;
            }
            $bytes = (int) filesize($ruta);
            $clave = basename($ruta) . '|' . $bytes;
            if (isset($viejos[$clave]) && Archivos::ruta((string) $viejos[$clave]['ruta'])) {
                $pasos[$k]['archivo'] = $viejos[$clave];
            } else {
                $pasos[$k]['archivo'] = ['tipo' => 'documento', 'mime' => 'application/pdf', 'nombre' => basename($ruta), 'bytes' => $bytes,
                                         'ruta' => Archivos::guardarBytes((string) file_get_contents($ruta), 'application/pdf', $empresaId)];
                $viejos[$clave] = $pasos[$k]['archivo'];
            }
            $r['pdf']++;
        }
        return $pasos;
    }

    /** "Arriba tienes el PDF…" + precios de un programa por módulos (Básico $12,99 / Completo $25), en los 5 idiomas. */
    private static function precioModular(string $p, bool $dominio, string $extra = ''): array
    {
        $x = $extra === '' ? ['', '', '', '', ''] : explode('|', $extra);
        return [
            "📄 Arriba tienes el PDF con todos los planes y módulos de *$p*.\n\n• *Básico:* \$12,99 + IVA al mes, con 3 módulos a elegir (cada módulo más, \$5).\n• *Completo:* \$25 + IVA al mes, con todos los módulos y el Asistente IA.\n\n🎁 Pagando 6 meses pagas 5, y pagando el año pagas 10" . ($dominio ? ' y te regalamos tu dominio propio' : '') . ".{$x[0]}\n\nPruébalo 3 días: escribe *demo*.",
            "📄 Above is the PDF with all the plans and modules of *$p* (in Spanish).\n\n• *Basic:* \$12.99 + VAT per month, with 3 modules of your choice (each extra module, \$5).\n• *Complete:* \$25 + VAT per month, with every module and the AI Assistant.\n\n🎁 Pay 6 months and get 1 free; pay the year and get 2 free" . ($dominio ? ' plus your own domain' : '') . ".{$x[1]}\n\nTry it for 3 days: type *demo*.",
            "📄 Acima está o PDF com todos os planos e módulos do *$p* (em espanhol).\n\n• *Básico:* US\$ 12,99 + impostos por mês, com 3 módulos à escolha (cada módulo extra, US\$ 5).\n• *Completo:* US\$ 25 + impostos por mês, com todos os módulos e o Assistente de IA.\n\n🎁 Pagando 6 meses você paga 5, e pagando o ano paga 10" . ($dominio ? ' e ganha seu domínio próprio' : '') . ".{$x[2]}\n\nTeste por 3 dias: escreva *demo*.",
            "📄 Ci-dessus, le PDF avec tous les forfaits et modules de *$p* (en espagnol).\n\n• *Basique :* 12,99 \$ + TVA par mois, avec 3 modules au choix (chaque module en plus : 5 \$).\n• *Complet :* 25 \$ + TVA par mois, avec tous les modules et l'Assistant IA.\n\n🎁 6 mois payés = 1 mois offert ; l'année payée = 2 mois offerts" . ($dominio ? ' et votre nom de domaine' : '') . ".{$x[3]}\n\nEssayez-le 3 jours : écrivez *demo*.",
            "📄 Qui sopra trovi il PDF con tutti i piani e i moduli di *$p* (in spagnolo).\n\n• *Base:* 12,99 \$ + IVA al mese, con 3 moduli a scelta (ogni modulo in più, 5 \$).\n• *Completo:* 25 \$ + IVA al mese, con tutti i moduli e l'Assistente IA.\n\n🎁 Pagando 6 mesi ne paghi 5, pagando l'anno ne paghi 10" . ($dominio ? ' e ti regaliamo il tuo dominio' : '') . ".{$x[4]}\n\nProvalo per 3 giorni: scrivi *demo*.",
        ];
    }

    /** El mensaje con el enlace de la demo de un programa, en los 5 idiomas: [es, en, pt, fr, it]. */
    private static function demo(string $programa, string $enlace, string $extra = ''): array
    {
        $x = $extra === '' ? ['', '', '', '', ''] : explode('|', $extra);
        return [
            "¡Excelente! 🎉 Pide tu demo de *$programa* aquí:\n$enlace\n\n• Dura 3 días y trae todos los módulos.\n• Usa tus datos reales: los necesitamos para activar tu demo.\n• Se crea en 5 a 10 minutos y la clave te llega a tu correo.\n• Arriba te dejé el PDF con los planes y precios.{$x[0]}\n\nSi algo no te funciona, escribe *asesor*.",
            "Great! 🎉 Request your *$programa* demo here:\n$enlace\n\n• It lasts 3 days and includes every module.\n• Please use your real details: we need them to activate your demo.\n• It is ready in 5 to 10 minutes and the password is sent to your email.\n• The PDF above has the plans and prices (in Spanish).{$x[1]}\n\nIf anything goes wrong, type *agent*.",
            "Ótimo! 🎉 Peça sua demonstração do *$programa* aqui:\n$enlace\n\n• Dura 3 dias e traz todos os módulos.\n• Use seus dados reais: precisamos deles para ativar sua demonstração.\n• Fica pronta em 5 a 10 minutos e a senha chega no seu e-mail.\n• Acima deixei o PDF com os planos e preços (em espanhol).{$x[2]}\n\nSe algo não funcionar, escreva *atendente*.",
            "Parfait ! 🎉 Demandez votre démo de *$programa* ici :\n$enlace\n\n• Elle dure 3 jours et inclut tous les modules.\n• Utilisez vos vraies coordonnées : nous en avons besoin pour activer votre démo.\n• Elle est prête en 5 à 10 minutes et le mot de passe arrive par e-mail.\n• Le PDF ci-dessus contient les forfaits et les prix (en espagnol).{$x[3]}\n\nEn cas de souci, écrivez *conseiller*.",
            "Ottimo! 🎉 Richiedi la demo di *$programa* qui:\n$enlace\n\n• Dura 3 giorni e include tutti i moduli.\n• Usa i tuoi dati reali: ci servono per attivare la demo.\n• È pronta in 5-10 minuti e la password arriva alla tua email.\n• Qui sopra trovi il PDF con piani e prezzi (in spagnolo).{$x[4]}\n\nSe qualcosa non funziona, scrivi *operatore*.",
        ];
    }

    /** (24 nov 2026) Qué trae la Tienda de gráficos para vMix y cuánto cuesta, en los 5 idiomas: [es, en, pt, fr, it]. */
    private static function tiendaVmix(): array
    {
        $u = 'https://tienda.tukansoftware.com';
        return [
            "🎬 *Gráficos para vMix* de la Tienda Tukán\n\n• 8 estilos y 7 deportes: fútbol, futsal, vóley, ecuavóley, básquet, tenis y pádel.\n• Marcador, reloj, alineaciones, cambios, avisos y más títulos de cada deporte.\n• Diseñador fácil para poner tus equipos, colores y escudos, sin saber diseño.\n• Panel listo (*UTC Edición QF*) incluido, transiciones de 3 segundos y manual en PDF.\n• Teclas para Ulanzi D200H o Elgato Stream Deck (una incluida).\n\n💳 *¿Cuánto cuesta?* Cada *estilo* vale *USD 12 + IVA* y es para *un deporte* (por ejemplo, Estilo Copa · Fútbol). Pago único, con 6 meses de soporte.\n• 1 estilo de 1 deporte: \$12 + IVA.\n• Los 8 estilos de un deporte: pagas 7 (\$84 + IVA), 1 va de regalo.\n• 1 estilo para los 7 deportes: pagas 6 (\$72 + IVA), 1 va de regalo.\nCon la tarjeta de socio, por cada 5 que compras el siguiente va de regalo.\nNecesitas vMix en Windows.\n\nMira el video de cada estilo y compra aquí:\n$u\n\nSi tienes otra duda, escribe *asesor*.",
            "🎬 *vMix graphics* from the Tukán Store\n\n• 8 styles and 7 sports: football, futsal, volleyball, ecuavolley, basketball, tennis and padel.\n• Scoreboard, clock, line-ups, substitutions, alerts and more titles for each sport.\n• An easy Designer to add your teams, colors and crests, no design skills needed.\n• Ready-made control panel (*UTC Edición QF*) included, 3-second transitions and a PDF manual.\n• Keys for Ulanzi D200H or Elgato Stream Deck (one included).\n\n💳 *How much is it?* Each *style* costs *USD 12 + VAT* and is for *one sport* (for example, Copa style · Football). One-time payment, with 6 months of support.\n• 1 style for 1 sport: \$12 + VAT.\n• All 8 styles for one sport: you pay 7 (\$84 + VAT), 1 is on us.\n• 1 style for all 7 sports: you pay 6 (\$72 + VAT), 1 is on us.\nWith the member card, for every 5 you buy the next one is on us.\nYou need vMix on Windows. The store is in Spanish.\n\nWatch the video of each style and buy here:\n$u\n\nAny other question? Type *agent*.",
            "🎬 *Gráficos para vMix* da Loja Tukán\n\n• 8 estilos e 7 esportes: futebol, futsal, vôlei, ecuavôlei, basquete, tênis e padel.\n• Placar, cronômetro, escalações, substituições, avisos e mais títulos de cada esporte.\n• Um Designer fácil para colocar seus times, cores e escudos, sem saber design.\n• Painel pronto (*UTC Edición QF*) incluído, transições de 3 segundos e manual em PDF.\n• Teclas para Ulanzi D200H ou Elgato Stream Deck (uma incluída).\n\n💳 *Quanto custa?* Cada *estilo* custa *US\$ 12 + impostos* e é para *um esporte* (por exemplo, Estilo Copa · Futebol). Pagamento único, com 6 meses de suporte.\n• 1 estilo de 1 esporte: US\$ 12 + impostos.\n• Os 8 estilos de um esporte: você paga 7 (US\$ 84 + impostos), 1 é por nossa conta.\n• 1 estilo para os 7 esportes: você paga 6 (US\$ 72 + impostos), 1 é por nossa conta.\nCom o cartão de sócio, a cada 5 que você compra o próximo é por nossa conta.\nVocê precisa do vMix no Windows. A loja está em espanhol.\n\nVeja o vídeo de cada estilo e compre aqui:\n$u\n\nOutra dúvida? Escreva *atendente*.",
            "🎬 *Habillages pour vMix* de la Boutique Tukán\n\n• 8 styles et 7 sports : football, futsal, volley, ecuavolley, basket, tennis et padel.\n• Tableau de score, chrono, compositions, changements, alertes et d'autres titres pour chaque sport.\n• Un Designer simple pour mettre vos équipes, couleurs et écussons, sans savoir dessiner.\n• Panneau de contrôle prêt (*UTC Edición QF*) inclus, transitions de 3 secondes et manuel en PDF.\n• Touches pour Ulanzi D200H ou Elgato Stream Deck (une incluse).\n\n💳 *Combien ça coûte ?* Chaque *style* coûte *12 \$ + TVA* et sert pour *un sport* (par exemple, style Copa · Football). Paiement unique, avec 6 mois d'assistance.\n• 1 style pour 1 sport : 12 \$ + TVA.\n• Les 8 styles d'un sport : vous en payez 7 (84 \$ + TVA), 1 est offert.\n• 1 style pour les 7 sports : vous en payez 6 (72 \$ + TVA), 1 est offert.\nAvec la carte de membre, tous les 5 achats le suivant est offert.\nIl faut vMix sous Windows. La boutique est en espagnol.\n\nRegardez la vidéo de chaque style et achetez ici :\n$u\n\nUne autre question ? Écrivez *conseiller*.",
            "🎬 *Grafiche per vMix* del Negozio Tukán\n\n• 8 stili e 7 sport: calcio, calcio a 5, pallavolo, ecuavolley, basket, tennis e padel.\n• Tabellone, cronometro, formazioni, sostituzioni, avvisi e altri titoli per ogni sport.\n• Un Designer facile per inserire squadre, colori e stemmi, senza saper disegnare.\n• Pannello di controllo pronto (*UTC Edición QF*) incluso, transizioni di 3 secondi e manuale in PDF.\n• Tasti per Ulanzi D200H o Elgato Stream Deck (uno incluso).\n\n💳 *Quanto costa?* Ogni *stile* costa *12 \$ + IVA* e vale per *uno sport* (per esempio, stile Copa · Calcio). Pagamento unico, con 6 mesi di assistenza.\n• 1 stile per 1 sport: 12 \$ + IVA.\n• Gli 8 stili di uno sport: ne paghi 7 (84 \$ + IVA), 1 è in regalo.\n• 1 stile per i 7 sport: ne paghi 6 (72 \$ + IVA), 1 è in regalo.\nCon la tessera socio, ogni 5 acquisti il successivo è in regalo.\nServe vMix su Windows. Il negozio è in spagnolo.\n\nGuarda il video di ogni stile e acquista qui:\n$u\n\nAltre domande? Scrivi *operatore*.",
        ];
    }

    /** Todos los chatbots. Cada texto: [es, en, pt, fr, it]. */
    public static function definiciones(): array
    {
        $D = [];

        // ============================================================ 1. PEDIR DEMO
        $tusalon = self::demo('TuSalón', 'https://tusalon.tukanec.com/?r=registro');
        $tuspa = self::demo('TuSpa', 'https://tuspa.tukanec.com/?r=registro');
        $tugroomer = self::demo('TuGroomer', 'https://tugroomer.tukanec.com/?r=registro');
        $tuguarderia = self::demo('TuGuardería Canina', 'https://tuguarderia.tukanec.com/pedir_demo.php');
        $tulavadora = self::demo('TuLavadora', 'https://tulavadora.tukanec.com/?r=registro');
        $tumecanica = self::demo('TuMecánica', 'https://tumecanica.tukanec.com/?r=registro');
        $tuevento = self::demo('TuEvento', 'https://tuevento.tukanec.com/?r=registro');
        $tuplanner = self::demo('TuPlanner', 'https://tuplanner.tukanec.com/?r=registro');
        $tuhospedaje = self::demo('TuHospedaje', 'https://tuhospedaje.tukanec.com/?r=registro');
        $tudron = [   // (23 nov 2026) TuDron NO tiene demo (decisión del 10 nov): tiene el Plan Inicial sin costo
            "🚁 *TuDron* no tiene demo: empiezas *sin costo* con el Plan Inicial (1 dron, 1 piloto, hasta 20 vuelos, sin vencimiento). Arriba tienes el PDF con los planes.\n\nRegístrate aquí: https://tudron.tukanec.com/?r=registro\n\nSi algo no te funciona, escribe *asesor*.",
            "🚁 *TuDron* has no demo: you start at *no cost* with the Starter Plan (1 drone, 1 pilot, up to 20 flights, no expiry). The PDF above has the plans (in Spanish).\n\nSign up here: https://tudron.tukanec.com/?r=registro\n\nIf anything goes wrong, type *agent*.",
            "🚁 O *TuDron* não tem demonstração: você começa *sem custo* com o Plano Inicial (1 drone, 1 piloto, até 20 voos, sem vencimento). Acima está o PDF com os planos (em espanhol).\n\nCadastre-se aqui: https://tudron.tukanec.com/?r=registro\n\nSe algo não funcionar, escreva *atendente*.",
            "🚁 *TuDron* n'a pas de démo : vous commencez *sans frais* avec le Plan Initial (1 drone, 1 pilote, jusqu'à 20 vols, sans expiration). Le PDF ci-dessus contient les forfaits (en espagnol).\n\nInscrivez-vous ici : https://tudron.tukanec.com/?r=registro\n\nEn cas de souci, écrivez *conseiller*.",
            "🚁 *TuDron* non ha una demo: inizi *senza costi* con il Piano Iniziale (1 drone, 1 pilota, fino a 20 voli, senza scadenza). Qui sopra trovi il PDF con i piani (in spagnolo).\n\nRegistrati qui: https://tudron.tukanec.com/?r=registro\n\nSe qualcosa non funziona, scrivi *operatore*.",
        ];

        $elige = [
            'es' => '¿Cuál programa quieres probar?', 'en' => 'Which program would you like to try?', 'pt' => 'Qual programa você quer testar?',
            'fr' => 'Quel programme voulez-vous essayer ?', 'it' => 'Quale programma vuoi provare?',
        ];
        $pasos = [
            ['id' => 'p1', 'tipo' => 'botones', 'texto' => "¡Hola! 👋 Con gusto te preparamos una *demo de 3 días* con todos los módulos.\n\n¿Qué tipo de negocio tienes?",
             'opciones' => [['Belleza y spa', 'p2'], ['Mascotas', 'p3'], ['Autos', 'p4'], ['Eventos y hoteles', 'p5'], ['Deportes y academias', 'p6'],
                            ['Drones', 'p17'], ['WhatsApp de mi negocio', 'p18'], ['Otro negocio', 'p19']]],
            ['id' => 'p2', 'tipo' => 'botones', 'texto' => "*TuSalón*: peluquerías y barberías.\n*TuSpa*: spas, masajes y tratamientos.\n\n" . $elige['es'],
             'opciones' => [['TuSalón', 'p7'], ['TuSpa', 'p8']]],
            ['id' => 'p3', 'tipo' => 'botones', 'texto' => "*TuGroomer*: peluquería y spa de mascotas.\n*TuGuardería Canina*: guarderías y hoteles de perros.\n\n" . $elige['es'],
             'opciones' => [['TuGroomer', 'p9'], ['TuGuardería Canina', 'p10']]],
            ['id' => 'p4', 'tipo' => 'botones', 'texto' => "*TuLavadora*: lavadoras y lubricadoras de autos.\n*TuMecánica*: mecánicas y talleres.\n\n" . $elige['es'],
             'opciones' => [['TuLavadora', 'p11'], ['TuMecánica', 'p12']]],
            ['id' => 'p5', 'tipo' => 'botones', 'texto' => "*TuEvento*: salones de eventos, quintas y haciendas.\n*TuPlanner*: wedding planners y organizadores de eventos.\n*TuHospedaje*: hoteles, hostales y hosterías.\n\n" . $elige['es'],
             'opciones' => [['TuEvento', 'p13'], ['TuPlanner', 'p14'], ['TuHospedaje', 'p15']]],
            ['id' => 'p6', 'tipo' => 'pregunta', 'guardar' => 'notas', 'siguiente' => 'p16', 'etiqueta' => 'demo deportes', 'etapa' => 'interesado',
             'texto' => "⚽ *TuVocalía* es para ligas, campeonatos y escuelas deportivas, y *TuAcademia* para academias de baile, música y natación. Su demo la prepara una persona del equipo.\n\nCuéntanos en un mensaje: el *nombre* de tu liga, escuela o academia, la *ciudad* y el *deporte o disciplina*."],
            ['id' => 'p7', 'tipo' => 'fin', 'pdf' => 'Tukan_TuSalon_Planes_y_Precios.pdf', 'texto' => $tusalon[0], 'etiqueta' => 'demo tusalon', 'etapa' => 'interesado'],
            ['id' => 'p8', 'tipo' => 'fin', 'pdf' => 'Tukan_TuSpa_Planes_y_Precios.pdf', 'texto' => $tuspa[0], 'etiqueta' => 'demo tuspa', 'etapa' => 'interesado'],
            ['id' => 'p9', 'tipo' => 'fin', 'pdf' => 'Tukan_TuGroomer_Planes_y_Precios.pdf', 'texto' => $tugroomer[0], 'etiqueta' => 'demo tugroomer', 'etapa' => 'interesado'],
            ['id' => 'p10', 'tipo' => 'fin', 'pdf' => 'Tukan_TuGuarderia_Canina_Planes_y_Precios.pdf', 'texto' => $tuguarderia[0], 'etiqueta' => 'demo tuguarderia', 'etapa' => 'interesado'],
            ['id' => 'p11', 'tipo' => 'fin', 'pdf' => 'Tukan_TuLavadora_Planes_y_Precios.pdf', 'texto' => $tulavadora[0], 'etiqueta' => 'demo tulavadora', 'etapa' => 'interesado'],
            ['id' => 'p12', 'tipo' => 'fin', 'pdf' => 'Tukan_TuMecanica_Planes_y_Precios.pdf', 'texto' => $tumecanica[0], 'etiqueta' => 'demo tumecanica', 'etapa' => 'interesado'],
            ['id' => 'p13', 'tipo' => 'fin', 'pdf' => 'Tukan_TuEvento_Planes_y_Precios.pdf', 'texto' => $tuevento[0], 'etiqueta' => 'demo tuevento', 'etapa' => 'interesado'],
            ['id' => 'p14', 'tipo' => 'fin', 'pdf' => 'Tukan_TuPlanner_Planes_y_Precios.pdf', 'texto' => $tuplanner[0], 'etiqueta' => 'demo tuplanner', 'etapa' => 'interesado'],
            ['id' => 'p15', 'tipo' => 'fin', 'pdf' => 'Tukan_TuHospedaje_Planes_y_Precios.pdf', 'texto' => $tuhospedaje[0], 'etiqueta' => 'demo tuhospedaje', 'etapa' => 'interesado'],
            ['id' => 'p16', 'tipo' => 'persona', 'texto' => '¡Gracias! 🙌 En un momento te atiende una persona del equipo para preparar tu demo.'],
            ['id' => 'p17', 'tipo' => 'fin', 'pdf' => 'Tukan_TuDron_Planes_y_Precios.pdf', 'texto' => $tudron[0], 'etiqueta' => 'interesado tudron', 'etapa' => 'interesado'],
            ['id' => 'p18', 'tipo' => 'persona', 'pdf' => 'Tukan_WhatsApp_Tukan_Planes_y_Precios.pdf', 'etiqueta' => 'interesado whatsapp tukan', 'etapa' => 'interesado',
             'texto' => "📲 *WhatsApp Tukán* no tiene demo, porque se conecta al WhatsApp Business de *tu propio número*: bandeja para todo tu equipo, chatbots, campañas, catálogo y pedidos. Arriba tienes el PDF con los planes.\n\nEn un momento una persona del equipo te explica cómo funciona y te ayuda a conectarlo."],
            ['id' => 'p19', 'tipo' => 'pregunta', 'guardar' => 'notas', 'siguiente' => 'p20', 'etiqueta' => 'demo otro', 'etapa' => 'interesado',
             'texto' => 'Cuéntanos qué tipo de negocio tienes y qué te gustaría controlar, y te recomendamos el programa ideal.'],
            ['id' => 'p20', 'tipo' => 'persona', 'texto' => '¡Gracias! 🙌 En un momento te atiende una persona del equipo.'],
        ];
        $tr = [
            'en' => ['palabras' => 'trial, free trial, request a demo, try it', 'pasos' => [
                'p1' => ['texto' => "Hi! 👋 We'll gladly set up a *3-day demo* with every module for you.\n\nWhat kind of business do you have?",
                         'opciones' => ['Beauty and spa', 'Pets', 'Cars', 'Events and hotels', 'Sports and academies', 'Drones', 'WhatsApp for business', 'Other business']],
                'p2' => ['texto' => "*TuSalón*: hair salons and barbershops.\n*TuSpa*: spas, massages and treatments.\n\n" . $elige['en'], 'opciones' => ['TuSalón', 'TuSpa']],
                'p3' => ['texto' => "*TuGroomer*: pet grooming and spa.\n*TuGuardería Canina*: dog daycares and dog hotels.\n\n" . $elige['en'], 'opciones' => ['TuGroomer', 'TuGuardería Canina']],
                'p4' => ['texto' => "*TuLavadora*: car washes and lube shops.\n*TuMecánica*: mechanics and auto repair shops.\n\n" . $elige['en'], 'opciones' => ['TuLavadora', 'TuMecánica']],
                'p5' => ['texto' => "*TuEvento*: event venues and estates.\n*TuPlanner*: wedding planners and event organizers.\n*TuHospedaje*: hotels, hostels and inns.\n\n" . $elige['en'], 'opciones' => ['TuEvento', 'TuPlanner', 'TuHospedaje']],
                'p6' => ['texto' => "⚽ *TuVocalía* is for leagues, tournaments and sports schools, and *TuAcademia* for dance, music and swimming academies. A team member prepares their demo.\n\nTell us in one message: the *name* of your league, school or academy, the *city* and the *sport or discipline*."],
                'p7' => ['texto' => $tusalon[1]], 'p8' => ['texto' => $tuspa[1]], 'p9' => ['texto' => $tugroomer[1]], 'p10' => ['texto' => $tuguarderia[1]],
                'p11' => ['texto' => $tulavadora[1]], 'p12' => ['texto' => $tumecanica[1]], 'p13' => ['texto' => $tuevento[1]], 'p14' => ['texto' => $tuplanner[1]],
                'p15' => ['texto' => $tuhospedaje[1]], 'p17' => ['texto' => $tudron[1]],
                'p16' => ['texto' => 'Thank you! 🙌 Someone from our team will be with you shortly to prepare your demo.'],
                'p18' => ['texto' => "📲 *WhatsApp Tukán* has no demo, because it connects to the WhatsApp Business of *your own number*: one inbox for your whole team, chatbots, campaigns, catalog and orders. The PDF above has the plans (in Spanish).\n\nSomeone from our team will explain how it works and help you connect it shortly."],
                'p19' => ['texto' => 'Tell us what kind of business you have and what you would like to manage, and we will recommend the right program.'],
                'p20' => ['texto' => 'Thank you! 🙌 Someone from our team will be with you shortly.'],
            ]],
            'pt' => ['palabras' => 'demonstracao, teste, quero testar, pedir demonstracao', 'pasos' => [
                'p1' => ['texto' => "Olá! 👋 Preparamos com prazer uma *demonstração de 3 dias* com todos os módulos.\n\nQue tipo de negócio você tem?",
                         'opciones' => ['Beleza e spa', 'Pets', 'Carros', 'Eventos e hotéis', 'Esportes e academias', 'Drones', 'WhatsApp do negócio', 'Outro negócio']],
                'p2' => ['texto' => "*TuSalón*: salões de beleza e barbearias.\n*TuSpa*: spas, massagens e tratamentos.\n\n" . $elige['pt'], 'opciones' => ['TuSalón', 'TuSpa']],
                'p3' => ['texto' => "*TuGroomer*: banho e tosa de pets.\n*TuGuardería Canina*: creches e hotéis para cães.\n\n" . $elige['pt'], 'opciones' => ['TuGroomer', 'TuGuardería Canina']],
                'p4' => ['texto' => "*TuLavadora*: lava-rápidos e troca de óleo.\n*TuMecánica*: oficinas mecânicas.\n\n" . $elige['pt'], 'opciones' => ['TuLavadora', 'TuMecánica']],
                'p5' => ['texto' => "*TuEvento*: salões de festas e chácaras.\n*TuPlanner*: wedding planners e organizadores de eventos.\n*TuHospedaje*: hotéis, hostels e pousadas.\n\n" . $elige['pt'], 'opciones' => ['TuEvento', 'TuPlanner', 'TuHospedaje']],
                'p6' => ['texto' => "⚽ *TuVocalía* é para ligas, campeonatos e escolinhas esportivas, e *TuAcademia* para academias de dança, música e natação. A demonstração é preparada por uma pessoa da equipe.\n\nConte em uma mensagem: o *nome* da sua liga, escola ou academia, a *cidade* e o *esporte ou modalidade*."],
                'p7' => ['texto' => $tusalon[2]], 'p8' => ['texto' => $tuspa[2]], 'p9' => ['texto' => $tugroomer[2]], 'p10' => ['texto' => $tuguarderia[2]],
                'p11' => ['texto' => $tulavadora[2]], 'p12' => ['texto' => $tumecanica[2]], 'p13' => ['texto' => $tuevento[2]], 'p14' => ['texto' => $tuplanner[2]],
                'p15' => ['texto' => $tuhospedaje[2]], 'p17' => ['texto' => $tudron[2]],
                'p16' => ['texto' => 'Obrigado! 🙌 Em instantes uma pessoa da equipe vai te atender para preparar sua demonstração.'],
                'p18' => ['texto' => "📲 O *WhatsApp Tukán* não tem demonstração, porque se conecta ao WhatsApp Business do *seu próprio número*: uma caixa de entrada para toda a equipe, chatbots, campanhas, catálogo e pedidos. Acima está o PDF com os planos (em espanhol).\n\nEm instantes uma pessoa da equipe explica como funciona e te ajuda a conectar."],
                'p19' => ['texto' => 'Conte que tipo de negócio você tem e o que gostaria de controlar, e indicamos o programa ideal.'],
                'p20' => ['texto' => 'Obrigado! 🙌 Em instantes uma pessoa da equipe vai te atender.'],
            ]],
            'fr' => ['palabras' => 'essai, demonstration, essayer, demander une demo', 'pasos' => [
                'p1' => ['texto' => "Bonjour ! 👋 Nous vous préparons volontiers une *démo de 3 jours* avec tous les modules.\n\nQuel type d'entreprise avez-vous ?",
                         'opciones' => ['Beauté et spa', 'Animaux', 'Voitures', 'Événements et hôtels', 'Sports et académies', 'Drones', 'WhatsApp entreprise', 'Autre activité']],
                'p2' => ['texto' => "*TuSalón* : salons de coiffure et barbiers.\n*TuSpa* : spas, massages et soins.\n\n" . $elige['fr'], 'opciones' => ['TuSalón', 'TuSpa']],
                'p3' => ['texto' => "*TuGroomer* : toilettage et spa pour animaux.\n*TuGuardería Canina* : garderies et pensions pour chiens.\n\n" . $elige['fr'], 'opciones' => ['TuGroomer', 'TuGuardería Canina']],
                'p4' => ['texto' => "*TuLavadora* : stations de lavage et vidange.\n*TuMecánica* : garages et ateliers mécaniques.\n\n" . $elige['fr'], 'opciones' => ['TuLavadora', 'TuMecánica']],
                'p5' => ['texto' => "*TuEvento* : salles de réception et domaines.\n*TuPlanner* : wedding planners et organisateurs d'événements.\n*TuHospedaje* : hôtels, auberges et maisons d'hôtes.\n\n" . $elige['fr'], 'opciones' => ['TuEvento', 'TuPlanner', 'TuHospedaje']],
                'p6' => ['texto' => "⚽ *TuVocalía* est pour les ligues, tournois et écoles de sport, et *TuAcademia* pour les académies de danse, musique et natation. Leur démo est préparée par un membre de l'équipe.\n\nIndiquez-nous en un message : le *nom* de votre ligue, école ou académie, la *ville* et le *sport ou la discipline*."],
                'p7' => ['texto' => $tusalon[3]], 'p8' => ['texto' => $tuspa[3]], 'p9' => ['texto' => $tugroomer[3]], 'p10' => ['texto' => $tuguarderia[3]],
                'p11' => ['texto' => $tulavadora[3]], 'p12' => ['texto' => $tumecanica[3]], 'p13' => ['texto' => $tuevento[3]], 'p14' => ['texto' => $tuplanner[3]],
                'p15' => ['texto' => $tuhospedaje[3]], 'p17' => ['texto' => $tudron[3]],
                'p16' => ['texto' => "Merci ! 🙌 Un membre de l'équipe va vous répondre dans un instant pour préparer votre démo."],
                'p18' => ['texto' => "📲 *WhatsApp Tukán* n'a pas de démo, car il se connecte au WhatsApp Business de *votre propre numéro* : une boîte de réception pour toute l'équipe, chatbots, campagnes, catalogue et commandes. Le PDF ci-dessus contient les forfaits (en espagnol).\n\nUn membre de l'équipe va vous expliquer son fonctionnement et vous aider à le connecter."],
                'p19' => ['texto' => "Dites-nous quel type d'entreprise vous avez et ce que vous souhaitez gérer, et nous vous conseillerons le bon programme."],
                'p20' => ['texto' => "Merci ! 🙌 Un membre de l'équipe va vous répondre dans un instant."],
            ]],
            'it' => ['palabras' => 'prova, dimostrazione, provare, richiedere una demo', 'pasos' => [
                'p1' => ['texto' => "Ciao! 👋 Ti prepariamo volentieri una *demo di 3 giorni* con tutti i moduli.\n\nChe tipo di attività hai?",
                         'opciones' => ['Bellezza e spa', 'Animali', 'Auto', 'Eventi e hotel', 'Sport e accademie', 'Droni', 'WhatsApp aziendale', 'Altra attività']],
                'p2' => ['texto' => "*TuSalón*: parrucchieri e barbieri.\n*TuSpa*: spa, massaggi e trattamenti.\n\n" . $elige['it'], 'opciones' => ['TuSalón', 'TuSpa']],
                'p3' => ['texto' => "*TuGroomer*: toelettatura e spa per animali.\n*TuGuardería Canina*: asili e pensioni per cani.\n\n" . $elige['it'], 'opciones' => ['TuGroomer', 'TuGuardería Canina']],
                'p4' => ['texto' => "*TuLavadora*: autolavaggi e cambio olio.\n*TuMecánica*: officine meccaniche.\n\n" . $elige['it'], 'opciones' => ['TuLavadora', 'TuMecánica']],
                'p5' => ['texto' => "*TuEvento*: sale ricevimenti e tenute.\n*TuPlanner*: wedding planner e organizzatori di eventi.\n*TuHospedaje*: hotel, ostelli e agriturismi.\n\n" . $elige['it'], 'opciones' => ['TuEvento', 'TuPlanner', 'TuHospedaje']],
                'p6' => ['texto' => "⚽ *TuVocalía* è per leghe, tornei e scuole sportive, e *TuAcademia* per accademie di danza, musica e nuoto. La demo la prepara una persona del team.\n\nScrivici in un messaggio: il *nome* della tua lega, scuola o accademia, la *città* e lo *sport o disciplina*."],
                'p7' => ['texto' => $tusalon[4]], 'p8' => ['texto' => $tuspa[4]], 'p9' => ['texto' => $tugroomer[4]], 'p10' => ['texto' => $tuguarderia[4]],
                'p11' => ['texto' => $tulavadora[4]], 'p12' => ['texto' => $tumecanica[4]], 'p13' => ['texto' => $tuevento[4]], 'p14' => ['texto' => $tuplanner[4]],
                'p15' => ['texto' => $tuhospedaje[4]], 'p17' => ['texto' => $tudron[4]],
                'p16' => ['texto' => 'Grazie! 🙌 Tra un momento una persona del team ti risponde per preparare la tua demo.'],
                'p18' => ['texto' => "📲 *WhatsApp Tukán* non ha una demo, perché si collega al WhatsApp Business del *tuo numero*: una casella per tutto il team, chatbot, campagne, catalogo e ordini. Qui sopra trovi il PDF con i piani (in spagnolo).\n\nTra un momento una persona del team ti spiega come funziona e ti aiuta a collegarlo."],
                'p19' => ['texto' => 'Raccontaci che tipo di attività hai e cosa vorresti gestire, e ti consigliamo il programma giusto.'],
                'p20' => ['texto' => 'Grazie! 🙌 Tra un momento una persona del team ti risponde.'],
            ]],
        ];
        $D[] = ['nombre' => 'Pedir demo', 'palabras' => 'demo, demos, una demo, quiero una demo, pedir demo, solicitar demo, prueba del programa, demostracion, demostraciones, una demostracion, quiero una demostracion, demo gratis, prueba gratis, version de prueba, quiero probar', 'pasos' => $pasos, 'tr' => $tr];

        // ============================================================ 2. PRECIOS Y PLANES (precios sin IVA, 12 nov 2026) — con el PDF de cada programa
        $sede = "\n• Cada sede extra: \$10 al mes.|\n• Each extra location: \$10 per month.|\n• Cada unidade extra: US\$ 10 por mês.|\n• Chaque site en plus : 10 \$ par mois.|\n• Ogni sede in più: 10 \$ al mese.";
        $P = [
            'p7' => ['TuSalon', self::precioModular('TuSalón', true)],
            'p8' => ['TuSpa', self::precioModular('TuSpa', true)],
            'p9' => ['TuGroomer', self::precioModular('TuGroomer', true, $sede)],
            'p10' => ['TuGuarderia_Canina', self::precioModular('TuGuardería Canina', false, $sede)],
            'p11' => ['TuLavadora', self::precioModular('TuLavadora', true, $sede)],
            'p12' => ['TuMecanica', self::precioModular('TuMecánica', true, $sede)],
            'p14' => ['TuEvento', self::precioModular('TuEvento', true)],
            'p15' => ['TuPlanner', self::precioModular('TuPlanner', true)],
            'p17' => ['TuVocalia', self::precioModular('TuVocalía', true,
                "\n• Para ligas los planes son Básico y Profesional; para escuelas, Escuela Básico y Escuela Completo. Cada deporte adicional: \$7 al mes."
                . "|\n• Leagues: Basic and Professional; schools: School Basic and School Complete. Each extra sport: \$7 per month."
                . "|\n• Ligas: Básico e Profissional; escolas: Escola Básico e Escola Completo. Cada esporte adicional: US\$ 7 por mês."
                . "|\n• Ligues : Basique et Professionnel ; écoles : École Basique et École Complet. Chaque sport en plus : 7 \$ par mois."
                . "|\n• Leghe: Base e Professionale; scuole: Scuola Base e Scuola Completo. Ogni sport in più: 7 \$ al mese.")],
            'p18' => ['TuAcademia', self::precioModular('TuAcademia', true,
                "\n• Cada disciplina adicional: \$7 al mes.|\n• Each extra discipline: \$7 per month.|\n• Cada modalidade adicional: US\$ 7 por mês.|\n• Chaque discipline en plus : 7 \$ par mois.|\n• Ogni disciplina in più: 7 \$ al mese.")],
        ];
        $arriba = ["📄 Arriba tienes el PDF con todos los detalles.\n\n", "📄 Above is the PDF with all the details (in Spanish).\n\n", "📄 Acima está o PDF com todos os detalhes (em espanhol).\n\n",
                   "📄 Ci-dessus, le PDF avec tous les détails (en espagnol).\n\n", "📄 Qui sopra trovi il PDF con tutti i dettagli (in spagnolo).\n\n"];
        $esp = [   // programas con planes propios: [archivo, [es, en, pt, fr, it]]
            'p13' => ['Flota_Tukan', [
                "*Flota Tukán* (gasolina, gastos y mantenimiento de tus vehículos)\n\n• \$5 + IVA al mes: hasta 5 vehículos y 5 conductores, todo incluido.\n🎁 Pagando 6 meses pagas 5, y pagando el año pagas 10.\n\nEscribe *asesor* y te preparamos una demo de 3 días.",
                "*Flota Tukán* (fuel, expenses and maintenance of your vehicles)\n\n• \$5 + VAT per month: up to 5 vehicles and 5 drivers, everything included.\n🎁 Pay 6 months and get 1 free; pay the year and get 2 free.\n\nType *agent* and we will set up a 3-day demo for you.",
                "*Flota Tukán* (combustível, gastos e manutenção dos seus veículos)\n\n• US\$ 5 + impostos por mês: até 5 veículos e 5 motoristas, tudo incluído.\n🎁 Pagando 6 meses você paga 5, e pagando o ano paga 10.\n\nEscreva *atendente* e preparamos uma demonstração de 3 dias.",
                "*Flota Tukán* (carburant, dépenses et entretien de vos véhicules)\n\n• 5 \$ + TVA par mois : jusqu'à 5 véhicules et 5 conducteurs, tout compris.\n🎁 6 mois payés = 1 mois offert ; l'année payée = 2 mois offerts.\n\nÉcrivez *conseiller* et nous vous préparons une démo de 3 jours.",
                "*Flota Tukán* (carburante, spese e manutenzione dei tuoi veicoli)\n\n• 5 \$ + IVA al mese: fino a 5 veicoli e 5 autisti, tutto incluso.\n🎁 Pagando 6 mesi ne paghi 5, pagando l'anno ne paghi 10.\n\nScrivi *operatore* e ti prepariamo una demo di 3 giorni."]],
            'p16' => ['TuHospedaje', [
                "*TuHospedaje* (hoteles, hostales y hosterías)\n\n• *Hotel / Hostería:* \$33,99 + IVA al mes, hasta 30 habitaciones.\n• *Hostería Total:* \$55 + IVA al mes, hasta 50 habitaciones, todo incluido con IA.\n\n🎁 Pagando 6 meses pagas 5, y pagando el año pagas 10.\n\nPruébalo 3 días: escribe *demo*.",
                "*TuHospedaje* (hotels, hostels and inns)\n\n• *Hotel / Inn:* \$33.99 + VAT per month, up to 30 rooms.\n• *Inn Total:* \$55 + VAT per month, up to 50 rooms, everything included with AI.\n\n🎁 Pay 6 months and get 1 free; pay the year and get 2 free.\n\nTry it for 3 days: type *demo*.",
                "*TuHospedaje* (hotéis, hostels e pousadas)\n\n• *Hotel / Pousada:* US\$ 33,99 + impostos por mês, até 30 quartos.\n• *Pousada Total:* US\$ 55 + impostos por mês, até 50 quartos, tudo incluído com IA.\n\n🎁 Pagando 6 meses você paga 5, e pagando o ano paga 10.\n\nTeste por 3 dias: escreva *demo*.",
                "*TuHospedaje* (hôtels, auberges et maisons d'hôtes)\n\n• *Hôtel / Auberge :* 33,99 \$ + TVA par mois, jusqu'à 30 chambres.\n• *Auberge Total :* 55 \$ + TVA par mois, jusqu'à 50 chambres, tout compris avec l'IA.\n\n🎁 6 mois payés = 1 mois offert ; l'année payée = 2 mois offerts.\n\nEssayez-le 3 jours : écrivez *demo*.",
                "*TuHospedaje* (hotel, ostelli e agriturismi)\n\n• *Hotel / Locanda:* 33,99 \$ + IVA al mese, fino a 30 camere.\n• *Locanda Total:* 55 \$ + IVA al mese, fino a 50 camere, tutto incluso con IA.\n\n🎁 Pagando 6 mesi ne paghi 5, pagando l'anno ne paghi 10.\n\nProvalo per 3 giorni: scrivi *demo*."]],
            'p20' => ['WhatsApp_Tukan', [
                "*WhatsApp Tukán* (la API oficial de WhatsApp para tu negocio)\n\n• *Todo incluido:* \$15 + IVA al mes: bandeja para tu equipo, chatbots, campañas, catálogo y pedidos.\n• *Todo incluido + IA:* \$20 + IVA al mes: además, un agente con IA contesta solo las 24 horas.\n\nMes a mes, sin contrato. Los mensajes que cobra Meta se pagan directo a Meta.\n\nEscribe *asesor* y te explicamos cómo conectarlo a tu número.",
                "*WhatsApp Tukán* (the official WhatsApp API for your business)\n\n• *All included:* \$15 + VAT per month: inbox for your team, chatbots, campaigns, catalog and orders.\n• *All included + AI:* \$20 + VAT per month: plus an AI agent that replies on its own 24/7.\n\nMonth to month, no contract. Messages charged by Meta are paid directly to Meta.\n\nType *agent* and we will explain how to connect it to your number.",
                "*WhatsApp Tukán* (a API oficial do WhatsApp para o seu negócio)\n\n• *Tudo incluído:* US\$ 15 + impostos por mês: caixa de entrada para a equipe, chatbots, campanhas, catálogo e pedidos.\n• *Tudo incluído + IA:* US\$ 20 + impostos por mês: além disso, um agente de IA responde sozinho 24 horas.\n\nMês a mês, sem contrato. As mensagens cobradas pela Meta são pagas direto à Meta.\n\nEscreva *atendente* e explicamos como conectar ao seu número.",
                "*WhatsApp Tukán* (l'API officielle de WhatsApp pour votre entreprise)\n\n• *Tout compris :* 15 \$ + TVA par mois : boîte de réception pour l'équipe, chatbots, campagnes, catalogue et commandes.\n• *Tout compris + IA :* 20 \$ + TVA par mois : en plus, un agent IA répond tout seul 24 h/24.\n\nSans engagement, mois par mois. Les messages facturés par Meta se paient directement à Meta.\n\nÉcrivez *conseiller* et nous vous expliquons comment le connecter à votre numéro.",
                "*WhatsApp Tukán* (l'API ufficiale di WhatsApp per la tua attività)\n\n• *Tutto incluso:* 15 \$ + IVA al mese: casella per il team, chatbot, campagne, catalogo e ordini.\n• *Tutto incluso + IA:* 20 \$ + IVA al mese: in più un agente IA risponde da solo 24 ore su 24.\n\nMese per mese, senza contratto. I messaggi addebitati da Meta si pagano direttamente a Meta.\n\nScrivi *operatore* e ti spieghiamo come collegarlo al tuo numero."]],
            'p21' => ['TuDron', [
                "*TuDron* (para operadores de drones)\n\n• *Plan Inicial:* sin costo (1 dron, 1 piloto, hasta 20 vuelos).\n• *Plan TuDron:* \$12,99 + IVA al mes: hasta 10 drones, pilotos y vuelos ilimitados y 10 ha de mapas al mes.\n\nRegístrate aquí: https://tudron.tukanec.com/?r=registro",
                "*TuDron* (for drone operators)\n\n• *Starter Plan:* no cost (1 drone, 1 pilot, up to 20 flights).\n• *TuDron Plan:* \$12.99 + VAT per month: up to 10 drones, unlimited pilots and flights, and 10 ha of maps per month.\n\nSign up here: https://tudron.tukanec.com/?r=registro",
                "*TuDron* (para operadores de drones)\n\n• *Plano Inicial:* sem custo (1 drone, 1 piloto, até 20 voos).\n• *Plano TuDron:* US\$ 12,99 + impostos por mês: até 10 drones, pilotos e voos ilimitados e 10 ha de mapas por mês.\n\nCadastre-se aqui: https://tudron.tukanec.com/?r=registro",
                "*TuDron* (pour les opérateurs de drones)\n\n• *Plan Initial :* sans frais (1 drone, 1 pilote, jusqu'à 20 vols).\n• *Plan TuDron :* 12,99 \$ + TVA par mois : jusqu'à 10 drones, pilotes et vols illimités et 10 ha de cartes par mois.\n\nInscrivez-vous ici : https://tudron.tukanec.com/?r=registro",
                "*TuDron* (per operatori di droni)\n\n• *Piano Iniziale:* senza costi (1 drone, 1 pilota, fino a 20 voli).\n• *Piano TuDron:* 12,99 \$ + IVA al mese: fino a 10 droni, piloti e voli illimitati e 10 ha di mappe al mese.\n\nRegistrati qui: https://tudron.tukanec.com/?r=registro"]],
            'p22' => ['TuBroadcast', [
                "*TuBroadcast* (transmite en vivo desde tu celular)\n\n• *Básico:* \$5,99 + IVA al mes por celular.\n• *Completo:* \$9,99 + IVA al mes por celular (SRT, grabación y control remoto).\n• *Pase de 24 horas:* \$3 + IVA por celular, ideal para un evento.\n\nCómpralo aquí: https://tuner.tukanec.com/?r=comprar",
                "*TuBroadcast* (stream live from your phone)\n\n• *Basic:* \$5.99 + VAT per month per phone.\n• *Complete:* \$9.99 + VAT per month per phone (SRT, recording and remote control).\n• *24-hour pass:* \$3 + VAT per phone, ideal for a single event.\n\nBuy it here: https://tuner.tukanec.com/?r=comprar",
                "*TuBroadcast* (transmita ao vivo pelo celular)\n\n• *Básico:* US\$ 5,99 + impostos por mês por celular.\n• *Completo:* US\$ 9,99 + impostos por mês por celular (SRT, gravação e controle remoto).\n• *Passe de 24 horas:* US\$ 3 + impostos por celular, ideal para um evento.\n\nCompre aqui: https://tuner.tukanec.com/?r=comprar",
                "*TuBroadcast* (diffusez en direct depuis votre téléphone)\n\n• *Basique :* 5,99 \$ + TVA par mois et par téléphone.\n• *Complet :* 9,99 \$ + TVA par mois et par téléphone (SRT, enregistrement et contrôle à distance).\n• *Pass 24 heures :* 3 \$ + TVA par téléphone, idéal pour un événement.\n\nAchetez-le ici : https://tuner.tukanec.com/?r=comprar",
                "*TuBroadcast* (trasmetti in diretta dal telefono)\n\n• *Base:* 5,99 \$ + IVA al mese per telefono.\n• *Completo:* 9,99 \$ + IVA al mese per telefono (SRT, registrazione e controllo remoto).\n• *Pass 24 ore:* 3 \$ + IVA per telefono, ideale per un evento.\n\nAcquistalo qui: https://tuner.tukanec.com/?r=comprar"]],
        ];
        foreach ($esp as $k => [$a, $t]) {
            $P[$k] = [$a, array_map(fn ($x, $y) => $x . $y, $arriba, $t)];
        }
        $de = ['¿De cuál quieres los precios?', 'Which one would you like prices for?', 'De qual você quer os preços?', 'Pour lequel voulez-vous les prix ?', 'Di quale vuoi i prezzi?'];
        $grupos = [   // submenús: [opciones, [descripción es, en, pt, fr, it]]
            'p2' => [[['TuSalón', 'p7'], ['TuSpa', 'p8']], ["*TuSalón*: peluquerías y barberías.\n*TuSpa*: spas, masajes y tratamientos.", "*TuSalón*: hair salons and barbershops.\n*TuSpa*: spas, massages and treatments.",
                "*TuSalón*: salões de beleza e barbearias.\n*TuSpa*: spas, massagens e tratamentos.", "*TuSalón* : salons de coiffure et barbiers.\n*TuSpa* : spas, massages et soins.", "*TuSalón*: parrucchieri e barbieri.\n*TuSpa*: spa, massaggi e trattamenti."]],
            'p3' => [[['TuGroomer', 'p9'], ['TuGuardería Canina', 'p10']], ["*TuGroomer*: peluquería y spa de mascotas.\n*TuGuardería Canina*: guarderías y hoteles de perros.", "*TuGroomer*: pet grooming and spa.\n*TuGuardería Canina*: dog daycares and dog hotels.",
                "*TuGroomer*: banho e tosa de pets.\n*TuGuardería Canina*: creches e hotéis para cães.", "*TuGroomer* : toilettage et spa pour animaux.\n*TuGuardería Canina* : garderies et pensions pour chiens.", "*TuGroomer*: toelettatura e spa per animali.\n*TuGuardería Canina*: asili e pensioni per cani."]],
            'p4' => [[['TuLavadora', 'p11'], ['TuMecánica', 'p12'], ['Flota Tukán', 'p13']], ["*TuLavadora*: lavadoras y lubricadoras de autos.\n*TuMecánica*: mecánicas y talleres.\n*Flota Tukán*: gasolina, gastos y mantenimiento de tus vehículos.",
                "*TuLavadora*: car washes and lube shops.\n*TuMecánica*: mechanics and auto repair shops.\n*Flota Tukán*: fuel, expenses and maintenance of your vehicles.",
                "*TuLavadora*: lava-rápidos e troca de óleo.\n*TuMecánica*: oficinas mecânicas.\n*Flota Tukán*: combustível, gastos e manutenção dos seus veículos.",
                "*TuLavadora* : stations de lavage et vidange.\n*TuMecánica* : garages et ateliers mécaniques.\n*Flota Tukán* : carburant, dépenses et entretien de vos véhicules.",
                "*TuLavadora*: autolavaggi e cambio olio.\n*TuMecánica*: officine meccaniche.\n*Flota Tukán*: carburante, spese e manutenzione dei tuoi veicoli."]],
            'p5' => [[['TuEvento', 'p14'], ['TuPlanner', 'p15'], ['TuHospedaje', 'p16']], ["*TuEvento*: salones de eventos, quintas y haciendas.\n*TuPlanner*: wedding planners y organizadores de eventos.\n*TuHospedaje*: hoteles, hostales y hosterías.",
                "*TuEvento*: event venues and estates.\n*TuPlanner*: wedding planners and event organizers.\n*TuHospedaje*: hotels, hostels and inns.",
                "*TuEvento*: salões de festas e chácaras.\n*TuPlanner*: wedding planners e organizadores de eventos.\n*TuHospedaje*: hotéis, hostels e pousadas.",
                "*TuEvento* : salles de réception et domaines.\n*TuPlanner* : wedding planners et organisateurs d'événements.\n*TuHospedaje* : hôtels, auberges et maisons d'hôtes.",
                "*TuEvento*: sale ricevimenti e tenute.\n*TuPlanner*: wedding planner e organizzatori di eventi.\n*TuHospedaje*: hotel, ostelli e agriturismi."]],
            'p6' => [[['TuVocalía', 'p17'], ['TuAcademia', 'p18']], ["*TuVocalía*: ligas, campeonatos y escuelas deportivas.\n*TuAcademia*: academias de baile, música y natación.",
                "*TuVocalía*: leagues, tournaments and sports schools.\n*TuAcademia*: dance, music and swimming academies.",
                "*TuVocalía*: ligas, campeonatos e escolinhas esportivas.\n*TuAcademia*: academias de dança, música e natação.",
                "*TuVocalía* : ligues, tournois et écoles de sport.\n*TuAcademia* : académies de danse, musique et natation.",
                "*TuVocalía*: leghe, tornei e scuole sportive.\n*TuAcademia*: accademie di danza, musica e nuoto."]],
        ];
        $pasos = [['id' => 'p1', 'tipo' => 'botones', 'texto' => "💰 Con gusto te cuento los precios y te mando el PDF con todos los detalles.\n\n¿De qué tipo de negocio es?",
                   'opciones' => [['Belleza y spa', 'p2'], ['Mascotas', 'p3'], ['Autos y flotas', 'p4'], ['Eventos y hoteles', 'p5'], ['Deportes y academias', 'p6'],
                                  ['WhatsApp Tukán', 'p20'], ['TuDron', 'p21'], ['TuBroadcast', 'p22'], ['Gráficos vMix', 'p24'], ['Hablar con asesor', 'p23']]]];
        foreach ($grupos as $id => [$ops, $txt]) {
            $pasos[] = ['id' => $id, 'tipo' => 'botones', 'texto' => $txt[0] . "\n\n" . $de[0], 'opciones' => $ops];
        }
        foreach ($P as $id => [$archivo, $t]) {
            $pasos[] = ['id' => $id, 'tipo' => 'fin', 'pdf' => 'Tukan_' . $archivo . '_Planes_y_Precios.pdf', 'texto' => $t[0], 'etiqueta' => 'precios', 'etapa' => 'interesado'];
        }
        $pasos[] = ['id' => 'p23', 'tipo' => 'persona', 'texto' => '¡Claro! 🙌 En un momento te atiende una persona del equipo.'];
        $vmix = self::tiendaVmix();
        $pasos[] = ['id' => 'p24', 'tipo' => 'fin', 'texto' => $vmix[0], 'etiqueta' => 'tienda vmix', 'etapa' => 'interesado'];   // (24 nov 2026) sin PDF: la tienda tiene videos
        $L = ['en' => 1, 'pt' => 2, 'fr' => 3, 'it' => 4];
        $p1 = [
            'en' => ["💰 Happy to tell you our prices and send you the PDF with all the details.\n\nWhat kind of business is it?", ['Beauty and spa', 'Pets', 'Cars and fleets', 'Events and hotels', 'Sports and academies', 'WhatsApp Tukán', 'TuDron', 'TuBroadcast', 'vMix graphics', 'Talk to an agent']],
            'pt' => ["💰 Com prazer te conto os preços e envio o PDF com todos os detalhes.\n\nDe que tipo de negócio é?", ['Beleza e spa', 'Pets', 'Carros e frotas', 'Eventos e hotéis', 'Esportes e academias', 'WhatsApp Tukán', 'TuDron', 'TuBroadcast', 'Gráficos vMix', 'Falar com atendente']],
            'fr' => ["💰 Avec plaisir, voici nos prix et le PDF avec tous les détails.\n\nQuel type d'entreprise est-ce ?", ['Beauté et spa', 'Animaux', 'Voitures et flottes', 'Événements et hôtels', 'Sports et académies', 'WhatsApp Tukán', 'TuDron', 'TuBroadcast', 'Habillages vMix', 'Parler à un conseiller']],
            'it' => ["💰 Volentieri ti dico i prezzi e ti mando il PDF con tutti i dettagli.\n\nChe tipo di attività è?", ['Bellezza e spa', 'Animali', 'Auto e flotte', 'Eventi e hotel', 'Sport e accademie', 'WhatsApp Tukán', 'TuDron', 'TuBroadcast', 'Grafiche vMix', 'Parla con operatore']],
        ];
        $p23 = ['en' => 'Of course! 🙌 Someone from our team will be with you shortly.', 'pt' => 'Claro! 🙌 Em instantes uma pessoa da equipe vai te atender.',
                'fr' => "Bien sûr ! 🙌 Un membre de l'équipe va vous répondre dans un instant.", 'it' => 'Certo! 🙌 Tra un momento una persona del team ti risponde.'];
        $palabras = ['en' => 'price, prices, pricing, cost, how much, plans, fee, fees', 'pt' => 'preco, precos, quanto custa, valor, valores, planos, mensalidade',
                     'fr' => 'prix, tarif, tarifs, combien, combien ca coute, forfaits, abonnement', 'it' => 'prezzo, prezzi, quanto costa, costo, piani, tariffe, abbonamento'];
        $tr = [];
        foreach ($L as $l => $n) {
            $ps = ['p1' => ['texto' => $p1[$l][0], 'opciones' => $p1[$l][1]], 'p23' => ['texto' => $p23[$l]], 'p24' => ['texto' => $vmix[$n]]];
            foreach ($grupos as $id => [$ops, $txt]) {
                $ps[$id] = ['texto' => $txt[$n] . "\n\n" . $de[$n], 'opciones' => array_column($ops, 0)];
            }
            foreach ($P as $id => [, $t]) {
                $ps[$id] = ['texto' => $t[$n]];
            }
            $tr[$l] = ['palabras' => $palabras[$l], 'pasos' => $ps];
        }
        $D[] = ['nombre' => 'Precios y planes', 'palabras' => 'precio, precios, costo, costos, cuanto cuesta, cuanto vale, cuanto sale, planes, tarifa, tarifas, mensualidad', 'pasos' => $pasos, 'tr' => $tr];

        // ============================================================ 3. SOPORTE
        $pasos = [
            ['id' => 'p1', 'tipo' => 'botones', 'texto' => "Hola 👋 Estamos para ayudarte. ¿Qué necesitas?",
             'opciones' => [['Tengo un problema', 'p2'], ['Olvidé mi clave', 'p4'], ['Grupo de ayuda', 'p5']]],
            ['id' => 'p2', 'tipo' => 'pregunta', 'guardar' => 'notas', 'siguiente' => 'p3', 'etiqueta' => 'soporte',
             'texto' => "Cuéntanos en un solo mensaje *qué programa usas* y *qué pasa*. Después, si puedes, envía una foto de la pantalla."],
            ['id' => 'p3', 'tipo' => 'persona', 'texto' => "¡Gracias! 🙏 Ya lo pasamos al equipo de soporte. Te contestamos lo antes posible en horario de oficina (Ecuador)."],
            ['id' => 'p4', 'tipo' => 'pregunta', 'guardar' => 'notas', 'siguiente' => 'p3', 'etiqueta' => 'soporte',
             'texto' => "Te ayudamos a recuperarla 🔑 Escríbenos *qué programa usas* y *el correo con el que entras*."],
            ['id' => 'p5', 'tipo' => 'fin', 'texto' => "Únete a nuestro grupo de ayuda en Telegram, donde respondemos dudas y avisamos las novedades:\nhttps://t.me/+dQfU0W9yvo85MzRh"],
        ];
        $tr = [
            'en' => ['palabras' => 'support, help desk, i have a problem, i have an issue, i get an error, getting an error, not working, doesnt work, cant log in, forgot my password', 'pasos' => [
                'p1' => ['texto' => 'Hi 👋 We are here to help. What do you need?', 'opciones' => ['I have a problem', 'Forgot my password', 'Help group']],
                'p2' => ['texto' => "Tell us in one message *which program you use* and *what is happening*. Then, if you can, send a screenshot."],
                'p3' => ['texto' => 'Thank you! 🙏 We have passed it to our support team. We will reply as soon as possible during office hours (Ecuador time).'],
                'p4' => ['texto' => "We'll help you recover it 🔑 Tell us *which program you use* and *the email you log in with*."],
                'p5' => ['texto' => "Join our help group on Telegram, where we answer questions and share news:\nhttps://t.me/+dQfU0W9yvo85MzRh"],
            ]],
            'pt' => ['palabras' => 'suporte, tenho um problema, da erro, aparece um erro, nao funciona, nao consigo entrar, esqueci minha senha', 'pasos' => [
                'p1' => ['texto' => 'Olá 👋 Estamos aqui para ajudar. Do que você precisa?', 'opciones' => ['Tenho um problema', 'Esqueci minha senha', 'Grupo de ajuda']],
                'p2' => ['texto' => "Conte em uma só mensagem *qual programa você usa* e *o que está acontecendo*. Depois, se puder, envie uma foto da tela."],
                'p3' => ['texto' => 'Obrigado! 🙏 Já passamos para a equipe de suporte. Respondemos o quanto antes em horário comercial (Equador).'],
                'p4' => ['texto' => "Vamos te ajudar a recuperá-la 🔑 Escreva *qual programa você usa* e *o e-mail com que entra*."],
                'p5' => ['texto' => "Entre no nosso grupo de ajuda no Telegram, onde respondemos dúvidas e avisamos as novidades:\nhttps://t.me/+dQfU0W9yvo85MzRh"],
            ]],
            'fr' => ['palabras' => 'support, assistance, j ai un probleme, j ai une erreur, ne marche pas, ne fonctionne pas, mot de passe oublie', 'pasos' => [
                'p1' => ['texto' => 'Bonjour 👋 Nous sommes là pour vous aider. De quoi avez-vous besoin ?', 'opciones' => ["J'ai un problème", 'Mot de passe oublié', "Groupe d'aide"]],
                'p2' => ['texto' => "Dites-nous en un seul message *quel programme vous utilisez* et *ce qui se passe*. Ensuite, si possible, envoyez une capture d'écran."],
                'p3' => ['texto' => "Merci ! 🙏 Nous l'avons transmis à l'équipe d'assistance. Nous répondons au plus vite pendant les heures de bureau (heure de l'Équateur)."],
                'p4' => ['texto' => "Nous allons vous aider à le récupérer 🔑 Indiquez *quel programme vous utilisez* et *l'e-mail avec lequel vous vous connectez*."],
                'p5' => ['texto' => "Rejoignez notre groupe d'aide sur Telegram, où nous répondons aux questions et annonçons les nouveautés :\nhttps://t.me/+dQfU0W9yvo85MzRh"],
            ]],
            'it' => ['palabras' => 'supporto, assistenza, ho un problema, mi da errore, non funziona, non riesco ad accedere, password dimenticata', 'pasos' => [
                'p1' => ['texto' => 'Ciao 👋 Siamo qui per aiutarti. Di cosa hai bisogno?', 'opciones' => ['Ho un problema', 'Password dimenticata', 'Gruppo di aiuto']],
                'p2' => ['texto' => "Scrivici in un solo messaggio *quale programma usi* e *cosa succede*. Poi, se puoi, invia una foto dello schermo."],
                'p3' => ['texto' => "Grazie! 🙏 L'abbiamo passato al team di assistenza. Ti rispondiamo il prima possibile in orario d'ufficio (Ecuador)."],
                'p4' => ['texto' => "Ti aiutiamo a recuperarla 🔑 Scrivici *quale programma usi* e *l'email con cui accedi*."],
                'p5' => ['texto' => "Unisciti al nostro gruppo di aiuto su Telegram, dove rispondiamo ai dubbi e annunciamo le novità:\nhttps://t.me/+dQfU0W9yvo85MzRh"],
            ]],
        ];
        $D[] = ['nombre' => 'Soporte', 'palabras' => 'soporte, soporte tecnico, tengo un problema, tengo problemas, me sale error, sale error, me da error, da error, no funciona, no me funciona, no puedo entrar, no me deja entrar, tengo una falla, olvide mi clave, olvide mi contrasena', 'pasos' => $pasos, 'tr' => $tr];

        // ============================================================ 4. HABLAR CON UN ASESOR
        $pasos = [
            ['id' => 'p1', 'tipo' => 'persona', 'etiqueta' => 'pidio asesor',
             'texto' => "¡Claro! 🙌 En un momento te atiende una persona del equipo. Si es fuera del horario de oficina (Ecuador), te contestamos apenas abramos."],
        ];
        $tr = [
            'en' => ['palabras' => 'agent, human, real person, talk to someone, talk to a person, representative, call me', 'pasos' => [
                'p1' => ['texto' => 'Of course! 🙌 Someone from our team will be with you shortly. If it is outside office hours (Ecuador time), we will reply as soon as we open.']]],
            'pt' => ['palabras' => 'atendente, humano, falar com alguem, falar com uma pessoa, me liga', 'pasos' => [
                'p1' => ['texto' => 'Claro! 🙌 Em instantes uma pessoa da equipe vai te atender. Se for fora do horário comercial (Equador), respondemos assim que abrirmos.']]],
            'fr' => ['palabras' => 'conseiller, conseillere, humain, parler a quelqu un, parler a une personne, appelez moi', 'pasos' => [
                'p1' => ['texto' => "Bien sûr ! 🙌 Un membre de l'équipe va vous répondre dans un instant. En dehors des heures de bureau (heure de l'Équateur), nous répondons dès l'ouverture."]]],
            'it' => ['palabras' => 'operatore, consulente, umano, parlare con qualcuno, parlare con una persona, chiamatemi', 'pasos' => [
                'p1' => ['texto' => "Certo! 🙌 Tra un momento una persona del team ti risponde. Se è fuori orario d'ufficio (Ecuador), ti rispondiamo appena apriamo."]]],
        ];
        $D[] = ['nombre' => 'Hablar con un asesor', 'palabras' => 'asesor, asesora, humano, hablar con alguien, hablar con una persona, hablar con un asesor, llamenme, llamame', 'pasos' => $pasos, 'tr' => $tr];

        // ============================================================ 5. TIENDA GRÁFICOS vMIX (24 nov 2026, pedido de Dimitry)
        // Lo arranca el botón «¿Dudas? Escríbenos» de tienda.tukansoftware.com («…los gráficos para vMix de la Tienda Tukán…»
        // y, desde Mis compras, «compré en la Tienda Tukán (pedido N° …)»), o quien escriba vmix, marcador vmix…
        $u = 'https://tienda.tukansoftware.com';
        $pasos = [
            ['id' => 'p1', 'tipo' => 'botones', 'texto' => "¡Hola! 👋 Gracias por escribir a la *Tienda Tukán* (gráficos para vMix). ¿En qué te ayudamos?",
             'opciones' => [['Qué trae y precio', 'p2'], ['Cómo comprar', 'p3'], ['Ya compré: ayuda', 'p4'], ['Cómo se instala', 'p5'], ['Hablar con asesor', 'p7']]],
            ['id' => 'p2', 'tipo' => 'fin', 'texto' => $vmix[0], 'etiqueta' => 'tienda vmix', 'etapa' => 'interesado'],
            ['id' => 'p3', 'tipo' => 'fin', 'etiqueta' => 'tienda vmix', 'etapa' => 'interesado',
             'texto' => "🛒 Así compras:\n1. Entra a $u y elige el estilo y el deporte.\n2. Crea tu cuenta con tu correo y una contraseña.\n3. Paga con tarjeta (en dólares).\n4. Al instante te llega un correo y bajas todo en *Mis compras*: un solo zip con UTC Edición QF, tu Diseñador, los paneles y el manual.\n\nPor ser un producto digital que se entrega al instante, no hay reembolsos. Si tienes otra duda, escribe *asesor*."],
            ['id' => 'p4', 'tipo' => 'pregunta', 'guardar' => 'notas', 'siguiente' => 'p6', 'etiqueta' => 'soporte tienda',
             'texto' => "Con gusto te ayudamos 🙌 Cuéntanos en un solo mensaje tu *número de pedido* (o el correo de tu cuenta) y *qué pasa*. Si puedes, después envía una foto de la pantalla."],
            ['id' => 'p5', 'tipo' => 'fin', 'etiqueta' => 'tienda vmix',
             'texto' => "🛠️ La guía paso a paso está aquí:\n$u/instalar\n\nEn resumen: descomprime el zip en una carpeta fija (por ejemplo C:\\UTC QF) y abre primero «LEEME - Empieza aqui.txt». Después abre vMix y luego vMixController.exe. Todo viene explicado en el *Manual de operaciones QF.pdf*.\n\nSi te trabas, escribe *asesor*."],
            ['id' => 'p6', 'tipo' => 'persona', 'texto' => "¡Gracias! 🙏 Ya lo pasamos al equipo. Te contestamos lo antes posible en horario de oficina (Ecuador)."],
            ['id' => 'p7', 'tipo' => 'persona', 'etiqueta' => 'pidio asesor', 'texto' => "¡Claro! 🙌 En un momento te atiende una persona del equipo."],
        ];
        $tr = [
            'en' => ['palabras' => 'vmix, vmix graphics, vmix scoreboard, tukan store, graphics store', 'pasos' => [
                'p1' => ['texto' => "Hi! 👋 Thanks for writing to the *Tukán Store* (vMix graphics). How can we help?",
                         'opciones' => ["What's in it and price", 'How to buy', 'I bought: need help', 'How to install', 'Talk to an agent']],
                'p2' => ['texto' => $vmix[1]],
                'p3' => ['texto' => "🛒 How to buy:\n1. Go to $u and choose the style and the sport.\n2. Create your account with your email and a password.\n3. Pay by card (in US dollars).\n4. You instantly get an email and download everything in *Mis compras* (My purchases): one zip with UTC Edición QF, your Designer, the panels and the manual.\n\nAs a digital product delivered instantly, there are no refunds. Any other question? Type *agent*."],
                'p4' => ['texto' => "Happy to help 🙌 Tell us in one message your *order number* (or your account email) and *what is happening*. If you can, then send a screenshot."],
                'p5' => ['texto' => "🛠️ The step-by-step guide is here (in Spanish):\n$u/instalar\n\nIn short: unzip the file into a fixed folder (for example C:\\UTC QF) and open «LEEME - Empieza aqui.txt» first. Then open vMix and after that vMixController.exe. Everything is explained in the *Manual de operaciones QF.pdf*.\n\nIf you get stuck, type *agent*."],
                'p6' => ['texto' => 'Thank you! 🙏 We have passed it to our team. We will reply as soon as possible during office hours (Ecuador time).'],
                'p7' => ['texto' => 'Of course! 🙌 Someone from our team will be with you shortly.'],
            ]],
            'pt' => ['palabras' => 'vmix, graficos vmix, placar vmix, loja tukan', 'pasos' => [
                'p1' => ['texto' => "Olá! 👋 Obrigado por escrever para a *Loja Tukán* (gráficos para vMix). Como podemos ajudar?",
                         'opciones' => ['O que inclui e preço', 'Como comprar', 'Já comprei: ajuda', 'Como instalar', 'Falar com atendente']],
                'p2' => ['texto' => $vmix[2]],
                'p3' => ['texto' => "🛒 Como comprar:\n1. Entre em $u e escolha o estilo e o esporte.\n2. Crie sua conta com seu e-mail e uma senha.\n3. Pague com cartão (em dólares).\n4. Na hora chega um e-mail e você baixa tudo em *Mis compras* (Minhas compras): um só zip com o UTC Edición QF, seu Designer, os painéis e o manual.\n\nPor ser um produto digital entregue na hora, não há reembolso. Outra dúvida? Escreva *atendente*."],
                'p4' => ['texto' => "Com prazer te ajudamos 🙌 Conte em uma só mensagem o *número do pedido* (ou o e-mail da sua conta) e *o que está acontecendo*. Se puder, depois envie uma foto da tela."],
                'p5' => ['texto' => "🛠️ O guia passo a passo está aqui (em espanhol):\n$u/instalar\n\nResumindo: descompacte o zip numa pasta fixa (por exemplo C:\\UTC QF) e abra primeiro «LEEME - Empieza aqui.txt». Depois abra o vMix e em seguida o vMixController.exe. Tudo está explicado no *Manual de operaciones QF.pdf*.\n\nSe travar, escreva *atendente*."],
                'p6' => ['texto' => 'Obrigado! 🙏 Já passamos para a equipe. Respondemos o quanto antes em horário comercial (Equador).'],
                'p7' => ['texto' => 'Claro! 🙌 Em instantes uma pessoa da equipe vai te atender.'],
            ]],
            'fr' => ['palabras' => 'vmix, habillage vmix, habillages vmix, tableau de score vmix, boutique tukan', 'pasos' => [
                'p1' => ['texto' => "Bonjour ! 👋 Merci d'écrire à la *Boutique Tukán* (habillages pour vMix). Comment pouvons-nous vous aider ?",
                         'opciones' => ['Contenu et prix', 'Comment acheter', "J'ai acheté : aide", 'Comment installer', 'Parler à un conseiller']],
                'p2' => ['texto' => $vmix[3]],
                'p3' => ['texto' => "🛒 Comment acheter :\n1. Allez sur $u et choisissez le style et le sport.\n2. Créez votre compte avec votre e-mail et un mot de passe.\n3. Payez par carte (en dollars).\n4. Vous recevez aussitôt un e-mail et vous téléchargez tout dans *Mis compras* (Mes achats) : un seul zip avec UTC Edición QF, votre Designer, les panneaux et le manuel.\n\nProduit numérique livré immédiatement : pas de remboursement. Une autre question ? Écrivez *conseiller*."],
                'p4' => ['texto' => "Avec plaisir 🙌 Indiquez-nous en un seul message votre *numéro de commande* (ou l'e-mail de votre compte) et *ce qui se passe*. Si possible, envoyez ensuite une capture d'écran."],
                'p5' => ['texto' => "🛠️ Le guide pas à pas est ici (en espagnol) :\n$u/instalar\n\nEn bref : décompressez le zip dans un dossier fixe (par exemple C:\\UTC QF) et ouvrez d'abord «LEEME - Empieza aqui.txt». Ouvrez ensuite vMix, puis vMixController.exe. Tout est expliqué dans le *Manual de operaciones QF.pdf*.\n\nEn cas de blocage, écrivez *conseiller*."],
                'p6' => ['texto' => "Merci ! 🙏 Nous l'avons transmis à l'équipe. Nous répondons au plus vite pendant les heures de bureau (heure de l'Équateur)."],
                'p7' => ['texto' => "Bien sûr ! 🙌 Un membre de l'équipe va vous répondre dans un instant."],
            ]],
            'it' => ['palabras' => 'vmix, grafiche vmix, tabellone vmix, negozio tukan', 'pasos' => [
                'p1' => ['texto' => "Ciao! 👋 Grazie per aver scritto al *Negozio Tukán* (grafiche per vMix). Come possiamo aiutarti?",
                         'opciones' => ['Contenuto e prezzo', 'Come acquistare', 'Ho acquistato: aiuto', 'Come installare', 'Parla con operatore']],
                'p2' => ['texto' => $vmix[4]],
                'p3' => ['texto' => "🛒 Come acquistare:\n1. Vai su $u e scegli lo stile e lo sport.\n2. Crea il tuo account con la tua email e una password.\n3. Paga con carta (in dollari).\n4. Subito ti arriva un'email e scarichi tutto in *Mis compras* (I miei acquisti): un solo zip con UTC Edición QF, il tuo Designer, i pannelli e il manuale.\n\nEssendo un prodotto digitale consegnato subito, non ci sono rimborsi. Altre domande? Scrivi *operatore*."],
                'p4' => ['texto' => "Ti aiutiamo volentieri 🙌 Scrivici in un solo messaggio il *numero d'ordine* (o l'email del tuo account) e *cosa succede*. Se puoi, poi invia una foto dello schermo."],
                'p5' => ['texto' => "🛠️ La guida passo passo è qui (in spagnolo):\n$u/instalar\n\nIn breve: estrai lo zip in una cartella fissa (per esempio C:\\UTC QF) e apri prima «LEEME - Empieza aqui.txt». Poi apri vMix e dopo vMixController.exe. È tutto spiegato nel *Manual de operaciones QF.pdf*.\n\nSe ti blocchi, scrivi *operatore*."],
                'p6' => ['texto' => "Grazie! 🙏 L'abbiamo passato al team. Ti rispondiamo il prima possibile in orario d'ufficio (Ecuador)."],
                'p7' => ['texto' => 'Certo! 🙌 Tra un momento una persona del team ti risponde.'],
            ]],
        ];
        $D[] = ['nombre' => 'Tienda gráficos vMix', 'palabras' => 'vmix, graficos vmix, graficos para vmix, graficos de vmix, marcador vmix, marcadores vmix, titulos vmix, tienda tukan, tienda de graficos, utc edicion qf, utc qf, disenador qf, compre en la tienda', 'pasos' => $pasos, 'tr' => $tr];

        return $D;
    }
}
