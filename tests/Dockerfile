# Test runner image: PHP + mysqli + Composer dev dependencies + WordPress test suite.
# Built and run via `make test` (see docker-compose.yml, profile "test").
# No apt-get: old PHP images sit on archived Debian releases whose mirrors break.
ARG PHP_VERSION=8.3

# Resolve dev dependencies for the target PHP version in the Composer image
# (it ships git/unzip), outside the bind-mounted plugin directory so the
# container never writes vendor/ or composer.lock into the working tree.
FROM composer:2 AS deps
ARG PHP_VERSION
WORKDIR /opt/composer
COPY composer.json ./
RUN composer config platform.php "${PHP_VERSION}" \
	&& composer install --no-interaction --no-progress --prefer-dist

FROM php:${PHP_VERSION}-cli
ARG WP_VERSION=latest

RUN docker-php-ext-install mysqli

COPY --from=deps /opt/composer /opt/composer

ENV WP_TESTS_DIR=/opt/wordpress-tests-lib \
	WP_CORE_DIR=/opt/wordpress \
	WP_TESTS_PHPUNIT_POLYFILLS_PATH=/opt/composer/vendor/yoast/phpunit-polyfills

COPY bin/install-wp-tests.sh /usr/local/bin/install-wp-tests.sh
RUN bash /usr/local/bin/install-wp-tests.sh wordpress_test root root testdb:3306 "${WP_VERSION}" true

WORKDIR /app
ENTRYPOINT ["/opt/composer/vendor/bin/phpunit"]
