#!/bin/sh
set -e

echo "Waiting for WordPress to be ready..."
until wp core is-installed --path=/var/www/html 2>/dev/null; do
  sleep 2
done

echo "Installing WordPress..."
wp core install \
  --path=/var/www/html \
  --url="http://localhost:8080" \
  --title="Test Site" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com \
  --skip-email

echo "Installing WooCommerce..."
wp plugin install woocommerce --activate --path=/var/www/html

echo "Activating Stripe Terminal plugin..."
wp plugin activate stripe-terminal-for-woocommerce --path=/var/www/html

echo "Configuring WooCommerce..."
wp option update woocommerce_store_address "123 Test St" --path=/var/www/html
wp option update woocommerce_store_city "San Francisco" --path=/var/www/html
wp option update woocommerce_default_country "US:CA" --path=/var/www/html
wp option update woocommerce_store_postcode "94105" --path=/var/www/html
wp option update woocommerce_currency "USD" --path=/var/www/html

echo "Configuring Stripe Terminal gateway..."
wp option update woocommerce_stripe_terminal_for_woocommerce_settings \
  '{"enabled":"yes","title":"Stripe Terminal","description":"Pay in person using Stripe Terminal.","test_mode":"yes","test_secret_key":"'"${STRIPE_TEST_SECRET_KEY:-sk_test_placeholder}"'"}' \
  --format=json --path=/var/www/html

echo "Creating test product..."
wp wc product create \
  --name="Test Product" \
  --type=simple \
  --regular_price=10.00 \
  --path=/var/www/html \
  --user=admin

echo "Setup complete!"
