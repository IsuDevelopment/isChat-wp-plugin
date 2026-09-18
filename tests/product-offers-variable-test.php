<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function add_action(): void {}
function wc_get_price_to_display( object $product, array $args ): float { return (float) $args['price']; }
function wc_attribute_label( string $name ): string { return 'pa_rozmiar' === $name ? 'Rozmiar' : $name; }
function taxonomy_exists(): bool { return false; }
function get_term_by(): false { return false; }
function sanitize_text_field( string $value ): string { return trim( $value ); }

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

echo "product-offers-variable-test: OK\n";
