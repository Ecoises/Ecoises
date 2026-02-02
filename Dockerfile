FROM serversideup/php:8.3-fpm-nginx

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

EXPOSE 8080

CMD ["/bin/sh", "-c", "export NGINX_HOST_PORT=${PORT:-8080} && /usr/local/bin/start-container"]