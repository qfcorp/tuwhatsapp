#!/usr/bin/env bash
# Instalador de WhatsApp Tukán (Ubuntu con PHP, PostgreSQL, nginx y túnel de Cloudflare).
# Uso (como el usuario normal, NO como root), una sola línea:
#   curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o /tmp/instalar_tuwhatsapp.sh && bash /tmp/instalar_tuwhatsapp.sh
# Se puede correr las veces que quieras: actualiza el programa y NUNCA borra mensajes ni la configuración.
# Los mensajes van sin tildes a proposito (se leen bien por SSH desde el celular).
set -u

APP="${APP:-/var/www/tuwhatsapp}"
DOMINIO="${DOMINIO:-whatsapp.tukanec.com}"
REPO="${REPO:-https://github.com/qfcorp/tuwhatsapp.git}"
YO="$(id -un)"
cd /tmp

if [ "$(id -u)" = "0" ]; then
  echo "ERROR: no lo corras como root ni con sudo. Pega la linea tal cual (el script pide sudo cuando lo necesita)."; exit 1
fi

# Version de PHP: la que ya usa el servidor (TuSalon usa 8.5); si no hay, 8.3
if [ -z "${PHPV:-}" ]; then
  PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [ -n "$PHPV" ] || PHPV=8.3
fi

echo "== 1. Programas (PHP $PHPV, PostgreSQL, nginx)"
sudo apt-get install -y "php$PHPV-fpm" "php$PHPV-pgsql" "php$PHPV-curl" "php$PHPV-mbstring" postgresql nginx git curl openssl < /dev/null > /dev/null \
  && echo "   listo" || echo "   AVISO: no se pudieron instalar algunos programas (revisa el mensaje de arriba)"

echo "== 2. Bajar o actualizar el programa"
if [ -d "$APP/.git" ]; then
  sudo chown -R "$YO":www-data "$APP"
  git -C "$APP" pull -q && echo "   actualizado"
else
  sudo git clone -q "$REPO" "$APP" && echo "   bajado"
fi
sudo mkdir -p "$APP/config"
sudo chown -R "$YO":www-data "$APP"

echo "== 3. Base de datos"
ENVF="$APP/config/.env"
viejo() { [ -f "$ENVF" ] && grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- || true; }
CLAVE="$(viejo TUWA_DB_PASS)"
[ -n "$CLAVE" ] || CLAVE="$(openssl rand -hex 16)"
sudo -u postgres psql -qc "CREATE ROLE tuwhatsapp LOGIN" < /dev/null 2>/dev/null
sudo -u postgres psql -qc "ALTER ROLE tuwhatsapp LOGIN PASSWORD '$CLAVE'" < /dev/null
if [ "$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='tuwhatsapp'" < /dev/null)" != "1" ]; then
  sudo -u postgres createdb -O tuwhatsapp tuwhatsapp < /dev/null && echo "   base creada"
fi
PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U tuwhatsapp -d tuwhatsapp -q -v ON_ERROR_STOP=1 -f "$APP/db/schema.sql" < /dev/null 2>/dev/null \
  && echo "   tablas listas (no se borra nada)" || echo "   ERROR al preparar las tablas"

echo "== 4. Configuracion (config/.env)"
LLAVE="$(viejo TUWA_LLAVE)";        [ -n "$LLAVE" ]  || LLAVE="$(openssl rand -hex 32)"
VERIFY="$(viejo META_VERIFY_TOKEN)"; [ -n "$VERIFY" ] || VERIFY="tukan$(openssl rand -hex 12)"
API="$(viejo TUWA_API_TOKEN)";       [ -n "$API" ]    || API="$(openssl rand -hex 24)"
EXTRA=""
[ -f "$ENVF" ] && EXTRA="$(grep -E '^(META_APP_SECRET|META_APP_ID|META_ES_CONFIG_ID|META_GRAPH_VERSION|META_GRAPH_URL|TELEGRAM_BOT_TOKEN|TELEGRAM_CHAT_ID)=' "$ENVF" || true)"
{
  echo "TUWA_DB_HOST=127.0.0.1"
  echo "TUWA_DB_NAME=tuwhatsapp"
  echo "TUWA_DB_USER=tuwhatsapp"
  echo "TUWA_DB_PASS=$CLAVE"
  echo "TUWA_URL=https://$DOMINIO"
  echo "TUWA_LLAVE=$LLAVE"
  echo "TUWA_API_TOKEN=$API"
  echo "META_VERIFY_TOKEN=$VERIFY"
  [ -n "$EXTRA" ] && echo "$EXTRA"
} > "$ENVF.nuevo"
mv "$ENVF.nuevo" "$ENVF"
chmod 640 "$ENVF" && sudo chgrp www-data "$ENVF" && echo "   listo"

echo "== 5. nginx ($DOMINIO)"
sudo tee /etc/nginx/sites-available/tuwhatsapp > /dev/null <<NGINX
server {
    listen 80;
    server_name $DOMINIO;
    root $APP/public;
    index index.php;
    client_max_body_size 32M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    location /assets/ {
        expires 7d;
        try_files \$uri =404;
    }
    location ~ /\. { deny all; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
        fastcgi_read_timeout 90;
    }
}
NGINX
sudo ln -sf /etc/nginx/sites-available/tuwhatsapp /etc/nginx/sites-enabled/tuwhatsapp
sudo nginx -t -q && sudo systemctl reload nginx && sudo systemctl restart "php$PHPV-fpm" && echo "   listo" || echo "   ERROR en nginx (revisa: sudo nginx -t)"

echo "== 6. Respaldo diario de la base (3:40 am, guarda 14 dias)"
sudo mkdir -p /var/backups/tuwhatsapp && sudo chmod 700 /var/backups/tuwhatsapp
LINEA="40 3 * * * sudo -u postgres pg_dump -Fc tuwhatsapp > /var/backups/tuwhatsapp/tuwhatsapp_\$(date +\\%F).dump && find /var/backups/tuwhatsapp -name '*.dump' -mtime +14 -delete"
(sudo crontab -l 2>/dev/null | grep -v "backups/tuwhatsapp"; echo "$LINEA") | sudo crontab - && echo "   listo"

echo "== 6b. Envio de campañas (cada minuto, como www-data)"
sudo touch /var/log/tuwhatsapp-campanias.log && sudo chown www-data:www-data /var/log/tuwhatsapp-campanias.log
(sudo crontab -u www-data -l 2>/dev/null | grep -v "tuwhatsapp/bin/campanias"; echo "* * * * * php $APP/bin/campanias.php >> /var/log/tuwhatsapp-campanias.log 2>&1") | sudo crontab -u www-data - && echo "   listo"

echo
echo "=========== RESULTADO ==========="
echo "1) Tablas: $(PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U tuwhatsapp -d tuwhatsapp -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" < /dev/null) (deben ser 12)"
echo "2) Extensiones de PHP: $(php -m | grep -E -c '^(pdo_pgsql|curl|mbstring|sodium)$') (deben ser 4)"
curl -s -o /dev/null -w "3) Pagina de entrar: %{http_code} (debe ser 200)\n" -H "Host: $DOMINIO" "http://127.0.0.1/?r=login"
curl -s -o /dev/null -w "4) Webhook: %{http_code} (debe ser 403 hasta conectar Meta)\n" -H "Host: $DOMINIO" "http://127.0.0.1/webhook.php"
echo "5) Usuarios del panel: $(PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U tuwhatsapp -d tuwhatsapp -tAc "SELECT count(*) FROM usuarios" < /dev/null)"
echo "7) Envio de campañas: $(sudo crontab -u www-data -l 2>/dev/null | grep -c tuwhatsapp/bin/campanias) (debe ser 1)"
grep -q '^META_APP_SECRET=.' "$ENVF" && echo "6) Clave secreta de Meta: guardada" || echo "6) Clave secreta de Meta: FALTA (paso de Meta)"
grep -q '^META_ES_CONFIG_ID=.' "$ENVF" && grep -q '^META_APP_ID=.' "$ENVF" && echo "7) Boton QR de Meta: listo" || echo "7) Boton QR de Meta: FALTA (App ID y Configuration ID)"
echo "================================="
echo "Crear tu usuario:          php $APP/bin/crear_usuario.php"
echo "Guardar la clave de Meta:  bash $APP/deploy/clave_meta.sh"
