<?php
/**
 * Mirror gateway reader settings into WooCommerce POS on save or upgrade.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Settings;

/** Write-only bridge to Free's payment-gateway settings. */
final class Pos_Reader_Settings {
	/**
	 * Mirror reader settings without changing other gateway settings.
	 *
	 * @param array $settings  Saved gateway settings.
	 * @param bool  $overwrite Whether to replace existing POS values.
	 */
	public static function mirror( array $settings, bool $overwrite = true ): void {
		if ( ! Registration::pro_supported() ) {
			return;
		}
		$default = (string) ( $settings['default_reader'] ?? '' );
		$values  = array(
			'default_reader'  => $default,
			'allowed_readers' => array_values(
				array_filter(
					(array) ( $settings['allowed_readers'] ?? array() ),
					static function ( $reader ): bool {
						return is_string( $reader ) && '' !== $reader;
					}
				)
			),
			'lock_to_default' => '' !== $default && 'yes' === ( $settings['lock_to_default'] ?? 'no' ),
		);
		$pos     = get_option( 'woocommerce_pos_settings_payment_gateways', array() );
		$current = $pos['gateways'][ Settings::GATEWAY_ID ] ?? array();
		$pos['gateways'][ Settings::GATEWAY_ID ] = $overwrite ? array_replace( $current, $values ) : $current + $values;
		update_option( 'woocommerce_pos_settings_payment_gateways', $pos );
	}

	/** Seed missing POS settings once on upgrade, only with Pro present. */
	public static function migrate_once(): void {
		if ( ! Registration::pro_supported() || get_option( 'stwc_pos_reader_settings_version' ) ) {
			return;
		}
		self::mirror( Settings::get_gateway_settings(), false );
		update_option( 'stwc_pos_reader_settings_version', STWC_VERSION, false );
	}
}
