<?php
/**
 * Dynamic render for ischat/chat block.
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Inner block content (unused — dynamic block).
 * @var WP_Block $block    Block instance.
 */

declare( strict_types=1 );

$site_id = (string) get_option( 'acs_widget_public_key', '' );

if ( '' === trim( $site_id ) ) {
	return;
}

// Enqueue the widget runtime only when the floating bubble is not already loading it.
if ( '1' !== get_option( 'acs_widget_enabled', '0' ) ) {
	wp_enqueue_script(
		'acs-widget-runtime',
		trailingslashit( ACS_API_BASE_URL ) . 'widget.js',
		[],
		false,
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);
}

$height = isset( $attributes['height'] ) ? sanitize_text_field( (string) $attributes['height'] ) : '500px';

printf(
	'<div %1$s style="height:%2$s"><div data-ischat-chat data-site-id="%3$s" data-api-url="%4$s" data-lang="%5$s" style="height:100%%"></div></div>',
	get_block_wrapper_attributes(),
	esc_attr( $height ),
	esc_attr( $site_id ),
	esc_url( ACS_API_BASE_URL ),
	esc_attr( get_locale() )
);
