# Imagen base con PHP 8.3 + Nginx + PHP-FPM optimizada para Laravel
# intl ya viene incluida, NO necesitas install-php-extensions
FROM serversideup/php:8.3-fpm-nginx

# Copiamos el código con permisos correctos desde el principio
COPY --chown=www-data:www-data . /var/www/html

# Copiamos Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalamos dependencias (Composer ya no se quejará de intl)
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist

# Permisos finales para storage y cache (Laravel lo necesita)
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Puerto que Railway espera (la imagen usa 8080 internamente)
EXPOSE 8080

# Inicia Nginx + PHP-FPM automáticamente (script de la imagen)
CMD ["/usr/local/bin/start-container"]