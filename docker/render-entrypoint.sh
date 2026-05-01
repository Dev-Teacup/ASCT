#!/usr/bin/env bash
set -euo pipefail

: "${PORT:=10000}"

mkdir -p /var/www/html/storage/sessions
chown -R www-data:www-data /var/www/html/storage
chmod 0775 /var/www/html/storage /var/www/html/storage/sessions

cat > /etc/apache2/ports.conf <<APACHE_PORTS
Listen ${PORT}
APACHE_PORTS

cat > /etc/apache2/sites-available/000-default.conf <<APACHE_VHOST
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
APACHE_VHOST

exec "$@"
