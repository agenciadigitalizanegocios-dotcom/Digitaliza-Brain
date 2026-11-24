#!/bin/sh
set -e

# Pastas que o Laravel precisa (o volume de storage pode vir vazio).
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Banco SQLite num volume, para não perder ao recriar o container.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/app/database/data/database.sqlite}"
    mkdir -p "$(dirname "$DB_FILE")"
    [ -f "$DB_FILE" ] || touch "$DB_FILE"
fi

php artisan migrate --force
php artisan storage:link --force
php artisan view:cache

exec php artisan serve --host=0.0.0.0 --port=8000
