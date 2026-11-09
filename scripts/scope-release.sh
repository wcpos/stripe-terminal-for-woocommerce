#!/usr/bin/env bash
# Prefix the Stripe PHP SDK for the release zip. See docs/dependency-scoping.md.
#
# Run from the root of a build tree (never your dev checkout: it rewrites includes/ and
# removes vendor/stripe) that already holds vendor/:
#
#   bash scripts/scope-release.sh             # release: plain SDK removed from vendor/ and its autoload
#   bash scripts/scope-release.sh --keep-dev  # scoped test run: dev packages stay, vendor/stripe goes
#
# Needs PHP 8.2+ and Composer (php-scoper's floor); the output runs on PHP 7.4.
set -euo pipefail

KEEP_DEV=false
case "${1:-}" in
	'') ;;
	--keep-dev) KEEP_DEV=true ;;
	*) echo "Usage: bash scripts/scope-release.sh [--keep-dev]" >&2; exit 2 ;;
esac

if [ ! -f stripe-terminal-for-woocommerce.php ] || [ ! -d vendor/stripe/stripe-php ]; then
	echo "scope-release: run from a build tree root whose vendor/ holds stripe/stripe-php" >&2
	exit 1
fi

echo "== 1. Install php-scoper"
composer install --working-dir=php-scoper --no-interaction --prefer-dist

echo "== 2. Prefix stripe/stripe-php $(cat vendor/stripe/stripe-php/VERSION)"
rm -rf vendor_prefixed
php php-scoper/vendor/bin/php-scoper add-prefix \
	--config=php-scoper/scoper.inc.php \
	--output-dir=vendor_prefixed/stripe-php \
	--force --no-interaction

echo "== 3. Install the prefixed autoloader"
cp php-scoper/vendor-prefixed-autoload.php vendor_prefixed/autoload.php

echo "== 4. Rewrite \\Stripe\\ references in the plugin source"
php php-scoper/scope-source.php rewrite includes stripe-terminal-for-woocommerce.php

echo "== 5. Drop the plain SDK"
if [ "$KEEP_DEV" = true ]; then
	rm -rf vendor/stripe
	composer dump-autoload --no-interaction
else
	composer remove stripe/stripe-php --update-no-dev --no-interaction
fi

echo "== 6. Check"
# tests/ is not rewritten here (the release zip leaves it out; the scoped test run rewrites it next).
php php-scoper/scope-source.php check --skip-tests .
