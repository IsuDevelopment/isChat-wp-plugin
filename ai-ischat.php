<?php
/**
 * Plugin Name:       IsChat
 * Plugin URI:        https://ischat.ai
 * Description:       Connects your WordPress site to the IsChat AI chat and search platform.
 * Version:           0.0.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      6.8
 * Author:            IsChat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-ischat
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACS_VERSION', '0.0.5' );
define( 'ACS_PLUGIN_FILE', __FILE__ );
define( 'ACS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Base URL of the IsChat backend.
 * Override in wp-config.php for local development:
 *   define( 'ACS_API_BASE_URL', 'http://localhost:8000' );
 */
if ( ! defined( 'ACS_API_BASE_URL' ) ) {
	define( 'ACS_API_BASE_URL', 'https://ischat-backend-production.up.railway.app' );
}

require_once ACS_PLUGIN_DIR . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$acs_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/IsuDevelopment/isChat-wp-plugin/',
	__FILE__,
	'ai-ischat'
);
/** @var \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi $acs_vcs_api */
$acs_vcs_api = $acs_update_checker->getVcsApi();
$acs_vcs_api->enableReleaseAssets();

require_once ACS_PLUGIN_DIR . 'includes/class-acs-api-client.php';
require_once ACS_PLUGIN_DIR . 'includes/blocks/interface-acs-block-handler.php';
require_once ACS_PLUGIN_DIR . 'includes/blocks/class-acs-block-handler-core-file.php';
require_once ACS_PLUGIN_DIR . 'includes/blocks/class-acs-block-handler-t2-file-item.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-block-extractor.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-content-extractor.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-sync-queue.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-sync-manager.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-search-block.php';
require_once ACS_PLUGIN_DIR . 'includes/class-acs-chat-block.php';
require_once ACS_PLUGIN_DIR . 'admin/class-acs-admin.php';

function acs_init(): void {
	ACS_Admin::init();
	ACS_Sync_Manager::init();
	ACS_Search_Block::init();
	ACS_Chat_Block::init();
	acs_register_post_meta();
	acs_register_rest_routes();
	acs_enqueue_editor_assets();
}
add_action( 'plugins_loaded', 'acs_init' );

/**
 * Register all post meta fields used by the AI Indexing panel.
 * Using '' (all post types) — simpler than looping per type.
 */
function acs_register_post_meta(): void {
	add_action( 'init', function (): void {
		$auth_callback = static function ( bool $allowed, string $meta_key, int $post_id ): bool {
			return current_user_can( 'edit_post', $post_id );
		};

		// Gutenberg panel toggle — read/write.
		register_post_meta(
			'',
			'_acs_ai_index_enabled',
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '0',
				'sanitize_callback' => static fn ( $v ): string => '1' === (string) $v ? '1' : '0',
				'auth_callback'     => $auth_callback,
				'show_in_rest'      => true,
			]
		);

		// Read-only in REST (updated by sync manager after successful indexing).
		register_post_meta(
			'',
			'_acs_chatbot_indexed',
			[
				'type'          => 'string',
				'single'        => true,
				'default'       => '0',
				'auth_callback' => $auth_callback,
				'show_in_rest'  => true,
			]
		);

		register_post_meta(
			'',
			'_acs_last_indexed_at',
			[
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'auth_callback' => $auth_callback,
				'show_in_rest'  => true,
			]
		);

		// Extra description — contributed to indexed content by ACS_Content_Extractor.
		register_post_meta(
			'',
			'_acs_extra_description',
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => $auth_callback,
				'show_in_rest'      => true,
			]
		);
	} );
}

/**
 * REST endpoint: POST /wp-json/acs/v1/index-post/{id}
 * Triggers immediate indexing and returns the updated meta values.
 */
function acs_register_rest_routes(): void {
	add_action( 'rest_api_init', function (): void {
		register_rest_route(
			'acs/v1',
			'/index-post/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => 'acs_rest_index_post',
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'args'                => [
					'id' => [
						'validate_callback' => static fn ( $v ): bool => is_numeric( $v ) && (int) $v > 0,
					],
				],
			]
		);
	} );
}

function acs_rest_index_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$post = get_post( (int) $request['id'] );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'acs_not_found', __( 'Post not found.', 'ai-ischat' ), [ 'status' => 404 ] );
	}

	if ( ! ACS_Sync_Manager::is_configured() ) {
		return new WP_Error( 'acs_not_configured', __( 'Plugin is not configured (missing API key or Site ID).', 'ai-ischat' ), [ 'status' => 400 ] );
	}

	$result = ACS_Sync_Manager::index_post_now( $post );

	if ( ! $result['success'] ) {
		return new WP_Error( 'acs_index_failed', $result['message'], [ 'status' => 400 ] );
	}

	return new WP_REST_Response(
		[
			'success'         => true,
			'indexed'         => get_post_meta( $post->ID, '_acs_chatbot_indexed', true ),
			'last_indexed_at' => get_post_meta( $post->ID, '_acs_last_indexed_at', true ),
		]
	);
}

/**
 * Enqueue the Gutenberg sidebar panel script in the block editor.
 */
function acs_enqueue_editor_assets(): void {
	add_action( 'enqueue_block_editor_assets', function (): void {
		$asset_file = ACS_PLUGIN_DIR . 'build/index-panel/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'acs-index-panel',
			ACS_PLUGIN_URL . 'build/index-panel/index.js',
			$asset['dependencies'],
			$asset['version']
		);

		wp_set_script_translations( 'acs-index-panel', 'ai-ischat' );
	} );
}

register_activation_hook( __FILE__, 'acs_activate' );
function acs_activate(): void {
	ACS_Sync_Queue::create_table();
}

register_deactivation_hook( __FILE__, 'acs_deactivate' );
function acs_deactivate(): void {
	foreach ( [ ACS_Sync_Manager::CRON_HOOK, ACS_Sync_Manager::CATCHUP_CRON_HOOK ] as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}
