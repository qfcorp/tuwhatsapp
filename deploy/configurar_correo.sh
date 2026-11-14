#!/usr/bin/env bash
# Configura el correo que manda WhatsApp Tukan (respaldo de los avisos: chats que nadie contesta).
# Guarda en config/.env: SMTP_HOST, SMTP_PORT, SMTP_USUARIO, SMTP_CLAVE, SMTP_DE (y SMTP_DE_NOMBRE si lo escribes).
# Uso:  bash /var/www/tuwhatsapp/deploy/configurar_correo.sh
# En cada pregunta, solo Enter deja lo que ya estaba guardado. Al final manda un correo de prueba.
# Sin preguntas (para copiar y pegar): pasa los datos como variables y solo pide la clave del buzon:
#   CORREO_HOST=mail.tukanec.com CORREO_PUERTO=465 CORREO_USUARIO=avisos@tukanec.com CORREO_PRUEBA=tu@correo.com bash configurar_correo.sh
set -u
APP="${APP:-/var/www/tuwhatsapp}"
ENVF="$APP/config/.env"
[ -f "$ENVF" ] || { echo "ERROR: primero corre el instalador"; exit 1; }
guardar() {   # guardar NOMBRE VALOR
  sudo grep -v -E "^$1=" "$ENVF" > /tmp/tuwa_env_nuevo; echo "$1=$2" >> /tmp/tuwa_env_nuevo
  sudo cp /tmp/tuwa_env_nuevo "$ENVF"; rm -f /tmp/tuwa_env_nuevo
}
actual() { sudo grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- || true; }
limpio() { echo "$1" | tr -d ' \r'; }
preguntar() {   # preguntar NOMBRE "Texto" [por_defecto] [valor_ya_dado]
  if [ -n "${4:-}" ]; then guardar "$1" "$(limpio "$4")"; echo "$2: $4"; return; fi
  local ya; ya="$(actual "$1")"; [ -z "$ya" ] && ya="${3:-}"
  printf '%s [%s]: ' "$2" "$ya"
  read -r V; V="$(limpio "$V")"
  [ -z "$V" ] && V="$ya"
  [ -n "$V" ] && guardar "$1" "$V"
}

echo "== Correo para los avisos de chats sin contestar"
echo "   Usa un buzon de tu servidor de correo (por ejemplo avisos@tukansoftware.com)."
preguntar SMTP_HOST "Servidor de correo (SMTP), por ejemplo mail.tukansoftware.com" "" "${CORREO_HOST:-}"
preguntar SMTP_PORT "Puerto (587 = STARTTLS, 465 = SSL)" "587" "${CORREO_PUERTO:-}"
preguntar SMTP_USUARIO "Usuario del buzon (el correo completo)" "" "${CORREO_USUARIO:-}"
YA=""; [ -n "$(actual SMTP_CLAVE)" ] && YA=" (ya hay una guardada: Enter para dejarla)"
printf 'Clave del buzon%s: ' "$YA"
read -r -s C; echo
C="$(echo "$C" | tr -d '\r')"
[ -n "$C" ] && guardar SMTP_CLAVE "$C" && echo "   clave guardada"
preguntar SMTP_DE "Correo que aparece como remitente" "$(actual SMTP_USUARIO)" "${CORREO_DE:-${CORREO_USUARIO:-}}"
if [ -z "${CORREO_HOST:-}" ]; then
  printf 'Nombre del remitente (Enter = Tukan Mensajes con tilde): '
  read -r N; N="$(echo "$N" | tr -d '\r')"
  [ -n "$N" ] && guardar SMTP_DE_NOMBRE "$N"
fi
sudo chmod 640 "$ENVF"; sudo chgrp www-data "$ENVF"

if [ -n "${CORREO_PRUEBA:-}" ]; then P="$(limpio "$CORREO_PRUEBA")"; else
  printf 'Correo para mandar una PRUEBA (Enter = saltar): '
  read -r P; P="$(limpio "$P")"
fi
if [ -n "$P" ]; then
  sudo -u www-data env PARA="$P" php -r '
    require "'"$APP"'/app/bootstrap.php";
    try {
        \TuWhatsApp\Correo::enviar(getenv("PARA"), "Prueba de WhatsApp Tukan", "Si te llego este correo, los avisos de chats sin contestar ya funcionan.");
        echo "   LISTO: correo de prueba enviado a " . getenv("PARA") . " (revisa tambien la carpeta de spam)\n";
    } catch (Throwable $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }'
fi
echo "Listo. Cada persona elige cuando le llega el correo en: App en el celular -> Respaldo por correo."
