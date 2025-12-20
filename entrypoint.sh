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

# Set permissions for storage and bootstrap/cache
echo "Setting permissions for storage and bootstrap/cache..."
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache
echo "Permissions set."

# Execute the main container command
exec "$@"
