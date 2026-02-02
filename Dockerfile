FROM serversideup/php:8.3-fpm-nginx
RUN find /etc/nginx -type f && echo "LISTO"

COPY --chown=www-data:www-data . /var/www/html
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --ignore-platform-req=ext-intl

RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Reemplaza el puerto hardcodeado por la variable $PORT de Railway
RUN sed -i 's/listen 80;/listen $PORT;/' /etc/nginx/sites-enabled/default || \
    sed -i 's/listen 80;/listen $PORT;/' /etc/nginx/conf.d/default.conf

EXPOSE 8080

# Usa una línea CMD que reemplace $PORT al iniciar
CMD ["/bin/sh", "-c", "sed -i \"s/listen [0-9]*;/listen $PORT;/\" /etc/nginx/sites-enabled/default 2>/dev/null || sed -i \"s/listen [0-9]*;/listen $PORT;/\" /etc/nginx/conf.d/default.conf 2>/dev/null; /usr/local/bin/start-container"]