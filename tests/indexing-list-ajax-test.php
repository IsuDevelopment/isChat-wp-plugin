<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'ACS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ACS_PLUGIN_URL', 'https://example.com/wp-content/plugins/ai-ischat/' );
define( 'ACS_VERSION', 'test' );

class WP_Post {
	public string $post_status = 'publish';
	public function __construct( public int $ID, public string $post_type = 'product' ) {}
}

final class Json_Response extends RuntimeException {
	public function __construct( public bool $success, public array $payload, public int $status = 200 ) {
		parent::__construct( 'JSON response' );
	}
}

class ACS_Content_Extractor {
	public static bool $enabled = false;
	public static bool $indexed = false;
	public static string $last_indexed_at = '2026-09-18 09:00:00';
	public static function is_ai_index_enabled( int $post_id ): bool { return self::$enabled; }
	public static function is_currently_indexed( int $post_id ): bool { return self::$indexed; }
	public static function get_last_indexed_at( int $post_id ): string { return self::$last_indexed_at; }
}

class ACS_Sync_Manager {
	public static array $actions = [];
	public static function is_configured(): bool { return true; }
	public static function apply_indexing_preference( WP_Post $post, bool $enabled ): void {
		ACS_Content_Extractor::$enabled = $enabled;
		ACS_Content_Extractor::$indexed = false;
		self::$actions[] = [ 'toggle', $post->ID, $enabled ];
	}
	public static function queue_post_for_reindex( WP_Post $post ): bool {
		ACS_Content_Extractor::$indexed = false;
		self::$actions[] = [ 'force_sync', $post->ID ];
		return ACS_Content_Extractor::$enabled && 'publish' === $post->post_status;
	}
	public static function index_post_now( WP_Post $post ): array {
		if ( ! ACS_Content_Extractor::$enabled || 'publish' !== $post->post_status ) {
			return [ 'success' => false, 'message' => 'Not eligible.' ];
		}
		ACS_Content_Extractor::$indexed = true;
		ACS_Content_Extractor::$last_indexed_at = '2026-09-20 20:45:00';
		self::$actions[] = [ 'force_sync_now', $post->ID ];
		return [ 'success' => true, 'message' => 'Post indexed successfully.' ];
	}
}

$GLOBALS['acs_test_post'] = new WP_Post( 42 );
$GLOBALS['acs_can_edit'] = true;

function add_action(): void {}
function admin_url( string $path = '' ): string { return 'https://example.com/wp-admin/' . $path; }
function check_ajax_referer( string $action, string $field ): void {
	if ( 'acs_indexing_list_nonce' !== $action || 'nonce' !== $field ) {
		throw new RuntimeException( 'Unexpected nonce contract.' );
	}
}
function get_post( int $post_id ): ?WP_Post { return 42 === $post_id ? $GLOBALS['acs_test_post'] : null; }
function current_user_can( string $capability, int $post_id = 0 ): bool { return (bool) $GLOBALS['acs_can_edit']; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_send_json_success( array $payload, int $status = 200 ): never { throw new Json_Response( true, $payload, $status ); }
function wp_send_json_error( array $payload, int $status = 200 ): never { throw new Json_Response( false, $payload, $status ); }
function __( string $text ): string { return $text; }
function get_option( string $key ): string { return 'date_format' === $key ? 'Y-m-d' : 'H:i'; }
function mysql2date( string $format, string $date ): string { return '2026-09-18 09:00'; }

require dirname( __DIR__ ) . '/admin/class-acs-admin.php';

function perform_list_action( array $request ): Json_Response {
	$_POST = $request + [ 'nonce' => 'valid' ];
	try {
		ACS_Admin::ajax_indexing_list_action();
	} catch ( Json_Response $response ) {
		return $response;
	}
	throw new RuntimeException( 'AJAX handler did not terminate with JSON.' );
}

$toggle = perform_list_action( [ 'post_id' => '42', 'operation' => 'toggle', 'enabled' => '1' ] );
if ( ! $toggle->success || true !== $toggle->payload['state']['enabled'] || 'pending' !== $toggle->payload['state']['status'] ) {
	throw new RuntimeException( 'Toggle response did not expose the expected pending state.' );
}
if ( 'Last sync: 2026-09-18 09:00' !== $toggle->payload['state']['last_indexed_label'] ) {
	throw new RuntimeException( 'Last successful sync label is incorrect.' );
}

$force = perform_list_action( [ 'post_id' => '42', 'operation' => 'force_sync' ] );
if ( ! $force->success || 'indexed' !== $force->payload['state']['status'] ) {
	throw new RuntimeException( 'Force sync was not completed through AJAX.' );
}

$GLOBALS['acs_can_edit'] = false;
$denied = perform_list_action( [ 'post_id' => '42', 'operation' => 'toggle', 'enabled' => '0' ] );
if ( $denied->success || 403 !== $denied->status ) {
	throw new RuntimeException( 'Per-post capability check is missing.' );
}

$expected = [ [ 'toggle', 42, true ], [ 'force_sync_now', 42 ] ];
if ( $expected !== ACS_Sync_Manager::$actions ) {
	throw new RuntimeException( 'AJAX actions did not use the expected queue lifecycle.' );
}

echo "Indexing list AJAX test passed.\n";
