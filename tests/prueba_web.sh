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
ok 'tiene "2 nuevos" && tiene "No se entendieron: nada"' "pegar lista de personales"
get "/?r=contactos&t=personal"
ok 'tiene "Mamá" && tiene "Papá" && ! tiene "Pedro"' "filtro personales"
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

echo "== Seguridad"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" -L -d "accion=tipo&tipo=personal" "$W/?r=chat&c=$C")
ok 'tiene "Intenta de nuevo" && ! tiene "Ahora es: Personal"' "POST sin csrf se rechaza"
get "/?r=algo_raro";  ok '[ "$CODIGO" = 404 ]' "ruta desconocida 404"
get "/?r=salir"; get "/?r=gasto"; ok '[ "$CODIGO" = 302 ]' "después de salir no entra"

echo
echo "Resultado: $BIEN bien, $MAL mal"
[ "$MAL" = 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$MAL" = 0 ]
