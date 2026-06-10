<?php
/**
 * Contract for Gutenberg block handlers used during content extraction.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ACS_Block_Handler_Interface {

	/**
	 * Whether this handler processes the given block name (e.g. 'core/file').
	 */
	public function handles( string $block_name ): bool;

	/**
	 * Extract indexable plain text from a parsed block array.
	 *
	 * @param array<string, mixed> $block Parsed block from parse_blocks().
	 */
	public function extract( array $block ): string;
}
