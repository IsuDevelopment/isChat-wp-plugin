<?php
/**
 * Extracts searchable content and structured metadata from WooCommerce products.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Extractor_WooCommerce {
	private const PRODUCT_SCHEMA_VERSION = 1;
	private const MAX_VARIATIONS          = 500;

	/**
	 * Extract a WooCommerce product without requiring WooCommerce at plugin load time.
	 *
	 * @return array{content: string, metadata: array<string, mixed>}|null
	 */
	public static function extract( WP_Post $post ): ?array {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );
		if ( ! is_object( $product ) ) {
			return null;
		}

		$short_description = self::clean_text( (string) $product->get_short_description() );
		$description       = self::clean_text( (string) $product->get_description() );
		$categories        = self::term_names( $post->ID, 'product_cat' );
		$tags              = self::term_names( $post->ID, 'product_tag' );
		$attributes        = self::attributes( $product );
		$currency          = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$price             = (string) $product->get_price();
		$regular_price     = (string) $product->get_regular_price();
		$sale_price        = (string) $product->get_sale_price();
		$price_min         = method_exists( $product, 'get_variation_price' ) ? (string) $product->get_variation_price( 'min', true ) : $price;
		$price_max         = method_exists( $product, 'get_variation_price' ) ? (string) $product->get_variation_price( 'max', true ) : $price;
		$stock_status      = (string) $product->get_stock_status();
		$image_id          = (int) $product->get_image_id();
		$image_url         = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		$gallery_urls      = self::gallery_urls( $product );
		$variations        = self::variations( $product );

		$content_parts = array_filter(
			[
				self::fact( 'Product', get_the_title( $post ) ),
				$short_description,
				$description,
				self::fact( 'SKU', (string) $product->get_sku() ),
				self::price_fact( $price_min, $price_max, $currency ),
				self::fact( 'Regular price', self::money( $regular_price, $currency ) ),
				self::fact( 'Sale price', self::money( $sale_price, $currency ) ),
				self::fact( 'On sale / promotion', $product->is_on_sale() ? 'yes' : 'no' ),
				self::fact( 'Availability', self::availability_label( $stock_status ) ),
				self::fact( 'Categories', implode( ', ', $categories ) ),
				self::fact( 'Tags', implode( ', ', $tags ) ),
				self::attributes_text( $attributes ),
			]
		);

		$metadata = [
			'product_schema_version' => self::PRODUCT_SCHEMA_VERSION,
			'entity_type'         => 'product',
			'product_type'        => sanitize_key( (string) $product->get_type() ),
			'sku'                 => sanitize_text_field( (string) $product->get_sku() ),
			'price'               => self::decimal_or_null( $price ),
			'regular_price'       => self::decimal_or_null( $regular_price ),
			'sale_price'          => self::decimal_or_null( $sale_price ),
			'price_min'           => self::decimal_or_null( $price_min ),
			'price_max'           => self::decimal_or_null( $price_max ),
			'currency'            => sanitize_text_field( $currency ),
			'stock_status'        => sanitize_key( $stock_status ),
			'stock_quantity'      => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'is_purchasable'      => (bool) $product->is_purchasable(),
			'is_on_sale'          => (bool) $product->is_on_sale(),
			'categories'          => $categories,
			'tags'                => $tags,
			'attributes'          => $attributes,
			'average_rating'      => (string) $product->get_average_rating(),
			'review_count'        => (int) $product->get_review_count(),
			'featured_image_id'   => $image_id ?: null,
			'featured_image_url'  => $image_url ?: null,
			'gallery_image_urls'  => $gallery_urls,
			'variation_count'      => $variations['total'],
			'variations_complete' => $variations['complete'],
			'variations'          => $variations['items'],
			'excerpt'             => $short_description ?: wp_trim_words( $description, 32, '...' ),
		];

		return [
			'content'  => implode( "\n\n", $content_parts ),
			'metadata' => array_filter(
				$metadata,
				static fn ( $value ): bool => null !== $value && '' !== $value && [] !== $value
			),
		];
	}

	private static function clean_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** @return string[] */
	private static function term_names( int $post_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $terms ) ) );
	}

	/**
	 * @param object $product
	 * @return array<int, array{name: string, values: string[]}>
	 */
	private static function attributes( object $product ): array {
		$result = [];
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}

			$name   = (string) $attribute->get_name();
			$label  = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name, $product ) : $name;
			$values = method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' )
				? wc_get_product_terms( (int) $product->get_id(), $name, [ 'fields' => 'names' ] )
				: ( method_exists( $attribute, 'get_options' ) ? $attribute->get_options() : [] );

			if ( is_wp_error( $values ) || ! is_array( $values ) ) {
				$values = [];
			}

			$values = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $values ) ) ) );
			if ( '' !== trim( $label ) && [] !== $values ) {
				$result[] = [ 'name' => sanitize_text_field( $label ), 'values' => $values ];
			}
		}

		return $result;
	}

	/** @param array<int, array{name: string, values: string[]}> $attributes */
	private static function attributes_text( array $attributes ): string {
		$lines = [];
		foreach ( $attributes as $attribute ) {
			$lines[] = $attribute['name'] . ': ' . implode( ', ', $attribute['values'] );
		}
		return [] === $lines ? '' : "Attributes:\n" . implode( "\n", $lines );
	}

	/**
	 * Extract concrete purchasable combinations without assuming attribute names.
	 *
	 * @param object $product
	 * @return array{items: array<int, array<string, mixed>>, total: int, complete: bool}
	 */
	private static function variations( object $product ): array {
		if ( ! method_exists( $product, 'get_children' ) ) {
			return [ 'items' => [], 'total' => 0, 'complete' => true ];
		}

		$children  = array_values( array_map( 'intval', (array) $product->get_children() ) );
		$total     = count( $children );
		$items     = [];
		$parent_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;

		foreach ( array_slice( $children, 0, self::MAX_VARIATIONS ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! is_object( $variation ) || ( method_exists( $variation, 'get_status' ) && 'publish' !== $variation->get_status() ) ) {
				continue;
			}

			$image_id  = method_exists( $variation, 'get_image_id' ) ? (int) $variation->get_image_id() : 0;
			if ( 0 === $image_id && method_exists( $product, 'get_image_id' ) ) {
				$image_id = (int) $product->get_image_id();
			}
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
			$items[]   = array_filter(
				[
					'id'             => $variation_id,
					'sku'            => sanitize_text_field( (string) $variation->get_sku() ),
					'attributes'     => self::variation_attributes( $variation, $product ),
					'price'          => self::decimal_or_null( (string) $variation->get_price() ),
					'regular_price'  => self::decimal_or_null( (string) $variation->get_regular_price() ),
					'sale_price'     => self::decimal_or_null( (string) $variation->get_sale_price() ),
					'currency'       => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
					'stock_status'   => sanitize_key( (string) $variation->get_stock_status() ),
					'stock_quantity' => null === $variation->get_stock_quantity() ? null : (int) $variation->get_stock_quantity(),
					'is_purchasable' => (bool) $variation->is_purchasable(),
					'is_on_sale'     => (bool) $variation->is_on_sale(),
					'image_url'      => $image_url ?: null,
					'url'            => method_exists( $variation, 'get_permalink' ) ? (string) $variation->get_permalink() : ( $parent_id ? get_permalink( $parent_id ) : '' ),
				],
				static fn ( $value ): bool => null !== $value && '' !== $value && [] !== $value
			);
		}

		return [
			'items'     => $items,
			'total'     => $total,
			'complete' => $total <= self::MAX_VARIATIONS,
		];
	}

	/**
	 * @param object $variation
	 * @param object $parent
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function variation_attributes( object $variation, object $parent ): array {
		$result = [];
		foreach ( (array) $variation->get_attributes() as $name => $value ) {
			$name  = (string) $name;
			$value = (string) $value;
			$label = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name, $parent ) : $name;

			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $name ) && function_exists( 'get_term_by' ) ) {
				$term = get_term_by( 'slug', $value, $name );
				if ( is_object( $term ) && isset( $term->name ) ) {
					$value = (string) $term->name;
				}
			}

			if ( '' !== trim( $label ) && '' !== trim( $value ) ) {
				$result[] = [
					'name'  => sanitize_text_field( $label ),
					'value' => sanitize_text_field( $value ),
				];
			}
		}

		return $result;
	}

	private static function fact( string $label, string $value ): string {
		return '' === trim( $value ) ? '' : $label . ': ' . $value;
	}

	private static function price_fact( string $min, string $max, string $currency ): string {
		if ( '' === $min && '' === $max ) {
			return '';
		}
		$value = '' !== $min && '' !== $max && $min !== $max ? $min . '–' . $max : ( $min ?: $max );
		return self::fact( 'Price', trim( $value . ' ' . $currency ) );
	}

	private static function money( string $value, string $currency ): string {
		return '' === trim( $value ) ? '' : trim( $value . ' ' . $currency );
	}

	private static function availability_label( string $status ): string {
		return [
			'instock'     => 'in stock',
			'outofstock'  => 'out of stock',
			'onbackorder' => 'available on backorder',
		][ $status ] ?? $status;
	}

	private static function decimal_or_null( string $value ): ?string {
		return '' === trim( $value ) || ! is_numeric( $value ) ? null : $value;
	}

	/** @param object $product @return string[] */
	private static function gallery_urls( object $product ): array {
		$urls = [];
		foreach ( array_slice( (array) $product->get_gallery_image_ids(), 0, 8 ) as $image_id ) {
			$url = wp_get_attachment_image_url( (int) $image_id, 'medium' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}
}
