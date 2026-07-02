FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

# app/ fica FORA do docroot, como no cPanel (public/ -> /var/www/html, app/ -> /var/www/app)
# bootstrap.php resolve por caminho relativo ../app

COPY entrypoint.sh /usr/local/bin/labrepay-entrypoint.sh
RUN chmod +x /usr/local/bin/labrepay-entrypoint.sh

ENTRYPOINT ["labrepay-entrypoint.sh"]
CMD ["apache2-foreground"]
