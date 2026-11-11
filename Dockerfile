FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html/uploads \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/techmarket.conf \
    && a2enconf techmarket
COPY render-entrypoint.sh /usr/local/bin/render-entrypoint.sh
RUN chmod +x /usr/local/bin/render-entrypoint.sh
CMD ["/usr/local/bin/render-entrypoint.sh"]
