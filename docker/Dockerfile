# Fixado em 8.4.27+ (não usar a tag flutuante "8.4-apache") — corrige as CVEs
# 2026-109618, 2026-109619, 2026-111497 e 2026-115560, e acompanha a versão
# mais atual disponível na HostGator (produção). Ajuste aqui sempre que o
# PHP lançar um patch de segurança nesta linha.
FROM php:8.4.27-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

# app/ fica FORA do docroot, como no cPanel (public/ -> /var/www/html, app/ -> /var/www/app)
# bootstrap.php resolve por caminho relativo ../app

COPY entrypoint.sh /usr/local/bin/labrepay-entrypoint.sh
RUN chmod +x /usr/local/bin/labrepay-entrypoint.sh

ENTRYPOINT ["labrepay-entrypoint.sh"]
CMD ["apache2-foreground"]
