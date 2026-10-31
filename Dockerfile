FROM php:8.3-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update && apt-get install -y --no-install-recommends curl libcurl4-openssl-dev libicu-dev libonig-dev libsodium-dev libsqlite3-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring pdo_sqlite sodium zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/returns.ini
COPY docker/entrypoint.sh /usr/local/bin/returns-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/returns-entrypoint && chmod +x /usr/local/bin/returns-entrypoint \
    && mkdir -p writable/data writable/session writable/cache writable/logs && chown -R www-data:www-data writable
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD php docker/web-health.php
ENTRYPOINT ["returns-entrypoint"]
CMD ["apache2-foreground"]
