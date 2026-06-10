<?php
/**
 * Handles the custom t2/file-item block.
 *
 * Example block comment:
 *   <!-- wp:t2/file-item {"id":806,"fileName":"Wniosek...","href":"https://example.com/file.pdf","size":"832 KB"} /-->
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Block_Handler_T2_File_Item implements ACS_Block_Handler_Interface {

	public function handles( string $block_name ): bool {
		return 't2/file-item' === $block_name;
	}

	public function extract( array $block ): string {
		$attrs     = $block['attrs'] ?? [];
		$id        = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		$file_name = isset( $attrs['fileName'] ) ? wp_strip_all_tags( (string) $attrs['fileName'] ) : '';
		$size      = isset( $attrs['size'] ) ? (string) $attrs['size'] : '';

		$title       = '';
		$description = '';

		if ( $id > 0 ) {
			$attachment = get_post( $id );
			if ( $attachment instanceof WP_Post ) {
				$title       = get_the_title( $attachment );
				$description = wp_strip_all_tags( $attachment->post_content );
			}
		}

		// Fallback to fileName attribute from the block.
		if ( '' === $title ) {
			$title = $file_name;
		}

		$parts = array_filter( [ $title, $description ] );
		if ( empty( $parts ) ) {
			return '';
		}

		$text = 'Załącznik: ' . implode( '. ', $parts );

		if ( '' !== $size ) {
			$text .= ' (' . $size . ')';
		}

		return $text;
	}
}
