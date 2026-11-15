#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

set -ex

# Resolve "latest" to a concrete release so core and the test suite always match
# (trunk's test suite is not guaranteed to work against the latest release).
if [ "$WP_VERSION" = "latest" ]; then
	RESOLVED=$(curl -sf https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[0-9.]*"' | head -1 | cut -d'"' -f4)
	if [ -n "$RESOLVED" ]; then
		WP_VERSION="$RESOLVED"
	fi
fi

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" = "latest" ] || [ "$WP_VERSION" = "trunk" ]; then
		ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
	else
		ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	fi

	curl -sL "$ARCHIVE_URL" | tar --strip-components=1 -zxmf - -C "$WP_CORE_DIR"
}

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		if [ "$WP_VERSION" = "latest" ] || [ "$WP_VERSION" = "trunk" ]; then
			DEV_REF="heads/trunk"
		elif [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
			# wordpress-develop tags major releases as X.Y.0 (core archives use X.Y).
			DEV_REF="tags/${WP_VERSION}.0"
		else
			DEV_REF="tags/${WP_VERSION}"
		fi

		# No silent fallback to trunk: a mismatched test suite breaks in confusing ways.
		TMP_DEV_DIR=$(mktemp -d)
		curl -sLf "https://github.com/WordPress/wordpress-develop/archive/refs/${DEV_REF}.tar.gz" | tar --strip-components=1 -zxmf - -C "$TMP_DEV_DIR"

		cp -R "$TMP_DEV_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$TMP_DEV_DIR/tests/phpunit/data" "$WP_TESTS_DIR/data"
		cp "$TMP_DEV_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		rm -rf "$TMP_DEV_DIR"
	fi

	sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s:__DIR__ . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return 0
	fi

	MYSQL_CLI="mysql"
	if command -v mariadb >/dev/null 2>&1; then
		MYSQL_CLI="mariadb"
	fi

	HOST_PART=$(echo "$DB_HOST" | cut -d: -f1)
	PORT_PART=$(echo "$DB_HOST" | cut -s -d: -f2)

	EXTRA_ARGS="--protocol=tcp -h${HOST_PART}"
	if [ -n "$PORT_PART" ]; then
		EXTRA_ARGS="${EXTRA_ARGS} -P${PORT_PART}"
	fi

	$MYSQL_CLI -u"$DB_USER" -p"$DB_PASS" $EXTRA_ARGS -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
}

install_wp
install_test_suite
install_db
