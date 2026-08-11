#!/bin/bash
set -e

echo "--------------------------------------------------------"
echo " Starting Certificate Drawer"
echo " Build Date: ${APP_BUILD_DATE:-unknown}"
echo "--------------------------------------------------------"

# Handle APP_KEY persistence in the same directory as the database
DB_PATH=${DB_DATABASE:-/var/www/html/database/database.sqlite}
DB_DIR=$(dirname "$DB_PATH")
KEY_FILE="$DB_DIR/.app_key"

# Check if running as scheduler
IS_SCHEDULER=false
for arg in "$@"; do
    if [ "$arg" = "schedule:work" ]; then
        IS_SCHEDULER=true
        break
    fi
done

if [ "$IS_SCHEDULER" = true ]; then
    echo "Scheduler container detected. Skipping database updates and APP_KEY generation."
    
    # Wait for the main container to create the APP_KEY and database file
    while [ ! -s "$KEY_FILE" ] || [ ! -s "$DB_PATH" ]; do
        echo "Waiting for main container to generate APP_KEY and database..."
        sleep 5
    done
    
    # Load the APP_KEY
    export APP_KEY=$(cat "$KEY_FILE")
    echo "Loaded persisted APP_KEY"
    
    # Wait for migrations to complete
    while true; do
        STATUS=$(php artisan migrate:status 2>/dev/null)
        EXIT_CODE=$?
        if [ $EXIT_CODE -ne 0 ] || echo "$STATUS" | grep -q "Pending" || [ -z "$STATUS" ]; then
            echo "Waiting for database migrations to complete..."
            sleep 5
        else
            break
        fi
    done
    
    echo "Database migrations are complete. Starting scheduler..."
    exec "$@"
fi

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
