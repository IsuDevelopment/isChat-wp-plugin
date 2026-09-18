<?php
/**
 * Handles sync hooks (save_post, delete_post) and WP-Cron batch processing.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Sync_Manager {

	const CRON_HOOK        = 'acs_process_sync_queue';
	const CATCHUP_CRON_HOOK = 'acs_catchup_sync';

	public static function init(): void {
		// WordPress hooks for content changes
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 10, 2 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition_status' ], 10, 3 );
		add_action( 'deleted_post', [ __CLASS__, 'on_deleted_post' ], 10, 2 );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'on_woocommerce_product_update' ], 20, 1 );
		add_action( 'woocommerce_update_product_variation', [ __CLASS__, 'on_woocommerce_product_variation_update' ], 20, 2 );
		add_action( 'woocommerce_product_set_stock', [ __CLASS__, 'on_woocommerce_product_stock_update' ], 20, 1 );
		add_action( 'woocommerce_variation_set_stock', [ __CLASS__, 'on_woocommerce_variation_stock_update' ], 20, 1 );
		add_action( 'woocommerce_scheduled_sales', [ __CLASS__, 'on_woocommerce_scheduled_sales' ], 20 );

		// WP-Cron — runs every 5 minutes to process the queue
		add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'acs_five_minutes', self::CRON_HOOK );
		}

		// Daily catch-up — queues enabled posts modified since last index
		add_action( self::CATCHUP_CRON_HOOK, [ __CLASS__, 'catchup_sync' ] );
		if ( ! wp_next_scheduled( self::CATCHUP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CATCHUP_CRON_HOOK );
		}

		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
	}

	/**
	 * Add custom 5-minute cron interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_cron_interval( array $schedules ): array {
		$schedules['acs_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'ai-ischat' ),
		];
		return $schedules;
	}

	/**
	 * Mark posts as needing manual re-index or de-index them when required.
	 */
	public static function on_save_post( int $post_id, WP_Post $post ): void {
		// Skip autosaves, revisions, and non-public post types
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ACS_Content_Extractor::should_deindex( $post ) ) {
			if ( ACS_Content_Extractor::is_currently_indexed( $post_id ) ) {
				ACS_Sync_Queue::enqueue( $post_id, $post->post_type, 'delete' );
			}
			update_post_meta( $post_id, '_acs_chatbot_indexed', '0' );
			return;
		}

		if ( ACS_Content_Extractor::is_indexable( $post ) ) {
			update_post_meta( $post_id, '_acs_chatbot_indexed', '0' );
			if ( 'product' === $post->post_type ) {
				ACS_Sync_Queue::enqueue( $post_id, 'product', 'upsert' );
			}
		} elseif ( ACS_Content_Extractor::is_currently_indexed( $post_id ) ) {
			ACS_Sync_Queue::enqueue( $post_id, $post->post_type, 'delete' );
			update_post_meta( $post_id, '_acs_chatbot_indexed', '0' );
		}
	}

	/**
	 * Handle publish → trash/draft transitions.
	 */
	public static function on_transition_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		$deindex_statuses = [ 'trash', 'draft', 'private', 'pending' ];
		if ( 'product_variation' === $post->post_type ) {
			$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
			if ( $parent_id > 0 ) {
				self::queue_product_update( $parent_id );
			}
			return;
		}

		if ( in_array( $new_status, $deindex_statuses, true ) && $old_status === 'publish' ) {
			ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'delete' );
			update_post_meta( $post->ID, '_acs_chatbot_indexed', '0' );
		}
	}

	/**
	 * Remove from index when post is permanently deleted.
	 */
	public static function on_deleted_post( int $post_id, WP_Post $post ): void {
		if ( 'product_variation' === $post->post_type ) {
			$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
			if ( $parent_id > 0 ) {
				self::queue_product_update( $parent_id );
			}
			return;
		}

		ACS_Sync_Queue::enqueue( $post_id, $post->post_type, 'delete' );
	}

	/**
	 * Queue product changes made through WooCommerce CRUD APIs.
	 */
	public static function on_woocommerce_product_update( int $product_id ): void {
		self::queue_product_or_parent_update( $product_id );
	}

	/** Queue the variable parent after price, attribute or status changes. */
	public static function on_woocommerce_product_variation_update( int $variation_id, int $parent_id = 0 ): void {
		if ( $parent_id <= 0 ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
			$parent_id = is_object( $variation ) && method_exists( $variation, 'get_parent_id' )
				? (int) $variation->get_parent_id()
				: 0;
		}

		if ( $parent_id > 0 ) {
			self::queue_product_update( $parent_id );
		}
	}

	/** @param object $product WooCommerce product object. */
	public static function on_woocommerce_product_stock_update( object $product ): void {
		if ( method_exists( $product, 'get_id' ) ) {
			self::queue_product_or_parent_update( (int) $product->get_id() );
		}
	}

	/** @param object $variation WooCommerce variation object. */
	public static function on_woocommerce_variation_stock_update( object $variation ): void {
		if ( method_exists( $variation, 'get_parent_id' ) ) {
			self::queue_product_update( (int) $variation->get_parent_id() );
		}
	}

	/**
	 * Scheduled sales can start or end without changing the parent post timestamp.
	 * Queue every opted-in published product; the queue deduplicates identities.
	 */
	public static function on_woocommerce_scheduled_sales(): void {
		$page = 1;
		do {
			$product_ids = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 250,
				'paged'          => $page,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => '_acs_ai_index_enabled',
				'meta_value'     => '1',
			] );

			foreach ( $product_ids as $product_id ) {
				self::queue_product_update( (int) $product_id );
			}
			$page++;
		} while ( 250 === count( $product_ids ) );
	}

	private static function queue_product_or_parent_update( int $product_id ): void {
		$post = $product_id > 0 ? get_post( $product_id ) : null;
		if ( $post instanceof WP_Post && 'product_variation' === $post->post_type ) {
			$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
			if ( $parent_id > 0 ) {
				self::queue_product_update( $parent_id );
			}
			return;
		}

		self::queue_product_update( $product_id );
	}

	private static function queue_product_update( int $product_id ): void {
		$post = $product_id > 0 ? get_post( $product_id ) : null;
		if ( ! $post instanceof WP_Post || ! ACS_Content_Extractor::is_indexable( $post ) ) {
			return;
		}

		update_post_meta( $product_id, '_acs_chatbot_indexed', '0' );
		ACS_Sync_Queue::enqueue( $product_id, 'product', 'upsert' );
	}

	/**
	 * Apply an AI-indexing preference changed outside the post editor.
	 *
	 * Enabling queues a fresh snapshot. Disabling replaces any pending upsert
	 * with an idempotent delete so stale content cannot remain in the index.
	 */
	public static function apply_indexing_preference( WP_Post $post, bool $enabled ): void {
		update_post_meta( $post->ID, '_acs_ai_index_enabled', $enabled ? '1' : '0' );
		update_post_meta( $post->ID, '_acs_chatbot_indexed', '0' );

		if ( $enabled && ACS_Content_Extractor::is_indexable( $post ) ) {
			ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'upsert' );
			return;
		}

		ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'delete' );
	}

	/** Queue a selected post for a fresh background sync. */
	public static function queue_post_for_reindex( WP_Post $post ): bool {
		if ( ! ACS_Content_Extractor::is_indexable( $post ) ) {
			return false;
		}

		update_post_meta( $post->ID, '_acs_chatbot_indexed', '0' );
		ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'upsert' );

		return true;
	}

	/**
	 * Process pending sync queue — called by WP-Cron.
	 * Can also be triggered manually (admin sync all button).
	 */
	public static function process_queue(): void {
		if ( ! self::is_configured() ) {
			return;
		}

		$client = self::make_client();
		$site_id = (int) get_option( 'acs_site_id', 0 );
		$jobs   = ACS_Sync_Queue::get_pending_batch();

		foreach ( $jobs as $job ) {
			$post = get_post( (int) $job->post_id );

			if ( $job->action === 'delete' || ! $post ) {
				$result = $client->delete_document( $job->post_id, 'wp_' . $job->post_type );
			} else {
				$data = ACS_Content_Extractor::extract( $post );

				if ( ! $data ) {
					update_post_meta( (int) $job->post_id, '_acs_chatbot_indexed', '0' );
					ACS_Sync_Queue::mark_done( (int) $job->id );
					continue;
				}

				$data['site_id'] = $site_id;
				if ( 'wp_product' === ( $data['source_type'] ?? '' ) ) {
					$metadata = is_array( $data['source_metadata'] ?? null ) ? $data['source_metadata'] : [];
					error_log( sprintf(
						'ACS product sync snapshot: product_id=%d variations=%d complete=%s payload_bytes=%d',
						(int) $job->post_id,
						(int) ( $metadata['variation_count'] ?? 0 ),
						false === ( $metadata['variations_complete'] ?? true ) ? 'no' : 'yes',
						strlen( (string) wp_json_encode( $data ) )
					) );
				}
				$result = $client->upsert_document( $data );
			}

			if ( $result['success'] ) {
				if ( $job->action === 'delete' || ! $post ) {
					update_post_meta( (int) $job->post_id, '_acs_chatbot_indexed', '0' );
				} else {
					update_post_meta( (int) $job->post_id, '_acs_chatbot_indexed', '1' );
					update_post_meta( (int) $job->post_id, '_acs_last_indexed_at', current_time( 'mysql' ) );
				}
				ACS_Sync_Queue::mark_done( (int) $job->id );
			} else {
				ACS_Sync_Queue::mark_failed( (int) $job->id, (int) $job->attempts );
				error_log( 'ACS sync failed for post ' . $job->post_id . ': ' . $result['message'] );
			}
		}
	}

	/**
	 * Enqueue all published posts/pages for sync (manual "Sync all").
	 */
	public static function enqueue_full_sync(): int {
		$enabled_types = ACS_Content_Extractor::get_enabled_post_types();
		$count         = 0;

		foreach ( $enabled_types as $post_type ) {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );

			foreach ( $posts as $post_id ) {
				$post = get_post( $post_id );
				if ( $post && ACS_Content_Extractor::is_indexable( $post ) ) {
					ACS_Sync_Queue::enqueue( (int) $post_id, $post->post_type, 'upsert' );
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Compare the complete local manifest with the backend and queue only the
	 * required deletes/upserts. Manual and API documents are outside this scope.
	 *
	 * @return array{success: bool, message: string, queued: int, to_delete: int, to_reindex: int, up_to_date: int}
	 */
	public static function reconcile_full_sync(): array {
		$manifest = self::build_full_sync_manifest();
		$result   = self::make_client()->full_sync( $manifest );

		if ( ! $result['success'] ) {
			return [
				'success'    => false,
				'message'    => $result['message'],
				'queued'     => 0,
				'to_delete'  => 0,
				'to_reindex' => 0,
				'up_to_date' => 0,
			];
		}

		$data       = is_array( $result['data'] ?? null ) ? $result['data'] : [];
		$to_delete  = is_array( $data['to_delete'] ?? null ) ? $data['to_delete'] : [];
		$to_reindex = is_array( $data['to_reindex'] ?? null ) ? $data['to_reindex'] : [];
		$queued     = 0;

		foreach ( $to_delete as $item ) {
			$source_id   = isset( $item['source_id'] ) ? (string) $item['source_id'] : '';
			$source_type = isset( $item['source_type'] ) ? (string) $item['source_type'] : '';
			if ( ! ctype_digit( $source_id ) || ! str_starts_with( $source_type, 'wp_' ) ) {
				continue;
			}

			ACS_Sync_Queue::enqueue( (int) $source_id, substr( $source_type, 3 ), 'delete' );
			$queued++;
		}

		foreach ( $to_reindex as $item ) {
			$source_id = isset( $item['source_id'] ) ? (string) $item['source_id'] : '';
			if ( ! ctype_digit( $source_id ) ) {
				continue;
			}

			$post = get_post( (int) $source_id );
			if ( ! $post instanceof WP_Post || ! ACS_Content_Extractor::is_indexable( $post ) ) {
				continue;
			}

			ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'upsert' );
			$queued++;
		}

		return [
			'success'    => true,
			'message'    => 'OK',
			'queued'     => $queued,
			'to_delete'  => count( $to_delete ),
			'to_reindex' => count( $to_reindex ),
			'up_to_date' => (int) ( $data['up_to_date'] ?? 0 ),
		];
	}

	/**
	 * @return array<int, array{source_type: string, source_id: string, hash: string}>
	 */
	private static function build_full_sync_manifest(): array {
		$manifest = [];

		foreach ( ACS_Content_Extractor::get_enabled_post_types() as $post_type ) {
			$page = 1;
			do {
				$post_ids = get_posts( [
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 250,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				] );

				foreach ( $post_ids as $post_id ) {
					$post = get_post( (int) $post_id );
					$data = $post instanceof WP_Post ? ACS_Content_Extractor::extract( $post ) : null;
					if ( $data === null ) {
						continue;
					}

					$manifest[] = [
						'source_type' => (string) $data['source_type'],
						'source_id'   => (string) $data['source_id'],
						'hash'        => (string) $data['hash'],
					];
				}

				$page++;
			} while ( count( $post_ids ) === 250 );
		}

		return $manifest;
	}

	/**
	 * Daily catch-up: queue enabled posts modified after their last successful index.
	 * Catches posts missed when the plugin was inactive or sync failed.
	 * Capped at 200 posts per run; the 5-min queue cron handles the rest.
	 */
	public static function catchup_sync(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_enabled
				ON pm_enabled.post_id = p.ID
				AND pm_enabled.meta_key = '_acs_ai_index_enabled'
				AND pm_enabled.meta_value = '1'
			LEFT JOIN {$wpdb->postmeta} pm_indexed
				ON pm_indexed.post_id = p.ID
				AND pm_indexed.meta_key = '_acs_last_indexed_at'
			WHERE p.post_status = 'publish'
			  AND (
			      pm_indexed.meta_value IS NULL
			      OR pm_indexed.meta_value = ''
			      OR p.post_modified_gmt > pm_indexed.meta_value
			  )
			LIMIT 200"
		);
		// phpcs:enable

		foreach ( $rows as $row ) {
			ACS_Sync_Queue::enqueue( (int) $row->ID, $row->post_type, 'upsert' );
		}
	}

	public static function is_configured(): bool {
		return ! empty( get_option( 'acs_api_key' ) )
			&& ! empty( get_option( 'acs_site_id' ) );
	}

	public static function make_client(): ACS_API_Client {
		return new ACS_API_Client(
			ACS_API_BASE_URL,
			(string) get_option( 'acs_api_key', '' )
		);
	}

	/**
	 * Manually index a single post immediately.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function index_post_now( WP_Post $post ): array {
		if ( ! self::is_configured() ) {
			return [
				'success' => false,
				'message' => __( 'Plugin is not fully configured.', 'ai-ischat' ),
			];
		}

		$data = ACS_Content_Extractor::extract( $post );
		if ( ! $data ) {
			return [
				'success' => false,
				'message' => __( 'This post is not eligible for AI indexing. Enable "Index in IsChat", use a supported post type, and keep the post published.', 'ai-ischat' ),
			];
		}

		$data['site_id'] = (int) get_option( 'acs_site_id', 0 );
		$result          = self::make_client()->upsert_document( $data );

		if ( ! $result['success'] ) {
			return $result;
		}

		update_post_meta( $post->ID, '_acs_chatbot_indexed', '1' );
		update_post_meta( $post->ID, '_acs_last_indexed_at', current_time( 'mysql' ) );

		return [
			'success' => true,
			'message' => __( 'Post indexed successfully.', 'ai-ischat' ),
		];
	}
}
