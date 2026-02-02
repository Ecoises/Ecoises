# Imagen base oficial con PHP 8.3 + Nginx + PHP-FPM preconfigurados para Laravel
FROM serversideup/php:8.3-fpm-nginx

# Instalamos la extensión intl (obligatoria para Filament v4.5+)
# Esto resuelve el error "ext-intl * -> it is missing from your system"
RUN install-php-extensions intl

# Copiamos todo el código del proyecto con permisos correctos desde el inicio
# (usuario y grupo www-data para evitar problemas de permisos en storage)
COPY --chown=www-data:www-data . /var/www/html

# Copiamos Composer desde la imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalamos las dependencias de Composer
# --no-dev para producción, --prefer-dist para más velocidad
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist

# Aseguramos permisos en las carpetas críticas de Laravel
# (aunque ya los pusimos con --chown, esto es por si acaso)
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Exponemos el puerto que usa Nginx en esta imagen
# Railway lo detecta automáticamente
EXPOSE 8080

# Comando por defecto de la imagen: inicia Nginx + PHP-FPM
# NO lo cambies, maneja todo automáticamente
CMD ["/usr/local/bin/start-container"]