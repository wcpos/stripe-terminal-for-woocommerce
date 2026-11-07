<?php
/**
 * Smoke test of a scoped build tree: the prefixed Stripe SDK loads, stays apart from any
 * other copy of stripe/stripe-php, and turns real API answers into its own classes.
 *
 * Usage: php php-scoper/smoke-test.php <build-root> [--foreign=<autoload.php>]
 *
 * With --foreign, that autoloader (another plugin's stripe/stripe-php) is loaded FIRST, the
 * order that breaks an unscoped plugin. No network, no dev dependencies, PHP 7.4 syntax:
 * CI also runs it on the plugin's PHP floor. See docs/dependency-scoping.md.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

$stwc_root    = null;
$stwc_foreign = null;
foreach ( array_slice( $argv, 1 ) as $stwc_arg ) {
	if ( 0 === strpos( $stwc_arg, '--foreign=' ) ) {
		$stwc_foreign = substr( $stwc_arg, strlen( '--foreign=' ) );
	} elseif ( null === $stwc_root ) {
		$stwc_root = rtrim( $stwc_arg, '/' );
	} else {
		$stwc_root = '';
	}
}
if ( ! $stwc_root || ! is_dir( $stwc_root ) || ( null !== $stwc_foreign && ! is_file( $stwc_foreign ) ) ) {
	fwrite( STDERR, "Usage: php smoke-test.php <build-root> [--foreign=<autoload.php>]\n" );
	exit( 2 );
}

if ( null !== $stwc_foreign ) {
	require $stwc_foreign;
}
require $stwc_root . '/vendor_prefixed/autoload.php';
require $stwc_root . '/vendor/autoload.php';

const STWC_P = 'WCPOS\\WooCommercePOS\\StripeTerminal\\Vendor\\Stripe\\';

$stwc_failed = false;

/**
 * Prints one result line.
 *
 * @param string $label  What was asserted.
 * @param bool   $passed Whether it held.
 * @param string $detail Shown on failure.
 */
function stwc_assert( $label, $passed, $detail = '' ) {
	global $stwc_failed;
	echo ( $passed ? 'ok   ' : 'FAIL ' ) . $label . ( $passed || '' === $detail ? '' : " ($detail)" ) . "\n";
	if ( ! $passed ) {
		$stwc_failed = true;
	}
}

/**
 * Runs $fn and returns what it threw, or null.
 *
 * @param callable $fn Code under test.
 * @return Throwable|null
 */
function stwc_thrown( callable $fn ) {
	try {
		$fn();
	} catch ( Throwable $e ) {
		return $e;
	}
	return null;
}

/**
 * Installs a fake HTTP client on the prefixed SDK that answers every request with one canned response.
 *
 * @param int    $code HTTP status.
 * @param string $body JSON body.
 */
function stwc_canned( $code, $body ) {
	$client = new class( $code, $body ) implements \WCPOS\WooCommercePOS\StripeTerminal\Vendor\Stripe\HttpClient\ClientInterface {
		/** @var int */
		private $code;
		/** @var string */
		private $body;

		/**
		 * @param int    $code HTTP status.
		 * @param string $body JSON body.
		 */
		public function __construct( $code, $body ) {
			$this->code = $code;
			$this->body = $body;
		}

		/** Returns the canned response. */
		public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ) {
			return array( $this->body, $this->code, array( 'request-id' => 'req_smoke' ) );
		}
	};
	call_user_func( array( STWC_P . 'ApiRequestor', 'setHttpClient' ), $client );
}

$stwc_prefixed_stripe = STWC_P . 'Stripe';
$stwc_prefixed_file   = ( new ReflectionClass( $stwc_prefixed_stripe ) )->getFileName();
stwc_assert(
	'prefixed SDK ' . constant( $stwc_prefixed_stripe . '::VERSION' ) . ' loads from vendor_prefixed/',
	realpath( $stwc_root . '/vendor_prefixed/stripe-php/lib/Stripe.php' ) === $stwc_prefixed_file,
	(string) $stwc_prefixed_file
);

// 1 and 2. Isolation from any other copy of the SDK.
if ( null === $stwc_foreign ) {
	stwc_assert( '1. no plain Stripe\\Stripe is loadable from the build', ! class_exists( 'Stripe\\Stripe' ) );
} else {
	$stwc_foreign_version = class_exists( 'Stripe\\Stripe' ) ? constant( 'Stripe\\Stripe::VERSION' ) : '(missing)';
	stwc_assert(
		"2. foreign Stripe\\Stripe $stwc_foreign_version differs from the prefixed SDK",
		constant( 'Stripe\\Stripe::VERSION' ) !== constant( $stwc_prefixed_stripe . '::VERSION' )
	);
	$stwc_prefixed_stripe::setApiKey( 'sk_test_scoped_only' );
	stwc_assert( '2. prefixed setApiKey() leaves the foreign key unset', null === \Stripe\Stripe::getApiKey(), (string) \Stripe\Stripe::getApiKey() );
}
$stwc_prefixed_stripe::setApiKey( 'sk_test_scoped_only' );
$stwc_prefixed_stripe::setMaxNetworkRetries( 0 );

// 3. Every class the SDK turns API objects into exists under the prefix.
$stwc_missing = array();
$stwc_maps    = array( constant( STWC_P . 'Util\\ObjectTypes::mapping' ) );
if ( defined( STWC_P . 'Util\\ObjectTypes::v2Mapping' ) ) {
	$stwc_maps[] = constant( STWC_P . 'Util\\ObjectTypes::v2Mapping' );
}
foreach ( $stwc_maps as $stwc_map ) {
	foreach ( $stwc_map as $stwc_class ) {
		if ( 0 !== strpos( $stwc_class, STWC_P ) || ! class_exists( $stwc_class ) ) {
			$stwc_missing[] = $stwc_class;
		}
	}
}
stwc_assert( '3. every Util\\ObjectTypes class exists under the prefix', ! $stwc_missing, implode( ', ', array_slice( $stwc_missing, 0, 5 ) ) );

// 4. The CA bundle every live curl call uses ships with the prefixed SDK.
$stwc_ca      = $stwc_prefixed_stripe::getCABundlePath();
$stwc_ca_root = realpath( $stwc_root . '/vendor_prefixed/stripe-php/data' );
stwc_assert(
	'4. getCABundlePath() is under vendor_prefixed/stripe-php/data/',
	$stwc_ca && $stwc_ca_root && is_file( $stwc_ca ) && 0 === strpos( $stwc_ca, $stwc_ca_root . DIRECTORY_SEPARATOR ),
	(string) $stwc_ca
);

// 5. A JSON answer becomes prefixed resource classes, nested objects included.
stwc_canned( 200, '{"id":"pi_x","object":"payment_intent","status":"succeeded","latest_charge":{"id":"ch_x","object":"charge","paid":true}}' );
$stwc_intent = null;
$stwc_error  = stwc_thrown(
	function () use ( &$stwc_intent ) {
		$stwc_intent = call_user_func( array( STWC_P . 'PaymentIntent', 'retrieve' ), 'pi_x' );
	}
);
stwc_assert(
	'5. PaymentIntent::retrieve() returns the prefixed PaymentIntent',
	is_a( $stwc_intent, STWC_P . 'PaymentIntent' ) && 'pi_x' === $stwc_intent->id,
	$stwc_error ? get_class( $stwc_error ) . ': ' . $stwc_error->getMessage() : ( is_object( $stwc_intent ) ? get_class( $stwc_intent ) : gettype( $stwc_intent ) )
);
stwc_assert(
	'5. its latest_charge is the prefixed Charge',
	$stwc_intent && is_a( $stwc_intent->latest_charge, STWC_P . 'Charge' ),
	$stwc_intent && is_object( $stwc_intent->latest_charge ) ? get_class( $stwc_intent->latest_charge ) : 'not an object'
);

// 6. Error answers become prefixed exceptions.
$stwc_errors = array(
	array( 401, '{"error":{"message":"Invalid API Key provided","type":"invalid_request_error"}}', 'Exception\\AuthenticationException' ),
	array( 402, '{"error":{"message":"Your card was declined.","type":"card_error","code":"card_declined","decline_code":"generic_decline"}}', 'Exception\\CardException' ),
);
foreach ( $stwc_errors as $stwc_case ) {
	stwc_canned( $stwc_case[0], $stwc_case[1] );
	$stwc_error = stwc_thrown(
		function () {
			call_user_func( array( STWC_P . 'PaymentIntent', 'retrieve' ), 'pi_x' );
		}
	);
	stwc_assert(
		"6. a {$stwc_case[0]} throws the prefixed {$stwc_case[2]}",
		is_a( $stwc_error, STWC_P . $stwc_case[2] ),
		$stwc_error ? get_class( $stwc_error ) . ': ' . $stwc_error->getMessage() : 'nothing thrown'
	);
}

// 7. A signed webhook becomes a prefixed Event; a wrong secret is refused with the prefixed exception.
$stwc_secret  = 'whsec_smoke_test';
$stwc_payload = '{"id":"evt_x","object":"event","type":"payment_intent.succeeded","data":{"object":{"id":"pi_x","object":"payment_intent"}}}';
$stwc_time    = time();
$stwc_header  = 't=' . $stwc_time . ',v1=' . hash_hmac( 'sha256', $stwc_time . '.' . $stwc_payload, $stwc_secret );
$stwc_event   = null;
$stwc_error   = stwc_thrown(
	function () use ( &$stwc_event, $stwc_payload, $stwc_header, $stwc_secret ) {
		$stwc_event = call_user_func( array( STWC_P . 'Webhook', 'constructEvent' ), $stwc_payload, $stwc_header, $stwc_secret );
	}
);
stwc_assert(
	'7. Webhook::constructEvent() returns the prefixed Event',
	is_a( $stwc_event, STWC_P . 'Event' ) && is_a( $stwc_event->data->object, STWC_P . 'PaymentIntent' ),
	$stwc_error ? get_class( $stwc_error ) . ': ' . $stwc_error->getMessage() : ( is_object( $stwc_event ) ? get_class( $stwc_event ) : gettype( $stwc_event ) )
);
$stwc_error = stwc_thrown(
	function () use ( $stwc_payload, $stwc_header ) {
		call_user_func( array( STWC_P . 'Webhook', 'constructEvent' ), $stwc_payload, $stwc_header, 'whsec_wrong' );
	}
);
stwc_assert(
	'7. a wrong secret throws the prefixed Exception\\SignatureVerificationException',
	is_a( $stwc_error, STWC_P . 'Exception\\SignatureVerificationException' ),
	$stwc_error ? get_class( $stwc_error ) : 'nothing thrown'
);

echo $stwc_failed ? "smoke test FAILED\n" : "smoke test ok\n";
exit( $stwc_failed ? 1 : 0 );
