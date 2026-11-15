#!/usr/bin/env bash
# Corre todas las pruebas con Meta simulado. Necesita PostgreSQL.
# Variables: PGHOST (127.0.0.1) PGPORT (5432) PGUSER (postgres) PGPASSWORD, SIM_PUERTO (8097), WEB_PUERTO (8096), PLUX_PUERTO (8098), PANEL_PUERTO (8095), SMTP_PUERTO (8099), TG_PUERTO (8094), FCM_PUERTO (8093)
set -u
cd "$(dirname "$0")/.."
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}" PGUSER="${PGUSER:-postgres}"
DB="${TUWA_DB_PRUEBA:-tuwhatsapp_prueba}"   # en paralelo con otras pruebas: TUWA_DB_PRUEBA=otra_base
export TUWA_DB_PRUEBA="$DB"
export PGTZ="${PGTZ:-America/Guayaquil}"   # fechas de la base = las del programa (si no, de noche fallan las pruebas que comparan fechas)
SIM_PUERTO="${SIM_PUERTO:-8097}"
WEB_PUERTO="${WEB_PUERTO:-8096}"
PLUX_PUERTO="${PLUX_PUERTO:-8098}"
PANEL_PUERTO="${PANEL_PUERTO:-8095}"
SMTP_PUERTO="${SMTP_PUERTO:-8099}"
TG_PUERTO="${TG_PUERTO:-8094}"
FCM_PUERTO="${FCM_PUERTO:-8093}"
export SIM_LOG="$(mktemp)"
export SIM_URL="http://127.0.0.1:$SIM_PUERTO"
export TUWA_ARCHIVOS="$(mktemp -d)"
export TUKAN_IA_ENV=/no/existe   # nunca usar la clave real del servidor en las pruebas
export TUWA_SIN_SENDMAIL=1        # las pruebas nunca usan el sendmail de la maquina (los correos van al SMTP falso)

dropdb --if-exists "$DB" && createdb "$DB" || { echo "No se pudo crear la base de prueba"; exit 1; }
psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null || exit 1
psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null 2>&1 || { echo "schema.sql no se puede correr dos veces"; exit 1; }

php -S "127.0.0.1:$SIM_PUERTO" tests/meta_simulado.php > /dev/null 2>&1 &
SIM=$!
# Plux FALSO (nunca se llama a Plux de verdad): llaves RSA de prueba hechas aquí mismo
PLUX_DIR="$(mktemp -d)"
openssl genrsa -out "$PLUX_DIR/privada.pem" 2048 2>/dev/null && openssl rsa -in "$PLUX_DIR/privada.pem" -pubout -out "$PLUX_DIR/publica.pem" 2>/dev/null || { echo "No se pudieron hacer las llaves de prueba de Plux"; exit 1; }
export PLUX_PUB="$PLUX_DIR/publica.pem" PLUX_LOG="$PLUX_DIR/plux.log" PLUX_URL="http://127.0.0.1:$PLUX_PUERTO"
FALSO_LOG="$PLUX_LOG" FALSO_ID_CLIENTE=cliente_plux_prueba FALSO_CLAVE=clave_plux_prueba FALSO_LLAVE_PRIVADA="$PLUX_DIR/privada.pem" \
  FALSO_ESTABLECIMIENTO=EST-123 FALSO_BASE="$PLUX_URL" php -S "127.0.0.1:$PLUX_PUERTO" tests/plux_falso.php > /dev/null 2>&1 &
PLUXF=$!
# Panel Tukán Central SIMULADO (pagar el plan con tarjeta desde Mi cuenta): empieza con el pago con tarjeta APAGADO, como hoy
export PANEL_ESTADO="$PLUX_DIR/panel.json" PANEL_LOG="$PLUX_DIR/panel.log" PANEL_SECRETO="$(printf 'a1%.0s' $(seq 32))" PANEL_BASE="http://127.0.0.1:$PANEL_PUERTO"
: > "$PANEL_LOG"
php -S "127.0.0.1:$PANEL_PUERTO" tests/panel_simulado.php > /dev/null 2>&1 &
PANELF=$!
# Servidor de correo FALSO (respaldo por correo de los avisos): nunca manda nada de verdad
export SMTP_LOG="$PLUX_DIR/correos.log" SMTP_PUERTO
: > "$SMTP_LOG"
php tests/smtp_falso.php "$SMTP_PUERTO" > /dev/null 2>&1 &
SMTPF=$!
# Telegram SIMULADO (avisos por Telegram): nunca se habla con Telegram de verdad
export TG_LOG="$PLUX_DIR/telegram.log" TG_URL="http://127.0.0.1:$TG_PUERTO"
: > "$TG_LOG"
php -S "127.0.0.1:$TG_PUERTO" tests/telegram_simulado.php > /dev/null 2>&1 &
TGF=$!
# Firebase (avisos nativos de la app) y OAuth de Google SIMULADOS: cuenta de servicio de prueba hecha aquí mismo
openssl genrsa -out "$PLUX_DIR/fcm_privada.pem" 2048 2>/dev/null && openssl rsa -in "$PLUX_DIR/fcm_privada.pem" -pubout -out "$PLUX_DIR/fcm_publica.pem" 2>/dev/null || { echo "No se pudieron hacer las llaves de prueba de Firebase"; exit 1; }
export FCM_LOG="$PLUX_DIR/fcm.log" FCM_URL="http://127.0.0.1:$FCM_PUERTO" FCM_SIM_PUBLICA="$PLUX_DIR/fcm_publica.pem" FCM_SIM_PRIVADA="$PLUX_DIR/fcm_privada.pem" FCM_SIM_CORREO="tukan-avisos@tukan-mensajes-prueba.iam.gserviceaccount.com"
: > "$FCM_LOG"
php -S "127.0.0.1:$FCM_PUERTO" tests/fcm_simulado.php > /dev/null 2>&1 &
FCMF=$!
trap 'kill $SIM $PLUXF $PANELF $SMTPF $TGF $FCMF ${WEB:-} 2>/dev/null; rm -rf "$TUWA_ARCHIVOS" "$PLUX_DIR"' EXIT
sleep 0.5

export TUWA_DB_HOST="$PGHOST" TUWA_DB_PORT="$PGPORT" TUWA_DB_NAME="$DB" TUWA_DB_USER="$PGUSER" TUWA_DB_PASS="${PGPASSWORD:-}"
echo "######## Idiomas"
php -d display_errors=1 -d error_reporting=-1 tests/prueba_idiomas.php || exit 1

echo "######## Lógica"
php -d display_errors=1 -d error_reporting=-1 tests/prueba_logica.php || exit 1

echo "######## Factura electrónica con TuFactura (simulado)"
php -d display_errors=1 -d error_reporting=-1 tests/prueba_tufactura.php || exit 1

echo "######## Panel Tukán Central v2"
dropdb "$DB" && createdb "$DB" && psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null || exit 1
php -d display_errors=1 -d error_reporting=-1 tests/prueba_central_v2.php || exit 1

echo "######## Pantallas"
dropdb "$DB" && createdb "$DB" && psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null || exit 1
export WEB_URL="http://127.0.0.1:$WEB_PUERTO"
ENVF="$(mktemp)"
cat > "$ENVF" <<EOF
TUWA_LLAVE=$(printf 'ab%.0s' $(seq 32))
META_GRAPH_URL=$SIM_URL
META_APP_SECRET=secreto_de_prueba
META_VERIFY_TOKEN=verifica123
TUWA_API_TOKEN=api_token_de_prueba_0123456789abcdef
TUWA_URL=https://whatsapp.ejemplo.com
META_APP_ID=2592670637899782
META_ES_CONFIG_ID=1112223334445
TUKAN_CENTRAL_ID=tuwhatsapp
TUKAN_CENTRAL_SECRET=$(printf 'a1%.0s' $(seq 32))
TUKAN_CENTRAL_URL=$PANEL_BASE
TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789
TUKAN_IA_URL=$SIM_URL
TUKAN_IA_ESPERA_MS=0
TUWA_PUSH_PRUEBA=$SIM_URL/push/
PAYPHONE_URL=$SIM_URL
PLUX_API_BASE=$PLUX_URL/intv1/
TUWA_TELEGRAM_TOKEN=123456:tokenDePruebaDelBotTukanMensajes0123456789
TUWA_TELEGRAM_SECRETO=secreto_telegram_de_prueba_0123456789
TUWA_TELEGRAM_BOT=TukanMensajesBot
TUWA_TELEGRAM_API_URL=$TG_URL
TUWA_FCM_URL=$FCM_URL
EOF
TUWA_ENV="$ENVF" php -d display_errors=0 -d log_errors=1 -d error_log="$ENVF.log" -S "127.0.0.1:$WEB_PUERTO" -t public > /dev/null 2>&1 &
WEB=$!
sleep 0.7
TUWA_ENV="$ENVF" bash tests/prueba_web.sh
R=$?
# Actualizar sobre una base CON datos (como en el servidor): schema.sql tiene que correr sin errores
if psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null 2>"$ENVF.schema"; then echo "schema.sql sobre una base con datos: bien"; else echo "FALLA: schema.sql sobre una base con datos"; cat "$ENVF.schema"; R=1; fi
echo "######## App de Android (Google Play)"
TUWA_ENV="$ENVF" bash tests/prueba_app_android.sh || R=1
echo "######## App de iPhone (sin precios ni pagos, regla de Apple)"
TUWA_ENV="$ENVF" bash tests/prueba_app_iphone.sh || R=1
cp "$ENVF.log" /tmp/tuwa_err.log 2>/dev/null
if [ -s "$ENVF.log" ]; then echo "Avisos de PHP:"; cat "$ENVF.log"; R=1; fi
exit $R
