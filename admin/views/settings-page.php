<?php
/**
 * Settings page view — IsChat.
 *
 * Rendered by ACS_Admin::render_settings_page().
 * Three tabs: Connection | Content | Knowledge Base.
 * All credentials (API key, site ID, site key) live on the Connection tab.
 *
 * @package AI_Chatbot_SaaS
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Determine active tab (defaults to 'connection').
$acs_current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$acs_valid_tabs  = [ 'connection', 'content', 'knowledge-base' ];
if ( ! in_array( $acs_current_tab, $acs_valid_tabs, true ) ) {
	$acs_current_tab = 'connection';
}

// Queue stats and configuration state.
$acs_stats         = ACS_Sync_Queue::get_stats();
$acs_is_configured = ACS_Sync_Manager::is_configured();

// Public post types for the Content tab.
$acs_public_types    = get_post_types( [ 'public' => true ], 'objects' );
$acs_saved_pt        = (array) get_option( 'acs_post_types', [ 'post', 'page' ] );
$acs_widget_enabled  = '1' === get_option( 'acs_widget_enabled', '0' );

// Helper: build tab URL.
$acs_tab_url = function ( string $tab ) : string {
	return esc_url( add_query_arg( [
		'page' => 'ai-ischat',
		'tab'  => $tab,
	], admin_url( 'options-general.php' ) ) );
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'IsChat', 'ai-ischat' ); ?></h1>

	<?php settings_errors( 'acs_messages' ); ?>

	<?php if ( $acs_stats['pending'] > 0 || $acs_stats['failed'] > 0 ) : ?>
	<div class="notice notice-info is-dismissible">
		<p>
		<?php
		printf(
			/* translators: %1$d: pending jobs, %2$d: failed jobs */
			esc_html__( 'Sync queue: %1$d pending, %2$d failed.', 'ai-ischat' ),
			(int) $acs_stats['pending'],
			(int) $acs_stats['failed']
		);
		?>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( ! $acs_is_configured ) : ?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Plugin is not fully configured. Please fill in the API Key and Site ID on the Connection tab.', 'ai-ischat' ); ?></p>
	</div>
	<?php endif; ?>

	<!-- Tab navigation -->
	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-ischat' ); ?>">
		<a href="<?php echo $acs_tab_url( 'connection' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
		   class="nav-tab<?php echo 'connection' === $acs_current_tab ? ' nav-tab-active' : ''; ?>"
		   <?php echo 'connection' === $acs_current_tab ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Connection', 'ai-ischat' ); ?>
		</a>
		<a href="<?php echo $acs_tab_url( 'content' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
		   class="nav-tab<?php echo 'content' === $acs_current_tab ? ' nav-tab-active' : ''; ?>"
		   <?php echo 'content' === $acs_current_tab ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Content', 'ai-ischat' ); ?>
		</a>
		<a href="<?php echo $acs_tab_url( 'knowledge-base' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
		   class="nav-tab<?php echo 'knowledge-base' === $acs_current_tab ? ' nav-tab-active' : ''; ?>"
		   <?php echo 'knowledge-base' === $acs_current_tab ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Knowledge Base', 'ai-ischat' ); ?>
		</a>
	</nav>

	<!-- ===================================================================== -->
	<!-- TAB: Connection                                                        -->
	<!-- ===================================================================== -->
	<?php if ( 'connection' === $acs_current_tab ) : ?>
	<div class="tab-content" id="acs-tab-connection">

		<form method="post" action="options.php">
			<?php settings_fields( 'acs_connection_group' ); ?>
			<h2 class="title"><?php esc_html_e( 'API Connection', 'ai-ischat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="acs_api_key"><?php esc_html_e( 'API Key', 'ai-ischat' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="acs_api_key"
							name="acs_api_key"
							value="<?php echo esc_attr( (string) get_option( 'acs_api_key', '' ) ); ?>"
							class="regular-text"
							autocomplete="new-password"
						/>
						<p class="description">
							<?php esc_html_e( 'Secret API key — copy it from the IsChat panel → Sites → your site → API Key.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="acs_site_id"><?php esc_html_e( 'Site ID', 'ai-ischat' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="acs_site_id"
							name="acs_site_id"
							value="<?php echo esc_attr( (string) get_option( 'acs_site_id', '' ) ); ?>"
							class="small-text"
							min="1"
							step="1"
						/>
						<p class="description">
							<?php esc_html_e( 'Numeric site ID from the IsChat dashboard.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="acs_widget_public_key"><?php esc_html_e( 'Site Key', 'ai-ischat' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="acs_widget_public_key"
							name="acs_widget_public_key"
							value="<?php echo esc_attr( (string) get_option( 'acs_widget_public_key', '' ) ); ?>"
							class="regular-text"
							placeholder="site_testkey123"
						/>
						<p class="description">
							<?php esc_html_e( 'Public site key from the IsChat panel (e.g. site_testkey123). Used by the chat widget and the IsChat blocks.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Connection Settings', 'ai-ischat' ) ); ?>
		</form>

		<hr />

		<!-- Actions: Test Connection + Sync All -->
		<h2 class="title"><?php esc_html_e( 'Actions', 'ai-ischat' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Save your settings first, then use these actions to verify the connection or push all content to the knowledge base.', 'ai-ischat' ); ?>
		</p>
		<p style="margin-top: 1em;">
			<button type="button" id="acs-test-connection" class="button button-secondary" <?php disabled( ! $acs_is_configured ); ?>>
				<?php esc_html_e( 'Test Connection', 'ai-ischat' ); ?>
			</button>
			&nbsp;
			<button type="button" id="acs-sync-all" class="button button-primary" <?php disabled( ! $acs_is_configured ); ?>>
				<?php esc_html_e( 'Sync All Content', 'ai-ischat' ); ?>
			</button>
		</p>
		<div id="acs-action-result" style="margin-top: 12px;" aria-live="polite"></div>

		<hr />

		<!-- Queue status -->
		<h2 class="title"><?php esc_html_e( 'Queue Status', 'ai-ischat' ); ?></h2>
		<table class="widefat striped" style="max-width: 400px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Pending jobs', 'ai-ischat' ); ?></td>
					<td><strong><?php echo (int) $acs_stats['pending']; ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Failed jobs', 'ai-ischat' ); ?></td>
					<td>
						<strong style="color: <?php echo $acs_stats['failed'] > 0 ? '#d63638' : 'inherit'; ?>;">
							<?php echo (int) $acs_stats['failed']; ?>
						</strong>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Next cron run', 'ai-ischat' ); ?></td>
					<td>
						<?php
						$acs_next = wp_next_scheduled( ACS_Sync_Manager::CRON_HOOK );
						if ( $acs_next ) {
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time until next cron run */
									__( 'in %s', 'ai-ischat' ),
									human_time_diff( time(), $acs_next )
								)
							);
						} else {
							esc_html_e( 'Not scheduled', 'ai-ischat' );
						}
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Last queue run', 'ai-ischat' ); ?></td>
					<td>
						<?php
						$acs_last_run = get_option( 'acs_queue_last_run', [] );
						if ( is_array( $acs_last_run ) && ! empty( $acs_last_run['time'] ) ) {
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time since the last queue run */
									__( '%s ago', 'ai-ischat' ),
									human_time_diff( (int) $acs_last_run['time'], time() )
								)
							);
						} else {
							esc_html_e( 'Never', 'ai-ischat' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( $acs_stats['pending'] > 0 && ACS_Sync_Manager::cron_looks_stalled() ) : ?>
		<div class="notice notice-warning inline" style="margin-top: 12px;">
			<p><?php esc_html_e( 'WP-Cron does not seem to run on this site, so the queue will not empty on its own. Use "Process queue now" and keep this page open until it finishes, or ask your host to set up a system cron for wp-cron.php.', 'ai-ischat' ); ?></p>
		</div>
		<?php endif; ?>

		<p style="margin-top: 1em;">
			<button type="button" id="acs-process-queue" class="button button-secondary" <?php disabled( ! $acs_is_configured || 0 === (int) $acs_stats['pending'] ); ?>>
				<?php esc_html_e( 'Process queue now', 'ai-ischat' ); ?>
			</button>
			&nbsp;
			<button type="button" id="acs-retry-failed" class="button button-secondary" <?php disabled( 0 === (int) $acs_stats['failed'] ); ?>>
				<?php esc_html_e( 'Retry failed jobs', 'ai-ischat' ); ?>
			</button>
		</p>
		<div id="acs-queue-result" style="margin-top: 12px;" aria-live="polite"></div>

	</div><!-- #acs-tab-connection -->
	<?php endif; ?>

	<!-- ===================================================================== -->
	<!-- TAB: Content                                                           -->
	<!-- ===================================================================== -->
	<?php if ( 'content' === $acs_current_tab ) : ?>
	<div class="tab-content" id="acs-tab-content">

		<form method="post" action="options.php">
			<?php settings_fields( 'acs_content_group' ); ?>
			<h2 class="title"><?php esc_html_e( 'Indexing', 'ai-ischat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types to Index', 'ai-ischat' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Post Types to Index', 'ai-ischat' ); ?></span>
							</legend>
							<?php foreach ( $acs_public_types as $acs_pt ) : ?>
							<label style="display: block; margin-bottom: 4px;">
								<input
									type="checkbox"
									name="acs_post_types[]"
									value="<?php echo esc_attr( $acs_pt->name ); ?>"
									<?php checked( in_array( $acs_pt->name, $acs_saved_pt, true ) ); ?>
								/>
								<?php echo esc_html( $acs_pt->label ); ?>
								<code style="color: #666; font-size: 12px;">(<?php echo esc_html( $acs_pt->name ); ?>)</code>
							</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Selected post types become eligible for AI indexing, but each post still requires explicit "Index in IsChat" opt-in and a manual indexing action.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Chat Widget', 'ai-ischat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="acs_widget_enabled"><?php esc_html_e( 'Enable Chat Widget', 'ai-ischat' ); ?></label>
					</th>
					<td>
						<label>
							<input
								type="checkbox"
								id="acs_widget_enabled"
								name="acs_widget_enabled"
								value="1"
								<?php checked( $acs_widget_enabled ); ?>
							/>
							<?php esc_html_e( 'Inject the chat widget script on all public-facing pages', 'ai-ischat' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Requires the Site Key on the Connection tab.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Content Settings', 'ai-ischat' ) ); ?>
		</form>

	</div><!-- #acs-tab-content -->
	<?php endif; ?>

	<!-- ===================================================================== -->
	<!-- TAB: Knowledge Base                                                    -->
	<!-- ===================================================================== -->
	<?php if ( 'knowledge-base' === $acs_current_tab ) : ?>
	<div class="tab-content" id="acs-tab-kb">

		<h2 class="title"><?php esc_html_e( 'Manual Knowledge Base', 'ai-ischat' ); ?></h2>
		<p>
			<?php esc_html_e( 'Add custom knowledge to your chatbot — FAQs, company facts, product details, or any text you want the AI to know about. This content is stored as a single document in the knowledge base.', 'ai-ischat' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="acs_manual_kb"><?php esc_html_e( 'Knowledge Base Content', 'ai-ischat' ); ?></label>
				</th>
				<td>
					<textarea
						id="acs_manual_kb"
						name="acs_manual_kb"
						rows="18"
						class="large-text code"
						style="font-family: monospace; white-space: pre-wrap;"
					><?php echo esc_textarea( (string) get_option( 'acs_manual_kb', '' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Plain text only. Write clearly — the AI will use this verbatim. Each save replaces the previous content.', 'ai-ischat' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" id="acs-save-kb" class="button button-primary">
				<?php esc_html_e( 'Save &amp; Sync to Knowledge Base', 'ai-ischat' ); ?>
			</button>
		</p>
		<div id="acs-kb-result" style="margin-top: 12px;" aria-live="polite"></div>

	</div><!-- #acs-tab-kb -->
	<?php endif; ?>

</div><!-- .wrap -->

<script type="text/javascript">
/* global jQuery */
( function ( $ ) {
	'use strict';

	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'acs_admin_nonce' ) ); ?>;

	var i18n = {
		testing:         <?php echo wp_json_encode( __( 'Testing…',                           'ai-ischat' ) ); ?>,
		testBtn:         <?php echo wp_json_encode( __( 'Test Connection',                          'ai-ischat' ) ); ?>,
		syncing:         <?php echo wp_json_encode( __( 'Syncing…',                            'ai-ischat' ) ); ?>,
		syncBtn:         <?php echo wp_json_encode( __( 'Sync All Content',                         'ai-ischat' ) ); ?>,
		saving:          <?php echo wp_json_encode( __( 'Saving…',                             'ai-ischat' ) ); ?>,
		saveBtn:         <?php echo wp_json_encode( __( 'Save & Sync to Knowledge Base',            'ai-ischat' ) ); ?>,
		requestFailed:   <?php echo wp_json_encode( __( 'Request failed. Please try again.',        'ai-ischat' ) ); ?>,
		connectionFailed:<?php echo wp_json_encode( __( 'Connection failed.',                       'ai-ischat' ) ); ?>,
		syncFailed:      <?php echo wp_json_encode( __( 'Sync failed.',                             'ai-ischat' ) ); ?>,
		saveFailed:      <?php echo wp_json_encode( __( 'Save failed.',                             'ai-ischat' ) ); ?>,
		plan:            <?php echo wp_json_encode( __( 'Plan:',                                    'ai-ischat' ) ); ?>,
		documents:       <?php echo wp_json_encode( __( 'Documents:',                              'ai-ischat' ) ); ?>,
		syncsToday:      <?php echo wp_json_encode( __( 'Syncs today:',                            'ai-ischat' ) ); ?>,
		processing:      <?php echo wp_json_encode( __( 'Processing…',                              'ai-ischat' ) ); ?>,
		processBtn:      <?php echo wp_json_encode( __( 'Process queue now',                        'ai-ischat' ) ); ?>,
		/* translators: %1$d: items sent so far, %2$d: items still pending, %3$d: failed items */
		progress:        <?php echo wp_json_encode( __( 'Sent %1$d, %2$d left, %3$d failed. Keep this page open…', 'ai-ischat' ) ); ?>,
		/* translators: %1$d: items sent, %2$d: failed items */
		queueDone:       <?php echo wp_json_encode( __( 'Done: %1$d sent, %2$d failed.',            'ai-ischat' ) ); ?>,
		/* translators: %1$d: items sent, %2$d: items waiting for a retry */
		queueWaiting:    <?php echo wp_json_encode( __( 'Sent %1$d. %2$d items wait for an automatic retry after an error.', 'ai-ischat' ) ); ?>,
		/* translators: %d: number of jobs put back into the queue */
		requeued:        <?php echo wp_json_encode( __( '%d failed jobs put back in the queue.',    'ai-ischat' ) ); ?>
	};

	/** Fill %d / %1$d placeholders of a translated string. */
	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return template.replace( /%(\d)\$d|%d/g, function ( match, position ) {
			return String( position ? args[ position - 1 ] : args[ next++ ] );
		} );
	}

	/**
	 * Drain the queue batch after batch from the browser, so syncing does not
	 * depend on WP-Cron. Stops when nothing is left, or when a batch makes no
	 * progress (what remains is waiting out a retry backoff).
	 */
	function drainQueue( $result, onDone ) {
		var sent = 0;
		var $btn = $( '#acs-process-queue' ).prop( 'disabled', true ).text( i18n.processing );

		function finish() {
			$btn.prop( 'disabled', false ).text( i18n.processBtn );
			if ( onDone ) {
				onDone();
			}
		}

		function step() {
			$.post( ajaxUrl, { action: 'acs_process_queue', nonce: nonce } )
				.done( function ( response ) {
					if ( ! response.success ) {
						showResult( $result, 'error', ( response.data && response.data.message ) ? response.data.message : i18n.syncFailed );
						finish();
						return;
					}
					var data = response.data;
					sent += data.processed;
					if ( data.pending > 0 && ( data.processed > 0 || data.failed_now > 0 ) ) {
						showResult( $result, 'success', format( i18n.progress, sent, data.pending, data.failed ) );
						step();
						return;
					}
					showResult(
						$result,
						data.failed > 0 ? 'error' : 'success',
						data.pending > 0 ? format( i18n.queueWaiting, sent, data.pending ) : format( i18n.queueDone, sent, data.failed )
					);
					finish();
				} )
				.fail( function () {
					showResult( $result, 'error', i18n.requestFailed );
					finish();
				} );
		}

		step();
	}

	$( '#acs-process-queue' ).on( 'click', function () {
		drainQueue( $( '#acs-queue-result' ) );
	} );

	$( '#acs-retry-failed' ).on( 'click', function () {
		var $btn    = $( this ).prop( 'disabled', true );
		var $result = $( '#acs-queue-result' );

		$.post( ajaxUrl, { action: 'acs_retry_failed', nonce: nonce } )
			.done( function ( response ) {
				if ( response.success ) {
					showResult( $result, 'success', format( i18n.requeued, response.data.requeued ) );
					drainQueue( $result );
				} else {
					showResult( $result, 'error', i18n.requestFailed );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				showResult( $result, 'error', i18n.requestFailed );
				$btn.prop( 'disabled', false );
			} );
	} );

	/**
	 * Render an inline WordPress-style notice inside $container.
	 *
	 * @param {jQuery} $container  Target element.
	 * @param {string} type        'success' | 'error'
	 * @param {string} message     Plain-text message (will be HTML-escaped).
	 */
	function showResult( $container, type, message ) {
		var cls = ( 'success' === type ) ? 'notice-success' : 'notice-error';
		// Escape message by letting the browser handle it via text node.
		var $p = $( '<p>' ).text( message );
		$container.html( $( '<div>' ).addClass( 'notice ' + cls + ' inline' ).append( $p ) );
	}

	// -------------------------------------------------------------------------
	// Test Connection
	// -------------------------------------------------------------------------
	$( '#acs-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#acs-action-result' );

		$btn.prop( 'disabled', true ).text( i18n.testing );

		$.post( ajaxUrl, { action: 'acs_test_connection', nonce: nonce } )
			.done( function ( response ) {
				if ( response.success ) {
					var apiData = ( response.data && response.data.data ) ? response.data.data : {};
					var usage   = apiData.usage   || {};
					var limits  = apiData.limits  || {};
					var plan    = apiData.plan     || '';

					var parts = [ response.data.message ];

					if ( plan ) {
						parts.push( i18n.plan + ' ' + plan + '.' );
					}
					if ( undefined !== usage.documents && undefined !== limits.max_documents ) {
						parts.push( i18n.documents + ' ' + usage.documents + ' / ' + limits.max_documents + '.' );
					}
					if ( undefined !== usage.syncs_today && undefined !== limits.max_syncs_per_day ) {
						parts.push( i18n.syncsToday + ' ' + usage.syncs_today + ' / ' + limits.max_syncs_per_day + '.' );
					}

					showResult( $result, 'success', parts.join( ' ' ) );
				} else {
					showResult( $result, 'error', ( response.data && response.data.message ) ? response.data.message : i18n.connectionFailed );
				}
			} )
			.fail( function () {
				showResult( $result, 'error', i18n.requestFailed );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( i18n.testBtn );
			} );
	} );

	// -------------------------------------------------------------------------
	// Sync All Content
	// -------------------------------------------------------------------------
	$( '#acs-sync-all' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#acs-action-result' );

		if ( ! window.confirm( <?php echo wp_json_encode( __( 'This will queue all published content for re-sync. Continue?', 'ai-ischat' ) ); ?> ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.syncing );

		$.post( ajaxUrl, { action: 'acs_sync_all', nonce: nonce } )
			.done( function ( response ) {
				if ( response.success ) {
					showResult( $result, 'success', response.data.message );
					if ( response.data.pending > 0 ) {
						drainQueue( $result, function () {
							$btn.prop( 'disabled', false ).text( i18n.syncBtn );
						} );
						return;
					}
				} else {
					showResult( $result, 'error', ( response.data && response.data.message ) ? response.data.message : i18n.syncFailed );
				}
				$btn.prop( 'disabled', false ).text( i18n.syncBtn );
			} )
			.fail( function () {
				showResult( $result, 'error', i18n.requestFailed );
				$btn.prop( 'disabled', false ).text( i18n.syncBtn );
			} );
	} );

	// -------------------------------------------------------------------------
	// Save & Sync Manual Knowledge Base
	// -------------------------------------------------------------------------
	$( '#acs-save-kb' ).on( 'click', function () {
		var $btn     = $( this );
		var $result  = $( '#acs-kb-result' );
		var kbContent = $( '#acs_manual_kb' ).val();

		$btn.prop( 'disabled', true ).text( i18n.saving );

		$.post( ajaxUrl, {
			action:        'acs_save_manual_kb',
			nonce:         nonce,
			acs_manual_kb: kbContent
		} )
			.done( function ( response ) {
				if ( response.success ) {
					showResult( $result, 'success', response.data.message );
				} else {
					showResult( $result, 'error', ( response.data && response.data.message ) ? response.data.message : i18n.saveFailed );
				}
			} )
			.fail( function () {
				showResult( $result, 'error', i18n.requestFailed );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( i18n.saveBtn );
			} );
	} );

}( jQuery ) );
</script>
