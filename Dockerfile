# Use the official PHP image as a base
FROM php:8.3-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    sqlite-dev \
    oniguruma-dev \
    libxml2-dev

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    gd \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    zip \
    exif \
    pcntl \
    bcmath \
    opcache \
    intl \
    soap \
    xml

# Clear cache
RUN rm -rf /var/cache/apk/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application code
COPY . /var/www/html

# Copy entrypoint script and make it executable
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Set entrypoint to our custom script
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# Start PHP-FPM as the default command for the entrypoint
CMD ["php-fpm"]
