ARG PHP_VERSION=8.5

FROM composer:2.7 AS vendor

WORKDIR /src/

COPY composer.lock composer.json /src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

FROM appwrite/utopia-base:php-${PHP_VERSION}-2.1.0 AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /code

# pcov rather than Xdebug: it only does line coverage, which is all a report
# needs, and costs a fraction of the run time. Loaded but switched off, so
# every ordinary run is unaffected — `composer coverage` turns it on for the
# one run that wants it.
RUN apk add --no-cache --virtual .pcov-build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo 'pcov.enabled=0' >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini \
    && apk del .pcov-build-deps

COPY --from=vendor /src/vendor /code/vendor

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

COPY ./composer.json /code/composer.json
COPY ./composer.lock /code/composer.lock
COPY ./phpunit.xml /code/phpunit.xml
COPY ./phpstan.neon /code/phpstan.neon
COPY ./pint.json /code/pint.json
COPY ./src /code/src
COPY ./tests /code/tests

CMD [ "tail", "-f", "/dev/null" ]
