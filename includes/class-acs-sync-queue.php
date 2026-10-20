<?php
/**
 * Local sync queue — stores pending sync jobs in a custom DB table.
 * Processed by WP-Cron in batches to avoid blocking admin.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_Sync_Queue {

	const TABLE_NAME  = 'acs_sync_queue';
	const BATCH_SIZE  = 20;
	const MAX_RETRIES = 3;

	/**
	 * Create the queue table on plugin activation.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action      VARCHAR(20)     NOT NULL,
			post_id     BIGINT UNSIGNED NOT NULL,
			post_type   VARCHAR(50)     NOT NULL DEFAULT '',
			attempts    TINYINT         NOT NULL DEFAULT 0,
			status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
			scheduled_at DATETIME       NOT NULL,
			created_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY status_scheduled (status, scheduled_at),
			KEY post_id (post_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add a job to the queue (upsert deduplication per post).
	 */
	public static function enqueue( int $post_id, string $post_type, string $action = 'upsert' ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// Keep identities separate when a post changes type: deleting the old
		// wp_page and inserting the new wp_post are two different operations.
		$wpdb->delete( $table, [
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'status'    => 'pending',
		], [ '%d', '%s', '%s' ] );

		$wpdb->insert( $table, [
			'action'       => $action,
			'post_id'      => $post_id,
			'post_type'    => $post_type,
			'attempts'     => 0,
			'status'       => 'pending',
			'scheduled_at' => current_time( 'mysql' ),
			'created_at'   => current_time( 'mysql' ),
		], [ '%s', '%d', '%s', '%d', '%s', '%s', '%s' ] );
	}

	/**
	 * Get next batch of pending jobs. Stock changes go first: a full sync of
	 * hundreds of products must not keep a sold-out one on offer meanwhile.
	 *
	 * @param bool $stock_only Return stock changes only.
	 * @return array<int, object>
	 */
	public static function get_pending_batch( bool $stock_only = false ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_NAME;
		$now    = current_time( 'mysql' );
		$filter = $stock_only ? "AND action = 'stock'" : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'pending'
				 AND scheduled_at <= %s
				 {$filter}
				 ORDER BY CASE WHEN action = 'stock' THEN 0 ELSE 1 END, id ASC
				 LIMIT %d",
				$now,
				self::BATCH_SIZE
			)
		);
	}

	/**
	 * Mark a job as done and remove it.
	 */
	public static function mark_done( int $id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE_NAME, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Mark a job as failed (increment attempts, reschedule or abandon).
	 */
	public static function mark_failed( int $id, int $attempts ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		if ( $attempts >= self::MAX_RETRIES ) {
			$wpdb->update( $table, [ 'status' => 'failed' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
			return;
		}

		// Exponential backoff: 5min, 30min, 2h
		$delays      = [ 300, 1800, 7200 ];
		$delay       = $delays[ $attempts ] ?? 7200;
		// Site-local time, like enqueue() and get_pending_batch(); gmdate()
		// here skewed the backoff by the site's UTC offset.
		$retry_after = wp_date( 'Y-m-d H:i:s', time() + $delay );

		$wpdb->update( $table, [
			'attempts'     => $attempts + 1,
			'scheduled_at' => $retry_after,
		], [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ] );
	}

	/**
	 * Put every abandoned job back in the queue with a clean slate.
	 *
	 * @return int Number of jobs re-queued.
	 */
	public static function retry_failed(): int {
		global $wpdb;

		return (int) $wpdb->update(
			$wpdb->prefix . self::TABLE_NAME,
			[
				'status'       => 'pending',
				'attempts'     => 0,
				'scheduled_at' => current_time( 'mysql' ),
			],
			[ 'status' => 'failed' ],
			[ '%s', '%d', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array{pending: int, failed: int}
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status" );

		$stats = [ 'pending' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			$stats[ $row->status ] = (int) $row->cnt;
		}

		return $stats;
	}
}
