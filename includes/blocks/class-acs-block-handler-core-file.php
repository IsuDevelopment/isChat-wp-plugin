<?php
/**
 * Handles the native WordPress core/file block.
 *
 * Example block comment:
 *   <!-- wp:file {"id":68,"href":"https://example.com/info.pdf"} -->
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Block_Handler_Core_File implements ACS_Block_Handler_Interface {

	public function handles( string $block_name ): bool {
		return 'core/file' === $block_name;
	}

	public function extract( array $block ): string {
		$attrs = $block['attrs'] ?? [];
		$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		$href  = isset( $attrs['href'] ) ? (string) $attrs['href'] : '';

		$title       = '';
		$description = '';

		if ( $id > 0 ) {
			$attachment = get_post( $id );
			if ( $attachment instanceof WP_Post ) {
				$title       = get_the_title( $attachment );
				$description = wp_strip_all_tags( $attachment->post_content );
			}
		}

		if ( '' === $title && '' !== $href ) {
			$title = basename( (string) wp_parse_url( $href, PHP_URL_PATH ) );
		}

		$parts = array_filter( [ $title, $description ] );
		if ( empty( $parts ) ) {
			return '';
		}

		return 'Załącznik: ' . implode( '. ', $parts );
	}
}
