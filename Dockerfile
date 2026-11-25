FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install SQLite PDO & OpenSSL & cURL extensions
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev pkg-config libssl-dev \
    && docker-php-ext-install pdo_sqlite curl

# Copy web files
WORKDIR /var/www/html
COPY portal/ /var/www/html/
COPY database/ /var/www/database/

# Ensure Apache can write to SQLite database
RUN chown -R www-data:www-data /var/www/html /var/www/database \
    && chmod -R 775 /var/www/database

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t portal portal/router.php"]
