<?php
/**
 * Guarded WC_Payment_Gateway stub for unit tests that touch the Gateway without WooCommerce loaded.
 * Mirrors the stub GatewayTest declares inline; the class_exists guard keeps the two compatible
 * whichever file loads first.
 */

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id;
		public $method_title;
		public $method_description;
		public $title;
		public $description;
		public $supports = array( 'products' );
		public $form_fields = array();
		public $options = array();

		public function get_option( $key ) {
			return 'enable_moto' === $key ? 'no' : ( $this->options[ $key ] ?? null );
		}

		public function init_settings() {}

		public function supports( $feature ) {
			return in_array( $feature, $this->supports, true );
		}
	}
}
