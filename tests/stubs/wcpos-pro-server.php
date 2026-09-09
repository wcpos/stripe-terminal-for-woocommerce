<?php
// phpcs:disable Universal.Namespaces, Generic.Files.OneObjectStructurePerFile -- Requested single bootstrap stub bundles Pro and WordPress contracts.
/** Minimal Pro contracts; production Pro classes win when present. */
namespace WCPOS\WooCommercePOSPro\Payments\Server {
	if ( ! interface_exists( Provider_Adapter_Interface::class ) ) {
		interface Provider_Adapter_Interface {
			public function provider(): string;
			public function describe( \WC_Payment_Gateway $gateway ): array;
			public function list_readers();
			public function create_reader_action( array $row, string $reader_id );
			public function fetch( string $ref );
			public function cancel( string $ref );
			public function capture( string $ref );
			public function refund( array $row, int $refund_id, string $amount );
			public function verify_webhook( \WP_REST_Request $request );
		}
	}
	if ( ! class_exists( Abstract_Provider_Adapter::class ) ) {
		abstract class Abstract_Provider_Adapter implements Provider_Adapter_Interface {}
	}
	if ( ! class_exists( Event_Log::class ) ) {
		class Event_Log {
			public static function append( array $row, string $level, string $message ): array {
				return $row;
			}
		}
	}
	if ( ! class_exists( Reader_Curation::class ) ) {
		class Reader_Curation {
			public static $forgotten = array();
			public static function forget( string $id ): void {
				self::$forgotten[] = $id;
			}
		}
	}
}
namespace {
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private $body    = '';
			private $headers = array();
			private $params  = array();
			public function set_body( $body ) {
				$this->body = $body;
			}
			public function get_body() {
				return $this->body;
			}
			public function set_header( $key, $value ) {
				$this->headers[ strtolower( $key ) ] = $value;
			}
			public function get_header( $key ) {
				return $this->headers[ strtolower( $key ) ] ?? null;
			}
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}
		}
	}
}
