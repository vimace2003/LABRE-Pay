#!/bin/sh
# Garante que a pasta app/ é gravável pelo PHP (config.php, install.lock, backups)
if [ -d /var/www/app ]; then
  chown -R www-data:www-data /var/www/app 2>/dev/null || true
fi
exec "$@"
