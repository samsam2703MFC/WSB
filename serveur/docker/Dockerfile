# =============================================================================
#  wsb-web — toutes les boutiques (et le site BuddyCorp) dans UN conteneur
#  PHP + Apache, derrière le Traefik du serveur. Le code, les configurations,
#  les photos et les données restent sur l'hôte (/srv/webshops, monté tel
#  quel) : le conteneur ne garde rien, on peut le reconstruire à tout moment.
#  Les sites Apache des boutiques sont écrits par wsm dans /srv/webshops/apache/.
# =============================================================================
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libicu-dev libzip-dev curl \
 && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd intl zip bcmath pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate remoteip >/dev/null \
 && a2dissite 000-default >/dev/null

COPY php.ini        /usr/local/etc/php/conf.d/90-wsm.ini
COPY apache-wsm.conf /etc/apache2/conf-enabled/zz-wsm.conf
