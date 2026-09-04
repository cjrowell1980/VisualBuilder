FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.* ./
RUN npm run build

FROM composer:2 AS dependencies
WORKDIR /app
ENV COMPOSER_HOME=/tmp/composer
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth,target=/tmp/composer/auth.json \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-fpm-alpine
RUN apk add --no-cache libpq-dev icu-dev nginx supervisor \
    && docker-php-ext-install pdo_pgsql intl opcache
WORKDIR /var/www/html
COPY . .
COPY --from=dependencies /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
