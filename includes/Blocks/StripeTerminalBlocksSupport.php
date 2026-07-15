<?php
/**
 * WooCommerce Blocks payment method integration for Stripe Terminal.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Stripe Terminal with the Cart and Checkout blocks.
 *
 * Content is informational only: Terminal collection still happens on the
 * classic order-pay page after process_payment redirects there.
 */
final class StripeTerminalBlocksSupport extends AbstractPaymentMethodType {
	/**
	 * Payment method name (must match Gateway ID).
	 *
	 * @var string
	 */
	protected $name = 'stripe_terminal_for_woocommerce';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Returns whether this payment method should be active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( 'yes' !== $this->get_setting( 'enabled', 'no' ) ) {
			return false;
		}

		// Built Blocks bundle uses the automatic JSX runtime (window.ReactJSXRuntime),
		// which WordPress only registers from 6.6+. On older installs, stay inactive so
		// Blocks checkout is not broken by a missing script dependency.
		return $this->supports_blocks_jsx_runtime();
	}

	/**
	 * Whether the current WordPress install provides react-jsx-runtime.
	 *
	 * @return bool
	 */
	private function supports_blocks_jsx_runtime(): bool {
		global $wp_version;

		if ( \is_string( $wp_version ) && version_compare( $wp_version, '6.6', '>=' ) ) {
			return true;
		}

		// Fallback for odd boots / filtered $wp_version: prefer the actual script registry.
		return \function_exists( 'wp_script_is' ) && wp_script_is( 'react-jsx-runtime', 'registered' );
	}

	/**
	 * Registers and returns the script handles for this payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! $this->supports_blocks_jsx_runtime() ) {
			return array();
		}

		$script_path       = 'assets/js/blocks/index.js';
		$script_asset_path = STWC_PLUGIN_DIR . 'assets/js/blocks/index.asset.php';
		$script_url        = STWC_PLUGIN_URL . $script_path;

		$script_asset = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => STWC_VERSION,
			);

		$dependencies = array_unique(
			array_merge(
				$script_asset['dependencies'],
				array(
					'wc-blocks-registry',
					'wc-settings',
				)
			)
		);

		wp_register_script(
			'stripe-terminal-blocks-integration',
			$script_url,
			$dependencies,
			$script_asset['version'],
			true
		);

		wp_set_script_translations(
			'stripe-terminal-blocks-integration',
			'stripe-terminal-for-woocommerce',
			STWC_PLUGIN_DIR . 'languages'
		);

		return array( 'stripe-terminal-blocks-integration' );
	}

	/**
	 * Data made available to the payment method script.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->get_supported_features(),
		);
	}
}
