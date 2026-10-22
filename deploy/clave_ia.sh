#!/usr/bin/env bash
# Enciende el Asistente con IA (Claude de Anthropic): guarda la clave de la API en config/.env.
# La clave se crea en https://console.anthropic.com (API Keys) y empieza con sk-ant-
# Uso:  bash /var/www/tuwhatsapp/deploy/clave_ia.sh
# Solo Enter deja la que ya estaba guardada. Escribir la palabra borrar apaga la IA.
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
[ -f "$ENVF" ] || { echo "ERROR: primero corre el instalador"; exit 1; }
guardar() { grep -v -E "^$1=" "$ENVF" > "$ENVF.nuevo"; [ -n "$2" ] && echo "$1=$2" >> "$ENVF.nuevo"; mv "$ENVF.nuevo" "$ENVF"; }
YA=""; grep -q '^TUWA_IA_CLAVE=.' "$ENVF" && YA=" (ya hay una guardada: Enter para dejarla)"
printf 'Pega la clave de la API de Anthropic%s: ' "$YA"
read -r K; K="$(echo "$K" | tr -d ' \r')"
if [ "$K" = "borrar" ]; then
  guardar TUWA_IA_CLAVE ""; echo "   IA apagada"
elif [ -n "$K" ]; then
  echo "$K" | grep -qE '^sk-ant-[A-Za-z0-9_-]{20,}$' || { echo "ERROR: no parece una clave de Anthropic (empieza con sk-ant-). No se guardo nada."; exit 1; }
  guardar TUWA_IA_CLAVE "$K"; echo "   clave guardada"
fi
chmod 640 "$ENVF"; sudo chgrp www-data "$ENVF"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
sudo systemctl restart "php$PHPV-fpm" && echo "LISTO"
