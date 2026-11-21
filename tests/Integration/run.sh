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
ln -sfn /plugin wp-content/plugins/zeny-woocommerce
wp plugin activate zeny-woocommerce
wp option update woocommerce_currency IDR
echo "WordPress $(wp core version), WooCommerce $(wp plugin get woocommerce --field=version)"

mkdir -p /tmp/zeny-stub
php -S 127.0.0.1:8099 /plugin/tests/Integration/stub-server.php >/tmp/zeny-stub/server.log 2>&1 &
trap 'kill $! 2>/dev/null || true' EXIT

php /plugin/vendor/bin/phpunit -c /plugin/tests/Integration/phpunit.xml "$@"
