#!/usr/bin/env bash
# Pruebas de lo de la app de Android (Google Play, «Tukán Mensajes»):
#  - /.well-known/assetlinks.json, /privacidad.php y /eliminar-datos.php sin entrar
#  - modo app (?app=play): nombre «Tukán Mensajes», sin precios del programa ni enlaces para pedirlo o pagarlo afuera
#  - deploy/demo_google.php crear | borrar: cuenta de MUESTRA aparte para el revisor de Google que nunca llama a Meta,
#    no conecta números, no cambia la contraseña y no toca ninguna otra cuenta.
# La llama tests/correr.sh (misma base y servidor web, Meta simulado). Necesita: WEB_URL, TUWA_DB_PRUEBA, TUWA_ENV, SIM_LOG.
set -u
W="${WEB_URL:?}"
: "${TUWA_DB_PRUEBA:?}" "${TUWA_ENV:?}"
J="$(mktemp)"; P="$(mktemp)"; H="$(mktemp)"; S="$(mktemp)"; BIEN=0; MAL=0
trap 'rm -f "$J" "$P" "$H" "$S"' EXIT
ok()  { if eval "$1"; then BIEN=$((BIEN+1)); else MAL=$((MAL+1)); echo "  FALLA: $2"; cp "$P" "/tmp/tuwa_app_falla_$MAL.html" 2>/dev/null; fi; }
get() { CODIGO=$(curl -s -D "$H" -o "$P" -w '%{http_code}' -b "$J" -c "$J" "$W$1"); }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$P" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local r="$1"; shift; CODIGO=$(curl -s -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$(csrf)" "$@" "$W$r"); }
tiene() { grep -q -- "$1" "$P"; }
sql() { PGPASSWORD="${PGPASSWORD:-}" psql -qtAc "$1" -d "$TUWA_DB_PRUEBA"; }
demo() { TUWA_ENV="$TUWA_ENV" php deploy/demo_google.php "$@"; }
entrar() { : > "$J"; get "/?r=login$2"; post "/?r=login" --data-urlencode "correo=$1" --data-urlencode "clave=$3"; }
CORREO=revisor.google@tukanec.com
HUELLA="39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC"
# Huella de todo lo que NO es de la cuenta de muestra: no debe cambiar nunca
OTRAS="SELECT md5(coalesce(string_agg(x, '|' ORDER BY x), '')) FROM (
  SELECT 'e'||id||nombre||estado||coalesce(vence::text,'')||modulos AS x FROM empresas WHERE NOT muestra
  UNION ALL SELECT 'u'||u.id||u.correo||u.clave||u.activo FROM usuarios u JOIN empresas e ON e.id=u.empresa_id WHERE NOT e.muestra
  UNION ALL SELECT 'l'||l.id||coalesce(phone_number_id,'')||token_cifrado FROM lineas l JOIN empresas e ON e.id=l.empresa_id WHERE NOT e.muestra
  UNION ALL SELECT 'c'||c.id||c.telefono||c.nombre FROM contactos c JOIN lineas l ON l.id=c.linea_id JOIN empresas e ON e.id=l.empresa_id WHERE NOT e.muestra
  UNION ALL SELECT 'm'||m.id||m.estado||m.texto FROM mensajes m JOIN lineas l ON l.id=m.linea_id JOIN empresas e ON e.id=l.empresa_id WHERE NOT e.muestra
) t"
meta() { wc -l < "${SIM_LOG:-/dev/null}" 2>/dev/null || echo 0; }

echo "== App de Android: archivos públicos"
: > "$J"
get "/.well-known/assetlinks.json"
ok '[ "$CODIGO" = 200 ] && grep -qi "content-type: application/json" "$H"' "assetlinks.json responde 200 como JSON"
ok 'php -r "\$d=json_decode(file_get_contents(\$argv[1]),true); exit(\$d[0][\"target\"][\"package_name\"]===\"com.tukansoftware.tuwhatsapp\" && in_array(\$argv[2], \$d[0][\"target\"][\"sha256_cert_fingerprints\"], true) ? 0 : 1);" "$P" "$HUELLA"' "assetlinks tiene el paquete y la huella de la llave de subida"
get "/privacidad.php"
ok '[ "$CODIGO" = 200 ] && tiene "Tukán Mensajes" && tiene "no está afiliado" && tiene "fotos, audios y notas de voz, videos, documentos"' "privacidad: nombre de la app, sin afiliación con Meta, archivos de terceros"
ok 'tiene "contenido de los mensajes" && tiene "Anthropic" && tiene "Pago Plux" && tiene "No guardamos" && tiene "agenda" && tiene "no se graba"' "privacidad: mensajes, IA, cobros, contactos de la agenda y llamadas"
ok 'tiene "id=\"borrar\"" && tiene "Eliminar mis datos" && tiene "15 días" && tiene "info@tukansoftware.com" && tiene "28 de octubre de 2026"' "privacidad: cómo borrar la cuenta y los datos"
ok '! tiene "name=\"clave\""' "la privacidad se ve sin entrar"
get "/eliminar-datos.php"
ok '[ "$CODIGO" = 200 ] && tiene "Tukán Mensajes" && tiene "audios" && tiene "persona del equipo"' "eliminar datos: app, archivos y usuarios del equipo"
get "/?r=login"
ok 'tiene "WhatsApp <b>Tukán</b>" && ! grep -q "tuwa_app" "$J"' "en la web sigue el nombre WhatsApp Tukán"
get "/?r=bandeja&app=play"
ok 'grep -q "tuwa_app" "$J" && [ "$CODIGO" = 302 ]' "?app=play deja la cookie de la app y manda a entrar"
get "/?r=login"
ok 'tiene "Tukán <b>Mensajes</b>" && ! tiene "WhatsApp <b>Tukán</b>" && tiene "Entra con la cuenta que te dio Tukán"' "en la app se llama Tukán Mensajes"

echo "== Cuenta de muestra para Google: crear"
ANTES=$(sql "$OTRAS")
M0=$(meta)
demo > "$S" 2>&1; ok '[ $? = 1 ] && grep -q "Uso:" "$S"' "sin acción muestra el uso"
demo crear > "$S" 2>&1; R=$?
ok '[ $R = 0 ] && grep -q "Usuario:    $CORREO" "$S" && grep -q "Contrasena: Demo-[0-9]\{6\}" "$S"' "crear: muestra usuario y contraseña una vez"
CLAVE=$(grep -o 'Demo-[0-9]\{6\}' "$S" | head -1)
ED=$(sql "SELECT empresa_id FROM usuarios WHERE correo='$CORREO'")
ok '[ -n "$ED" ] && [ "$(sql "SELECT muestra||estado||plan||modulos||(vence > current_date + 170)::text FROM empresas WHERE id=$ED")" = "truedemotodotrue" ]' "empresa de muestra: demo de 180 días, plan Todo incluido sin IA"
ok '[ "$(sql "SELECT count(*) FROM lineas WHERE empresa_id=$ED AND phone_number_id LIKE '"'"'muestra-%'"'"' AND token_cifrado='"'"''"'"'")" = 1 ]' "un número de ficción (muestra-…, sin token)"
ok '[ "$(sql "SELECT count(*) FROM contactos c JOIN lineas l ON l.id=c.linea_id WHERE l.empresa_id=$ED")" = 8 ] && [ "$(sql "SELECT count(*) FROM contactos c JOIN lineas l ON l.id=c.linea_id WHERE l.empresa_id=$ED AND c.telefono !~ '"'"'^1202555010[0-9]$'"'"'")" = 0 ]' "8 contactos, todos con números de ficción (+1 202 555 01xx)"
ok '[ "$(sql "SELECT count(*) FROM pedidos p JOIN lineas l ON l.id=p.linea_id WHERE l.empresa_id=$ED")" = 3 ] && [ "$(sql "SELECT count(*) FROM productos p JOIN lineas l ON l.id=p.linea_id WHERE l.empresa_id=$ED")" = 6 ]' "6 productos y 3 pedidos de ejemplo"
ok '[ "$(sql "SELECT rol||super::text FROM usuarios WHERE correo='"'"'$CORREO'"'"'")" = "adminfalse" ]' "el revisor es administrador de su cuenta, no personal de Tukán"
ok '[ "$(sql "$OTRAS")" = "$ANTES" ]' "crear no tocó ninguna otra cuenta"

echo "== Cuenta de muestra: el revisor entra desde la app"
entrar "$CORREO" "&app=play" "$CLAVE"
ok 'tiene "Camila Ortega" && tiene "Tukán <b>Mensajes</b>" && ! tiene "Términos y condiciones de"' "entra directo a la bandeja (términos ya aceptados)"
get "/?r=mas"
ok 'tiene "Agente con IA" && tiene "No está activo en tu cuenta" && ! tiene "Pedir a Tukán" && ! tiene "wa.me/593996408397"' "Más: el extra de IA sin enlace para pedirlo ni pagarlo"
get "/?r=ia"
ok '! tiene "wa.me/593996408397" && ! tiene "\$5" && ! tiene "\$25" && ! tiene "\$20"' "IA apagada: sin precios del programa"
get "/?r=cuenta"
ok 'tiene "Tienda Muestra Tukán" && ! tiene "\$20" && ! tiene "\$25" && ! tiene "wa.me/593996408397"' "Mi cuenta sin precios del programa"
C1=$(sql "SELECT c.id FROM contactos c JOIN lineas l ON l.id=c.linea_id WHERE l.empresa_id=$ED AND c.nombre='Lucía Herrera'")
get "/?r=chat&c=$C1"
ok 'tiene "chocolate sin azúcar"' "abre una conversación de ejemplo"
post "/?r=chat&c=$C1" --data-urlencode "accion=enviar" --data-urlencode "texto=Hola, sí tenemos."
ok 'tiene "Cuenta de demostración: no se envía nada a WhatsApp"' "Enviar por el sistema avisa que es de demostración"
ok '[ "$(meta)" = "$M0" ]' "nada de la cuenta de muestra llegó a Meta (simulado)"
ok '[ "$(sql "SELECT estado||costo FROM mensajes WHERE contacto_id=$C1 AND direccion='"'"'sale'"'"' ORDER BY id DESC LIMIT 1")" = "fallido0.00000" ]' "el mensaje queda como no enviado y sin costo"
get "/?r=cuenta"
post "/?r=cuenta" --data-urlencode "accion=clave" --data-urlencode "actual=$CLAVE" --data-urlencode "nueva=OtraClave1234" --data-urlencode "repetir=OtraClave1234"
ok 'tiene "Cuenta de demostración"' "no se cambia la contraseña"
get "/?r=linea"
post "/?r=linea" --data-urlencode "nombre=Otro" --data-urlencode "telefono=0991112233" --data-urlencode "phone_number_id=999" --data-urlencode "waba_id=1" --data-urlencode "token=EAAGotro123456789"
ok 'tiene "Cuenta de demostración" && [ "$(sql "SELECT count(*) FROM lineas WHERE empresa_id=$ED")" = 1 ]' "no se conectan números a mano"
get "/?r=bandeja"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$(csrf)" --data-urlencode "code=abc" "$W/?r=conectar_qr")
ok 'tiene "\"ok\":false" && tiene "demostración"' "no se conectan números con el botón QR"
ok '[ "$(meta)" = "$M0" ] && [ "$(sql "$OTRAS")" = "$ANTES" ]' "usarla no llamó a Meta ni tocó otra cuenta"
entrar "$CORREO" "" "$CLAVE"
get "/?r=mas"
ok 'tiene "Pedir a Tukán" && tiene "WhatsApp <b>Tukán</b>"' "en la web (sin la app) sigue el enlace para pedir la IA"

echo "== Cuenta de muestra: volver a crear y borrar"
demo crear > "$S" 2>&1
CLAVE2=$(grep -o 'Demo-[0-9]\{6\}' "$S" | head -1)
ok '[ -n "$CLAVE2" ] && [ "$(sql "SELECT count(*) FROM empresas WHERE muestra")" = 1 ] && [ "$(sql "SELECT count(*) FROM usuarios WHERE correo='"'"'$CORREO'"'"'")" = 1 ]' "crear otra vez: una sola cuenta de muestra y un solo revisor"
if [ "$CLAVE2" != "$CLAVE" ]; then
  entrar "$CORREO" "" "$CLAVE"
  ok 'tiene "incorrectos"' "la contraseña anterior ya no sirve"
fi
entrar "$CORREO" "" "$CLAVE2"; ok 'tiene "Camila Ortega"' "entra con la contraseña nueva"
demo borrar > "$S" 2>&1
ok '[ $? = 0 ] && grep -q "se borro la Tienda Muestra" "$S"' "borrar"
ok '[ "$(sql "SELECT count(*) FROM empresas WHERE muestra")" = 0 ] && [ "$(sql "SELECT count(*) FROM usuarios WHERE correo='"'"'$CORREO'"'"'")" = 0 ] && [ "$(sql "SELECT count(*) FROM lineas WHERE phone_number_id LIKE '"'"'muestra-%'"'"'")" = 0 ]' "no queda la cuenta, el revisor ni el número de ficción"
demo borrar > "$S" 2>&1; ok '[ $? = 0 ] && grep -q "No habia" "$S"' "borrar dos veces no falla"
ok '[ "$(sql "$OTRAS")" = "$ANTES" ]' "borrar no tocó ninguna otra cuenta"

echo "== Cuenta de muestra: nunca toca una cuenta de verdad con ese correo"
FAC=$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')
EV=$(sql "INSERT INTO empresas (nombre, estado) VALUES ('NEGOCIO REAL', 'activa') RETURNING id" | head -1)
sql "INSERT INTO usuarios (empresa_id, correo, nombre, clave, rol) VALUES ($EV, '$CORREO', 'Real', '$FAC', 'admin')" > /dev/null
demo crear > "$S" 2>&1; ok '[ $? = 1 ] && grep -q "NO es de muestra" "$S"' "crear se niega si el correo es de una cuenta real"
demo borrar > "$S" 2>&1; ok '[ $? = 1 ] && [ "$(sql "SELECT nombre FROM empresas WHERE id=$EV")" = "NEGOCIO REAL" ]' "borrar se niega y no borra la cuenta real"
sql "DELETE FROM empresas WHERE id=$EV" > /dev/null

echo "== Cuenta vencida dentro de la app: sin enlaces para pagar afuera"
EV=$(sql "INSERT INTO empresas (nombre, estado, motivo_suspension) VALUES ('NEGOCIO SUSPENDIDO', 'suspendida', 'Prueba') RETURNING id" | head -1)
sql "INSERT INTO usuarios (empresa_id, correo, nombre, clave, rol, idioma) VALUES ($EV, 'suspendida@prueba.ec', 'S', '$FAC', 'admin', NULL)" > /dev/null
entrar "suspendida@prueba.ec" "&app=play" "claveSegura1"; get "/?r=bloqueada"
ok 'tiene "Cuenta no disponible" && ! tiene "wa.me" && tiene "en-app"' "en la app: sin enlace de WhatsApp para renovar"
entrar "suspendida@prueba.ec" "" "claveSegura1"; get "/?r=bloqueada"
ok 'tiene "Cuenta no disponible" && tiene "wa.me"' "en la web: sigue el enlace de WhatsApp"
sql "DELETE FROM empresas WHERE id=$EV" > /dev/null

echo "App de Android: $BIEN bien, $MAL mal"
[ "$MAL" = 0 ]
