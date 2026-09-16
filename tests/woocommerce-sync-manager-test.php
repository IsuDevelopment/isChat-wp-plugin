<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public string $post_status = 'publish';
	public function __construct( public int $ID, public string $post_type = 'product' ) {}
}

class ACS_Content_Extractor {
	public static function is_indexable( WP_Post $post ): bool { return 'product' === $post->post_type; }
	public static function is_currently_indexed( int $post_id ): bool { return true; }
}

class ACS_Sync_Queue {
	public static array $jobs = [];
	public static function enqueue( int $post_id, string $post_type, string $action = 'upsert' ): void {
		self::$jobs[] = compact( 'post_id', 'post_type', 'action' );
	}
}

class ACS_Test_Variation {
	public function get_parent_id(): int { return 7564; }
}

function get_post( int $id ): ?WP_Post { return $id > 0 ? new WP_Post( $id ) : null; }
function update_post_meta( int $id, string $key, string $value ): void {}

require dirname( __DIR__ ) . '/includes/class-acs-sync-manager.php';

ACS_Sync_Manager::on_woocommerce_product_update( 7564 );
ACS_Sync_Manager::on_woocommerce_variation_stock_update( new ACS_Test_Variation() );

if ( ! method_exists( ACS_Sync_Manager::class, 'on_woocommerce_product_variation_update' ) ) {
	throw new RuntimeException( 'Variation price and attribute updates must queue the parent product.' );
}

ACS_Sync_Manager::on_woocommerce_product_variation_update( 9001, 7564 );
ACS_Sync_Manager::on_deleted_post( 7564, new WP_Post( 7564 ) );

$expected = [
	[ 'post_id' => 7564, 'post_type' => 'product', 'action' => 'upsert' ],
	[ 'post_id' => 7564, 'post_type' => 'product', 'action' => 'upsert' ],
	[ 'post_id' => 7564, 'post_type' => 'product', 'action' => 'upsert' ],
	[ 'post_id' => 7564, 'post_type' => 'product', 'action' => 'delete' ],
];

if ( ACS_Sync_Queue::$jobs !== $expected ) {
	throw new RuntimeException( 'WooCommerce product lifecycle did not use the expected queue identities.' );
}

echo "WooCommerce sync manager test passed.\n";
