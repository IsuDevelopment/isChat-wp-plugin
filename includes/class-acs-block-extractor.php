<?php
/**
 * Extracts indexable text from Gutenberg file blocks within post content.
 *
 * Uses parse_blocks() to walk the block tree and delegates each block to a
 * registered ACS_Block_Handler_Interface implementation. New block types can
 * be supported by implementing the interface and registering the handler here.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Block_Extractor {

	/** @var ACS_Block_Handler_Interface[]|null */
	private static ?array $handlers = null;

	/**
	 * Extract indexable text from all handled Gutenberg blocks in the content.
	 * Returns empty string when content has no blocks or no handled blocks found.
	 */
	public static function extract_file_text( string $content ): string {
		if ( ! has_blocks( $content ) ) {
			return '';
		}

		$blocks = parse_blocks( $content );
		$texts  = [];

		foreach ( $blocks as $block ) {
			$text = self::process_block( $block );
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return implode( "\n", $texts );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function process_block( array $block ): string {
		$block_name = $block['blockName'] ?? '';

		if ( '' !== $block_name ) {
			foreach ( self::get_handlers() as $handler ) {
				if ( $handler->handles( $block_name ) ) {
					return $handler->extract( $block );
				}
			}
		}

		// Recurse into inner blocks (columns, groups, etc.) when no handler matched.
		$texts = [];
		foreach ( $block['innerBlocks'] ?? [] as $inner ) {
			$text = self::process_block( $inner );
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return implode( "\n", $texts );
	}

	/**
	 * @return ACS_Block_Handler_Interface[]
	 */
	private static function get_handlers(): array {
		if ( null === self::$handlers ) {
			self::$handlers = [
				new ACS_Block_Handler_Core_File(),
				new ACS_Block_Handler_T2_File_Item(),
			];
		}

		return self::$handlers;
	}
}
