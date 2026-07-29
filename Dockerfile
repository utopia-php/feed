# One image for every PHP version the library is tested against. Adding a
# version is a single entry in the `php-versions` matrix in
# .github/workflows/tests.yml — there is nothing to add here.
#
#   PHP_VERSION=8.6 docker compose build
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

# Composer itself, for the `test`, `check` and `lint` scripts. The base image
# ships PHP but not composer.
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

COPY ./composer.json /code/composer.json
COPY ./composer.lock /code/composer.lock
COPY ./phpunit.xml /code/phpunit.xml
COPY ./phpstan.neon /code/phpstan.neon
COPY ./pint.json /code/pint.json
COPY ./src /code/src
COPY ./tests /code/tests

CMD [ "tail", "-f", "/dev/null" ]
