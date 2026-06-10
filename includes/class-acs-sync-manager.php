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

		if ( in_array( $new_status, $deindex_statuses, true ) && $old_status === 'publish' ) {
			ACS_Sync_Queue::enqueue( $post->ID, $post->post_type, 'delete' );
			update_post_meta( $post->ID, '_acs_chatbot_indexed', '0' );
		}
	}

	/**
	 * Remove from index when post is permanently deleted.
	 */
	public static function on_deleted_post( int $post_id, WP_Post $post ): void {
		ACS_Sync_Queue::enqueue( $post_id, $post->post_type, 'delete' );
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
