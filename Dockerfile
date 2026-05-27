FROM php:8.3-apache

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod 755 /var/www/html/data

EXPOSE 80
