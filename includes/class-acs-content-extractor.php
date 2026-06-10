<?php
/**
 * Extracts clean text content from WordPress posts.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Content_Extractor {

	/**
	 * Extract indexable data from a WP_Post.
	 *
	 * @param WP_Post $post
	 * @return array<string, mixed>|null  null if post should not be indexed
	 */
	public static function extract( WP_Post $post ): ?array {
		if ( ! self::is_indexable( $post ) ) {
			return null;
		}

		$content = self::get_clean_content( $post );

		if ( empty( trim( $content ) ) ) {
			return null;
		}

		return [
			'source_type' => 'wp_' . $post->post_type,
			'source_id'   => (string) $post->ID,
			'post_type'   => $post->post_type,
			'status'      => $post->post_status,
			'title'       => get_the_title( $post ),
			'url'         => get_permalink( $post ),
			'source_metadata' => self::get_source_metadata( $post ),
			'content'     => $content,
			'language'    => self::get_language( $post ),
			'hash'        => md5( $content ),
		];
	}

	/**
	 * Whether the post should be indexed.
	 * Requires explicit per-post opt-in via the sidebar metabox.
	 */
	public static function is_indexable( WP_Post $post ): bool {
		$enabled_types    = self::get_enabled_post_types();
		$indexable_status = [ 'publish' ];

		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return false;
		}

		if ( ! in_array( $post->post_status, $indexable_status, true ) ) {
			return false;
		}

		if ( ! self::is_ai_index_enabled( $post->ID ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether AI indexing is explicitly enabled for a post.
	 */
	public static function is_ai_index_enabled( int $post_id ): bool {
		return '1' === get_post_meta( $post_id, '_acs_ai_index_enabled', true );
	}

	/**
	 * Whether the post is currently indexed in the chatbot backend.
	 */
	public static function is_currently_indexed( int $post_id ): bool {
		return '1' === get_post_meta( $post_id, '_acs_chatbot_indexed', true );
	}

	/**
	 * Last successful index timestamp for a post.
	 */
	public static function get_last_indexed_at( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_acs_last_indexed_at', true );
	}

	/**
	 * Whether the post should be removed from index (trashed/deleted/draft).
	 */
	public static function should_deindex( WP_Post $post ): bool {
		$remove_on_status = [ 'trash', 'draft', 'private', 'pending' ];
		return in_array( $post->post_status, $remove_on_status, true );
	}

	/**
	 * Get clean text content from a post.
	 */
	private static function get_clean_content( WP_Post $post ): string {
		$parts = [];

		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = wp_strip_all_tags( $post->post_excerpt );
		}

		// Extra AI description — admin-supplied context not visible on the page.
		$extra = get_post_meta( $post->ID, '_acs_extra_description', true );
		if ( ! empty( trim( (string) $extra ) ) ) {
			$parts[] = sanitize_textarea_field( (string) $extra );
		}

		// Extract title + description from Gutenberg file blocks (core/file, t2/file-item, …).
		$block_text = ACS_Block_Extractor::extract_file_text( $post->post_content );
		if ( '' !== $block_text ) {
			$parts[] = $block_text;
		}

		// Apply WP content filters (handles shortcodes, blocks, etc.)
		$content = apply_filters( 'the_content', $post->post_content );
		$content = wp_strip_all_tags( $content );

		// Collapse excessive whitespace
		$content = preg_replace( '/\s+/', ' ', $content );
		$parts[] = trim( $content );

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * Build stable source metadata used by contextual search results.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_source_metadata( WP_Post $post ): array {
		$featured_image_id  = get_post_thumbnail_id( $post );
		$featured_image_url = $featured_image_id ? wp_get_attachment_image_url( $featured_image_id, 'medium' ) : '';
		$excerpt            = has_excerpt( $post )
			? wp_strip_all_tags( get_the_excerpt( $post ) )
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 32, '...' );

		return array_filter(
			[
				'wp_id'              => $post->ID,
				'featured_image_id'  => $featured_image_id ? (int) $featured_image_id : null,
				'featured_image_url' => $featured_image_url ?: null,
				'excerpt'            => $excerpt ?: null,
				'modified_at'        => get_post_modified_time( DATE_ATOM, true, $post ),
			],
			static fn ( $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * Get post language (uses Polylang/WPML if available, falls back to site lang).
	 */
	private static function get_language( WP_Post $post ): string {
		// Polylang support
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post->ID, 'slug' );
			if ( $lang ) {
				return $lang;
			}
		}

		// WPML support
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return ICL_LANGUAGE_CODE;
		}

		return substr( get_locale(), 0, 2 );
	}

	/**
	 * Get post types enabled for indexing.
	 *
	 * @return string[]
	 */
	public static function get_enabled_post_types(): array {
		$saved = get_option( 'acs_post_types', [] );
		if ( ! empty( $saved ) && is_array( $saved ) ) {
			return $saved;
		}
		return [ 'post', 'page' ];
	}
}
