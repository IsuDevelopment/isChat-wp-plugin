<?php
/**
 * Gutenberg block for inline IsChat contextual search.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Search_Block {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		wp_register_script(
			'acs-search-block-editor',
			ACS_PLUGIN_URL . 'assets/js/search-block-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n' ],
			ACS_VERSION,
			true
		);

		register_block_type(
			'ischat/search',
			[
				'api_version'     => 2,
				'editor_script'   => 'acs-search-block-editor',
				'render_callback' => [ self::class, 'render' ],
				'attributes'      => [
					'className' => [
						'type' => 'string',
					],
				],
			]
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		$site_id = (string) get_option( 'acs_widget_public_key', '' );

		if ( '' === trim( $site_id ) ) {
			return '';
		}

		if ( '1' !== get_option( 'acs_widget_enabled', '0' ) ) {
			wp_enqueue_script(
				'acs-widget-runtime',
				trailingslashit( ACS_API_BASE_URL ) . 'widget.js',
				[],
				ACS_VERSION,
				true
			);
		}

		$class_name = isset( $attributes['className'] ) ? sanitize_html_class( (string) $attributes['className'] ) : '';
		$classes    = trim( 'wp-block-ischat-search ' . $class_name );

		return sprintf(
			'<div class="%1$s"><div data-ischat-search data-site-id="%2$s" data-api-url="%3$s" data-lang="%4$s"></div></div>',
			esc_attr( $classes ),
			esc_attr( $site_id ),
			esc_url( ACS_API_BASE_URL ),
			esc_attr( get_locale() )
		);
	}
}
