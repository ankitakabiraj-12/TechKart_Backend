
FROM php:8.2-apache

# Install required PHP extensions and enable Apache modules
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
       pdo_mysql \
       curl \
       mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Set public/ as Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    && sed -ri 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' \
    /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

# Copy backend source code
COPY . /var/www/html

EXPOSE 8080

CMD ["apache2-foreground"]
