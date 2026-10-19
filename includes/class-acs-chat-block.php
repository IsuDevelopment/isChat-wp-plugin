<?php
/**
 * Gutenberg block for inline IsChat chat window.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Chat_Block {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$block = register_block_type( ACS_PLUGIN_DIR . 'build/chat' );

		// Core registers the editor script's translations against
		// wp-content/languages only; point it at the bundled ones.
		if ( $block instanceof WP_Block_Type ) {
			foreach ( $block->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'ai-ischat', ACS_PLUGIN_DIR . 'languages' );
			}
		}
	}
}
