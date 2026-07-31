ARG PHP_VERSION=8.5

FROM composer:2.7 AS vendor

WORKDIR /src/

COPY composer.lock composer.json /src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

FROM appwrite/utopia-base:php-${PHP_VERSION}-2.1.0 AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /code

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
