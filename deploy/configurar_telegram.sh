#!/usr/bin/env bash
# Conecta el bot PROPIO de Tukan Mensajes para los avisos por Telegram de cada persona del equipo.
# (No es el bot de las alertas internas de Tukan: TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID no se tocan.)
# Antes: en Telegram, habla con @BotFather -> /newbot -> nombre "Tukan Mensajes" -> usuario que termine en "bot" -> copia el token.
# Uso:  bash /var/www/tuwhatsapp/deploy/configurar_telegram.sh            (pregunta el token, no se ve al escribirlo)
#       TELEGRAM_TOKEN_NUEVO=123456:ABC... bash configurar_telegram.sh    (sin preguntas)
# Guarda en config/.env: TUWA_TELEGRAM_TOKEN, TUWA_TELEGRAM_SECRETO (lo crea solo) y TUWA_TELEGRAM_BOT (nombre del bot),
# revisa el token con Telegram y registra el webhook https://<tu dominio>/telegram.php. Se puede correr las veces que haga falta.
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
[ -f "$ENVF" ] || { echo "ERROR: primero corre el instalador"; exit 1; }
guardar() {   # guardar NOMBRE VALOR  (entre comillas dobles; archivo temporal en la misma carpeta, nunca en /tmp)
  case "$2" in
    *'"'*) echo "   ERROR: el valor de $1 no puede tener comillas dobles."; return 1 ;;
  esac
  local tmp; tmp="$(sudo mktemp "$ENVF.XXXXXX")" || return 1
  sudo sh -c 'grep -v -E "^$1=" "$2" > "$3"; true' _ "$1" "$ENVF" "$tmp"
  printf '%s="%s"\n' "$1" "$2" | sudo tee -a "$tmp" > /dev/null
  sudo chown --reference="$ENVF" "$tmp" && sudo chmod 640 "$tmp" && sudo chgrp www-data "$tmp" && sudo mv "$tmp" "$ENVF"
}
actual() { sudo grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

echo "== Bot de Telegram para los avisos de WhatsApp Tukan"
T="${TELEGRAM_TOKEN_NUEVO:-}"
if [ -z "$T" ]; then
  YA=""; [ -n "$(actual TUWA_TELEGRAM_TOKEN)" ] && YA=" (ya hay uno guardado: Enter para dejarlo)"
  printf 'Token del bot (de @BotFather)%s: ' "$YA"
  read -r -s T; echo
fi
T="$(echo "$T" | tr -d ' \r')"
ANTES="$(actual TUWA_TELEGRAM_TOKEN)"
BOT_ANTES="$(actual TUWA_TELEGRAM_BOT)"
if [ -n "$T" ]; then
  echo "$T" | grep -Eq '^[0-9]{5,}:[A-Za-z0-9_-]{30,}$' || { echo "ERROR: ese token no parece de un bot (se ve como 123456789:ABC...)."; exit 1; }
  guardar TUWA_TELEGRAM_TOKEN "$T" || exit 1
  echo "   token guardado"
fi
[ -n "$(actual TUWA_TELEGRAM_TOKEN)" ] || { echo "ERROR: falta el token del bot."; exit 1; }
if [ -z "$(actual TUWA_TELEGRAM_SECRETO)" ]; then
  guardar TUWA_TELEGRAM_SECRETO "$(openssl rand -hex 32)" || exit 1
fi
SALIDA="$(sudo -u www-data php "$APP/bin/telegram.php" conectar 2>&1)"; R=$?
if [ $R -ne 0 ]; then
  echo "   $SALIDA"
  if [ -n "$T" ] && [ "$T" != "$ANTES" ]; then guardar TUWA_TELEGRAM_TOKEN "$ANTES"; echo "   (se dejo el token que estaba antes)"; fi
  exit 1
fi
BOT="$(echo "$SALIDA" | sed -n 's/^BOT=//p' | head -1)"
[ -n "$BOT" ] || { echo "ERROR: Telegram no devolvio el nombre del bot."; exit 1; }
guardar TUWA_TELEGRAM_BOT "$BOT" || exit 1
if [ -n "$BOT_ANTES" ] && [ "$BOT_ANTES" != "$BOT" ]; then
  # Otro bot: los Telegram conectados al bot viejo ya no reciben nada. Se desconecta a todos y se quita el webhook del viejo.
  N="$(printf '%s' "$ANTES" | sudo -u www-data php "$APP/bin/telegram.php" cambio_bot | sed -n 's/^DESCONECTADOS=//p')"
  echo "   ATENCION: cambiaste de bot (@$BOT_ANTES -> @$BOT). Se desconecto a ${N:-0} personas."
  echo "   Cada persona debe conectar de nuevo: App en el celular -> Avisos por Telegram -> Conectar mi Telegram."
fi
echo "LISTO: bot @$BOT conectado"
echo "Cada persona lo conecta en: WhatsApp Tukan -> App en el celular -> Avisos por Telegram -> Conectar mi Telegram."
