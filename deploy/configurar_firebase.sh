#!/usr/bin/env bash
# Guarda la clave de Firebase (cuenta de servicio) para los avisos nativos de la app Tukan Mensajes.
# Uso:  bash /var/www/tuwhatsapp/deploy/configurar_firebase.sh /ruta/de/la/clave.json
#       bash /var/www/tuwhatsapp/deploy/configurar_firebase.sh estado
# La clave se guarda CIFRADA en la base (con TUWA_LLAVE). Despues de guardarla, borra el archivo .json.
# Tambien se puede subir desde el programa: menu Tukan -> Avisos de la app (Firebase).
set -euo pipefail
APP="${APP:-/var/www/tuwhatsapp}"
RUTA="${1:-}"
if [ "$RUTA" = "estado" ]; then
  sudo -u www-data php "$APP/bin/firebase.php" estado
  exit 0
fi
if [ -z "$RUTA" ] || [ ! -r "$RUTA" ]; then
  echo "Uso: bash $APP/deploy/configurar_firebase.sh /ruta/de/la/clave.json"
  echo "(no encuentro el archivo: ${RUTA:-sin ruta})"
  exit 1
fi
echo "== Clave de Firebase para los avisos de la app Tukan Mensajes"
if SALIDA="$(sudo -u www-data php "$APP/bin/firebase.php" guardar < "$RUTA" 2>&1)"; then
  PROY="$(printf '%s\n' "$SALIDA" | sed -n 's/^PROYECTO=//p')"
  echo "   LISTO: clave guardada (proyecto $PROY)."
  echo "   Ahora borra el archivo: rm $RUTA"
  echo "   Para probar: abre la app Tukan Mensajes en tu celular y en el programa toca"
  echo "   App en el celular -> Mandarme un aviso de prueba a la app."
else
  echo "   No se pudo guardar: $SALIDA"
  exit 1
fi
