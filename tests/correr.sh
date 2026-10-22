#!/usr/bin/env bash
# Corre todas las pruebas con Meta simulado. Necesita PostgreSQL.
# Variables: PGHOST (127.0.0.1) PGPORT (5432) PGUSER (postgres) PGPASSWORD, SIM_PUERTO (8097), WEB_PUERTO (8096)
set -u
cd "$(dirname "$0")/.."
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}" PGUSER="${PGUSER:-postgres}"
DB=tuwhatsapp_prueba
SIM_PUERTO="${SIM_PUERTO:-8097}"
WEB_PUERTO="${WEB_PUERTO:-8096}"
export SIM_LOG="$(mktemp)"
export SIM_URL="http://127.0.0.1:$SIM_PUERTO"
export TUWA_ARCHIVOS="$(mktemp -d)"
export TUKAN_IA_ENV=/no/existe   # nunca usar la clave real del servidor en las pruebas

dropdb --if-exists "$DB" && createdb "$DB" || { echo "No se pudo crear la base de prueba"; exit 1; }
psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null || exit 1
psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null 2>&1 || { echo "schema.sql no se puede correr dos veces"; exit 1; }

php -S "127.0.0.1:$SIM_PUERTO" tests/meta_simulado.php > /dev/null 2>&1 &
SIM=$!
trap 'kill $SIM ${WEB:-} 2>/dev/null; rm -rf "$TUWA_ARCHIVOS"' EXIT
sleep 0.5

export TUWA_DB_HOST="$PGHOST" TUWA_DB_PORT="$PGPORT" TUWA_DB_NAME="$DB" TUWA_DB_USER="$PGUSER" TUWA_DB_PASS="${PGPASSWORD:-}"
echo "######## Idiomas"
php -d display_errors=1 -d error_reporting=-1 tests/prueba_idiomas.php || exit 1

echo "######## Lógica"
php -d display_errors=1 -d error_reporting=-1 tests/prueba_logica.php || exit 1

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
TUKAN_CENTRAL_URL=https://panel.ejemplo.com
TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789
TUKAN_IA_URL=$SIM_URL
TUKAN_IA_ESPERA_MS=0
EOF
TUWA_ENV="$ENVF" php -d display_errors=0 -d log_errors=1 -d error_log="$ENVF.log" -S "127.0.0.1:$WEB_PUERTO" -t public > /dev/null 2>&1 &
WEB=$!
sleep 0.7
TUWA_ENV="$ENVF" bash tests/prueba_web.sh
R=$?
cp "$ENVF.log" /tmp/tuwa_err.log 2>/dev/null
if [ -s "$ENVF.log" ]; then echo "Avisos de PHP:"; cat "$ENVF.log"; R=1; fi
exit $R
