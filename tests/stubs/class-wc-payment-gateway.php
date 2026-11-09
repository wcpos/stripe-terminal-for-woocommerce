<?php
/**
 * Guarded WC_Payment_Gateway stub for unit tests that touch the Gateway without WooCommerce loaded.
 * The same members as CompletionRaceTest's inline stub, a subset of GatewayTest's (which adds
 * is_available, get_field_key and process_admin_options); the class_exists guards keep them
 * compatible whichever file loads first.
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
			return 'enable_moto' === $key ? ( $this->options['enable_moto'] ?? 'no' ) : ( $this->options[ $key ] ?? null );
		}

		public function init_settings() {}

		public function supports( $feature ) {
			return in_array( $feature, $this->supports, true );
		}
	}
}
