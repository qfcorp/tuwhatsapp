#!/usr/bin/env bash
# App de iPhone (App Store, regla 3.1.1 de Apple; regla SIEMPRE de Dimitry desde el 12 nov 2026): dentro de la app NO se ve ningún
# precio del plan, del agente con IA ni de nada que venda Tukán, ni botones, enlaces o formularios para pagar, contratar, pedir,
# mejorar o cancelar el plan, ni textos que digan dónde o a quién pagar («escribe a Tukán», WhatsApp de ventas, pagos.tukanec.com).
# Se reconoce por «TukanIOS» en el navegador (User-Agent) o por la cookie canal=ios que deja ?app=ios (u ?origen=ios).
# Lo que el negocio cobra a SUS clientes sigue igual, y sin TukanIOS (la web y la app de Google Play) todo queda como antes.
# La llama tests/correr.sh (misma base, servidor web y Panel simulado). Necesita: WEB_URL, TUWA_DB_PRUEBA, PANEL_ESTADO, PANEL_LOG.
set -u
W="${WEB_URL:?}"
: "${TUWA_DB_PRUEBA:?}"
J="$(mktemp)"; P="$(mktemp)"; H="$(mktemp)"; BIEN=0; MAL=0
UA_IOS="Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 TukanIOS/1.0.0"
UA=()   # vacío = navegador de siempre; iphone() pone el de la app de iPhone
trap 'rm -f "$J" "$P" "$H"' EXIT
ok()  { if eval "$1"; then BIEN=$((BIEN+1)); else MAL=$((MAL+1)); echo "  FALLA: $2"; cp "$P" "/tmp/tuwa_iphone_falla_$MAL.html" 2>/dev/null; fi; }
iphone() { UA=(-A "$UA_IOS"); }
web() { UA=(); }
get() { CODIGO=$(curl -s "${UA[@]}" -D "$H" -o "$P" -w '%{http_code}' -b "$J" -c "$J" "$W$1"); }
seguir() { CODIGO=$(curl -s "${UA[@]}" -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" "$W$1"); }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$P" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local r="$1"; shift; CODIGO=$(curl -s "${UA[@]}" -L -o "$P" -w '%{http_code}' -b "$J" -c "$J" --data-urlencode "csrf=$(csrf)" "$@" "$W$r"); }
tiene() { grep -q -- "$1" "$P"; }
sql() { PGPASSWORD="${PGPASSWORD:-}" psql -qtAc "$1" -d "$TUWA_DB_PRUEBA"; }
panel() { php tests/panel_simulado.php "$@" > /dev/null; }
enlog() { grep -c -- "$1" "${PANEL_LOG:-/dev/null}" 2>/dev/null || true; }
entrar() { : > "$J"; get "/?r=login"; post "/?r=login" --data-urlencode "correo=$1" --data-urlencode "clave=claveSegura1"; }
# Nada de lo que vende Tukán en la página
sin_venta() {
  ok '! grep -q "\$[0-9]" "$P"' "$1: sin montos en dólares"
  ok '! tiene "USD"' "$1: sin «USD»"
  ok '! grep -q "Paga\b\|Pagar\b" "$P"' "$1: sin «Paga» ni «Pagar»"
  ok '! tiene "ontratar"' "$1: sin «Contratar»"
  ok '! tiene "Pedir cotizaci\|por cotizaci\|cotizamos"' "$1: sin pedir cotización del plan (las cotizaciones del negocio a SUS clientes sí)"
  ok '! tiene "wa.me/593983662181"' "$1: sin WhatsApp de ventas de Tukán"
  ok '! tiene "pagos.tukanec.com"' "$1: sin pagos.tukanec.com"
  ok '! tiene "scribe a Tukán\|Pedir a Tukán\|con Tukán Software"' "$1: sin decir a quién pedirlo o pagarlo"
  ok '! tiene "Cancelar mi plan\|cancelar_plan\|seguir_plan"' "$1: sin cancelar ni cambiar el plan"
  ok '! tiene "pago-tarjeta\|pagar_tarjeta"' "$1: sin pagar con tarjeta"
}
FAC=$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')
TV=$(php -r 'require "src/Terminos.php"; echo \TuWhatsApp\Terminos::VERSION;' 2>/dev/null || grep -o "VERSION = '[^']*'" src/Terminos.php | grep -o "[0-9-]\{10\}")
nueva_empresa() { # nombre estado vence correo [modulos] -> id
  sql "DELETE FROM usuarios WHERE correo='$4'" > /dev/null
  local id; id=$(sql "INSERT INTO empresas (nombre, estado, vence, modulos, terminos_version) VALUES ('$1', '$2', $3, '${5:-}', '$TV') RETURNING id" | head -1)
  sql "INSERT INTO usuarios (empresa_id, correo, nombre, clave, rol, idioma) VALUES ($id, '$4', 'Dueña', '$FAC', 'admin', NULL)" > /dev/null
  echo "$id"
}

echo "== App de iPhone: cómo se reconoce"
web; : > "$J"; get "/?r=login"
ok 'tiene "WhatsApp <b>Tukán</b>" && tiene "Pide tu prueba por WhatsApp" && ! tiene "tukan-ios"' "web: igual que antes (nombre, pedir prueba, sin la marca del iPhone)"
iphone; get "/?r=login"
ok 'tiene "tukan-ios" && tiene "en-app"' "con TukanIOS en el navegador: <body> con tukan-ios (y en-app)"
ok 'tiene "Tukán <b>Mensajes</b>" && ! tiene "Pide tu prueba por WhatsApp"' "iPhone: se llama Tukán Mensajes y sin pedir prueba por WhatsApp"
sin_venta "iPhone entrar"
web; : > "$J"; get "/?r=login&app=ios"
ok 'grep -q "canal" "$J"' "?app=ios deja la cookie canal=ios"
get "/?r=login"
ok 'tiene "tukan-ios"' "… y con la cookie se sabe que es la app (sin TukanIOS)"
: > "$J"; get "/?r=login&origen=ios"
ok 'grep -q "canal" "$J"' "?origen=ios también deja la cookie"
: > "$J"; get "/?r=login&app=play"
ok '! grep -q "canal" "$J"' "?app=play (Google Play) no deja la cookie del iPhone"

echo "== Mi cuenta (activa, sin IA) con el pago con tarjeta prendido en el Panel"
panel modo activo; panel limpiar
EA=$(nueva_empresa "NEGOCIO IPHONE" activa "current_date + 20" iphone.activa@prueba.ec)
web; entrar iphone.activa@prueba.ec
get "/?r=cuenta"
ok 'tiene "plan-precio" && tiene "Cancelar mi plan" && tiene "Pagar con tarjeta"' "web: precio, cancelar y pagar con tarjeta (igual que antes)"
get "/?r=mas"
ok 'tiene "Pedir a Tukán"' "web Más: el agente con IA se pide a Tukán"
iphone
get "/?r=cuenta"
ok '[ "$CODIGO" = 200 ] && tiene "Tu plan: " && tiene "tukan-ios"' "iPhone: Mi cuenta abre con el plan"
ok 'tiene "Próxima renovación"' "iPhone: información neutra de la fecha"
sin_venta "iPhone Mi cuenta"
ok '! tiene "Meta te cobra directo"' "iPhone: sin decir cómo se paga"
ok '! tiene "Para agregar más, escribe a Tukán"' "iPhone: sin pedir más usuarios a Tukán"
get "/?r=mas"
ok 'tiene "No está activo en tu cuenta"' "iPhone Más: el agente con IA solo dice que no está activo"
sin_venta "iPhone Más"
get "/?r=lineas"
ok '! tiene "Para conectar otro, escribe a Tukán"' "iPhone Números: sin pedir otro número a Tukán"

echo "== En el iPhone el servidor rechaza pagar, cancelar y seguir con el plan (mensaje neutro)"
CREAR0=$(enlog '"accion":"crear"')
get "/?r=cuenta"; post "/?r=cuenta" --data-urlencode "accion=pagar_tarjeta" --data-urlencode "plan=todo"
ok '! tiene "PAGINA DE PAGO" && tiene "Esta acción no está disponible en la app." && [ "$(enlog "\"accion\":\"crear\"")" = "$CREAR0" ]' "pagar con tarjeta: no se crea el cobro"
get "/?r=cuenta"; post "/?r=cuenta" --data-urlencode "accion=cancelar_plan" --data-urlencode "confirmo=1" --data-urlencode "motivo=iphone"
ok 'tiene "Esta acción no está disponible en la app." && [ "$(sql "SELECT coalesce(cancelar_en::text, '"''"') FROM empresas WHERE id=$EA")" = "" ]' "cancelar el plan: no se guarda"
get "/?r=cuenta"; post "/?r=cuenta" --data-urlencode "accion=seguir_plan"
ok 'tiene "Esta acción no está disponible en la app."' "seguir con el plan: tampoco"
get "/?r=cuenta"; post "/?r=cuenta" --data-urlencode "accion=mi_clave" --data-urlencode "actual=x" --data-urlencode "nueva=y" --data-urlencode "repetir=y"
ok '! tiene "Esta acción no está disponible en la app."' "lo demás de Mi cuenta (contraseña) sigue funcionando"

echo "== Módulo que no está en el plan"
get "/?r=ia"; seguir "/?r=mas"
ok 'tiene "no está activo en tu cuenta"' "iPhone: el agente con IA no está activo (neutro)"
ok '! tiene "Escribe a Tukán para activarlo"' "… sin «Escribe a Tukán para activarlo»"
web; get "/?r=ia"; seguir "/?r=mas"
ok 'tiene "Escribe a Tukán para activarlo"' "web: igual que antes"

echo "== Cuenta vencida o demo terminada: en el iPhone solo información neutra"
sql "UPDATE empresas SET vence = current_date - 2 WHERE id=$EA" > /dev/null
iphone; seguir "/?r=cuenta"
ok 'tiene "Cuenta no disponible" && tiene "Tu plan venció"' "iPhone vencida: lo dice"
sin_venta "iPhone vencida"
web; seguir "/?r=cuenta"
ok 'tiene "Cuenta no disponible" && tiene "Pagar con tarjeta" && tiene "wa.me/593983662181"' "web vencida: pagar con tarjeta y WhatsApp de Tukán (igual que antes)"
CREAR0=$(enlog '"accion":"crear"')
iphone; get "/?r=bloqueada"; post "/?r=bloqueada" --data-urlencode "accion=pagar_tarjeta" --data-urlencode "plan=todo"
ok '! tiene "PAGINA DE PAGO" && [ "$(enlog "\"accion\":\"crear\"")" = "$CREAR0" ]' "iPhone vencida: tampoco paga forzando el formulario"
sql "UPDATE empresas SET estado='demo', vence = current_date - 1 WHERE id=$EA" > /dev/null
seguir "/?r=cuenta"
ok 'tiene "Tu demo terminó"' "iPhone demo terminada: lo dice"
sin_venta "iPhone demo terminada"
sql "UPDATE empresas SET estado='suspendida', motivo_suspension='Prueba' WHERE id=$EA" > /dev/null
seguir "/?r=cuenta"
ok 'tiene "Cuenta no disponible"' "iPhone suspendida: lo dice"
sin_venta "iPhone suspendida"
web; seguir "/?r=cuenta"
ok 'tiene "wa.me/593983662181"' "web suspendida: con WhatsApp de Tukán"
GALLETAS="$(sed 's/^#HttpOnly_//' "$J" | awk '!/^#/ && NF >= 7 { printf "%s=%s; ", $6, $7 }')tuwa_app=play"
CODIGO=$(curl -s -o "$P" -w '%{http_code}' -H "Cookie: $GALLETAS" "$W/?r=bloqueada")
ok 'tiene "revisarlo con Tukán Software" && ! tiene "wa.me" && ! tiene "tukan-ios"' "Google Play: su texto de siempre, sin la marca del iPhone"

echo "== Lo que el negocio cobra a SUS clientes sigue igual en el iPhone"
sql "UPDATE empresas SET estado='activa', vence = current_date + 20, motivo_suspension='' WHERE id=$EA" > /dev/null
iphone
for r in bandeja pedidos catalogo cobros; do
  seguir "/?r=$r"; ok '[ "$CODIGO" = 200 ] && ! tiene "Cuenta no disponible"' "iPhone: $r abre"
done

panel modo activo; panel limpiar
sql "DELETE FROM usuarios WHERE empresa_id=$EA; DELETE FROM empresas WHERE id=$EA" > /dev/null
echo "App de iPhone: $BIEN bien, $MAL mal"
[ "$MAL" = 0 ]
