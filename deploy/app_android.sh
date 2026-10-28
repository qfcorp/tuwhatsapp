#!/usr/bin/env bash
# Hace que nginx muestre /.well-known/assetlinks.json (lo necesita la app de Android "Tukan Mensajes" (WhatsApp Tukan) para abrir a pantalla
# completa, sin barra de navegador) y revisa que la politica de privacidad se vea.
# Uso en el servidor (qfcorp, usuario qf), despues de actualizar con el instalador:
#   bash /var/www/tuwhatsapp/deploy/instalar.sh
#   sudo bash /var/www/tuwhatsapp/deploy/app_android.sh
# El instalador ya escribe este bloque en nginx; este script lo revisa (y lo pone si faltara) y prueba que todo responda.
# Se puede correr varias veces. Guarda una copia de la configuracion y la devuelve si algo sale mal.
# Para pruebas se puede cambiar la carpeta, el dominio y el archivo:  APP=/otra HOST=otro.dominio CONF=/ruta/conf sudo -E bash ...
set -u
if [ "$(id -u)" -ne 0 ]; then echo "Correlo con sudo:  sudo bash $0"; exit 1; fi

APP=${APP:-/var/www/tuwhatsapp}
APP=${APP%/}
RAIZ="$APP/public"
HOST=${HOST:-whatsapp.tukanec.com}
MARCA="tuwhatsapp-app-android"
ARCHIVO="$RAIZ/.well-known/assetlinks.json"
PAQUETE="com.tukansoftware.tuwhatsapp"

if [ ! -f "$ARCHIVO" ]; then
    echo "Falta $ARCHIVO. Primero actualiza:  bash $APP/deploy/instalar.sh"
    exit 1
fi
if ! php -r 'exit(is_array(json_decode(file_get_contents($argv[1]), true)) ? 0 : 1);' "$ARCHIVO" 2>/dev/null; then
    echo "ERROR: $ARCHIVO no es un JSON valido (revisa las comas y las comillas). No se cambio nada."
    exit 1
fi

if [ -z "${CONF:-}" ]; then
    RAIZ_RE=$(printf '%s' "$RAIZ" | sed 's/[.[\*^$/]/\\&/g')
    CONF=$(grep -lE "root\s+$RAIZ_RE/?\s*;" /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf 2>/dev/null | head -1)
fi
if [ -z "$CONF" ]; then echo "No encontre la configuracion de nginx de $RAIZ. Mandame:  grep -rl tuwhatsapp /etc/nginx"; exit 1; fi
CONF=$(readlink -f "$CONF")
echo "Configuracion de nginx: $CONF"

recargar() { systemctl reload nginx 2>/dev/null || nginx -s reload; }

if grep -q "$MARCA" "$CONF"; then
    echo "Ya estaba puesto. No se cambio nada."
elif grep -qE "location\s*=\s*/\.well-known/assetlinks\.json" "$CONF"; then
    echo "La configuracion ya tenia su propio bloque para assetlinks.json. No se cambio nada."
else
    # Se agrega justo despues de la linea "root /var/www/tuwhatsapp/public;" que esta directo dentro de "server { }".
    # "location =" (coincidencia exacta) le gana a "location ~ /\." que bloquea los archivos con punto (.env, .git siguen bloqueados).
    NUEVO=$(mktemp)
    awk -v raiz="$RAIZ" -v marca="$MARCA" '
        function bloque() {
            print ""
            print "    # " marca ": la app de Android verifica este archivo (no borrar)"
            print "    location = /.well-known/assetlinks.json {"
            print "        types { }"
            print "        default_type application/json;"
            print "        alias " raiz "/.well-known/assetlinks.json;"
            print "        allow all;"
            print "    }"
        }
        {
            linea = $0; limpia = linea; sub(/#.*/, "", limpia)
            print linea
            t = limpia; gsub(/^[ \t]+|[ \t]+$/, "", t)
            if (prof == 1 && t ~ ("^root[ \t]+" raiz "/?[ \t]*;$")) { bloque(); puestos++ }
            prof += gsub(/\{/, "{", limpia) - gsub(/\}/, "}", limpia)
        }
        END { if (puestos == 0) exit 3 }
    ' "$CONF" > "$NUEVO"
    if [ $? -ne 0 ]; then
        rm -f "$NUEVO"
        echo "No encontre la linea \"root $RAIZ;\" dentro de un bloque server. Mandame:  sudo cat $CONF"
        exit 1
    fi
    COPIA="/root/nginx_tuwhatsapp_$(date +%Y%m%d_%H%M%S).bak"
    cp "$CONF" "$COPIA"
    cat "$NUEVO" > "$CONF"; rm -f "$NUEVO"
    if nginx -t 2>/dev/null; then
        recargar; sleep 2
        echo "Listo: nginx ya muestra /.well-known/assetlinks.json. Copia de la configuracion anterior: $COPIA"
    else
        cp "$COPIA" "$CONF"
        echo "nginx no acepto el cambio; se devolvio la configuracion anterior. Mandame lo que sale con:  sudo nginx -t"
        exit 1
    fi
fi

# Prueba: el archivo debe salir como JSON con el paquete de la app, y la politica de privacidad con 200
PUERTO=$(grep -oE "listen\s+([0-9.]+:)?[0-9]+" "$CONF" | head -1 | grep -oE "[0-9]+$"); PUERTO=${PUERTO:-80}
if [ "$PUERTO" = "443" ]; then BASE="https://127.0.0.1"; else BASE="http://127.0.0.1:$PUERTO"; fi
RESP=$(curl -sk -D - -H "Host: $HOST" "$BASE/.well-known/assetlinks.json")
CODIGO=$(printf '%s' "$RESP" | head -1 | awk '{print $2}')
TIPO=$(printf '%s' "$RESP" | grep -i '^content-type:' | head -1 | tr -d '\r')
echo "Prueba de /.well-known/assetlinks.json -> $CODIGO ($TIPO)"
printf '%s' "$RESP" | sed '1,/^\r\{0,1\}$/d'
echo
PRIV=$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $HOST" "$BASE/privacidad.php")
echo "Prueba de /privacidad.php -> $PRIV"
if [ "$CODIGO" = "200" ] && printf '%s' "$TIPO" | grep -qi 'application/json' && printf '%s' "$RESP" | grep -q "$PAQUETE" && [ "$PRIV" = "200" ]; then
    echo "OK: la app de Android ya puede verificar el sitio."
else
    echo "ALGO FALLO: debe salir 200, application/json, el texto con $PAQUETE y la privacidad con 200. Mandame todo lo que salio arriba."
    exit 1
fi
