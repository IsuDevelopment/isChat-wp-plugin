<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function add_action(): void {}
function wc_get_price_to_display( object $product, array $args ): float { return (float) $args['price']; }
function wc_attribute_label( string $name ): string { return 'pa_rozmiar' === $name ? 'Rozmiar' : $name; }
function taxonomy_exists(): bool { return false; }
function get_term_by(): false { return false; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function wc_format_decimal( $value, int $decimals ): string { return number_format( (float) $value, $decimals, '.', '' ); }
function wc_get_price_decimals(): int { return 2; }
function esc_url_raw( string $url ): string { return $url; }
function wp_get_attachment_image_url( int $id ): string { return 'https://shop.example.com/img/' . $id . '.jpg'; }

$products = [];
function wc_get_product( int $id ): object|false {
	global $products;
	return $products[ $id ] ?? false;
}

final class VariableOfferParent {
	public function get_children(): array { return [ 101, 102, 103 ]; }
}

final class VariableOfferVariation {
	public function __construct(
		private readonly string $size,
		private readonly string $stock,
		private readonly string $price,
		private readonly string $regular,
		private readonly bool $sale = false,
	) {}
	public function get_status(): string { return 'publish'; }
	public function is_purchasable(): bool { return true; }
	public function get_stock_status(): string { return $this->stock; }
	public function get_price(): string { return $this->price; }
	public function get_regular_price(): string { return $this->regular; }
	public function is_on_sale(): bool { return $this->sale; }
	public function get_attributes(): array { return [ 'pa_rozmiar' => $this->size ]; }
	public function get_permalink(): string { return 'https://shop.example.com/product/dress/?size=' . $this->size; }
	public function get_image_id(): int { return 0; }
}

$products = [
	101 => new VariableOfferVariation( '36', 'instock', '299.00', '299.00' ),
	102 => new VariableOfferVariation( '38', 'outofstock', '319.00', '319.00' ),
	103 => new VariableOfferVariation( '42', 'instock', '349.00', '399.00', true ),
];

require_once dirname( __DIR__ ) . '/includes/class-acs-product-offers.php';

$method = new ReflectionMethod( ACS_Product_Offers::class, 'variable_offer' );
$method->setAccessible( true );
$offer = $method->invoke( null, new VariableOfferParent() );

if ( 299.0 !== $offer['price'] || 349.0 !== $offer['price_max'] ) {
	throw new RuntimeException( 'The live variable-product price range is incorrect.' );
}
if ( '36, 42' !== ( $offer['attributes'][0]['value'] ?? null ) ) {
	throw new RuntimeException( 'Only purchasable in-stock sizes may be returned.' );
}
if ( 'instock' !== $offer['stock_status'] || true !== $offer['is_purchasable'] ) {
	throw new RuntimeException( 'The aggregate variable offer must be purchasable and in stock.' );
}

$under_320 = $method->invoke( null, new VariableOfferParent(), null, 320.0 );
if ( '36' !== ( $under_320['attributes'][0]['value'] ?? null ) || 299.0 !== $under_320['price_max'] ) {
	throw new RuntimeException( 'Price constraints must remove variants outside the requested range.' );
}

$variants = $offer['variants'] ?? [];
if ( 2 !== count( $variants ) ) {
	throw new RuntimeException( 'Only purchasable in-stock variations may be offered as a picker.' );
}
if ( '36' !== $variants[0]['label'] || '299.00' !== $variants[0]['price'] || null !== $variants[0]['regular_price'] ) {
	throw new RuntimeException( 'A variant must carry its own concrete price.' );
}
if ( '42' !== $variants[1]['label'] || '349.00' !== $variants[1]['price'] || '399.00' !== $variants[1]['regular_price'] ) {
	throw new RuntimeException( 'A discounted variant must carry its own crossed-out price.' );
}
if ( false === strpos( (string) $variants[1]['url'], 'size=42' ) ) {
	throw new RuntimeException( 'Each variant must link to itself, not to the parent.' );
}
if ( 1 !== count( $under_320['variants'] ) || '36' !== $under_320['variants'][0]['label'] ) {
	throw new RuntimeException( 'Price constraints must also narrow the offered variants.' );
}

echo "product-offers-variable-test: OK\n";
