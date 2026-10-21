#!/usr/bin/env bash
# Guarda los datos de la app de Meta en config/.env y, si quieres, el aviso por Telegram.
#   - Identificador de la app (App ID) y de la configuracion del boton QR (Configuration ID): no son secretos.
#   - Clave secreta de la app (App Secret): se pega a mano, no se ve en ningun lado.
# Uso:  bash /var/www/tuwhatsapp/deploy/clave_meta.sh [APP_ID] [CONFIG_ID]
# En cada pregunta, solo Enter deja lo que ya estaba guardado.
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
[ -f "$ENVF" ] || { echo "ERROR: primero corre el instalador"; exit 1; }
guardar() {   # guardar NOMBRE VALOR
  grep -v -E "^$1=" "$ENVF" > "$ENVF.nuevo"; echo "$1=$2" >> "$ENVF.nuevo"; mv "$ENVF.nuevo" "$ENVF"
}
actual() { grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- || true; }
limpio() { echo "$1" | tr -d ' \r'; }

# 1. Identificador de la app
A="$(limpio "${1:-}")"
if [ -z "$A" ]; then
  printf 'Identificador de la app de Meta (App ID, solo numeros) [%s]: ' "$(actual META_APP_ID)"
  read -r A; A="$(limpio "$A")"
fi
if [ -n "$A" ]; then
  echo "$A" | grep -qE '^[0-9]{6,20}$' || { echo "ERROR: el App ID son solo numeros. No se guardo nada."; exit 1; }
  guardar META_APP_ID "$A"; echo "   App ID guardado"
fi

# 2. Identificador de la configuracion del boton QR
K="$(limpio "${2:-}")"
if [ -z "$K" ]; then
  printf 'Identificador de la configuracion (Configuration ID, solo numeros) [%s]: ' "$(actual META_ES_CONFIG_ID)"
  read -r K; K="$(limpio "$K")"
fi
if [ -n "$K" ]; then
  echo "$K" | grep -qE '^[0-9]{6,20}$' || { echo "ERROR: el Configuration ID son solo numeros. No se guardo nada."; exit 1; }
  guardar META_ES_CONFIG_ID "$K"; echo "   Configuration ID guardado"
fi

# 3. Clave secreta
YA=""; [ -n "$(actual META_APP_SECRET)" ] && YA=" (ya hay una guardada: Enter para dejarla)"
printf 'Pega la Clave secreta de la app de Meta (32 letras y numeros)%s: ' "$YA"
read -r S; S="$(limpio "$S")"
if [ -n "$S" ]; then
  echo "$S" | grep -qE '^[0-9a-f]{32}$' || { echo "ERROR: no parece la clave (son 32 letras a-f y numeros). No se guardo la clave."; exit 1; }
  guardar META_APP_SECRET "$S"; echo "   clave guardada"
elif [ -z "$YA" ]; then
  echo "   AVISO: no hay clave secreta guardada; sin ella no se aceptan los mensajes que llegan."
fi

# 4. Telegram (opcional)
printf 'Avisos de gasto por Telegram? Pega el token del bot (o solo Enter para saltar): '
read -r T; T="$(limpio "$T")"
if [ -n "$T" ]; then
  printf 'Pega tu chat_id de Telegram: '
  read -r C; C="$(limpio "$C")"
  guardar TELEGRAM_BOT_TOKEN "$T"; guardar TELEGRAM_CHAT_ID "$C"
  echo "   Telegram guardado"
fi
chmod 640 "$ENVF"; sudo chgrp www-data "$ENVF"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
sudo systemctl restart "php$PHPV-fpm" && echo "LISTO"
