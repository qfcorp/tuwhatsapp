#!/usr/bin/env bash
# Conecta WhatsApp Tukán con el Panel Tukán Central (protocolo v1).
# Uso (en el servidor, con sudo; el comando completo lo da el Panel Central al agregar el programa):
#   sudo bash /var/www/tuwhatsapp/deploy/conectar_central.sh ID SECRETO URL
# Ejemplo:
#   sudo bash /var/www/tuwhatsapp/deploy/conectar_central.sh tuwhatsapp 3f9a...(64) https://panel.tukanec.com
# Se puede correr varias veces (por ejemplo, si el panel cambia el secreto). Mensajes sin tildes a proposito.
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
DOMINIO="${DOMINIO:-whatsapp.tukanec.com}"
if [ "$#" -ne 3 ]; then
  echo "ERROR: faltan datos. Son 3: ID, SECRETO y URL (los da el Panel Tukan Central)."
  echo "Uso: sudo bash $APP/deploy/conectar_central.sh ID SECRETO URL"; exit 1
fi
ID="$1"; SECRETO="$2"; URL="${3%/}"
[[ "$ID" =~ ^[a-z0-9_-]{2,40}$ ]] || { echo "ERROR: el ID debe tener de 2 a 40 letras minusculas, numeros, - o _ (ejemplo: tuwhatsapp)."; exit 1; }
[[ "$SECRETO" =~ ^[A-Za-z0-9]{32,128}$ ]] || { echo "ERROR: el SECRETO debe tener de 32 a 128 letras y numeros."; exit 1; }
[[ "$URL" =~ ^https?:// ]] || { echo "ERROR: la URL debe empezar con https://"; exit 1; }
[ "$(id -u)" = "0" ] || { echo "ERROR: corre este comando con sudo."; exit 1; }
[ -f "$ENVF" ] || { echo "ERROR: no existe $ENVF. Primero instala WhatsApp Tukan."; exit 1; }

echo "== 1. Guardar en config/.env"
grep -v -E '^(TUKAN_CENTRAL_ID|TUKAN_CENTRAL_SECRET|TUKAN_CENTRAL_URL)=' "$ENVF" > "$ENVF.nuevo"
{ echo "TUKAN_CENTRAL_ID=$ID"; echo "TUKAN_CENTRAL_SECRET=$SECRETO"; echo "TUKAN_CENTRAL_URL=$URL"; } >> "$ENVF.nuevo"
mv "$ENVF.nuevo" "$ENVF" && chown "$(stat -c %U "$APP")":www-data "$ENVF" && chmod 640 "$ENVF" && echo "   listo"

echo "== 2. Preparar la base y recargar PHP"
PASS="$(grep -E '^TUWA_DB_PASS=' "$ENVF" | cut -d= -f2-)"
PGPASSWORD="$PASS" psql -h 127.0.0.1 -U tuwhatsapp -d tuwhatsapp -q -v ON_ERROR_STOP=1 -f "$APP/db/schema.sql" < /dev/null > /dev/null 2>&1 && echo "   base lista" || echo "   AVISO: no se pudo preparar la base"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl restart "php$PHPV-fpm" && echo "   PHP recargado"

echo "== 3. Prueba (pide las cifras con un enlace firmado)"
T="$(ID="$ID" S="$SECRETO" php -r '
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $now = time();
  $p = $b(json_encode(["v"=>1,"tipo"=>"estado","inst"=>getenv("ID"),"email"=>"prueba@tukanec.com","nombre"=>"Prueba","iat"=>$now,"exp"=>$now+60,"nonce"=>bin2hex(random_bytes(16))]));
  echo $p . "." . $b(hash_hmac("sha256", $p, getenv("S"), true));')"
R="$(curl -s -H "Host: $DOMINIO" "http://127.0.0.1/tukan_central.php?accion=estado&t=$T")"
if echo "$R" | grep -q '"ok":true'; then echo "OK: conectado con el Panel Tukan Central"; else echo "ERROR: la prueba fallo: $R"; exit 1; fi
