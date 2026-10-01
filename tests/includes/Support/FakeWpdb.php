<?php
/**
 * The options table, enough for an INSERT IGNORE claim with
 * compare-and-delete release (the PaymentLock queries).
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests\Support;

class FakeWpdb {
	public $options = 'wp_options';
	public $rows    = array();

	public function prepare( $query, ...$args ) {
		return (string) json_encode( array( $query, $args ) );
	}

	public function query( $prepared ) {
		list( $sql, $args ) = json_decode( $prepared, true );
		$sql                = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( "INSERT IGNORE INTO {$this->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')" === $sql ) {
			if ( array_key_exists( $args[0], $this->rows ) ) {
				return 0;
			}
			$this->rows[ $args[0] ] = $args[1];

			return 1;
		}
		if ( "DELETE FROM {$this->options} WHERE option_name = %s AND option_value = %s" === $sql ) {
			if ( isset( $this->rows[ $args[0] ] ) && $this->rows[ $args[0] ] === $args[1] ) {
				unset( $this->rows[ $args[0] ] );

				return 1;
			}

			return 0;
		}
		throw new \RuntimeException( 'fake wpdb: unsupported query: ' . $sql );
	}

	public function get_var( $prepared ) {
		list( $sql, $args ) = json_decode( $prepared, true );
		$sql                = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( "SELECT option_value FROM {$this->options} WHERE option_name = %s" === $sql ) {
			return $this->rows[ $args[0] ] ?? null;
		}
		throw new \RuntimeException( 'fake wpdb: unsupported query: ' . $sql );
	}
}
