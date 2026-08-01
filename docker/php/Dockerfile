# =========================================================================
# Imagem PHP-FPM da aplicação (Fase 10).
#
# Responsabilidade: servir a aplicação Laravel real por PHP-FPM, com número
# de workers EXPLÍCITO (docker/php/www.conf), para que a carga HTTP com k6
# exercite concorrência de verdade — ao contrário do `php artisan serve`
# single-worker usado na Fase 8.
#
# Escolha de topologia justificada em docs/fases/fase-10-http-concurrency.md.
# =========================================================================
FROM php:8.4-fpm-alpine

# ext-redis via PECL: a mesma extensão usada em desenvolvimento e nas provas,
# para que o comportamento de EVALSHA/getLastError seja idêntico.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Instala dependências em camada própria: mudar código-fonte não invalida o
# cache do Composer.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --prefer-dist --no-progress || \
    composer install --no-interaction --no-scripts --prefer-dist --no-progress --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

# Contagem de workers fixa e visível: é a variável independente da Fase 10.
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-rate-limiter.conf

EXPOSE 9000
