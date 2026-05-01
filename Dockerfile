FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        default-mysql-client \
        libcurl4-openssl-dev \
    && docker-php-ext-install curl mysqli pdo_mysql \
    && a2enmod headers rewrite \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/
COPY docker/render-entrypoint.sh /usr/local/bin/render-entrypoint

RUN mkdir -p /var/www/html/storage/sessions \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 0775 /var/www/html/storage /var/www/html/storage/sessions \
    && chmod +x /usr/local/bin/render-entrypoint

ENV PORT=10000

ENTRYPOINT ["render-entrypoint"]
CMD ["apache2-foreground"]
