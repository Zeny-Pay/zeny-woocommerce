#!/usr/bin/env bash
# Installs WordPress + WooCommerce, activates the plugin, starts the stub Zeny
# API and runs the integration tests. Runs inside the wordpress container.
set -euo pipefail
cd /var/www/html
wp() { php -d memory_limit=512M /usr/local/bin/wp --allow-root --quiet "$@"; }

wp core download --version="$WP_VERSION" --force
wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db --skip-check --force
wp core install --url=http://localhost:8080 --title="Zeny Test Shop" --admin_user=admin \
	--admin_password=admin --admin_email=admin@example.com --skip-email
if [ -n "$WC_VERSION" ]; then
	wp plugin install woocommerce --version="$WC_VERSION" --activate
else
	wp plugin install woocommerce --activate
fi
# HPOS can only be switched on before the first order exists.
if [ "${ORDER_STORE:-posts}" = "hpos" ]; then
	wp wc hpos enable
fi
ln -sfn /plugin wp-content/plugins/zeny-woocommerce
# No mail server in the container; order emails are not under test.
mkdir -p wp-content/mu-plugins
echo "<?php add_filter( 'pre_wp_mail', '__return_false' );" > wp-content/mu-plugins/zeny-test-no-mail.php
wp plugin activate zeny-woocommerce
wp option update woocommerce_currency IDR
echo "WordPress $(wp core version), WooCommerce $(wp plugin get woocommerce --field=version), order store: ${ORDER_STORE:-posts}"

# WP-CLI does not load Composer, so this exercises the plugin's own autoloader.
wp eval 'new Zeny\WooCommerce\WooCommerce\Gateway(); echo "plugin autoloader ok\n";'

mkdir -p /tmp/zeny-stub
php -S 127.0.0.1:8099 /plugin/tests/Integration/stub-server.php >/tmp/zeny-stub/server.log 2>&1 &
stub=$!
# The shop itself, for tests that go through real HTTP (webhook, order status).
php -S 127.0.0.1:8080 -t /var/www/html >/tmp/zeny-stub/wordpress.log 2>&1 &
shop=$!
trap 'kill $stub $shop 2>/dev/null || true' EXIT

php /plugin/vendor/bin/phpunit -c /plugin/tests/Integration/phpunit.xml "$@" || { status=$?; tail -20 /tmp/zeny-stub/wordpress.log; exit $status; }
