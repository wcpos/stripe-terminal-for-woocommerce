# Dependency scoping: the Stripe PHP SDK

## What ships

The release zip carries its own copy of `stripe/stripe-php` under the namespace
`WCPOS\WooCommercePOS\StripeTerminal\Vendor\Stripe\...`:

```
vendor_prefixed/
  autoload.php            # spl_autoload_register for the prefixed namespace
  stripe-php/lib/...      # the SDK, every Stripe class prefixed
  stripe-php/data/ca-certificates.crt
vendor/                   # Composer's autoloader, with no Stripe entry
```

`stripe-terminal-for-woocommerce.php` requires `vendor_prefixed/autoload.php` when it exists, just
before `vendor/autoload.php`. The plugin's own classes in the zip call `\WCPOS\...\Vendor\Stripe\...`.

## Why

WordPress loads every plugin into one PHP process. If another plugin bundles a different
`stripe/stripe-php` and its autoloader runs first, an unscoped plugin gets that plugin's
`\Stripe\` classes: a fatal on a missing method, or charges sent through an SDK version this
plugin was never tested with. Under its own prefix the plugin's SDK cannot meet anyone else's.

The CA bundle ships too: `lib/Stripe.php` finds it at `__DIR__ . '/../data/ca-certificates.crt'`
and every live curl call to Stripe uses it, so `lib/` and `data/` keep their relative layout.

## Development is unchanged

Development is a plain `composer install`. The source in `includes/` and the tests keep writing
`\Stripe\...`, and the tests mock the SDK by that name. Nothing generated is committed:
`vendor_prefixed/` is gitignored, and the rewrite to the prefixed names happens only on a build tree.

## The release build

`scripts/scope-release.sh` (also `composer scope-release`) runs from the root of a **build tree**
that already holds `vendor/`. Never run it in your dev checkout: it rewrites `includes/` and removes
the plain SDK. It needs PHP 8.2+ (php-scoper's floor); its output runs on PHP 7.4.

1. Installs php-scoper (`php-scoper/composer.json`, an exact version) into `php-scoper/vendor/`.
2. Prefixes `vendor/stripe/stripe-php` (`lib/` and `data/`) into `vendor_prefixed/stripe-php/`
   with `php-scoper/scoper.inc.php`.
3. Copies `php-scoper/vendor-prefixed-autoload.php` to `vendor_prefixed/autoload.php`.
4. Rewrites `\Stripe\` references in `includes/` and the main plugin file
   (`php-scoper/scope-source.php rewrite`).
5. Removes the plain SDK: `composer remove stripe/stripe-php --update-no-dev` (with `--keep-dev`:
   `rm -rf vendor/stripe` and `composer dump-autoload`, keeping the dev packages).
6. Runs `scope-source.php check --skip-tests .` (`tests/` is not rewritten in a release tree; the
   zip leaves it out).

To build a release tree locally:

```sh
B=$(mktemp -d)
rsync -a --exclude='.git*' --exclude=.claude ./ "$B/"
cd "$B"
composer install --no-dev --no-interaction --prefer-dist
bash scripts/scope-release.sh
php php-scoper/smoke-test.php "$B"
```

`.github/workflows/release.yml` does the same before it creates the GitHub release, then reruns
the smoke test and `check` on the staged zip contents (without `php-scoper/` and `tests/`) before
it zips, so nothing is published from a tree that fails either.

## What `check` refuses

`php php-scoper/scope-source.php check [--skip-tests] <root>` exits 1, listing `file:line:name`, on:

- any `\Stripe\...` name or `'Stripe\...'` string literal (including `overload:`/`alias:` mock
  names) left in a `.php` file outside `vendor/`, `vendor_prefixed/` and `php-scoper/` (and
  `tests/` with `--skip-tests`), and any group use `use Stripe\{...}` the rewrite cannot handle;
- `vendor/stripe` still on disk;
- a `Stripe\\` key in any `vendor/composer/autoload_*.php`;
- a missing `vendor_prefixed/autoload.php` or `vendor_prefixed/stripe-php/data/ca-certificates.crt`.

It fails on any tree nobody has rewritten, including a dev checkout; that is what it is for.
Comments and docblocks are not checked or rewritten.

## The smoke test

`php php-scoper/smoke-test.php <build-root> [--foreign=<autoload.php>]` loads the build the way
the plugin does (with `--foreign`, another copy of `stripe/stripe-php` is loaded first) and proves,
with a fake HTTP client and no network: no plain `Stripe\Stripe` is loadable from the build (or,
with `--foreign`, the two SDKs and their API keys stay apart); every class in the SDK's
`Util\ObjectTypes` map exists under the prefix; the CA bundle path is under `vendor_prefixed/`; a
canned `PaymentIntent` answer, its nested `latest_charge`, 401 and 402 errors, and a signed webhook
all come back as prefixed classes.

## php-scoper exceptions

`scoper.inc.php` exposes and excludes no Stripe symbol. It excludes the `PHPUnit` namespace only,
because php-scoper otherwise prefixes the string in the SDK's own test-runner guard,
`class_exists('PHPUnit\Framework\TestCase')` in `Util/AgentPluginHint.php`.

## When the SDK is bumped

Nothing beyond the normal bump of `stripe/stripe-php` in `composer.json`. The scoped build, `check`
and the smoke test run on every release, and CI proves the new version before it ships.
