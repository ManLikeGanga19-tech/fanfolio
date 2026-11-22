#!/usr/bin/env sh
# Local first-run bootstrap: dependencies, .env, app key, database. Safe to run on every start.
set -eu

cd /var/www/html

if [ "${FANFOLIO_BOOTSTRAP:-1}" = "1" ]; then
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if ! grep -q '^APP_KEY=base64:' .env; then
        php artisan key:generate --force
    fi

    until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -q; do
        echo "waiting for postgres..."
        sleep 1
    done

    php artisan migrate --force
fi

exec "$@"
