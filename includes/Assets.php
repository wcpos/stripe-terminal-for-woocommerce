<?php
/**
 * Built asset URL resolution.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal;

/**
 * Resolves built asset paths through the webpack manifest.
 *
 * The payment-frontend build writes content-hashed filenames
 * (js/payment.<hash>.js) and records them in assets/manifest.json. The hash
 * changes the URL itself whenever the file changes, so a cache that ignores
 * the `?ver=` query string (optimiser plugins, CDNs, the WCPOS desktop
 * webview) cannot keep serving a previous release's script against the
 * current PHP.
 */
class Assets {
	/**
	 * URL of a built asset.
	 *
	 * @param string $path Path relative to assets/, as the source names it (e.g. js/payment.js).
	 */
	public static function url( string $path ): string {
		return STWC_PLUGIN_URL . 'assets/' . self::resolve( $path );
	}

	/**
	 * Path relative to assets/ of the built file for $path.
	 *
	 * Falls back to $path itself when the manifest is missing or does not
	 * list it. The build always emits hashed names, so that fallback only
	 * happens on a broken or absent build and the enqueued file will 404;
	 * AssetsTest keeps the committed manifest and files in step.
	 *
	 * @param string      $path          Path relative to assets/, as the source names it.
	 * @param string|null $manifest_file Manifest to read; defaults to assets/manifest.json.
	 */
	public static function resolve( string $path, ?string $manifest_file = null ): string {
		$manifest_file = $manifest_file ?? STWC_PLUGIN_DIR . 'assets/manifest.json';

		if ( ! is_readable( $manifest_file ) ) {
			return $path;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, not a remote URL.
		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );

		if ( ! \is_array( $manifest ) || ! isset( $manifest[ $path ] ) || ! \is_string( $manifest[ $path ] ) ) {
			return $path;
		}

		return $manifest[ $path ];
	}
}
