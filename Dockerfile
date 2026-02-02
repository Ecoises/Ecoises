# Imagen base oficial con PHP 8.3 + Nginx + PHP-FPM preconfigurados para Laravel
FROM serversideup/php:8.3-fpm-nginx

# Copiamos el código del proyecto
# --chown asegura permisos correctos desde el principio (usuario www-data)
COPY --chown=www-data:www-data . /var/www/html

# Instalamos Composer desde la imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalamos dependencias de PHP (solo producción, sin dev)
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist

# Ajustamos permisos para storage y bootstrap/cache (Laravel lo necesita)
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache

# Exponemos el puerto que usa Nginx en esta imagen (Railway lo detecta automáticamente)
EXPOSE 8080

# Comando por defecto: inicia Nginx + PHP-FPM automáticamente
# NO cambies esto, la imagen lo maneja sola
CMD ["/usr/local/bin/start-container"]