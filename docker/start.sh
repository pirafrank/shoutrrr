#!/usr/bin/env sh
set -eu

chown -R www-data:www-data /var/www/html/storage /var/www/html/database/sqlite
exec supervisord -c /etc/supervisor/php-laravel.conf
