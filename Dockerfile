FROM php:8.2-apache

# ដំឡើង Extensions ចាំបាច់សម្រាប់ Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# បើកដំណើរការ Apache Rewrite Module សម្រាប់ Route របស់ Laravel
RUN a2enmod rewrite

# កំណត់ Document Root របស់ Apache ទៅកាន់ Folder public របស់ Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# ផ្លាស់ប្ដូរទីតាំងការងារទៅក្នុង Server
WORKDIR /var/www/html
COPY . .

# ដំឡើង Composer 
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ដំឡើង Packages និងបិទការឆែក Platform រំខាន
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# កំណត់សិទ្ធិ Permissions លើ Folder ផ្ទុកទិន្នន័យ  
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80