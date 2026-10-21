#!/usr/bin/env bash
# Guarda la "Clave secreta de la app" (App Secret) de Meta y, si quieres, el aviso por Telegram.
# Uso:  bash /var/www/tuwhatsapp/deploy/clave_meta.sh
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
[ -f "$ENVF" ] || { echo "ERROR: primero corre el instalador"; exit 1; }
guardar() {   # guardar NOMBRE VALOR
  grep -v -E "^$1=" "$ENVF" > "$ENVF.nuevo"; echo "$1=$2" >> "$ENVF.nuevo"; mv "$ENVF.nuevo" "$ENVF"
}
printf 'Pega la Clave secreta de la app de Meta (32 letras y numeros) y presiona Enter: '
read -r S
S="$(echo "$S" | tr -d ' \r')"
if ! echo "$S" | grep -qE '^[0-9a-f]{32}$'; then echo "ERROR: no parece la clave (son 32 letras a-f y numeros). No se guardo nada."; exit 1; fi
guardar META_APP_SECRET "$S"
echo "   clave guardada"
printf 'Avisos de gasto por Telegram? Pega el token del bot (o solo Enter para saltar): '
read -r T
T="$(echo "$T" | tr -d ' \r')"
if [ -n "$T" ]; then
  printf 'Pega tu chat_id de Telegram: '
  read -r C
  C="$(echo "$C" | tr -d ' \r')"
  guardar TELEGRAM_BOT_TOKEN "$T"; guardar TELEGRAM_CHAT_ID "$C"
  echo "   Telegram guardado"
fi
chmod 640 "$ENVF"; sudo chgrp www-data "$ENVF"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
sudo systemctl restart "php$PHPV-fpm" && echo "LISTO"
