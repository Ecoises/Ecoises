# Imagen base con PHP 8.3 + Nginx + PHP-FPM (intl ya viene incluida)
FROM serversideup/php:8.3-fpm-nginx

# Copia el código con permisos correctos
COPY --chown=www-data:www-data . /var/www/html

# Copia Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalamos dependencias ignorando el chequeo de ext-intl (porque SÍ está instalada)
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --ignore-platform-reqs=ext-intl

# Permisos finales para Laravel
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Puerto para Railway
EXPOSE 8080

# Inicia todo automáticamente
CMD ["/usr/local/bin/start-container"]