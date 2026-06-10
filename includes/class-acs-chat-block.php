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
		register_block_type( ACS_PLUGIN_DIR . 'build/chat' );
	}
}
