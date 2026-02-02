FROM serversideup/php:8.3-fpm-nginx

# Instala extensiones PHP que necesitas
RUN install-php-extensions \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    intl \
    zip

# Copia tu código
COPY --chown=www-data:www-data . /var/www/html

# Instala Composer y dependencias
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --optimize-autoloader --no-dev --no-interaction

# Permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Puerto que Railway espera
EXPOSE 8080

# Comando por defecto (ya trae nginx + php-fpm)
CMD ["/usr/local/bin/start-container"]