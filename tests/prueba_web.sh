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
ok 'grep -q "200.\{1,2\}subscribed_apps" "$SIM_LOG" && ! tiene "todavía no avisará"' "suscribe la app a la cuenta para recibir mensajes"
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
ok '[ "$(PGPASSWORD="${PGPASSWORD:-}" psql -tAc "SELECT count(*) FROM webhook_log WHERE error LIKE '"'"'Firma no valida%'"'"'" -d tuwhatsapp_prueba)" = 1 ]' "la firma mala queda anotada para revisar"
ok '[ "$(webhook "$MSG")" = 200 ]' "mensaje firmado aceptado"
sleep 0.3

echo "== Bandeja y chat"
get "/?r=bandeja";  ok 'tiene "Peluquería Ana" && tiene "Automático: apagado" && tiene "id=\"activar-avisos\""' "aparece en la bandeja (con botón de avisos)"
ok '! tiene "<b>precios</b>" && tiene "&lt;b&gt;precios"' "el texto del cliente se escapa"
get "/?r=nuevos";   ok 'grep -q "\"sin_leer\":1" "$P"' "1 sin leer"
ok 'grep -q "\"aviso\":{\"id\":[0-9]*,\"nombre\":\"Peluquería Ana\",\"texto\":\"Hola, quiero <b>precios</b>\"" "$P" && grep -q "r=chat" "$P"' "datos para el aviso del navegador"
CODIGO=$(curl -s -o "$P" -w '%{http_code} %{content_type}' "$W/sw.js"); ok '[ "${CODIGO%% *}" = 200 ] && tiene "notificationclick"' "service worker de los avisos"
get "/?r=bandeja"
C=$(grep -o 'r=chat&amp;c=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=chat&c=$C"
ok 'tiene "Respuestas automáticas apagadas" && ! tiene "Enviar desde el celular" && ! tiene "Guardar en Pendientes"' "número solo API: sin botón del celular"
PGPASSWORD="${PGPASSWORD:-}" psql -qc "UPDATE lineas SET coexistencia = true" -d tuwhatsapp_prueba
get "/?r=chat&c=$C"
ok 'tiene "wa.me/593991111111" && tiene "Enviar desde el celular"' "con la app del celular (coexistencia): enlace gratis"
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
ok '[ "$CODIGO" = 200 ] && tiene "Plantillas" && tiene "Gasto" && tiene "Catálogo" && tiene "Chatbots" && tiene "Equipo y reparto" && ! tiene "Pedir a Tukán"' "Más: Tukán tiene todo"
get "/?r=bandeja"; ok 'tiene "r=embudo" && tiene "r=mas"' "menú de abajo con Embudo y Más"
get "/?r=cliente&id=$EMP"
ok 'tiene "Plan y extras" && tiene "Todo incluido" && tiene "Asistente con IA y agente que contesta solo"' "Tukán ve el plan y el extra de IA del cliente"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=plan:todo_ia"
ok 'tiene "Plan Todo incluido + IA aplicado" && tiene "Usuarios permitidos<input type=\"number\" name=\"max_usuarios\" min=\"1\" max=\"500\" value=\"50\""' "aplicar el plan Todo incluido + IA con un toque"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=modulos" --data-urlencode "modulos[]=reportes" --data-urlencode "modulos[]=inventado"
ok 'tiene "Módulos guardados"' "guardar extras del cliente (sin IA)"
post "/?r=cliente&id=$EMP" --data-urlencode "accion=datos" --data-urlencode "nombre=Salón Bella" --data-urlencode "estado=activa" --data-urlencode "vence=" \
     --data-urlencode "motivo_suspension=" --data-urlencode "max_numeros=1" --data-urlencode "max_usuarios=2"
J="$BELLA_J"
seguir "/?r=campanias"
ok '! tiene "no está incluido en tu plan"' "todo incluido: campañas abiertas sin pagar aparte"
seguir "/?r=ia"
ok 'tiene "no está incluido en tu plan" && tiene "Extra" && tiene "Pedir a Tukán"' "sin el extra de IA: bloqueada y se puede pedir"
seguir "/?r=embudo"; ok '! tiene "no está incluido en tu plan"' "embudo abierto (todo incluido)"

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
ok 'grep -q "\"protocolo_max\":2" "$P"' "estado dice que ya habla el protocolo v2"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tukan_central.php?accion=estado&t=$T")
ok '[ "$CODIGO" = 401 ] && grep -q "ya se usó" "$P" && grep -q "\"codigo\":\"nonce\"" "$P"' "el mismo enlace no sirve dos veces (con codigo)"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/tukan_central.php?accion=capacidades")
ok '[ "$CODIGO" = 405 ] && grep -q "\"codigo\":\"invalido\"" "$P"' "acción v2 por GET -> 405 invalido"
# Pedido v2 como lo hace el panel: POST, JSON y el token (con bh) en la cabecera X-Tukan-Token
pedirV2() { CUERPO="$2" TIPO="$1" php -r '$b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "="); $n = time(); $t = getenv("TIPO");
  $p = $b(json_encode(["v"=>2,"tipo"=>$t,"inst"=>"tuwhatsapp","email"=>"dimi@tukanec.com","nombre"=>"Dimitry","rol"=>"dueno","iat"=>$n,"exp"=>$n+($t==="ejecutar"?30:60),
       "nonce"=>bin2hex(random_bytes(16)),"bh"=>$b(hash("sha256", getenv("CUERPO"), true))]));
  echo $p . "." . $b(hash_hmac("sha256", $p, str_repeat("a1", 32), true));' > "$P.t"
  CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Content-Type: application/json; charset=utf-8" -H "X-Tukan-Token: $(cat "$P.t")" --data-binary "$2" "$W/tukan_central.php?accion=$1"); }
pedirV2 capacidades '{}'
ok '[ "$CODIGO" = 200 ] && grep -q "\"protocolo\":2" "$P" && grep -q "\"crear_demo\"" "$P" && grep -q "\"dias_prueba_max\":90" "$P"' "capacidades v2 por HTTP"
DEMO='{"operacion_id":"0123456789abcdef0123456789abcdef","accion":"crear_demo","datos":{"nombre":"Demo Web","contacto_nombre":"Rosa Web","email":"rosa@demoweb.ec","telefono":"0991112233","plan":null,"dias":7,"enviar_correo":false,"extra":{}}}'
pedirV2 ejecutar "$DEMO"
ok '[ "$CODIGO" = 200 ] && grep -q "\"clave\":\"[a-z0-9-]\{14\}\"" "$P" && grep -q "\"origen\":\"central\"" "$P"' "crear demo desde el panel (HTTP)"
DEMO_ID=$(grep -o '"cliente_id":"[0-9]*"' "$P" | head -1 | grep -o '[0-9]*')
pedirV2 ejecutar "$DEMO"
ok '[ "$CODIGO" = 200 ] && grep -q "\"repetida\":true" "$P" && grep -q "\"clave\":null" "$P"' "crear demo repetido: sin clave"
pedirV2 clientes '{"estados":["prueba"]}'
ok '[ "$CODIGO" = 200 ] && grep -q "Demo Web" "$P"' "la demo sale en clientes"
J="$(mktemp)"
T=$(tokenCentral sso)
seguir "/tukan_central.php?accion=sso&t=$T"
ok 'tiene "Clientes de Tukán" && tiene "← Panel Tukán" && tiene "Entraste desde el Panel Tukán Central"' "entrar con un toque desde el panel central"
seguir "/tukan_central.php?accion=sso&t=$T"
ok 'tiene "No se pudo entrar" && tiene "ya se usó"' "enlace de entrada repetido"
CODIGO=$(curl -s -D - -o /dev/null "$W/tukan_central.php?accion=sso&t=$T" | grep -i -c "X-Tukan-Codigo: nonce")
ok '[ "$CODIGO" = 1 ]' "el SSO repetido trae la cabecera X-Tukan-Codigo"
tokenIr() { IR="$1" php -r '$b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "="); $n = time();
  $p = $b(json_encode(["v"=>1,"tipo"=>"sso","inst"=>"tuwhatsapp","email"=>"dimi@tukanec.com","nombre"=>"Dimitry","iat"=>$n,"exp"=>$n+60,"nonce"=>bin2hex(random_bytes(16)),"ir"=>getenv("IR")]));
  echo $p . "." . $b(hash_hmac("sha256", $p, str_repeat("a1", 32), true));'; }
J="$(mktemp)"
seguir "/tukan_central.php?accion=sso&t=$(tokenIr "/?r=cliente&id=$DEMO_ID")"
ok 'tiene "Demo Web" && tiene "Plan y extras"' "SSO con enlace directo a la ficha del cliente"
ok 'tiene "Desde el Panel Tukán Central" && tiene "Demo creada: Demo Web" && tiene "Dimitry &lt;dimi@tukanec.com&gt;"' "la ficha muestra lo que hizo el panel"
J="$(mktemp)"
CODIGO=$(curl -s -D - -o /dev/null -b "$J" -c "$J" "$W/tukan_central.php?accion=sso&t=$(tokenIr "//malo.com")" | grep -i "^Location:" | tr -d '\r')
ok '[ "$CODIGO" = "Location: /?r=clientes" ]' "SSO con ir malo va al inicio"
seguir "/?r=clientes"; ok 'tiene "El enlace directo no era válido"' "y avisa que el enlace no era válido"

echo "== Catálogo, cobros, carrito y cotización"
J="$TUKAN_J"
get "/?r=catalogo"
ok '[ "$CODIGO" = 200 ] && tiene "Todavía no hay productos" && tiene "Subir muchos productos con Excel"' "catálogo vacío"
F="$(mktemp --suffix=.jpg)"; printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00' > "$F"; head -c 300 /dev/zero >> "$F"
CODIGO=$(curl -s -L -o "$P.2" -w '%{http_code}' -b "$J" -c "$J" -F "csrf=$(csrf)" -F "accion=guardar" -F "nombre=Plan TuSalón" -F "precio=29,90" -F "stock=" \
         -F "codigo=TS-1" -F "descripcion=Agenda y caja para peluquerías" -F "activo=1" -F "foto=@$F;filename=tusalon.jpg" "$W/?r=catalogo"); mv "$P.2" "$P"
ok 'tiene "Producto guardado" && tiene "Plan TuSalón" && tiene "\$29,90" && tiene "r=foto_producto"' "crear producto con foto"
FID=$(grep -o 'r=foto_producto&amp;id=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
TIPO=$(curl -s -o /dev/null -w '%{content_type}' -b "$J" "$W/?r=foto_producto&id=$FID"); ok '[ "$TIPO" = "image/jpeg" ]' "se ve la foto del producto"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -b "$BELLA_J" "$W/?r=foto_producto&id=$FID"); ok '[ "$CODIGO" != 200 ]' "otro cliente no ve la foto"
post "/?r=catalogo" --data-urlencode "accion=guardar" --data-urlencode "nombre=Plan TuGuardería" --data-urlencode "precio=abc"
ok 'tiene "no es un número"' "precio con letras no se acepta"
CSV="$(mktemp --suffix=.csv)"; printf 'nombre;precio;stock;codigo;descripcion\nPlan TuEvento;35;2;TE-1;Salones de eventos\n' > "$CSV"
get "/?r=catalogo"
CODIGO=$(curl -s -L -o "$P.2" -w '%{http_code}' -b "$J" -c "$J" -F "csrf=$(csrf)" -F "accion=importar" -F "archivo=@$CSV;filename=productos.csv" "$W/?r=catalogo"); mv "$P.2" "$P"
ok 'tiene "1 productos nuevos" && tiene "Plan TuEvento" && tiene "stock 2"' "subir productos con CSV"
PID=$(grep -o 'r=catalogo&amp;editar=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=catalogo&editar=$PID"; ok 'tiene "Cambiar producto"' "editar producto"
get "/?r=cobros"
post "/?r=cobros" --data-urlencode "negocio_ruc=1793135099001" --data-urlencode "iva_pct=15" --data-urlencode "negocio_direccion=Quito" --data-urlencode "cotizacion_dias=10" \
     --data-urlencode "pago_transferencia=Banco Pichincha 2201" --data-urlencode "pago_enlace=https://pay.ejemplo.com/{total}" --data-urlencode "payphone_token=" --data-urlencode "payphone_store="
ok 'tiene "Guardado" && tiene "value=\"1793135099001\"" && tiene "Banco Pichincha 2201"' "guardar cobros"
post "/?r=cobros" --data-urlencode "pago_enlace=http://malo.com" --data-urlencode "iva_pct=0"
ok 'tiene "debe empezar con https"' "el enlace de pago debe ser https"
get "/?r=chat&c=$C"
ok 'tiene "Carrito y cotización" && tiene "Plan TuSalón"' "el chat tiene carrito con los productos"
TS=$(grep -o '<option value="[0-9]*">Plan TuSalón' "$P" | head -1 | grep -o '[0-9]*')
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_agregar" --data-urlencode "producto_id=$TS" --data-urlencode "cantidad=2"
ok 'tiene "Agregado al carrito. Total \$68,77" && tiene "2 x <!--notr-->Plan TuSalón"' "agregar al carrito desde el chat (con IVA)"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_resumen"
ok 'tiene "Resumen enviado"' "mandar resumen del pedido"
ok 'grep -q "pay.ejemplo.com.\{1,2\}68.77" "$SIM_LOG"' "el resumen lleva el enlace de pago con el total"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_cotizacion"
ok 'tiene "Cotización enviada"' "mandar cotización en PDF"
ok 'grep -q "Cotizacion-0000[0-9].pdf" "$SIM_LOG" && grep -q "application.\{1,2\}pdf" "$SIM_LOG"' "Meta recibió el PDF"
post "/?r=chat&c=$C" --data-urlencode "accion=enviar_producto" --data-urlencode "producto_id=$TS"
ok 'tiene "Producto enviado" && tiene "Plan TuSalón"' "mandar un producto con foto"
post "/?r=chat&c=$C" --data-urlencode "accion=enviar_catalogo"
ok 'tiene "Catálogo enviado"' "mandar el catálogo para elegir"
get "/?r=pedidos"
ok 'tiene "Pedidos" && tiene "Carrito" && tiene "\$68,77"' "lista de pedidos"
PED=$(grep -o 'r=pedido&amp;id=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=pedido&id=$PED"
ok 'tiene "Pedido #" && tiene "Confirmar (pendiente de pago)" && tiene "Descargar PDF"' "ficha del pedido"
post "/?r=pedido&id=$PED" --data-urlencode "accion=libre" --data-urlencode "nombre=Instalación" --data-urlencode "precio=10"
ok 'tiene "Agregado" && tiene "Instalación"' "agregar algo fuera del catálogo"
post "/?r=pedido&id=$PED" --data-urlencode "accion=estado" --data-urlencode "estado=pendiente"
ok 'tiene "Pendiente de pago" && tiene "Marcar pagado"' "confirmar el pedido"
post "/?r=pedido&id=$PED" --data-urlencode "accion=estado" --data-urlencode "estado=pagado"
ok 'tiene "Pedido: Pagado" && tiene "Pagado el"' "marcar pagado"
get "/?r=chat&c=$C"; ok 'tiene "value=\"ganado\" selected"' "pagado: el embudo pasa a Ganado"
TIPO=$(curl -s -o "$P" -w '%{content_type}' -b "$J" "$W/?r=cotizacion&id=$PED")
ok '[ "$TIPO" = "application/pdf" ] && head -c 8 "$P" | grep -q "%PDF-1.4"' "descargar la cotización en PDF"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -b "$BELLA_J" "$W/?r=cotizacion&id=$PED"); ok '[ "$CODIGO" = 302 ]' "otro cliente no descarga la cotización"
post "/?r=cobros" --data-urlencode "iva_pct=0" --data-urlencode "pago_enlace=" --data-urlencode "pago_transferencia="

echo "== Cobro con tarjeta (Plux)"
SQL() { PGPASSWORD="${PGPASSWORD:-}" psql -tAc "$1" -d tuwhatsapp_prueba; }
LID=$(SQL "SELECT id FROM lineas ORDER BY id LIMIT 1")
get "/?r=cobros"
ok 'tiene "Cómo inscribirte en Plux" && tiene "plux.ec/contactos" && tiene "Datos para darle a Plux"' "guía para inscribirse en Plux"
ok 'tiene "https://whatsapp.ejemplo.com/plux.php" && tiene "tukan$LID" && tiene "https://whatsapp.ejemplo.com/pagar.php</code> (una por pedido)\|https://whatsapp.ejemplo.com/pagar.php?p=" && tiene "<b>sin parámetros</b>: <code>https://whatsapp.ejemplo.com/pagar.php</code>"' "datos técnicos para Plux (aviso de pago y regreso 3DS)"
CLAVE_AVISO=$(SQL "SELECT plux_aviso FROM lineas WHERE id = $LID")
ok 'tiene "$CLAVE_AVISO" && [ ${#CLAVE_AVISO} = 32 ]' "clave del aviso de pago creada"
post "/?r=cobros" --data-urlencode "iva_pct=0" --data-urlencode "plux_llave=no sirve"
ok 'tiene "llave pública de Plux no es válida"' "llave pública mala: avisa y no guarda"
get "/?r=cobros"
post "/?r=cobros" --data-urlencode "iva_pct=0" --data-urlencode "pago_transferencia=Banco Pichincha 2201" --data-urlencode "cobro_tarjeta=plux" \
     --data-urlencode "plux_establecimiento=EST-123" --data-urlencode "plux_cliente=cliente_plux_prueba" --data-urlencode "plux_clave=clave_plux_prueba" \
     --data-urlencode "plux_llave=$(cat "$PLUX_PUB")" --data-urlencode "plux_ambiente=sandbox"
ok 'tiene "Guardado" && tiene "Plux conectado en <b>pruebas</b>" && tiene "value=\"plux\" selected" && ! tiene "clave_plux_prueba"' "conectar Plux (en pruebas), la clave no se muestra"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_agregar" --data-urlencode "producto_id=$TS" --data-urlencode "cantidad=1"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_resumen"
ok 'tiene "Resumen enviado" && grep -q "whatsapp.ejemplo.com.\{1,2\}pagar.php?p=[a-f0-9]\{32\}" "$SIM_LOG"' "el resumen lleva la página de pago de Plux"
TOK=$(SQL "SELECT pago_token FROM pedidos WHERE metodo = 'plux' ORDER BY id DESC LIMIT 1")
PG=$(SQL "SELECT id FROM pedidos WHERE pago_token = '$TOK'")
PJ="$(mktemp)"   # cookies del comprador (otro navegador)
pagina() { CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$PJ" -c "$PJ" "$W/pagar.php?$1"); }
pagar() { CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$PJ" -c "$PJ" -H "Content-Type: application/json" --data-binary "$1" "$W/pagar.php"); }
csrfp() { grep -o 'data-csrf="[a-f0-9]*"' "$P" | head -1 | sed 's/.*="//;s/"$//'; }
pagina "p=$TOK"
ok '[ "$CODIGO" = 200 ] && tiene "Pagar pedido #" && tiene "Total a pagar" && tiene "\$29,90" && tiene "Modo de pruebas de Plux" && tiene "BEGIN PUBLIC KEY" && tiene "crypto-js.min.js"' "página de pago con el valor exacto y el formulario"
ok '! tiene "Recordar llamar" && ! tiene "593991111111"' "la página de pago no muestra datos del chat"
pagina "p=00000000000000000000000000000000"; ok '[ "$CODIGO" = 404 ] && tiene "no existe"' "enlace de pago que no existe"
pagina "p=$TOK"; CS=$(csrfp)
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" malo 4540639936908783)"
ok '[ "$CODIGO" = 400 ] && tiene "Recárgala"' "sin la clave de la página (csrf) no cobra"
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" "$CS" 4111111111111111)"
ok 'tiene "\"status\":\"failed\"" && tiene "Tu banco no aprobó el pago"' "tarjeta rechazada: mensaje claro"
ok '[ "$(SQL "SELECT estado || plux_paso FROM pedidos WHERE id = $PG")" = carritofallido ]' "rechazada: el pedido sigue abierto"
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" "$CS" 36417200103608)"
ok 'tiene "\"status\":\"otp_required\"" && tiene "OTP-1"' "el banco pide el código (OTP)"
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" "$CS" 36417200103608 999999)"
ok 'tiene "otp_incorrecto"' "código OTP incorrecto"
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" "$CS" 36417200103608 123456)"
ok 'tiene "\"status\":\"success\"" && tiene "r=ok"' "con el código OTP correcto: aprobado"
ok '[ "$(SQL "SELECT estado || '"'"' '"'"' || metodo || '"'"' '"'"' || plux_paso FROM pedidos WHERE id = $PG")" = "pagado plux pagado" ]' "el pedido queda Pagado con Plux"
ok 'grep -q "Recibimos tu pago de \$29,90" "$SIM_LOG"' "al cliente le llega la confirmación por WhatsApp"
U=$(tail -1 "$PLUX_LOG")
ok 'echo "$U" | grep -q "\"auth_ok\":true" && echo "$U" | grep -q "\"clave_ok\":true" && echo "$U" | grep -q "\"tarjeta_ok\":true" && echo "$U" | grep -q "\"establecimiento_ok\":true"' "Plux recibió la tarjeta bien cifrada y las credenciales del negocio"
ok 'echo "$U" | grep -q "\"retorno_limpio\":true" && echo "$U" | grep -q "\"monto\":29.9" && echo "$U" | grep -q "\"con_iva\":false" && echo "$U" | grep -q "\"descripcion\":\"WT-$PG Pedido #"' "regreso 3DS sin parámetros, valor final y referencia del pedido"
ok '[ "$(SQL "SELECT count(*) FROM pedidos WHERE plux_respuesta LIKE '"'"'%36417200103608%'"'"' OR plux_respuesta LIKE '"'"'%4540639936908783%'"'"'")" = 0 ] && ! grep -q "36417200103608" /tmp/tuwa_err.log 2>/dev/null' "el número de la tarjeta no se guarda"
pagina "p=$TOK"; ok 'tiene "ya está pagado" && ! tiene "plux-caja"' "pagado: la página ya no pide la tarjeta"
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK" "$CS" 4540639936908783)"
ok 'tiene "\"status\":\"success\""' "pagar dos veces no cobra otra vez"
ok '[ "$(grep -c "\"ruta\":\"pago\"" "$PLUX_LOG")" = 4 ]' "a Plux solo llegaron los 4 cobros de verdad"
get "/?r=pedido&id=$PG"; ok 'tiene "Pagado con tarjeta por Plux" && tiene "transacción FALSO-"' "en la ficha del pedido se ve el pago con tarjeta"
ok '! grep -q "<textarea[^>]*><!--notr" "$P"' "las notas no muestran marcas raras"
get "/?r=chat&c=$C"; ok '! grep -q "<textarea[^>]*><!--notr" "$P" && grep -q "<textarea name=\"notas\" rows=\"3\"" "$P"' "notas del chat sin marcas raras"

echo "== Plux: validación 3D Secure del banco"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_agregar" --data-urlencode "producto_id=$TS" --data-urlencode "cantidad=1"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_resumen"
TOK2=$(SQL "SELECT pago_token FROM pedidos WHERE metodo = 'plux' AND estado = 'carrito' ORDER BY id DESC LIMIT 1")
pagina "p=$TOK2"; CS=$(csrfp)
pagar "$(php tests/plux_tarjeta.php "$PLUX_PUB" "$TOK2" "$CS" 5555555555554444)"
ok 'tiene "3ds_required" && tiene "/banco?ref="' "el banco pide validación 3DS"
BANCO=$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["detail"]["url"];' "$P")
VUELTA=$(curl -s -o /dev/null -w '%{redirect_url}' "$BANCO")
ok 'echo "$VUELTA" | grep -q "^https://whatsapp.ejemplo.com/pagar.php?pti="' "el banco vuelve a la página de pago sin nuestros parámetros"
CODIGO=$(curl -s -o "$P" -w '%{http_code} %{redirect_url}' "$W/pagar.php?${VUELTA#*\?}")
ok 'echo "$CODIGO" | grep -q "^400" && tiene "No pudimos reconocer tu pedido"' "volver del banco en otro navegador: no se aplica a ciegas"
CODIGO=$(curl -s -o "$P" -w '%{http_code} %{redirect_url}' -b "$PJ" -c "$PJ" "$W/pagar.php?${VUELTA#*\?}")
ok 'echo "$CODIGO" | grep -q "^303 .*p=$TOK2.*r=ok"' "volver del banco: aprobado"
ok '[ "$(SQL "SELECT estado FROM pedidos WHERE pago_token = '"'"'$TOK2'"'"'")" = pagado ]' "pagado después de la validación 3DS"

echo "== Plux: aviso de pago (webhook)"
get "/?r=chat&c=$C"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_agregar" --data-urlencode "producto_id=$TS" --data-urlencode "cantidad=2"
post "/?r=chat&c=$C" --data-urlencode "accion=carrito_resumen"
PG3=$(SQL "SELECT id FROM pedidos WHERE metodo = 'plux' AND estado = 'carrito' ORDER BY id DESC LIMIT 1")
TOT3=$(SQL "SELECT total FROM pedidos WHERE id = $PG3")
avisoplux() { curl -s -o "$P" -w '%{http_code}' -u "$1" -H "Content-Type: application/json" --data-binary "$2" "$W/plux.php"; }
ok '[ "$(curl -s -o /dev/null -w "%{http_code}" "$W/plux.php")" = 405 ]' "el aviso de pago solo acepta POST"
ok '[ "$(avisoplux "tukan$LID:mala" "{\"state\":\"PAGADO\",\"description\":\"WT-$PG3\"}")" = 401 ]' "aviso de pago con clave mala"
ok '[ "$(avisoplux "tukan$LID:$CLAVE_AVISO" "{\"state\":\"PAGADO\",\"description\":\"WT-$PG3\",\"amount\":\"1.00\"}")" = 409 ]' "aviso con otro valor: no se aplica"
ok '[ "$(avisoplux "tukan$LID:$CLAVE_AVISO" "{\"state\":\"PAGADO\",\"description\":\"WT-$PG3 Pedido\",\"amount\":\"$TOT3\",\"id_transaccion\":\"PX-77\"}")" = 200 ] && tiene "\"resultado\":\"pagado\""' "aviso PAGADO: el pedido pasa a Pagado"
ok '[ "$(SQL "SELECT estado || '"'"' '"'"' || plux_transaccion FROM pedidos WHERE id = $PG3")" = "pagado PX-77" ]' "queda con el número de transacción de Plux"
ok '[ "$(avisoplux "cliente_plux_prueba:clave_plux_prueba" "{\"state\":\"PAGADO\",\"id_transaccion\":\"PX-77\"}")" = 200 ] && tiene "ya_estaba_pagado"' "aviso repetido (con las credenciales de Plux): no hace nada"
pagina "p=$(SQL "SELECT pago_token FROM pedidos WHERE id = $PG3")"; ok 'tiene "ya está pagado"' "la página dice que ya está pagado"
get "/?r=cobros"
post "/?r=cobros" --data-urlencode "iva_pct=0" --data-urlencode "pago_transferencia=" --data-urlencode "quitar_plux=1" --data-urlencode "cobro_tarjeta="
ok 'tiene "Guardado" && ! tiene "Plux conectado"' "quitar Plux"

echo "== Chatbots"
get "/?r=flujos"; ok 'tiene "Todavía no tienes chatbots"' "chatbots vacío"
post "/?r=flujos" --data-urlencode "nombre=Bienvenida" --data-urlencode "disparador=palabras" --data-urlencode "palabras=hola, info"
ok 'tiene "Chatbot creado con un ejemplo" && tiene "Paso 1" && tiene "Paso 3" && tiene "Ver productos"' "crear chatbot con ejemplo"
FL=$(grep -o 'name="csrf"' "$P" | head -1 >/dev/null; PGPASSWORD="${PGPASSWORD:-}" psql -tAc "SELECT id FROM flujos ORDER BY id DESC LIMIT 1" -d tuwhatsapp_prueba)
post "/?r=flujo&id=$FL" --data-urlencode "accion=probar" --data-urlencode "entradas=$(printf 'hola\n2')"
ok 'tiene "Soy el asistente de Tukán ventas" && tiene "en un momento te atiende una persona" && tiene "Aquí pasa a una persona"' "probar el chatbot sin mandar nada"
post "/?r=flujo&id=$FL" --data-urlencode "accion=agregar_paso" --data-urlencode "nombre=Bienvenida" --data-urlencode "disparador=palabras" --data-urlencode "palabras=hola, info" \
     --data-urlencode "paso[0][id]=p1" --data-urlencode "paso[0][tipo]=botones" --data-urlencode "paso[0][texto]=¿En qué te ayudo?" \
     --data-urlencode "paso[0][op_titulo][0]=Precios" --data-urlencode "paso[0][op_ir][0]=p2" --data-urlencode "paso[0][op_titulo][1]=Asesor" --data-urlencode "paso[0][op_ir][1]=p3" \
     --data-urlencode "paso[1][id]=p2" --data-urlencode "paso[1][tipo]=fin" --data-urlencode "paso[1][texto]=Te mando precios" \
     --data-urlencode "paso[2][id]=p3" --data-urlencode "paso[2][tipo]=persona" --data-urlencode "paso[2][texto]=Ya te atienden"
ok 'tiene "Paso agregado al final" && tiene "Paso 4"' "agregar un paso"
post "/?r=flujo&id=$FL" --data-urlencode "accion=guardar" --data-urlencode "nombre=Bienvenida" --data-urlencode "disparador=palabras" --data-urlencode "palabras=" --data-urlencode "activo=1" \
     --data-urlencode "paso[0][id]=p1" --data-urlencode "paso[0][tipo]=mensaje" --data-urlencode "paso[0][texto]=Hola"
ok 'tiene "al menos una palabra"' "encender sin palabras: avisa"
post "/?r=flujo&id=$FL" --data-urlencode "accion=guardar" --data-urlencode "nombre=Bienvenida" --data-urlencode "disparador=palabras" --data-urlencode "palabras=hola" --data-urlencode "activo=1" \
     --data-urlencode "paso[0][id]=p1" --data-urlencode "paso[0][tipo]=mensaje" --data-urlencode "paso[0][texto]=Hola desde el chatbot" --data-urlencode "paso[0][siguiente]=fin"
ok 'tiene "Chatbot guardado" && tiene "Encendido"' "guardar y encender"
get "/?r=flujos"; ok 'tiene "Bienvenida" && tiene "Encendido" && tiene "1 paso"' "lista de chatbots"
get "/?r=reglas"; ok 'tiene "Qué contesta primero" && tiene "r=flujos" && tiene "name=\"callar_al_atender\""' "Automático explica el orden"
post "/?r=flujo&id=$FL" --data-urlencode "accion=borrar"
ok 'tiene "Chatbot borrado"' "borrar chatbot"

echo "== Equipo, reparto y campos"
get "/?r=equipo"
ok '[ "$CODIGO" = 200 ] && tiene "Reparto automático" && tiene "Esperando respuesta" && tiene "Luis"' "vista del supervisor"
post "/?r=equipo" --data-urlencode "accion=reparto" --data-urlencode "reparto=turnos" --data-urlencode "regla_palabras[0]=factura" --data-urlencode "regla_usuario[0]=0"
ok 'tiene "Reparto guardado" && tiene "value=\"turnos\" checked"' "guardar reparto por turnos"
LU=$(PGPASSWORD="${PGPASSWORD:-}" psql -tAc "SELECT id FROM usuarios WHERE correo = 'luis@tukan.com'" -d tuwhatsapp_prueba)
post "/?r=equipo" --data-urlencode "accion=persona" --data-urlencode "id=$LU" --data-urlencode "que=disponible" --data-urlencode "valor=0"
ok 'tiene "Ausente"' "marcar ausente"
post "/?r=equipo" --data-urlencode "accion=persona" --data-urlencode "id=$LU" --data-urlencode "que=disponible" --data-urlencode "valor=1"
post "/?r=equipo" --data-urlencode "accion=reparto" --data-urlencode "reparto=manual"
get "/?r=campos"
post "/?r=campos" --data-urlencode "accion=crear" --data-urlencode "nombre=Ciudad" --data-urlencode "tipo=lista" --data-urlencode "opciones=Quito, Guayaquil"
ok 'tiene "Campo agregado" && tiene "Quito, Guayaquil"' "crear campo personalizado"
get "/?r=chat&c=$C"; ok 'tiene "name=\"campo\[ciudad\]\""' "el campo sale en el chat"
post "/?r=chat&c=$C" --data-urlencode "accion=datos" --data-urlencode "nombre=Ana Salón Quito" --data-urlencode "notas=Quiere TuSalón" --data-urlencode "etiquetas=" --data-urlencode "campo[ciudad]=quito"
ok 'tiene "Datos guardados" && tiene "<option selected>Quito</option>"' "guardar el valor del campo"
post "/?r=chat&c=$C" --data-urlencode "accion=datos" --data-urlencode "nombre=Ana" --data-urlencode "campo[ciudad]=Lima"
ok 'tiene "debe ser una de estas opciones"' "valor que no está en la lista"
TIPO=$(curl -s -o "$P" -w '%{content_type}' -b "$J" "$W/?r=integraciones&bajar=contactos")
ok 'tiene "Llegó del anuncio\";Ciudad" && tiene ";Quito"' "los campos personalizados salen en la descarga a Excel"

echo "== Agente con IA, anuncios y reportes"
get "/?r=ia"
post "/?r=ia" --data-urlencode "accion=bot" --data-urlencode "bot_activo=1" --data-urlencode "bot_cuando=fuera_horario" --data-urlencode "bot_nombre=Sofía" --data-urlencode "bot_max_dia=10" --data-urlencode "bot_vende=1"
ok 'tiene "Agente con IA encendido" && tiene "value=\"Sofía\"" && tiene "value=\"fuera_horario\" selected"' "encender el agente con IA"
post "/?r=ia" --data-urlencode "accion=bot"
ok 'tiene "Agente con IA guardado (apagado)"' "apagar el agente"
T=$(date +%s)
AD='{"object":"whatsapp_business_account","entry":[{"id":"200","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"phone_number_id":"100"},"contacts":[{"profile":{"name":"Vino del anuncio"},"wa_id":"593993330001"}],"messages":[{"from":"593993330001","id":"wamid.WEBAD","timestamp":"'$T'","type":"text","text":{"body":"Hola, vi su anuncio"},"referral":{"source_id":"120200001","source_type":"ad","source_url":"https://fb.me/x","headline":"Prueba gratis 7 días"}}]}}]}]}'
ok '[ "$(webhook "$AD")" = 200 ]' "llega alguien desde un anuncio"
get "/?r=reportes"
ok 'tiene "De qué anuncio llegaron" && tiene "Prueba gratis 7 días" && tiene "Pedidos pagados en el período"' "reporte de anuncios y pedidos"
get "/?r=bandeja"
CA=$(grep -o 'r=chat&amp;c=[0-9]*' "$P" | head -1 | grep -o '[0-9]*$')
get "/?r=chat&c=$CA"; ok 'tiene "Llegó desde el anuncio" && tiene "Prueba gratis 7 días"' "el chat muestra de qué anuncio llegó"

echo "== Tienda en línea"
get "/?r=tienda"
ok '[ "$CODIGO" = 200 ] && tiene "Shopify" && tiene "recordatorio_pago (es)"' "pantalla de tienda con las plantillas aprobadas"
post "/?r=tienda" --data-urlencode "tienda_tipo=shopify" --data-urlencode "tienda_secreto=secreto_shopify" --data-urlencode "tienda_plantilla_pedido=recordatorio_pago|es" --data-urlencode "tienda_carrito_horas=2"
ok 'tiene "Ahora pega la dirección" && tiene "/tienda.php?c=" && tiene "Configuración → Notificaciones → Webhooks"' "conectar Shopify muestra la dirección y los pasos"
CT=$(grep -o 'tienda.php?c=[a-f0-9]*' "$P" | head -1 | sed 's/.*c=//')
PEDIDO='{"id":9001,"name":"#2001","total_price":"40.00","total_tax":"0","financial_status":"paid","phone":"+593981234500","customer":{"first_name":"Carla","last_name":"Mora"},"line_items":[{"title":"Gorra","price":"20.00","quantity":2}]}'
FIRMA=$(printf '%s' "$PEDIDO" | openssl dgst -sha256 -hmac secreto_shopify -binary | base64)
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -H "Content-Type: application/json" -H "X-Shopify-Topic: orders/create" -H "X-Shopify-Hmac-Sha256: $FIRMA" --data-binary "$PEDIDO" "$W/tienda.php?c=$CT")
ok '[ "$CODIGO" = 200 ]' "Shopify manda un pedido firmado"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' -H "X-Shopify-Topic: orders/create" -H "X-Shopify-Hmac-Sha256: malo" --data-binary "$PEDIDO" "$W/tienda.php?c=$CT")
ok '[ "$CODIGO" = 401 ]' "firma mala: rechazado"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' --data-binary "$PEDIDO" "$W/tienda.php?c=000000000000000000000000"); ok '[ "$CODIGO" = 404 ]' "clave que no existe"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "$W/tienda.php?c=$CT"); ok '[ "$CODIGO" = 405 ]' "solo acepta POST"
get "/?r=tienda"; ok 'tiene "Últimos pedidos de la tienda" && tiene "Carla Mora" && tiene "\$40,00" && tiene "firma mal"' "el pedido aparece y se avisa la firma mala"
get "/?r=pedidos"; ok 'tiene "Shopify" && tiene "Carla Mora"' "el pedido de Shopify está en Pedidos"
ok 'grep -q "\"to\":\"593981234500\"" "$SIM_LOG" && grep -q "recordatorio_pago" "$SIM_LOG"' "al comprador le llega la plantilla por WhatsApp"
post "/?r=tienda" --data-urlencode "tienda_tipo=woocommerce" --data-urlencode "tienda_secreto="
CODIGO=$(curl -s -o "$P" -w '%{http_code}' --data-binary "webhook_id=55" "$W/tienda.php?c=$CT"); ok '[ "$CODIGO" = 200 ]' "WooCommerce: el ping al crear el webhook responde bien"
post "/?r=tienda" --data-urlencode "tienda_tipo="

echo "== App en el celular"
get "/?r=app"
ok '[ "$CODIGO" = 200 ] && tiene "Instalar la app" && tiene "data-vapid=\"B" && tiene "Activar avisos"' "pantalla de la app con la clave de avisos"
CODIGO=$(curl -s -o "$P" -w '%{http_code} %{content_type}' "$W/manifest.json"); ok '[ "${CODIGO%% *}" = 200 ] && tiene "\"display\": \"standalone\"" && tiene "icono-512.png"' "manifiesto para instalar"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "$W/assets/icono-192.png"); ok '[ "$CODIGO" = 200 ]' "ícono de la app"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/sw.js"); ok 'tiene "addEventListener('"'"'push'"'"'"' "el service worker recibe los avisos"
get "/?r=bandeja"; ok 'tiene "rel=\"manifest\""' "todas las páginas enlazan el manifiesto"
get "/?r=app"
SUB=$(php -r '$k = openssl_pkey_new(["curve_name" => "prime256v1", "private_key_type" => OPENSSL_KEYTYPE_EC]); $d = openssl_pkey_get_details($k);
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  echo json_encode(["endpoint" => getenv("SIM_URL") . "/push/ok/web1", "keys" => ["p256dh" => $b("\x04" . str_pad($d["ec"]["x"], 32, "\0", STR_PAD_LEFT) . str_pad($d["ec"]["y"], 32, "\0", STR_PAD_LEFT)), "auth" => $b(random_bytes(16))]]);')
post "/?r=push" --data-urlencode "accion=suscribir" --data-urlencode "datos=$SUB"
ok 'tiene "\"ok\":true" && tiene "te llegarán avisos"' "activar avisos en este celular"
get "/?r=app"
post "/?r=push" --data-urlencode "accion=probar"
ok 'tiene "Aviso de prueba enviado" && grep -q "push.\{1,2\}ok.\{1,2\}web1" "$SIM_LOG"' "aviso de prueba"
get "/?r=app"
post "/?r=push" --data-urlencode "accion=suscribir" --data-urlencode "datos={\"endpoint\":\"https://evil.com/x\",\"keys\":{\"p256dh\":\"x\",\"auth\":\"y\"}}"
ok 'tiene "\"ok\":false" && tiene "no es compatible"' "dirección de avisos rara: rechazada"

echo "== Permisos de lo nuevo"
entrar luis@tukan.com "$CLAVE_LUIS"
for R in catalogo cobros flujos equipo campos tienda; do get "/?r=$R"; ok '[ "$CODIGO" = 302 ]' "el agente no abre $R"; done
get "/?r=pedidos"; ok '[ "$CODIGO" = 200 ] && tiene "Pedidos"' "el agente sí ve los pedidos"
get "/?r=app"; ok '[ "$CODIGO" = 200 ]' "el agente puede activar la app"
get "/?r=mas"; ok 'tiene "r=pedidos" && ! tiene "r=catalogo" && tiene "r=app"' "Más del agente"
J="$TUKAN_J"

echo "== Idiomas (botón 🌐)"
get "/?r=bandeja&lang=en"
ok 'tiene "<html lang=\"en\"" && tiene ">Inbox<" && tiene "tukan-idioma" && tiene "r=bandeja&amp;lang=fr"' "la bandeja en inglés con el botón 🌐"
ok 'tiene "window.TUKAN_TR=" && tiene "\"Copiar\":\"Copy\""' "los textos del JavaScript también van en inglés"
ok '[ "$(PGPASSWORD="${PGPASSWORD:-}" psql -tAc "SELECT count(*) FROM usuarios WHERE idioma = '"'"'en'"'"'" -d tuwhatsapp_prueba)" -ge 1 ]' "el idioma queda guardado en la cuenta del usuario"
get "/?r=bandeja";  ok 'tiene ">Inbox<"' "sin ?lang sigue en inglés (se recuerda)"
get "/?r=nuevos";   ok 'grep -q "\"sin_leer\"" "$P" && ! tiene "<html"' "las respuestas JSON no se tocan"
get "/?r=bandeja&lang=fr"; ok 'tiene "<html lang=\"fr\"" && tiene ">Boîte de réception<"' "en francés"
get "/?r=bandeja&lang=es"; ok 'tiene "<html lang=\"es\"" && tiene ">Bandeja<" && ! tiene "window.TUKAN_TR={\""' "de vuelta en español, sin traducción"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/privacidad.php?lang=pt"); ok '[ "$CODIGO" = 200 ] && tiene "tukan-idioma" && tiene "<html lang=\"pt\""' "páginas públicas con el botón 🌐"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' "$W/?r=login&lang=it"); ok '[ "$CODIGO" = 200 ] || [ "$CODIGO" = 302 ]' "entrar con otro idioma no falla"

echo
echo "Resultado: $BIEN bien, $MAL mal"
[ "$MAL" = 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$MAL" = 0 ]
