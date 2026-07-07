FROM php:8.3-cli

# System deps: unzip/git so Composer can extract dist packages; libzip for the zip ext.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8080

# Install deps (if missing) then serve via PHP's built-in server.
# vendor/ lives on a named volume so it survives the source bind-mount.
CMD ["sh", "-c", "composer install --no-interaction --no-progress && php -S 0.0.0.0:8080 -t public"]
