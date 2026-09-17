<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['acs_test_options'] = [ 'acs_offer_verification_secret' => str_repeat( 'a', 64 ) ];
function add_action(): void {}
function __( string $value ): string { return $value; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function get_option( string $key, mixed $default = '' ): mixed { return $GLOBALS['acs_test_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value ): bool { $GLOBALS['acs_test_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value ): bool {
	if ( array_key_exists( $key, $GLOBALS['acs_test_options'] ) ) return false;
	$GLOBALS['acs_test_options'][ $key ] = $value; return true;
}
function wp_schedule_single_event(): void {}

class WP_Error {
	public function __construct( public string $code ) {}
}
class WP_REST_Request {
	public function __construct( private array $headers, private string $body ) {}
	public function get_header( string $key ): string { return $this->headers[ $key ] ?? ''; }
	public function get_body(): string { return $this->body; }
}

require_once dirname( __DIR__ ) . '/includes/class-acs-product-offers.php';

$body      = '{"items":[{"product_id":1,"variation_id":null}]}';
$timestamp = (string) time();
$nonce     = str_repeat( 'B', 32 );
$canonical = "POST\n/wp-json/acs/v1/product-offers\n" . hash( 'sha256', $body ) . "\n{$timestamp}\n{$nonce}";
$signature = hash_hmac( 'sha256', $canonical, str_repeat( 'a', 64 ) );
$request   = new WP_REST_Request( [
	'x-ischat-timestamp' => $timestamp,
	'x-ischat-nonce'     => $nonce,
	'x-ischat-signature' => $signature,
], $body );

if ( true !== ACS_Product_Offers::authorize( $request ) ) {
	throw new RuntimeException( 'A valid HMAC request must be authorized.' );
}
if ( ! ACS_Product_Offers::authorize( $request ) instanceof WP_Error ) {
	throw new RuntimeException( 'A reused nonce must be rejected.' );
}

echo "Product offer signature test passed.\n";
