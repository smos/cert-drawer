#!/bin/bash
set -e

# Handle APP_KEY persistence in the same directory as the database
DB_PATH=${DB_DATABASE:-/var/www/html/database/database.sqlite}
DB_DIR=$(dirname "$DB_PATH")
KEY_FILE="$DB_DIR/.app_key"

if [ -z "$APP_KEY" ]; then
    if [ -f "$KEY_FILE" ]; then
        echo "Loading persisted APP_KEY from $KEY_FILE"
        export APP_KEY=$(cat "$KEY_FILE")
    else
        echo "Generating new APP_KEY..."
        # Generate key and capture only the key part (base64:...)
        NEW_KEY=$(php artisan key:generate --show --no-ansi)
        mkdir -p "$DB_DIR"
        echo "$NEW_KEY" > "$KEY_FILE"
        export APP_KEY="$NEW_KEY"
        echo "New APP_KEY generated and persisted to $KEY_FILE"
    fi
fi

# Ensure SQLite database exists in the persistent volume
if [ ! -f "$DB_PATH" ]; then
    echo "Creating initial database at $DB_PATH..."
    mkdir -p "$DB_DIR"
    touch "$DB_PATH"
    chown www-data:www-data "$DB_PATH"
    
    # Run migrations and seed for the first time
    echo "Running initial migrations and seeding..."
    php artisan migrate --force
    php artisan db:seed --force
else
    # Just run migrations for updates
    echo "Running migrations..."
    php artisan migrate --force
fi

# Ensure storage is writable
chown -R www-data:www-data /var/www/html/storage /var/www/html/database

# Start the main process
echo "Starting Apache..."
exec "$@"
