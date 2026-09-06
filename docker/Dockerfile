# =====================================================================
# elli – Web-Container (Apache + PHP + gebautes Vue-Frontend)
# Multi-Stage: 1) Frontend bauen  2) PHP/Apache-Laufzeit
# =====================================================================

# ---- Stage 1: Frontend bauen ----------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /app

# Abhängigkeiten zuerst (besseres Layer-Caching)
COPY package.json package-lock.json ./
RUN npm ci

# Quellcode + Build. VITE_API_URL bleibt leer -> Frontend nutzt '/api.php'
# (gleiche Herkunft wie Apache, kein CORS).
COPY . .
RUN npm run build


# ---- Stage 2: PHP / Apache Laufzeit ---------------------------------
FROM php:8.2-apache AS runtime

# Systembibliotheken für die PHP-Erweiterungen (zip=docx/xlsx, gd=Bilder)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: mod_rewrite (SPA-Fallback) aktivieren
RUN a2enmod rewrite

# Backup-Import: Beim Umzug auf einen neuen Rechner wird ein kompletter
# Datenbank-Dump hochgeladen. Die PHP-Standardwerte (2M Upload / 8M POST)
# sind dafuer zu knapp - ein gewachsener Dump liegt schnell darueber.
RUN { \
      echo 'upload_max_filesize = 64M'; \
      echo 'post_max_size = 64M'; \
      echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/elli-import.ini

# Composer aus offiziellem Image übernehmen
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHP-Abhängigkeiten (PhpWord, PhpSpreadsheet, PdfParser) installieren
COPY docker/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Backend: api.php + die vier Word-Vorlagen (werden per __DIR__ geladen)
COPY src/assets/api.php ./api.php
COPY src/assets/*_template.docx ./

# Frontend: gebautes SPA aus Stage 1
COPY --from=frontend /app/dist/ ./

# Apache-VirtualHost
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
