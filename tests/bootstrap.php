<?php
/**
 * PHPUnit bootstrap file.
 *
 * Loads composer autoloader and sets up the test environment
 * with stubs for WordPress/WooCommerce dependencies.
 */

// Composer autoloader (loads plugin classes + test dependencies).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Plugin constants.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'STWC_VERSION', '0.0.0-test' );
define( 'STWC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'STWC_PLUGIN_URL', 'http://localhost/wp-content/plugins/stripe-terminal-for-woocommerce/' );

// Stub WP_Error since many methods return it and Brain\Monkey doesn't stub classes.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// Stub is_wp_error function.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Stub WC_Order_Item_Fee for tip-reconciliation tests.
if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
	class WC_Order_Item_Fee {
		public $name;
		public $amount;
		public $total;
		public $tax_status;

		public function set_name( $name ): void {
			$this->name = $name;
		}

		public function set_amount( $amount ): void {
			$this->amount = $amount;
		}

		public function set_total( $total ): void {
			$this->total = $total;
		}

		public function set_tax_status( $tax_status ): void {
			$this->tax_status = $tax_status;
		}
	}
}
