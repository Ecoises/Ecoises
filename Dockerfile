# Imagen base con PHP 8.3 + Nginx + PHP-FPM optimizada para Laravel
FROM serversideup/php:8.3-fpm-nginx

# Instalamos intl (obligatorio para Filament v4.5+)
# Esto soluciona el error anterior de ext-intl
RUN install-php-extensions intl

# Copiamos todo el código directamente al directorio que espera la imagen
# --chown evita problemas de permisos desde el principio
COPY --chown=www-data:www-data . /var/www/html

# Copiamos Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalamos dependencias (sin dev para producción)
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist

# Aseguramos permisos en storage y cache (aunque --chown ya ayuda)
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Exponemos el puerto que usa esta imagen (8080 por defecto)
EXPOSE 8080

# Comando por defecto de la imagen: inicia Nginx + PHP-FPM
# NO lo cambies
CMD ["/usr/local/bin/start-container"]