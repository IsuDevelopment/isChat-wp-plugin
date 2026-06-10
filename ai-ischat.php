<?php
/**
 * Plugin Name:       IsChat
 * Plugin URI:        https://ischat.ai
 * Description:       Connects your WordPress site to the IsChat AI chat and search platform.
 * Version:           0.0.2
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

define( 'ACS_VERSION', '1.0.0' );
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
	acs_register_index_metabox();
}
add_action( 'plugins_loaded', 'acs_init' );

/**
 * Register the "AI Indexing" sidebar metabox shown on all enabled post types.
 */
function acs_register_index_metabox(): void {
	add_action( 'init', function (): void {
		$post_types = ACS_Content_Extractor::get_enabled_post_types();

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_acs_ai_index_enabled',
				[
					'type'              => 'string',
					'single'            => true,
					'default'           => '0',
					'sanitize_callback' => static function ( $value ): string {
						return '1' === (string) $value ? '1' : '0';
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
					'show_in_rest'      => true,
				]
			);
		}
	} );

	add_action( 'add_meta_boxes', function (): void {
		$post_types = ACS_Content_Extractor::get_enabled_post_types();
		add_meta_box(
			'acs_index_settings',
			__( 'AI Indexing', 'ai-ischat' ),
			'acs_render_index_metabox',
			$post_types,
			'side',
			'default'
		);
	} );

	add_action( 'save_post', function ( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['_acs_ai_index_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_acs_ai_index_nonce'] ) ), 'acs_ai_index_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! in_array( $post_type, ACS_Content_Extractor::get_enabled_post_types(), true ) ) {
			return;
		}

		$value = isset( $_POST['_acs_ai_index_enabled'] ) ? '1' : '0';
		update_post_meta( $post_id, '_acs_ai_index_enabled', $value );
	}, 5 ); // priority 5 — runs before ACS_Sync_Manager::on_save_post (priority 10)
}

function acs_render_index_metabox( WP_Post $post ): void {
	$is_enabled      = ACS_Content_Extractor::is_ai_index_enabled( $post->ID );
	$is_indexed      = ACS_Content_Extractor::is_currently_indexed( $post->ID );
	$last_indexed_at = ACS_Content_Extractor::get_last_indexed_at( $post->ID );
	$can_index_now   = ACS_Sync_Manager::is_configured()
		&& 'publish' === $post->post_status
		&& ACS_Content_Extractor::is_ai_index_enabled( $post->ID );
	$manual_index_url = wp_nonce_url(
		add_query_arg(
			[
				'action'  => 'acs_manual_index_post',
				'post_id' => $post->ID,
			],
			admin_url( 'admin-post.php' )
		),
		'acs_manual_index_post_' . $post->ID,
		'_acs_manual_index_nonce'
	);

	wp_nonce_field( 'acs_ai_index_' . $post->ID, '_acs_ai_index_nonce' );
	?>
	<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
		<input type="checkbox" name="_acs_ai_index_enabled" value="1" <?php checked( true, $is_enabled ); ?> />
		<span><?php esc_html_e( 'Index in IsChat', 'ai-ischat' ); ?></span>
	</label>
	<p style="margin:6px 0 0;color:#666;font-size:11px;">
		<?php esc_html_e( 'Only checked posts can be indexed. Saving the post does not auto-index it — use the manual button below.', 'ai-ischat' ); ?>
	</p>
	<p style="margin:10px 0 0;font-size:12px;">
		<strong><?php esc_html_e( 'Chatbot Indexed:', 'ai-ischat' ); ?></strong>
		<?php echo $is_indexed ? '✓' : '✕'; ?>
	</p>
	<p style="margin:6px 0 0;font-size:12px;">
		<strong><?php esc_html_e( 'Last Indexed:', 'ai-ischat' ); ?></strong>
		<?php echo $last_indexed_at ? esc_html( $last_indexed_at ) : esc_html__( 'Never', 'ai-ischat' ); ?>
	</p>
	<?php if ( 'auto-draft' !== $post->post_status ) : ?>
	<p style="margin-top:12px;">
		<?php if ( $can_index_now ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $manual_index_url ); ?>">
				<?php esc_html_e( 'Manual Index Page', 'ai-ischat' ); ?>
			</a>
		<?php else : ?>
			<button type="button" class="button button-primary" disabled>
				<?php esc_html_e( 'Manual Index Page', 'ai-ischat' ); ?>
			</button>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<p style="margin:6px 0 0;color:#666;font-size:11px;">
		<?php esc_html_e( 'Manual indexing is available only for published posts with "Index in IsChat" enabled and a configured plugin connection.', 'ai-ischat' ); ?>
	</p>
	<?php
}

register_activation_hook( __FILE__, 'acs_activate' );
function acs_activate(): void {
	ACS_Sync_Queue::create_table();
}

register_deactivation_hook( __FILE__, 'acs_deactivate' );
function acs_deactivate(): void {
	$timestamp = wp_next_scheduled( ACS_Sync_Manager::CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, ACS_Sync_Manager::CRON_HOOK );
	}
}
