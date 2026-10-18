#!/bin/sh
# =============================================================================
# Arranque de la API.
#
# El orden importa: no se acepta tráfico hasta que la base responde. Un
# contenedor que levanta sin base de datos entra en estado de error con una
# excepción de conexión, que es más difícil de leer que un "no listo".
# =============================================================================
set -e

php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

# Espera activa a la base. Se usa pg_isready del propio cliente de PostgreSQL.
# El límite de 30 intentos son 60 segundos: pasado ese tiempo, es preferible
# que el contenedor muere y sea visible, en vez de quedar en un estado ambiguo.
intentos=0
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-flota_app}" -q
do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
        echo "La base de datos no respondió después de 60 segundos. El contenedor no arranca." >&2
        exit 1
    fi
    sleep 2
done

echo "Base de datos disponible."

exec "$@"