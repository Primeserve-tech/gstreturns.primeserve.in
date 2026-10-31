#!/bin/sh
set -eu
mkdir -p writable/data writable/session writable/cache writable/logs
mkdir -p "${GST_TEMP_DIR:-/var/www/html/writable/data}"
chown www-data:www-data "${GST_TEMP_DIR:-/var/www/html/writable/data}"
chown -R www-data:www-data writable
chmod 700 writable/data writable/session
exec "$@"
