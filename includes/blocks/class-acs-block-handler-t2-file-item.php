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
		$file_name = isset( $attrs['fileName'] ) ? wp_strip_all_tags( (string) $attrs['fileName'] ) : '';
		$href      = isset( $attrs['href'] ) ? esc_url_raw( (string) $attrs['href'] ) : '';
		$size      = isset( $attrs['size'] ) ? (string) $attrs['size'] : '';

		if ( '' === $file_name && '' === $href ) {
			return '';
		}

		$text = 'Załącznik: ' . ( $file_name ?: basename( (string) wp_parse_url( $href, PHP_URL_PATH ) ) );

		if ( '' !== $size ) {
			$text .= ' (' . $size . ')';
		}

		if ( '' !== $href ) {
			$text .= ' ' . $href;
		}

		return $text;
	}
}
