#!/usr/bin/env bash
# Pruebas de pantallas de punta a punta (las llama tests/correr.sh con el servidor de PHP y Meta simulado ya andando).
set -u
W="${WEB_URL:?}"
J="$(mktemp)"            # cookies
P="$(mktemp)"            # última página
BIEN=0; MAL=0
ok()  { if eval "$1"; then BIEN=$((BIEN+1)); else MAL=$((MAL+1)); echo "  FALLA: $2"; cp "$P" "/tmp/falla_$MAL.html"; fi; }
get() { CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" "$W$1"); }
seguir() { CODIGO=$(curl -s -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" "$W$1"); }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$P" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local r="$1"; shift; CODIGO=$(curl -s -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$(csrf)" "$@" "$W$r"); }
tiene() { grep -q -- "$1" "$P"; }
firmar() { printf '%s' "$1" | openssl dgst -sha256 -hmac secreto_de_prueba | sed 's/^.* //'; }
webhook() { curl -s -o /dev/null -w '%{http_code}' -H "Content-Type: application/json" -H "X-Hub-Signature-256: sha256=$(firmar "$1")" --data-binary "$1" "$W/webhook.php"; }

echo "== Entrar"
get "/?r=bandeja";  ok '[ "$CODIGO" = 302 ]' "sin sesión manda a entrar"
get "/?r=login";    ok '[ "$CODIGO" = 200 ] && tiene "no hay usuarios"' "pantalla de entrar sin usuarios"
php bin/crear_usuario.php dimi@tukanec.com "Dimitry" "claveSegura1" | grep -q LISTO; ok '[ $? = 0 ]' "crear usuario"
php bin/crear_usuario.php malo "x" "claveSegura1" > /dev/null 2>&1; ok '[ $? = 1 ]' "correo malo rechazado"
get "/?r=login"
post "/?r=login" --data-urlencode "correo=dimi@tukanec.com" --data-urlencode "clave=otra"
ok 'tiene "incorrectos"' "contraseña mala"
post "/?r=login" --data-urlencode "correo=DIMI@tukanec.com" --data-urlencode "clave=claveSegura1"
ok 'tiene "Conectar número" && tiene "Primero conecta"' "entra y pide conectar el número"
ok 'tiene "id=\"btn-qr\"" && tiene "data-app=\"2592670637899782\"" && tiene "data-config=\"1112223334445\"" && tiene "recomendado"' "muestra el botón Conectar con WhatsApp Business"
ok 'tiene "Conectar a mano"' "conectar a mano sigue disponible"

echo "== Conectar número"
post "/?r=linea" --data-urlencode "nombre=Tukán ventas" --data-urlencode "telefono=0996408397" --data-urlencode "phone_number_id=100" \
     --data-urlencode "waba_id=200" --data-urlencode "token=EAAGtokenDePrueba1234567890"
ok 'tiene "Guardado y conectado con Meta" && tiene "Tukán Software"' "guarda y prueba la conexión"
ok 'tiene "https://whatsapp.ejemplo.com/webhook.php" && tiene "verifica123"' "muestra los datos del webhook"
ok '! tiene "EAAGtokenDePrueba"' "el token no se muestra"
get "/?r=lineas";   ok 'tiene "Tukán ventas" && tiene "099 640 8397"' "lista de números"

echo "== Webhook"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/webhook.php?hub.mode=subscribe&hub.verify_token=verifica123&hub.challenge=987654")
ok '[ "$CODIGO" = 200 ] && [ "$(cat "$P")" = 987654 ]' "verificación de Meta"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "$W/webhook.php?hub.mode=subscribe&hub.verify_token=mal&hub.challenge=1")
ok '[ "$CODIGO" = 403 ]' "verificación con token malo"
T=$(date +%s)
MSG='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"593996408397","phone_number_id":"100"},"contacts":[{"profile":{"name":"Peluquería Ana"},"wa_id":"593991111111"}],"messages":[{"from":"593991111111","id":"wamid.WEB1","timestamp":"'$T'","type":"text","text":{"body":"Hola, quiero <b>precios</b>"}}]}}]}]}'
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -H "X-Hub-Signature-256: sha256=000" --data-binary "$MSG" "$W/webhook.php")
ok '[ "$CODIGO" = 401 ]' "sin firma correcta se rechaza"
ok '[ "$(webhook "$MSG")" = 200 ]' "mensaje firmado aceptado"
sleep 0.3

echo "== Bandeja y chat"
get "/?r=bandeja";  ok 'tiene "Peluquería Ana" && tiene "Automático: apagado"' "aparece en la bandeja"
ok '! tiene "<b>precios</b>" && tiene "&lt;b&gt;precios"' "el texto del cliente se escapa"
get "/?r=nuevos";   ok 'grep -q "\"sin_leer\":1" "$P"' "1 sin leer"
get "/?r=bandeja"
C=$(grep -o 'r=chat&amp;c=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=chat&c=$C"
ok 'tiene "Respuestas automáticas apagadas" && tiene "wa.me/593991111111"' "chat con motivo y enlace gratis"
ok 'tiene "Enviar por el sistema"' "ventana abierta: se puede enviar"
get "/?r=nuevos";   ok 'grep -q "\"sin_leer\":0" "$P"' "al abrir queda leído"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=tipo" --data-urlencode "tipo=cliente"
ok 'tiene "Ahora es: Cliente"' "marcar como cliente"
post "/?r=chat&c=$C" --data-urlencode "accion=enviar" --data-urlencode "texto=Hola Ana, te escribo desde el panel"
ok 'tiene "Enviado (\$0,0113)" && tiene "Por el sistema"' "enviar por el sistema con costo"
post "/?r=chat&c=$C" --data-urlencode "accion=datos" --data-urlencode "nombre=Ana Salón Quito" --data-urlencode "notas=Quiere TuSalón"
ok 'tiene "Ana Salón Quito" && tiene "Quiere TuSalón"' "nombre y notas"
post "/?r=chat&c=$C" --data-urlencode "accion=silencio" --data-urlencode "horas=24"
ok 'tiene "no se le contesta solo hasta"' "pausar automático"
post "/?r=chat&c=$C" --data-urlencode "accion=pendiente" --data-urlencode "texto=Recordar llamar"
ok 'tiene "Agregado a Pendientes"' "guardar en pendientes desde el chat"
get "/?r=chat&c=999999"; ok '[ "$CODIGO" = 302 ]' "chat que no existe"

echo "== Plantillas"
get "/?r=plantilla&c=$C"
ok 'tiene "recordatorio_pago" && tiene "promo_octubre" && ! tiene "en_revision"' "lista solo las aprobadas"
post "/?r=plantilla&c=$C" --data-urlencode "plantilla=recordatorio_pago|es" --data-urlencode "dato[]=Ana" --data-urlencode "dato[]=30/10"
ok 'tiene "Plantilla enviada (gratis)" && tiene "Hola Ana, tu plan vence el 30/10."' "plantilla de utilidad con la ventana abierta: gratis"

echo "== Crear plantillas desde el panel"
get "/?r=plantillas&c=$C"
ok 'tiene "Crear plantilla nueva" && tiene "Rechazada" && tiene "En revisión" && tiene "PROMOTIONAL"' "pantalla de plantillas con estados"
post "/?r=plantillas&c=$C" --data-urlencode "accion=crear" --data-urlencode "nombre=Demo Lista" --data-urlencode "categoria=UTILITY" --data-urlencode "idioma=es" \
     --data-urlencode "cuerpo=Hola {{1}}, tu demo de {{2}} ya está lista." --data-urlencode "ejemplo[1]=Ana" --data-urlencode "ejemplo[2]=TuSalón" \
     --data-urlencode "boton_tipo[0]=QUICK_REPLY" --data-urlencode "boton_texto[0]=Quiero verla"
ok 'tiene "Plantilla &quot;demo_lista&quot; enviada a Meta" && tiene "demo_lista"' "crear plantilla"
post "/?r=plantillas&c=$C" --data-urlencode "accion=crear" --data-urlencode "nombre=Mala" --data-urlencode "categoria=MARKETING" --data-urlencode "idioma=es" \
     --data-urlencode "cuerpo={{1}} oferta" --data-urlencode "ejemplo[1]=Ana"
ok 'tiene "no puede ir al principio" && tiene "{{1}} oferta"' "error explicado y el formulario no se borra"
post "/?r=plantillas&c=$C" --data-urlencode "accion=crear" --data-urlencode "nombre=auto prueba" --data-urlencode "categoria=MARKETING" --data-urlencode "idioma=es" \
     --data-urlencode "cuerpo=Promoción de octubre para tu negocio"
ok 'tiene "Ya está aprobada"' "plantilla aprobada al instante"
post "/?r=plantillas&c=$C" --data-urlencode "accion=borrar" --data-urlencode "nombre=demo_lista"
ok 'tiene "Plantilla &quot;demo_lista&quot; borrada"' "borrar plantilla"
get "/?r=plantilla&c=$C"
ok 'tiene "auto_prueba" && tiene "Crear o ver todas las plantillas"' "la aprobada ya se puede enviar"
get "/?r=bandeja"; ok 'tiene "r=plantillas"' "ícono de plantillas arriba"

echo "== Número nuevo y ventana cerrada"
get "/?r=bandeja"
post "/?r=nuevo_chat" --data-urlencode "telefono=0995555555" --data-urlencode "nombre=Pedro" --data-urlencode "tipo=cliente"
ok 'tiene "Pedro" && tiene "Enviar plantilla" && tiene "más de 24 horas"' "sin mensajes recientes pide plantilla"
post "/?r=nuevo_chat" --data-urlencode "telefono=12" --data-urlencode "tipo=cliente"
ok 'tiene "no es válido"' "número malo"

echo "== Pendientes"
get "/?r=pendientes"
ok 'tiene "Recordar llamar"' "el pendiente del chat"
post "/?r=pendientes" --data-urlencode "accion=agregar" --data-urlencode "telefono=0997777777" --data-urlencode "nombre=Carla" --data-urlencode "texto=Hola Carla, tu demo está lista"
ok 'tiene "Agregado a la lista" && tiene "wa.me/593997777777?text=Hola%20Carla%2C%20tu%20demo%20est%C3%A1%20lista"' "agregar con enlace listo"
ID=$(grep -o 'data-id="[0-9]*"' "$P" | tail -1 | grep -o '[0-9]*')
R=$(curl -s -b "$J" -c "$J" --data-urlencode "csrf=$(csrf)" -d "id=$ID&accion=enviado&ajax=1" "$W/?r=pendientes")
ok '[ "$R" = "{\"ok\":true}" ]' "marcar enviado (botón Sí, enviado)"
get "/?r=pendientes"
ok 'tiene "Últimos hechos" && tiene "Volver a pendientes"' "aparece en hechos"

echo "== Contactos"
get "/?r=contactos"
post "/?r=contactos" --data-urlencode "accion=importar" --data-urlencode $'lista=0992222222, Mamá\n0993333333, Papá\nnada' --data-urlencode "tipo=personal"
ok 'tiene "2 nuevos como Personal" && tiene "1 no son celulares" && tiene ": nada"' "pegar lista de personales"
get "/?r=contactos&t=personal"
ok 'tiene "Mamá" && tiene "Papá" && ! tiene "Pedro"' "filtro personales"
get "/?r=contactos"
ok 'tiene "Traer contactos del celular" && tiene "Elegir de la agenda del celular" && tiene "Todos los contactos"' "sección para traer del celular con la guía"
CODIGO=$(curl -s -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" -F "csrf=$(csrf)" -F accion=importar -F tipo=personal -F solo_nuevos=1 -F "archivo=@tests/datos/android.vcf;type=text/vcard" "$W/?r=contactos")
ok 'tiene "Listo: 2 nuevos como Personal" && tiene "1 no son celulares"' "subir archivo .vcf de Android"
post "/?r=contactos" --data-urlencode "accion=importar" --data-urlencode "tipo=personal" --data-urlencode "solo_nuevos=1" \
     --data-urlencode "contenido@tests/datos/google.csv" --data-urlencode "nombre_archivo=contacts.csv"
ok 'tiene "3 nuevos como Personal"' "archivo leído en el celular (CSV de Google)"
post "/?r=contactos" --data-urlencode "accion=importar" --data-urlencode "tipo=personal" --data-urlencode "solo_nuevos=1" --data-urlencode "lista=0992222222, Mamá"
ok 'tiene "1 ya estaban y no se tocaron"' "no cambia los que ya están"
post "/?r=contactos" --data-urlencode "accion=importar" --data-urlencode "tipo=personal" --data-urlencode "contenido=hola mundo" --data-urlencode "nombre_archivo=x.vcf"
ok 'tiene "No se encontró ningún contacto"' "archivo sin contactos"
get "/?r=contactos&b=Pedro";  ok 'tiene "Pedro" && ! tiene "Mamá"' "buscar"

echo "== Reglas"
get "/?r=reglas"
ok 'tiene "Contactos personales: nunca" && tiene "1. Precios y planes"' "pantalla de reglas"
post "/?r=reglas" --data-urlencode "auto_activo=1" --data-urlencode "regla_solo_clientes=1" --data-urlencode "regla_palabras=1" \
   --data-urlencode "regla_una_vez=1" --data-urlencode "palabras=precio, precios, demo" --data-urlencode "max_auto=3" --data-urlencode "horas_silencio=12" \
   --data-urlencode "mensaje_menu=Hola, gracias por escribir a Tukán:" \
   --data-urlencode "op_titulo[0]=Precios" --data-urlencode "op_respuesta[0]=Te cotizamos" \
   --data-urlencode "op_titulo[1]=Persona" --data-urlencode "op_respuesta[1]=Ya te atiende alguien" --data-urlencode "op_humano[1]=1" \
   $(for d in 1 2 3 4 5 6 7; do printf -- '--data-urlencode abre[%s]=08:00 --data-urlencode cierra[%s]=18:00 ' $d $d; done)
ok 'tiene "Reglas guardadas" && tiene "2. Persona"' "guardar reglas"
get "/?r=reglas&p_texto=precio&p_tipo=cliente&p_ya=0&p_dia=2&p_hora=10:00"
ok 'tiene "Sí se contesta solo" && tiene "Se le envió el menú"' "probador: cliente (sin horario marcado)"
get "/?r=reglas&p_texto=precio&p_tipo=personal&p_ya=0&p_dia=2&p_hora=10:00"
ok 'tiene "No se contesta" && tiene "Contacto personal"' "probador: personal"
get "/?r=bandeja";  ok 'tiene "Automático: encendido"' "bandeja muestra encendido"

echo "== Contestar solo de verdad (webhook)"
MSG2='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"metadata":{"phone_number_id":"100"},"contacts":[{"profile":{"name":"Pedro"},"wa_id":"593995555555"}],"messages":[{"from":"593995555555","id":"wamid.WEB2","timestamp":"'$(date +%s)'","type":"text","text":{"body":"precio de TuSalón"}}]}}]}]}'
webhook "$MSG2" > /dev/null; sleep 0.3
get "/?r=bandeja"
P2=$(grep -o 'r=chat&amp;c=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=chat&c=$P2"
ok 'tiene "Contestado solo" && tiene "Automático" && tiene "Hola, gracias por escribir a Tukán"' "Pedro (cliente) recibe el menú solo"
MSG3='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"metadata":{"phone_number_id":"100"},"contacts":[{"profile":{"name":"Mamá"}, "wa_id":"593992222222"}],"messages":[{"from":"593992222222","id":"wamid.WEB3","timestamp":"'$(date +%s)'","type":"text","text":{"body":"precio del arroz"}}]}}]}]}'
webhook "$MSG3" > /dev/null; sleep 0.3
get "/?r=bandeja&f=personal"
M=$(grep -o 'r=chat&amp;c=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=chat&c=$M"
ok 'tiene "Contacto personal: no se contesta solo" && ! tiene "Contestado solo"' "a Mamá no se le contesta"

echo "== Gasto"
get "/?r=gasto"
ok 'tiene "Gasto en Meta" && tiene "Respuestas automáticas" && tiene "Enviados desde aquí"' "pantalla de gasto"
post "/?r=gasto" --data-urlencode "accion=tope" --data-urlencode "tope=5,50" --data-urlencode "aviso_pct=70"
ok 'tiene "Tope guardado" && tiene "de \$5,50"' "cambiar tope"
post "/?r=gasto" --data-urlencode "accion=tope" --data-urlencode "tope=-1" --data-urlencode "aviso_pct=70"
ok 'tiene "entre \$0"' "tope negativo"
post "/?r=gasto" --data-urlencode "accion=tope" --data-urlencode "tope=0" --data-urlencode "aviso_pct=80"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=enviar" --data-urlencode "texto=esto no debe salir"
ok 'tiene "llegaste al tope" && tiene "Tope alcanzado: solo se envía gratis"' "con tope 0 no se envía nada pagado"
post "/?r=gasto" --data-urlencode "accion=tope" --data-urlencode "tope=12" --data-urlencode "aviso_pct=80"
post "/?r=gasto" --data-urlencode "accion=precios" --data-urlencode "precio[servicio]=0.0113" --data-urlencode "precio[utilidad]=0.0113" \
     --data-urlencode "precio[marketing]=0.074" --data-urlencode "precio[autenticacion]=0.0113"
ok 'tiene "Precios guardados"' "guardar precios"

echo "== API para otros programas"
A="$W/api.php?accion=pendiente"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -X POST -d '{}' "$A");  ok '[ "$CODIGO" = 401 ]' "API sin token"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer api_token_de_prueba_0123456789abcdef" -H "Content-Type: application/json" \
         -d '{"telefono":"0998888888","nombre":"Salón Bella","texto":"Tu demo vence mañana","origen":"tusalon"}' "$A")
ok '[ "$CODIGO" = 200 ] && grep -q "\"ok\":true" "$P"' "API agrega pendiente"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer api_token_de_prueba_0123456789abcdef" -d '{"telefono":"x","texto":"a"}' "$A")
ok '[ "$CODIGO" = 422 ]' "API con número malo"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer api_token_de_prueba_0123456789abcdef" \
         -d '{"telefono":"0998888888","nombre":"Salón Bella","tipo":"cliente"}' "$W/api.php?accion=contacto")
ok '[ "$CODIGO" = 200 ] && grep -q "\"tipo\":\"cliente\"" "$P"' "API registra cliente"
get "/?r=pendientes";  ok 'tiene "Salón Bella" && tiene "tusalon"' "el pendiente de la API aparece"

echo "== Conectar con código QR"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" -d "code=codigo_bueno_1234567&evento=FINISH&waba_id=300&phone_number_id=301" "$W/?r=conectar_qr")
ok '[ "$CODIGO" = 400 ] && tiene "\"ok\":false"' "sin csrf se rechaza"
get "/?r=linea"
T=$(grep -o 'data-csrf="[^"]*"' "$P" | head -1 | sed 's/.*="//;s/"$//')
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$T" --data-urlencode "code=codigo_vencido_99999" \
         --data-urlencode "evento=FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING" --data-urlencode "waba_id=300" --data-urlencode "phone_number_id=301" "$W/?r=conectar_qr")
ok '[ "$CODIGO" = 200 ] && tiene "\"ok\":false" && tiene "30 segundos"' "código vencido explica qué pasó"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$T" --data-urlencode "code=codigo_bueno_1234567" \
         --data-urlencode "evento=FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING" --data-urlencode "waba_id=300" --data-urlencode "phone_number_id=301" \
         --data-urlencode "nombre=QF Producciones" "$W/?r=conectar_qr")
ok '[ "$CODIGO" = 200 ] && tiene "\"ok\":true" && tiene "\"coexistencia\":true" && tiene "r=linea"' "conecta con código QR"
ok '! tiene "EAAG" && ! tiene "pin"' "no devuelve el token ni el PIN al navegador"
IR=$(grep -o '"ir":"[^"]*"' "$P" | sed 's/"ir":"//;s/"$//;s#\\/#/#g')
get "$IR"
ok 'tiene "Número conectado con código QR" && tiene "Conectado con código QR" && tiene "sigue funcionando en la app WhatsApp Business"' "pantalla del número conectado"
ok 'tiene "Contactos del celular" && tiene "pedido a Meta" && ! tiene "Traer historial"' "contactos e historial ya pedidos"
get "/?r=lineas";  ok 'tiene "QF Producciones" && tiene "Tukán ventas"' "los dos números en la lista"
H='{"object":"whatsapp_business_account","entry":[{"id":"300","changes":[{"field":"history","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"593996408397","phone_number_id":"301"},"history":[{"metadata":{"phase":0,"chunk_order":1,"progress":100},"threads":[{"id":"593987776655","messages":[{"from":"593987776655","id":"wamid.WEBH1","timestamp":"1760000000","type":"text","text":{"body":"Mensaje viejo del celular"}}]}]}]}}]}]}'
ok '[ "$(webhook "$H")" = 200 ]' "historial firmado aceptado"
sleep 0.3
get "/?r=bandeja"; ok 'tiene "Mensaje viejo del celular" || tiene "987 776 655"' "el historial aparece en la bandeja del número QR"
get "$IR"; ok 'tiene "completo"' "historial completo"
post "$IR" --data-urlencode "accion=sync_historial"
ok 'tiene "Meta solo lo permite una vez"' "no deja pedir el historial dos veces"
get "/?r=lineas"
L1=$(grep -o 'r=linea&amp;id=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
post "/?r=cambiar_linea" --data-urlencode "linea_id=$L1"

echo "== Páginas públicas para Meta"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/privacidad.php")
ok '[ "$CODIGO" = 200 ] && tiene "Política de privacidad" && tiene "info@tukansoftware.com" && tiene "Protección de Datos"' "política de privacidad sin entrar"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/eliminar-datos.php")
ok '[ "$CODIGO" = 200 ] && tiene "Cómo eliminar tus datos" && tiene "15 días"' "eliminar datos sin entrar"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/terminos.php"); ok '[ "$CODIGO" = 200 ] && tiene "Términos y condiciones de WhatsApp Tukán" && tiene "Meta"' "términos públicos"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tratamiento-datos.php"); ok '[ "$CODIGO" = 200 ] && tiene "Encargado del tratamiento" && tiene "LOPDP"' "acuerdo de datos público"

echo "== Seguridad"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" -L -d "accion=tipo&tipo=personal" "$W/?r=chat&c=$C")
ok 'tiene "Intenta de nuevo" && ! tiene "Ahora es: Personal"' "POST sin csrf se rechaza"
get "/?r=algo_raro";  ok '[ "$CODIGO" = 404 ]' "ruta desconocida 404"
get "/?r=salir"; get "/?r=gasto"; ok '[ "$CODIGO" = 302 ]' "después de salir no entra"


echo "== Cuentas por cliente"
entrar() { J="$(mktemp)"; get "/?r=login"; post "/?r=login" --data-urlencode "correo=$1" --data-urlencode "clave=$2"; }
claveMostrada() { grep -o '<code>[^<]*</code>' "$P" | sed -n 3p | sed 's/<[^>]*>//g'; }
entrar dimi@tukanec.com claveSegura1
TUKAN_J="$J"
get "/?r=clientes"
ok 'tiene "Clientes de Tukán" && tiene "Tukán Software (nosotros)"' "panel de clientes para el personal de Tukán"
VENCE=$(date -d '+7 days' +%F)
post "/?r=clientes" --data-urlencode "nombre=Salón Bella" --data-urlencode "estado=demo" --data-urlencode "vence=$VENCE" --data-urlencode "max_numeros=1" \
     --data-urlencode "max_usuarios=2" --data-urlencode "contacto=Bella" --data-urlencode "telefono=0991112222" \
     --data-urlencode "admin_nombre=Bella" --data-urlencode "admin_correo=bella@salon.com"
ok 'tiene "Cliente creado" && tiene "bella@salon.com" && tiene "Entrar a su cuenta (soporte)"' "crear cliente y ver su contraseña"
CLAVE_BELLA="$(claveMostrada)"
EMP=$(grep -o 'name="empresa_id" value="[0-9]*"' "$P" | head -1 | grep -o '[0-9]*')
get "/?r=cliente&id=$EMP"; ok '! tiene "$CLAVE_BELLA"' "la contraseña se muestra una sola vez"

entrar bella@salon.com "$CLAVE_BELLA"
BELLA_J="$J"
ok 'tiene "Antes de empezar" && tiene "terminos.php" && tiene "tratamiento-datos.php"' "el cliente debe aceptar los términos al entrar"
get "/?r=contactos"; ok '[ "$CODIGO" = 302 ]' "sin aceptar no entra a nada"
get "/?r=aceptar"
post "/?r=aceptar"
ok 'tiene "Marca la casilla"' "sin marcar la casilla no acepta"
post "/?r=aceptar" --data-urlencode "acepto=1"
ok 'tiene "Conectar número" && tiene "Primero conecta"' "el cliente entra y le pide conectar su número"
ok '! tiene "r=clientes"' "el cliente no ve el panel de Tukán"
get "/?r=clientes"; ok '[ "$CODIGO" = 302 ] || ! tiene "Clientes de Tukán"' "no puede abrir Clientes"
get "/?r=chat&c=$C"; ok '[ "$CODIGO" = 302 ]' "no puede abrir chats de Tukán"
seguir "/?r=linea&id=1"; ok 'tiene "no existe en tu cuenta"' "no puede abrir el número de Tukán"
get "/?r=cuenta"
post "/?r=linea" --data-urlencode "nombre=Robado" --data-urlencode "phone_number_id=100" --data-urlencode "token=EAAGtokenDePrueba1234567890"
ok 'tiene "otra cuenta"' "no puede conectar el número de otro"
get "/?r=cuenta"
ok 'tiene "Salón Bella" && tiene "Demo" && tiene "Números: 0 de 1"' "Mi cuenta con su plan"
post "/?r=cuenta" --data-urlencode "accion=agregar_usuario" --data-urlencode "nombre=Agente Uno" --data-urlencode "correo=agente@salon.com" --data-urlencode "rol=agente"
ok 'tiene "Usuario creado" && tiene "agente@salon.com"' "el cliente agrega a su agente"
CLAVE_AG="$(claveMostrada)"
ok '! tiene "Agregar una persona" && tiene "permite 2 usuarios"' "llegó al límite de usuarios"
post "/?r=cuenta" --data-urlencode "accion=token_api"
TOKEN_BELLA=$(grep -o 'tw_[0-9]*_[0-9a-f]*' "$P" | head -1)
ok '[ -n "$TOKEN_BELLA" ]' "crea su token de API"
post "/?r=cuenta" --data-urlencode "accion=mi_clave" --data-urlencode "actual=$CLAVE_BELLA" --data-urlencode "nueva=BellaNueva1" --data-urlencode "repetir=BellaNueva1"
ok 'tiene "Tu contraseña se cambió"' "cambia su contraseña"

entrar agente@salon.com "$CLAVE_AG"
ok 'tiene "todavía no conecta el número" && tiene "Mi cuenta" && ! tiene "Agregar una persona"' "el agente entra (sin número) y no administra"
get "/?r=reglas"; ok '[ "$CODIGO" = 302 ]' "el agente no abre las reglas"

CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer $TOKEN_BELLA" -d '{"telefono":"0991234567","texto":"hola"}' "$W/api.php?accion=pendiente")
ok '[ "$CODIGO" = 404 ] && grep -q "No existe ese número" "$P"' "API con token del cliente usa solo sus números"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer $TOKEN_BELLA" -d '{"telefono":"0991234567","texto":"hola","linea":1}' "$W/api.php?accion=pendiente")
ok '[ "$CODIGO" = 404 ]' "API del cliente no puede usar el número de Tukán"

J="$TUKAN_J"
get "/?r=cliente&id=$EMP"
ok 'tiene "Términos: aceptados el"' "Tukán ve cuándo aceptó los términos"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=datos" --data-urlencode "nombre=Salón Bella" --data-urlencode "estado=suspendida" --data-urlencode "vence=$VENCE" \
     --data-urlencode "motivo_suspension=Falta el pago de noviembre" --data-urlencode "max_numeros=1" --data-urlencode "max_usuarios=2"
ok 'tiene "Datos del cliente guardados" && tiene "Falta el pago de noviembre"' "Tukán suspende al cliente"
J="$BELLA_J"
seguir "/?r=bandeja"; ok 'tiene "Cuenta no disponible" && tiene "Falta el pago de noviembre" && tiene "wa.me/593996408397"' "cliente suspendido ve el aviso"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Authorization: Bearer $TOKEN_BELLA" -d '{"telefono":"0991234567","texto":"hola"}' "$W/api.php?accion=pendiente")
ok '[ "$CODIGO" = 403 ]' "API de cuenta suspendida"

J="$TUKAN_J"
get "/?r=cliente&id=$EMP"
post "/?r=ver_cuenta" --data-urlencode "empresa_id=$EMP"
ok 'tiene "Soporte: estás en la cuenta de" && tiene "Salón Bella" && tiene "no está al día" && tiene "Conectar número"' "Tukán entra a la cuenta del cliente para soporte"
post "/?r=volver_tukan"
ok 'tiene "Clientes de Tukán" && ! tiene "Soporte: estás"' "volver a Tukán"
get "/?r=bandeja"; ok 'tiene "Peluquería Ana\|Ana Salón Quito"' "Tukán sigue viendo lo suyo"


echo "== Campañas"
J="$TUKAN_J"
get "/?r=campanias"
ok 'tiene "Nueva campaña" && tiene "recordatorio_pago" && tiene "aceptó"' "pantalla de campañas"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=marketing" --data-urlencode "marketing=acepta"
ok 'tiene "Guardado" && tiene "aria-pressed=\"true\">Aceptó"' "marcar que aceptó promociones"
post "/?r=chat&c=$C" --data-urlencode "accion=datos" --data-urlencode "nombre=Ana Salón Quito" --data-urlencode "notas=" --data-urlencode "etiquetas=VIP, quito"
ok 'tiene "vip, quito"' "etiquetas del contacto"
get "/?r=campanias"
post "/?r=campanias" --data-urlencode "accion=revisar" --data-urlencode "nombre=Recordatorio web" --data-urlencode "plantilla=recordatorio_pago|es" \
     --data-urlencode "dato_tipo[1]=nombre" --data-urlencode "dato_valor[1]=amigo" --data-urlencode "dato_tipo[2]=fijo" --data-urlencode "dato_valor[2]=30 de noviembre" \
     --data-urlencode "filtro_tipo=cliente" --data-urlencode "filtro_etiqueta=vip" --data-urlencode "solo_acepta=1" --data-urlencode "por_minuto=30"
ok 'tiene "Revisar campaña" && tiene "Enviar a 1 contactos" && tiene "Hola Ana, tu plan vence el 30 de noviembre."' "revisar antes de enviar"
post "/?r=campanias" --data-urlencode "accion=lanzar" --data-urlencode "total=1"
ok 'tiene "Campaña creada para 1 contactos" && tiene "Enviando"' "campaña creada"
php bin/campanias.php | grep -q "enviados 1"; ok '[ $? = 0 ]' "el envío por minuto (cron) la manda"
get "/?r=campanias"
ok 'tiene "Terminada" && tiene "<b>1</b> de 1 enviados"' "campaña terminada"
post "/?r=campanias" --data-urlencode "accion=revisar" --data-urlencode "plantilla=promo_octubre|es" --data-urlencode "filtro_tipo=cliente" --data-urlencode "filtro_etiqueta=nadie" --data-urlencode "solo_acepta=1"
ok 'tiene "No hay contactos con esas condiciones" && ! tiene "Enviar a 0"' "sin contactos no deja enviar"
BAJA='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"metadata":{"phone_number_id":"100"},"contacts":[{"profile":{"name":"Ana"},"wa_id":"593991111111"}],"messages":[{"from":"593991111111","id":"wamid.WEBBAJA","timestamp":"'$(date +%s)'","type":"text","text":{"body":"STOP"}}]}}]}]}'
webhook "$BAJA" > /dev/null; sleep 0.3
get "/?r=chat&c=$C"
ok 'tiene "no quiere promociones" && tiene "Pidió no recibir más promociones"' "STOP la saca de las campañas"


echo "== Varios agentes y respuestas rápidas"
J="$TUKAN_J"
get "/?r=cuenta"
post "/?r=cuenta" --data-urlencode "accion=agregar_usuario" --data-urlencode "nombre=Luis Agente" --data-urlencode "correo=luis@tukan.com" --data-urlencode "rol=agente"
CLAVE_LUIS="$(claveMostrada)"
get "/?r=chat&c=$C"
ok 'tiene "Atiende" && tiene "Luis Agente" && tiene "(yo)</option>"' "en el chat se elige quién atiende"
ok 'grep -q "selected>Dimitry (yo)" "$P"' "al responder desde el panel, la conversación pasó a mí"
get "/?r=bandeja&f=mios"; ok 'tiene "Ana Salón Quito" && tiene "chip-asignado"' "filtro Míos"
get "/?r=bandeja&f=sin_asignar"; ok '! tiene "Ana Salón Quito"' "ya no está en Sin asignar"
get "/?r=chat&c=$C"
LUIS_ID=$(grep -o '<option value="[0-9]*"[^>]*>Luis Agente' "$P" | grep -o 'value="[0-9]*"' | grep -o '[0-9]*' | head -1)
post "/?r=chat&c=$C" --data-urlencode "accion=asignar" --data-urlencode "usuario_id=$LUIS_ID"
ok 'tiene "Conversación asignada" && tiene "Atender yo"' "pasarla a Luis"
get "/?r=bandeja&f=mios"; ok '! tiene "Ana Salón Quito"' "ya no está en mis chats"
get "/?r=respuestas"
post "/?r=respuestas" --data-urlencode "atajo=cuenta" --data-urlencode "texto=Banco Pichincha cuenta 123"
ok 'tiene "Respuesta guardada" && tiene "/cuenta"' "crear respuesta rápida"
get "/?r=chat&c=$C"; ok 'tiene "Respuesta rápida…" && tiene "Banco Pichincha cuenta 123"' "elegir respuesta rápida en el chat"
entrar luis@tukan.com "$CLAVE_LUIS"
seguir "/?r=chat&c=$C"; ok 'tiene "Respuesta rápida…" && ! tiene ">Editar<"' "el agente usa respuestas pero no las edita"
get "/?r=respuestas"; ok '[ "$CODIGO" = 302 ]' "el agente no abre la administración de respuestas"
get "/?r=campanias"; ok '[ "$CODIGO" = 302 ]' "el agente no abre campañas"
get "/?r=bandeja"; ok '! tiene "r=campanias" && ! tiene "r=reglas"' "el menú del agente no muestra Campañas ni Automático"


echo "== Módulos, fotos y audios"
J="$TUKAN_J"
get "/?r=mas"
ok '[ "$CODIGO" = 200 ] && tiene "Plantillas" && tiene "Gasto" && ! tiene "No incluido"' "Más: Tukán tiene todo"
get "/?r=bandeja"; ok 'tiene "r=embudo" && tiene "r=mas"' "menú de abajo con Embudo y Más"
get "/?r=cliente&id=$EMP"
ok 'tiene "Módulos del plan" && tiene "Campañas masivas"' "Tukán ve los módulos del cliente"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=plan:pro"
ok 'tiene "Plan Pro aplicado" && tiene "Números permitidos<input type=\"number\" name=\"max_numeros\" min=\"0\" max=\"100\" value=\"2\""' "aplicar el plan Pro con un toque"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=modulos" --data-urlencode "modulos[]=reportes" --data-urlencode "modulos[]=inventado"
ok 'tiene "Módulos guardados"' "guardar módulos del cliente"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=datos" --data-urlencode "nombre=Salón Bella" --data-urlencode "estado=activa" --data-urlencode "vence=" \
     --data-urlencode "motivo_suspension=" --data-urlencode "max_numeros=1" --data-urlencode "max_usuarios=2"
J="$BELLA_J"
seguir "/?r=campanias"
ok 'tiene "no está incluido en tu plan" && tiene "No incluido" && tiene "Pedir a Tukán"' "sin el módulo: campañas bloqueadas y se puede pedir"
get "/?r=bandeja"; ok '! tiene "r=embudo"' "sin embudo no sale en el menú"
seguir "/?r=embudo"; ok 'tiene "no está incluido en tu plan"' "embudo bloqueado por dirección"

J="$TUKAN_J"
T=$(date +%s)
FOTO='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"phone_number_id":"100"},"contacts":[{"profile":{"name":"Ana"},"wa_id":"593991111111"}],"messages":[{"from":"593991111111","id":"wamid.WEBFOTO","timestamp":"'$T'","type":"image","image":{"id":"777444555","mime_type":"image/jpeg","caption":"mi local"}}]}}]}]}'
ok '[ "$(webhook "$FOTO")" = 200 ]' "llega una foto"
get "/?r=chat&c=$C"
ok 'tiene "class=\"adjunto-foto\"" && tiene "mi local"' "la foto se ve en el chat"
AID=$(grep -o 'r=archivo&amp;id=[0-9]*' "$P" | tail -1 | grep -o '[0-9]*$')
TIPO=$(curl -s -o "$P" -w '%{content_type}' -b "$J" "$W/?r=archivo&id=$AID")
ok '[ "$TIPO" = "image/jpeg" ] && head -c 3 "$P" | od -An -tx1 | grep -q "ff d8 ff"' "abrir la foto desde el panel"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "$W/?r=archivo&id=$AID")
ok '[ "$CODIGO" = 302 ]' "sin sesión no se ve la foto"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -b "$BELLA_J" "$W/?r=archivo&id=$AID")
ok '[ "$CODIGO" = 404 ]' "otro cliente no puede ver la foto"
get "/?r=chat&c=$C"
ok 'tiene "enctype=\"multipart/form-data\"" && tiene "id=\"archivo\"" && tiene "id=\"grabar\""' "botones de adjuntar y nota de voz"
F="$(mktemp --suffix=.jpg)"; printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00' > "$F"; head -c 300 /dev/zero >> "$F"
CODIGO=$(curl -s -L -o "$P.2" -w '%{http_code}' -b "$J" -c "$J" -F "csrf=$(csrf)" -F "accion=enviar" -F "texto=Te mando el catálogo" -F "archivo=@$F;filename=catalogo.jpg" "$W/?r=chat&c=$C"); mv "$P.2" "$P"
ok 'tiene "Enviado" && tiene "Te mando el catálogo"' "enviar una foto desde el chat"
ok 'grep -q "\"type\":\"image\"" "$SIM_LOG"' "Meta recibió la foto"
X="$(mktemp --suffix=.exe)"; printf 'MZ\x90\x00programa' > "$X"
CODIGO=$(curl -s -L -o "$P.2" -w '%{http_code}' -b "$J" -c "$J" -F "csrf=$(csrf)" -F "accion=enviar" -F "texto=" -F "archivo=@$X;filename=virus.exe" "$W/?r=chat&c=$C"); mv "$P.2" "$P"
ok 'tiene "no se puede enviar por WhatsApp"' "un programa .exe no se envía"
get "/?r=reglas"; ok 'tiene "name=\"menu_botones\""' "opción de menú con botones en Automático"

echo "== Embudo, seguimientos, reportes, IA, enlaces e integraciones"
J="$TUKAN_J"
get "/?r=embudo"
ok '[ "$CODIGO" = 200 ] && tiene "Embudo de ventas" && tiene "Interesado" && tiene "Ganado" && tiene "r=chat&amp;c=$C"' "embudo con Ana en Nuevo"
post "/?r=embudo" --data-urlencode "c=$C" --data-urlencode "etapa=cotizado"
ok 'tiene "Movido a Cotizado"' "mover en el embudo"
get "/?r=chat&c=$C"
ok 'tiene "Etapa de la venta" && tiene "value=\"cotizado\" selected"' "el chat muestra la etapa"
post "/?r=chat&c=$C" --data-urlencode "accion=etapa" --data-urlencode "etapa=ganado" --data-urlencode "valor=1.200,50"
ok 'tiene "Embudo actualizado" && tiene "1.200,50"' "etapa y valor desde el chat"
get "/?r=embudo"; ok 'tiene "Ganado: <b>\$1.200,50"' "el embudo suma lo ganado"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=seguimiento" --data-urlencode "texto_seguimiento=¿Pudiste revisar la propuesta?" --data-urlencode "cuando=2d" --data-urlencode "solo_sin_respuesta=1"
ok 'tiene "Seguimiento programado para el" && tiene "Pudiste revisar la propuesta"' "programar seguimiento desde el chat"
post "/?r=chat&c=$C" --data-urlencode "accion=seguimiento" --data-urlencode "texto_seguimiento=x" --data-urlencode "cuando=fecha" --data-urlencode "fecha=2020-01-01T10:00"
ok 'tiene "La fecha ya pasó"' "no deja programar en el pasado"
get "/?r=seguimientos"
ok 'tiene "1 programados" && tiene "Pudiste revisar la propuesta" && tiene "solo si no responde"' "lista de seguimientos"
SID=$(grep -o 'name="id" value="[0-9]*"' "$P" | head -1 | grep -o '[0-9]*')
post "/?r=seguimientos" --data-urlencode "id=$SID"
ok 'tiene "Seguimiento cancelado" && tiene "0 programados"' "cancelar seguimiento"
get "/?r=reportes"
ok '[ "$CODIGO" = 200 ] && tiene "personas escribieron" && tiene "Mensajes por día" && tiene "Por persona del equipo" && tiene "Dimitry"' "reportes"
get "/?r=reportes&d=30"; ok 'tiene "Últimos 30 días" && tiene "class=\"activo\">Últimos 30"' "reportes de 30 días"
get "/?r=ia"
post "/?r=ia" --data-urlencode "conocimiento=Plan Pro: \$45 al mes"
ok 'tiene "Guardado. La IA usará" && tiene "Plan Pro: \$45 al mes"' "guardar lo que sabe la IA"
get "/?r=chat&c=$C"
ok 'tiene "Sugerir respuesta" && tiene "value=\"ia_resumir\""' "botones de IA en el chat"
post "/?r=chat&c=$C" --data-urlencode "accion=ia_sugerir"
ok 'tiene "La IA dejó un borrador" && tiene "El plan Pro cuesta"' "la IA deja el borrador en la caja"
post "/?r=chat&c=$C" --data-urlencode "accion=ia_resumir"
ok 'tiene "Resumen (IA)" && tiene "Siguiente paso"' "resumen de la IA"
get "/?r=ia"; ok 'tiene "Solo lo ve Tukán" && tiene "\$0,0004 de IA este mes" && tiene "Usa la IA compartida"' "Tukán ve el gasto de IA de la cuenta"
get "/?r=enlaces"
post "/?r=enlaces" --data-urlencode "accion=crear" --data-urlencode "nombre=Volante" --data-urlencode "texto=Hola, vi el volante"
ok 'tiene "Enlace creado" && tiene "w.php?c=" && tiene "data-qr=" && tiene "boton.js" && tiene "qrcode.js"' "crear enlace con QR y botón web"
COD=$(grep -o 'w.php?c=[a-z2-9]*' "$P" | head -1 | sed 's/.*c=//')
DESTINO=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$W/w.php?c=$COD")
ok '[ "$DESTINO" = "302 https://wa.me/593996408397?text=Hola%2C%20vi%20el%20volante" ]' "el enlace corto abre WhatsApp"
get "/?r=enlaces"; ok 'tiene ">1 clic<"' "cuenta el clic"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "$W/w.php?c=noexis")
ok '[ "$CODIGO" = 404 ]' "enlace que no existe"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/boton.js"); ok '[ "$CODIGO" = 200 ] && tiene "data-enlace"' "botón para la web"
TIPO=$(curl -s -o "$P" -w '%{content_type}' -b "$J" "$W/?r=integraciones&bajar=contactos")
ok '[ "$TIPO" = "text/csv; charset=utf-8" ] && tiene "Teléfono;Nombre" && tiene "593991111111"' "descargar contactos para Excel"
TIPO=$(curl -s -o "$P" -w '%{content_type}' -b "$J" "$W/?r=integraciones&bajar=mensajes&d=30")
ok '[ "$TIPO" = "text/csv; charset=utf-8" ] && tiene "Fecha;Teléfono"' "descargar mensajes"
get "/?r=integraciones"
post "/?r=integraciones" --data-urlencode "accion=guardar" --data-urlencode "url=https://127.0.0.1/robar"
ok 'tiene "red interna"' "no deja avisar a la red interna del servidor"
post "/?r=integraciones" --data-urlencode "accion=guardar" --data-urlencode "url=http://hook.ejemplo.com/x"
ok 'tiene "debe empezar con https"' "solo https"
get "/?r=mas"; ok 'tiene "r=embudo" && tiene "r=seguimientos" && tiene "r=reportes" && tiene "r=ia" && tiene "r=enlaces" && tiene "r=integraciones"' "Más con todos los módulos"
entrar luis@tukan.com "$CLAVE_LUIS"
get "/?r=reportes"; ok '[ "$CODIGO" = 302 ]' "el agente no abre reportes"
get "/?r=integraciones&bajar=contactos"; ok '[ "$CODIGO" = 302 ]' "el agente no descarga los contactos"
seguir "/?r=embudo"; ok 'tiene "Embudo de ventas"' "el agente sí usa el embudo"
get "/?r=mas"; ok 'tiene "r=seguimientos" && ! tiene "r=reportes" && ! tiene "r=integraciones"' "Más del agente sin lo de administrador"
J="$TUKAN_J"

echo "== Panel Tukán Central"
tokenCentral() { TIPO="$1" php -r '$b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "="); $n = time();
  $p = $b(json_encode(["v"=>1,"tipo"=>getenv("TIPO"),"inst"=>"tuwhatsapp","email"=>"dimi@tukanec.com","nombre"=>"Dimitry","iat"=>$n,"exp"=>$n+60,"nonce"=>bin2hex(random_bytes(16))]));
  echo $p . "." . $b(hash_hmac("sha256", $p, str_repeat("a1", 32), true));'; }
T=$(tokenCentral estado)
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tukan_central.php?accion=estado&t=$T")
ok '[ "$CODIGO" = 200 ] && grep -q "\"producto\":\"tuwhatsapp\"" "$P" && grep -q "\"negocios\":{\"total\":1" "$P"' "estado para el panel central"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tukan_central.php?accion=estado&t=$T")
ok '[ "$CODIGO" = 401 ] && grep -q "ya se usó" "$P"' "el mismo enlace no sirve dos veces"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tukan_central.php?accion=capacidades")
ok '[ "$CODIGO" = 400 ] && ! grep -q "codigo" "$P"' "acción v2 -> 400 sin codigo (el panel lo toma como v1)"
J="$(mktemp)"
T=$(tokenCentral sso)
seguir "/tukan_central.php?accion=sso&t=$T"
ok 'tiene "Clientes de Tukán" && tiene "← Panel Tukán" && tiene "Entraste desde el Panel Tukán Central"' "entrar con un toque desde el panel central"
seguir "/tukan_central.php?accion=sso&t=$T"
ok 'tiene "No se pudo entrar" && tiene "ya se usó"' "enlace de entrada repetido"

echo
echo "Resultado: $BIEN bien, $MAL mal"
[ "$MAL" = 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$MAL" = 0 ]
