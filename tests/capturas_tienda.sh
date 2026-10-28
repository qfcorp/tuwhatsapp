#!/usr/bin/env bash
# Saca las pantallas "crudas" del celular para la ficha de Google Play de «Tukán Mensajes» (WhatsApp Tukán), con la Tienda Muestra
# Tukán (datos inventados, números de ficción) en un WhatsApp Tukán LOCAL de prueba. Nunca usa el servidor real ni Meta: arma su
# propia base y la borra al final.
# Uso (computadora con PostgreSQL, PHP y Playwright para Python):
#   CAPTURAS=/carpeta PGUSER=... PGPASSWORD=... bash tests/capturas_tienda.sh
# Sale en /carpeta/cap_es y /carpeta/cap_en (8 pantallas cada una, en español y en inglés con el botón 🌐).
# Después, en qfcorp/tukan-apps:  python3 tienda/herramientas/armar_tienda_tuwhatsapp.py /carpeta
set -u
cd "$(dirname "$0")/.."
OUT="${CAPTURAS:?Falta CAPTURAS=/carpeta}"
PUERTO="${CAP_PUERTO:-8469}"
DB="${DB_CAPTURAS:-tuwhatsapp_capturas}"
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}" PGUSER="${PGUSER:-postgres}"
TMPD="$(mktemp -d)"
trap 'kill ${WEB:-} 2>/dev/null; dropdb --if-exists "$DB" 2>/dev/null; rm -rf "$TMPD"' EXIT
dropdb --if-exists "$DB" && createdb "$DB" || { echo "No se pudo crear la base $DB"; exit 1; }
psql -q -v ON_ERROR_STOP=1 -d "$DB" -f db/schema.sql > /dev/null 2>&1 || exit 1
# Meta, la IA y Plux apuntan a ningún lado (puerto 9): no sale nada aunque algo fallara
cat > "$TMPD/env" <<EOT
TUWA_DB_HOST=$PGHOST
TUWA_DB_PORT=$PGPORT
TUWA_DB_NAME=$DB
TUWA_DB_USER=$PGUSER
TUWA_DB_PASS=${PGPASSWORD:-}
TUWA_LLAVE=$(printf 'ab%.0s' $(seq 32))
META_GRAPH_URL=http://127.0.0.1:9
TUKAN_IA_URL=http://127.0.0.1:9
TUWA_URL=http://127.0.0.1:$PUERTO
TUWA_ARCHIVOS=$TMPD/archivos
EOT
export TUWA_ENV="$TMPD/env" TUKAN_IA_ENV=/no/existe
php deploy/demo_google.php crear > /dev/null || exit 1
# Solo para las fotos (en esta base que se borra al final): la persona con un nombre de ficción
CLAVE="Captura-$(date +%s)"
psql -q -d "$DB" -c "UPDATE usuarios SET nombre='Andrea Gómez', clave='$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CLAVE")' WHERE correo='revisor.google@tukanec.com'" || exit 1
CAMILA="$(psql -qtAc "SELECT id FROM contactos WHERE nombre='Camila Ortega'" -d "$DB")"
PEDIDO="$(psql -qtAc "SELECT id FROM pedidos WHERE estado='pendiente' LIMIT 1" -d "$DB")"
php -d display_errors=0 -S "127.0.0.1:$PUERTO" -t public > "$TMPD/web.log" 2>&1 &
WEB=$!
sleep 0.8
python3 tests/capturas_tienda.py "http://127.0.0.1:$PUERTO" revisor.google@tukanec.com "$CLAVE" "$CAMILA" "$PEDIDO" "$OUT"
