<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public function __construct( public int $ID, public string $post_type = 'product' ) {}
}

class WP_Error {}

class ACS_Test_Attribute {
	public function __construct( private string $name, private array $options, private bool $taxonomy = false ) {}
	public function get_name(): string { return $this->name; }
	public function get_options(): array { return $this->options; }
	public function is_taxonomy(): bool { return $this->taxonomy; }
}

class ACS_Test_Product {
	public function get_id(): int { return 7564; }
	public function get_short_description(): string { return '<p>Elegancka sukienka na wesele.</p>'; }
	public function get_description(): string { return '<p>Długa kreacja z dekoracyjną różą.</p>'; }
	public function get_sku(): string { return 'SCARLET-BORDO'; }
	public function get_type(): string { return 'variable'; }
	public function get_price(): string { return '449.00'; }
	public function get_regular_price(): string { return '499.00'; }
	public function get_sale_price(): string { return '449.00'; }
	public function get_variation_price( string $bound, bool $display ): string { return 'min' === $bound ? '449.00' : '479.00'; }
	public function get_stock_status(): string { return 'instock'; }
	public function get_stock_quantity(): ?int { return 4; }
	public function is_purchasable(): bool { return true; }
	public function is_on_sale(): bool { return true; }
	public function get_image_id(): int { return 7522; }
	public function get_gallery_image_ids(): array { return [ 7523, 7524 ]; }
	public function get_average_rating(): string { return '4.8'; }
	public function get_review_count(): int { return 12; }
	public function get_children(): array { return [ 9001, 9002 ]; }
	public function get_attributes(): array {
		return [
			new ACS_Test_Attribute( 'pa_kolor', [], true ),
			new ACS_Test_Attribute( 'Rozmiar', [ '36', '38', '40' ] ),
		];
	}
}

class ACS_Test_Variation {
	public function __construct(
		private int $id,
		private string $color,
		private string $price,
		private string $regular_price,
		private string $sale_price,
		private bool $on_sale
	) {}
	public function get_status(): string { return 'publish'; }
	public function get_sku(): string { return 9001 === $this->id ? 'SCARLET-GREEN-40' : 'SCARLET-RED-40'; }
	public function get_attributes(): array { return [ 'pa_kolor' => $this->color, 'pa_rozmiar' => '40' ]; }
	public function get_price(): string { return $this->price; }
	public function get_regular_price(): string { return $this->regular_price; }
	public function get_sale_price(): string { return $this->sale_price; }
	public function get_stock_status(): string { return 'instock'; }
	public function get_stock_quantity(): ?int { return 2; }
	public function is_purchasable(): bool { return true; }
	public function is_on_sale(): bool { return $this->on_sale; }
	public function get_image_id(): int { return 9001 === $this->id ? 9101 : 9102; }
	public function get_permalink(): string { return 'https://example.com/product/scarlet?variation_id=' . $this->id; }
}

function wc_get_product( int $id ): object {
	if ( 9001 === $id ) {
		return new ACS_Test_Variation( 9001, 'zielony', '499.00', '499.00', '', false );
	}
	if ( 9002 === $id ) {
		return new ACS_Test_Variation( 9002, 'czerwony', '399.00', '499.00', '399.00', true );
	}
	return new ACS_Test_Product();
}
function get_woocommerce_currency(): string { return 'PLN'; }
function get_the_title( WP_Post $post ): string { return 'Sukienka Scarlet maxi'; }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_trim_words( string $value, int $count, string $more ): string { return $value; }
function wp_get_post_terms( int $id, string $taxonomy, array $args ): array {
	return 'product_cat' === $taxonomy ? [ 'Sukienki', 'Na wesele' ] : [ 'bordo', 'maxi' ];
}
function wc_get_product_terms( int $id, string $taxonomy, array $args ): array { return [ 'bordo' ]; }
function wc_attribute_label( string $name, object $product ): string {
	return match ( $name ) {
		'pa_kolor' => 'Kolor',
		'pa_rozmiar' => 'Rozmiar',
		default => $name,
	};
}
function wp_get_attachment_image_url( int $id, string $size ): string { return 'https://example.com/image-' . $id . '.jpg'; }
function taxonomy_exists( string $taxonomy ): bool { return false; }

require dirname( __DIR__ ) . '/includes/class-acs-extractor-woocommerce.php';

$result = ACS_Extractor_WooCommerce::extract( new WP_Post( 7564 ) );

if ( null === $result ) {
	throw new RuntimeException( 'Expected product extraction result.' );
}

$expected_content = [ 'sukienka na wesele', 'Price: 449.00–479.00 PLN', 'Regular price: 499.00 PLN', 'Sale price: 449.00 PLN', 'On sale / promotion: yes', 'Availability: in stock', 'Kolor: bordo', 'Rozmiar: 36, 38, 40' ];
foreach ( $expected_content as $needle ) {
	if ( false === stripos( $result['content'], $needle ) ) {
		throw new RuntimeException( 'Missing searchable content: ' . $needle );
	}
}

$metadata = $result['metadata'];
$expectations = [
	'entity_type' => 'product',
	'product_type' => 'variable',
	'sku' => 'SCARLET-BORDO',
	'price_min' => '449.00',
	'price_max' => '479.00',
	'currency' => 'PLN',
	'stock_status' => 'instock',
];

foreach ( $expectations as $key => $value ) {
	if ( ( $metadata[ $key ] ?? null ) !== $value ) {
		throw new RuntimeException( sprintf( 'Unexpected %s metadata.', $key ) );
	}
}

if ( count( $metadata['gallery_image_urls'] ?? [] ) !== 2 ) {
	throw new RuntimeException( 'Expected gallery image URLs.' );
}

$variations = $metadata['variations'] ?? [];
if ( 2 !== count( $variations ) ) {
	throw new RuntimeException( 'Expected two structured product variations.' );
}

if ( true !== ( $metadata['variations_complete'] ?? null ) || 2 !== ( $metadata['variation_count'] ?? null ) ) {
	throw new RuntimeException( 'Expected a complete structured variation snapshot.' );
}

if ( false !== stripos( $result['content'], 'Variation 9001' ) || false !== stripos( $result['content'], 'Variation 9002' ) ) {
	throw new RuntimeException( 'Variation rows must remain structured metadata for atomic backend chunking.' );
}

$green = $variations[0];
$red   = $variations[1];

if ( true === ( $green['is_on_sale'] ?? null ) || '499.00' !== ( $green['price'] ?? null ) ) {
	throw new RuntimeException( 'Green size 40 must retain its regular price and sale state.' );
}

if ( false === ( $red['is_on_sale'] ?? null ) || '399.00' !== ( $red['sale_price'] ?? null ) ) {
	throw new RuntimeException( 'Red size 40 must retain its own promotional price and sale state.' );
}

if ( 'zielony' !== ( $green['attributes'][0]['value'] ?? null ) || 'czerwony' !== ( $red['attributes'][0]['value'] ?? null ) ) {
	throw new RuntimeException( 'Variation attributes must stay attached to the correct price and sale state.' );
}

echo "WooCommerce extractor test passed.\n";
