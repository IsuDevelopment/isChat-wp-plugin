<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public string $post_status = 'publish';
	public function __construct( public int $ID, public string $post_type = 'page' ) {}
}

class ACS_Content_Extractor {
	public static bool $indexable = true;
	public static function is_indexable( WP_Post $post ): bool { return self::$indexable; }
}

class ACS_Sync_Queue {
	public static array $jobs = [];
	public static function enqueue( int $post_id, string $post_type, string $action = 'upsert' ): void {
		self::$jobs[] = compact( 'post_id', 'post_type', 'action' );
	}
}

$GLOBALS['acs_meta'] = [];
function update_post_meta( int $id, string $key, string $value ): void {
	$GLOBALS['acs_meta'][ $id ][ $key ] = $value;
}

require dirname( __DIR__ ) . '/includes/class-acs-sync-manager.php';

$post = new WP_Post( 42 );
ACS_Sync_Manager::apply_indexing_preference( $post, true );
ACS_Sync_Manager::apply_indexing_preference( $post, false );
ACS_Content_Extractor::$indexable = false;
$queued_ineligible = ACS_Sync_Manager::queue_post_for_reindex( $post );
ACS_Content_Extractor::$indexable = true;
$queued_eligible = ACS_Sync_Manager::queue_post_for_reindex( $post );

$expected_jobs = [
	[ 'post_id' => 42, 'post_type' => 'page', 'action' => 'upsert' ],
	[ 'post_id' => 42, 'post_type' => 'page', 'action' => 'delete' ],
	[ 'post_id' => 42, 'post_type' => 'page', 'action' => 'upsert' ],
];

if ( ACS_Sync_Queue::$jobs !== $expected_jobs ) {
	throw new RuntimeException( 'List actions did not queue the expected upsert/delete lifecycle.' );
}

if ( true !== $queued_eligible || false !== $queued_ineligible ) {
	throw new RuntimeException( 'Force sync eligibility result is incorrect.' );
}

if ( '0' !== $GLOBALS['acs_meta'][42]['_acs_chatbot_indexed'] ) {
	throw new RuntimeException( 'Queued changes must mark the local index state as pending.' );
}

echo "Indexing list actions test passed.\n";
