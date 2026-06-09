<?php
/**
 * Admin settings page for IsChat.
 *
 * Registers the Settings → IsChat page, all options, AJAX handlers,
 * and the frontend widget injection.
 *
 * @package AI_Chatbot_SaaS
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Admin {

	/**
	 * Bootstrap all admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_acs_manual_index_post', [ __CLASS__, 'handle_manual_index_post' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_manual_index_notice' ] );
		add_action( 'init', [ __CLASS__, 'register_post_list_hooks' ] );

		// AJAX handlers (logged-in users only — no nopriv variant needed).
		add_action( 'wp_ajax_acs_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_acs_sync_all',        [ __CLASS__, 'ajax_sync_all' ] );
		add_action( 'wp_ajax_acs_save_manual_kb',  [ __CLASS__, 'ajax_save_manual_kb' ] );

		// Frontend widget injection.
		add_action( 'wp_footer', [ __CLASS__, 'inject_widget' ] );
	}

	// -------------------------------------------------------------------------
	// Admin page registration
	// -------------------------------------------------------------------------

	/**
	 * Register the options sub-page under Settings.
	 */
	public static function add_admin_page(): void {
		add_options_page(
			__( 'IsChat', 'ai-ischat' ),
			__( 'IsChat', 'ai-ischat' ),
			'manage_options',
			'ai-ischat',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Render the settings page view.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once ACS_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	// -------------------------------------------------------------------------
	// Settings registration (options API)
	// -------------------------------------------------------------------------

	/**
	 * Register all plugin options with sanitise callbacks.
	 */
	public static function register_settings(): void {

		// --- Connection group -------------------------------------------------

		register_setting( 'acs_connection_group', 'acs_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		register_setting( 'acs_connection_group', 'acs_site_id', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		// --- Content group ----------------------------------------------------

		register_setting( 'acs_content_group', 'acs_post_types', [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize_post_types' ],
			'default'           => [ 'post', 'page' ],
		] );

		register_setting( 'acs_content_group', 'acs_widget_enabled', [
			'type'              => 'string',
			// Checkbox: submitted as "1" when checked, absent when unchecked.
			'sanitize_callback' => function ( $value ) {
				return ! empty( $value ) ? '1' : '0';
			},
			'default'           => '0',
		] );

		register_setting( 'acs_content_group', 'acs_widget_public_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		// --- Knowledge Base group ---------------------------------------------

		register_setting( 'acs_kb_group', 'acs_manual_kb', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		] );
	}

	/**
	 * Sanitise the post-types array: only allow known public post types.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]
	 */
	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$public_types = array_keys( get_post_types( [ 'public' => true ] ) );

		return array_values(
			array_intersect(
				array_map( 'sanitize_key', $value ),
				$public_types
			)
		);
	}

	/**
	 * Register custom columns on the posts list screens.
	 */
	public static function register_post_list_hooks(): void {
		$post_types = array_keys( get_post_types( [ 'public' => true ], 'objects' ) );

		foreach ( $post_types as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ __CLASS__, 'add_indexed_posts_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ __CLASS__, 'render_indexed_posts_column' ], 10, 2 );
		}
	}

	/**
	 * Add "Chatbot Indexed" column to the posts list table.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function add_indexed_posts_column( array $columns ): array {
		$columns['acs_chatbot_indexed'] = __( 'Chatbot Indexed', 'ai-ischat' );
		return $columns;
	}

	/**
	 * Render "Chatbot Indexed" column content.
	 */
	public static function render_indexed_posts_column( string $column, int $post_id ): void {
		if ( 'acs_chatbot_indexed' !== $column ) {
			return;
		}

		$is_indexed = ACS_Content_Extractor::is_currently_indexed( $post_id );
		echo $is_indexed
			? '<span aria-label="' . esc_attr__( 'Indexed', 'ai-ischat' ) . '">✓</span>'
			: '<span aria-label="' . esc_attr__( 'Not indexed', 'ai-ischat' ) . '">✕</span>';
	}

	// -------------------------------------------------------------------------
	// AJAX: Test connection
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler — test the API connection.
	 *
	 * Success response shape:
	 *   { success: true, data: { message, data: { plan, usage, limits } } }
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'acs_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-ischat' ) ] );
		}

		if ( ! ACS_Sync_Manager::is_configured() ) {
			wp_send_json_error( [
				'message' => __( 'Plugin is not fully configured. Please fill in the API URL, API Key, and Site ID.', 'ai-ischat' ),
			] );
		}

		$client = ACS_Sync_Manager::make_client();
		$result = $client->verify();

		if ( ! $result['success'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}

		wp_send_json_success( [
			'message' => __( 'Connection successful!', 'ai-ischat' ),
			'data'    => $result['data'] ?? [],
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Sync all content
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler — enqueue all published content and immediately process one batch.
	 *
	 * Success response shape:
	 *   { success: true, data: { queued, processed, failed, message } }
	 */
	public static function ajax_sync_all(): void {
		check_ajax_referer( 'acs_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-ischat' ) ] );
		}

		if ( ! ACS_Sync_Manager::is_configured() ) {
			wp_send_json_error( [ 'message' => __( 'Plugin is not fully configured. Please check the Connection settings.', 'ai-ischat' ) ] );
		}

		// Verify connection and check document limits.
		$client = ACS_Sync_Manager::make_client();
		$verify = $client->verify();

		if ( ! $verify['success'] ) {
			wp_send_json_error( [ 'message' => $verify['message'] ] );
		}

		$verify_data = is_array( $verify['data'] ) ? $verify['data'] : [];
		$usage       = isset( $verify_data['usage'] )  && is_array( $verify_data['usage'] )  ? $verify_data['usage']  : [];
		$limits      = isset( $verify_data['limits'] ) && is_array( $verify_data['limits'] ) ? $verify_data['limits'] : [];

		$doc_used = isset( $usage['documents'] )      ? (int) $usage['documents']       : 0;
		$doc_max  = isset( $limits['max_documents'] ) ? (int) $limits['max_documents']  : 0;

		if ( $doc_max > 0 && $doc_used >= $doc_max ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %1$d: current document count, %2$d: maximum allowed documents */
					__( 'Document limit reached (%1$d / %2$d). Upgrade your plan to sync more content.', 'ai-ischat' ),
					$doc_used,
					$doc_max
				),
			] );
		}

		// Enqueue everything, then run one inline batch for immediate feedback.
		$queued       = ACS_Sync_Manager::enqueue_full_sync();
		$stats_before = ACS_Sync_Queue::get_stats();

		ACS_Sync_Manager::process_queue();

		$stats_after = ACS_Sync_Queue::get_stats();
		$processed   = max( 0, $stats_before['pending'] - $stats_after['pending'] );
		$failed      = (int) $stats_after['failed'];

		wp_send_json_success( [
			'queued'    => $queued,
			'processed' => $processed,
			'failed'    => $failed,
			'message'   => sprintf(
				/* translators: %1$d: total posts queued, %2$d: posts processed in this batch */
				__( 'Queued %1$d AI-enabled posts for sync. %2$d processed in this batch. Remaining items will be synced automatically in the background.', 'ai-ischat' ),
				$queued,
				$processed
			),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Save & sync manual knowledge base
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler — persist the manual knowledge base textarea and push it to the API.
	 *
	 * Success response shape:
	 *   { success: true, data: { message } }
	 */
	public static function ajax_save_manual_kb(): void {
		check_ajax_referer( 'acs_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-ischat' ) ] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_content = isset( $_POST['acs_manual_kb'] ) ? wp_unslash( $_POST['acs_manual_kb'] ) : '';
		$content     = sanitize_textarea_field( (string) $raw_content );

		update_option( 'acs_manual_kb', $content );

		// If the plugin is not yet configured, save only (no API call).
		if ( ! ACS_Sync_Manager::is_configured() ) {
			wp_send_json_success( [
				'message' => __( 'Manual knowledge base saved locally (not synced — connection settings are incomplete).', 'ai-ischat' ),
			] );
		}

		$site_id = (int) get_option( 'acs_site_id', 0 );
		$client  = ACS_Sync_Manager::make_client();

		$result = $client->upsert_document( [
			'source_type' => 'manual',
			'source_id'   => 'manual_kb',
			'title'       => __( 'Manual Knowledge Base', 'ai-ischat' ),
			'content'     => $content,
			'hash'        => md5( $content ),
			'site_id'     => $site_id,
		] );

		if ( ! $result['success'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}

		wp_send_json_success( [
			'message' => __( 'Manual knowledge base saved and synced successfully.', 'ai-ischat' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Frontend widget injection
	// -------------------------------------------------------------------------

	/**
	 * Inject the chat widget script tag in wp_footer (front-end only).
	 */
	public static function inject_widget(): void {
		// Guard: never run in admin context.
		if ( is_admin() ) {
			return;
		}

		if ( '1' !== get_option( 'acs_widget_enabled', '0' ) ) {
			return;
		}

		$public_key = (string) get_option( 'acs_widget_public_key', '' );

		if ( empty( $public_key ) ) {
			return;
		}

		$script_url = trailingslashit( ACS_API_BASE_URL ) . 'widget.js';
		$version    = self::get_widget_asset_version();
		$script_url = add_query_arg( 'v', rawurlencode( $version ), $script_url );
		$lang       = (string) get_bloginfo( 'language' );

		printf(
			'<script src="%s" data-site-id="%s" data-api-url="%s" data-lang="%s" defer></script>' . "\n",
			esc_url( $script_url ),
			esc_attr( $public_key ),
			esc_url( rtrim( ACS_API_BASE_URL, '/' ) ),
			esc_attr( $lang )
		);
	}

	/**
	 * Resolve widget asset version used for cache-busting.
	 *
	 * In local monorepo development we use backend/public/widget.js filemtime
	 * so every rebuild invalidates browser cache. In normal plugin installs the
	 * backend file is not present, so we fall back to ACS_VERSION.
	 */
	private static function get_widget_asset_version(): string {
		$fallback_version = defined( 'ACS_VERSION' ) ? (string) ACS_VERSION : '1.0.0';

		$local_widget_path = realpath( ACS_PLUGIN_DIR . '../../backend/public/widget.js' );
		if ( false === $local_widget_path || ! is_file( $local_widget_path ) ) {
			return $fallback_version;
		}

		$mtime = filemtime( $local_widget_path );
		if ( false === $mtime ) {
			return $fallback_version;
		}

		return (string) $mtime;
	}

	/**
	 * Handle manual index action from the post edit screen.
	 */
	public static function handle_manual_index_post(): void {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-ischat' ) );
		}

		if ( ! isset( $_REQUEST['_acs_manual_index_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_acs_manual_index_nonce'] ) ), 'acs_manual_index_post_' . $post_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'ai-ischat' ) );
		}

		$result = ACS_Sync_Manager::index_post_now( $post );
		$args   = [
			'post'                 => $post_id,
			'action'               => 'edit',
			'acs_manual_index'     => $result['success'] ? 'success' : 'error',
			'acs_manual_index_msg' => rawurlencode( $result['message'] ),
		];

		wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
		exit;
	}

	/**
	 * Render result notice after manual indexing.
	 */
	public static function render_manual_index_notice(): void {
		if ( ! is_admin() || ! isset( $_GET['acs_manual_index'], $_GET['acs_manual_index_msg'] ) ) {
			return;
		}

		$status  = sanitize_key( wp_unslash( $_GET['acs_manual_index'] ) );
		$message = sanitize_text_field( wp_unslash( $_GET['acs_manual_index_msg'] ) );
		$class   = 'success' === $status ? 'notice notice-success is-dismissible' : 'notice notice-error';

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( rawurldecode( $message ) )
		);
	}
}
