#!/bin/sh

# Exit immediately if a command exits with a non-zero status.
set -e

# Run composer install if vendor directory doesn't exist or is empty
if [ ! -d "/var/www/html/vendor" ] || [ -z "$(ls -A /var/www/html/vendor)" ]; then
    echo "Composer dependencies not found. Installing now..."
    composer install --no-dev --optimize-autoloader
    echo "Composer dependencies installed."
fi

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force
echo "Database migrations complete."

# Clear and cache configs (optional, good for performance)
echo "Optimizing Laravel application..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Laravel optimization complete."

# Execute the main container command
exec "$@"
