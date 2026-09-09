<?php
/**
 * Register the POS webhook independently of the legacy endpoint and secrets.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Server;

use WCPOS\WooCommercePOS\StripeTerminal\Logger;
use WCPOS\WooCommercePOS\StripeTerminal\Settings;

/** Settings-save provisioning of the Pro webhook route. */
final class Pos_Webhook {
	/** Stripe's maximum page size covers this store's endpoint discovery in one request. */
	private const ENDPOINT_LIMIT = 100;

	/**
	 * Ensure the current account/mode has a verifiable POS endpoint.
	 *
	 * @param string $api_key Stripe key for the mode being saved.
	 * @param string $mode    Test or live.
	 */
	public static function ensure( string $api_key, string $mode ): void {
		try {
			\Stripe\Stripe::setApiKey( $api_key );
			$url      = Stripe_Server_Provider::webhook_url();
			$key      = 'test' === $mode ? 'test_pos_webhook_secret' : 'pos_webhook_secret';
			$settings = Settings::get_gateway_settings();
			$webhooks = \Stripe\WebhookEndpoint::all( array( 'limit' => self::ENDPOINT_LIMIT ) );
			foreach ( $webhooks->data as $webhook ) {
				if ( $webhook->url !== $url ) {
					continue;
				}
				if ( ! empty( $settings[ $key ] ) ) {
					Logger::log( 'POS webhook already configured (' . $mode . ').' );
					return;
				}
				// Stripe reveals a signing secret only at creation; replace this POS endpoint only.
				$webhook->delete();
				Logger::log( 'Recreating POS webhook with missing signing secret (' . $mode . ').' );
				break;
			}
			$webhook = \Stripe\WebhookEndpoint::create(
				array(
					'url'            => $url,
					'enabled_events' => array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'terminal.reader.action_succeeded', 'terminal.reader.action_failed' ),
				)
			);
			$settings[ $key ] = $webhook->secret;
			update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $settings );
			Logger::log( 'POS webhook created (' . $mode . ').' );
		} catch ( \Throwable $e ) {
			Logger::log( 'POS webhook setup failed: ' . $e->getMessage(), 'error' );
		}
	}
}
