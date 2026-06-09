<?php
/**
 * Settings page view — IsChat.
 *
 * Rendered by ACS_Admin::render_settings_page().
 * Three tabs: Connection | Content | Knowledge Base.
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
			<p class="description" style="margin-bottom:1em">
				<?php
				printf(
					/* translators: %s: API base URL */
					esc_html__( 'Connecting to: %s', 'ai-ischat' ),
					'<code>' . esc_html( ACS_API_BASE_URL ) . '</code>'
				);
				?>
			</p>
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
							<?php esc_html_e( 'Secret API key — copy from the SaaS panel → Sites → your site → API Key.', 'ai-ischat' ); ?>
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
			</tbody>
		</table>

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
							<?php esc_html_e( 'When enabled, a <script> tag pointing to the widget will be added to wp_footer.', 'ai-ischat' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="acs_widget_public_key"><?php esc_html_e( 'Widget Public Key', 'ai-ischat' ); ?></label>
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
							<?php esc_html_e( 'The public site key from your SaaS dashboard (e.g. site_testkey123). Passed as data-site-id on the widget script tag.', 'ai-ischat' ); ?>
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
		testing:         <?php echo wp_json_encode( __( 'Testing\u2026',                           'ai-ischat' ) ); ?>,
		testBtn:         <?php echo wp_json_encode( __( 'Test Connection',                          'ai-ischat' ) ); ?>,
		syncing:         <?php echo wp_json_encode( __( 'Syncing\u2026',                            'ai-ischat' ) ); ?>,
		syncBtn:         <?php echo wp_json_encode( __( 'Sync All Content',                         'ai-ischat' ) ); ?>,
		saving:          <?php echo wp_json_encode( __( 'Saving\u2026',                             'ai-ischat' ) ); ?>,
		saveBtn:         <?php echo wp_json_encode( __( 'Save & Sync to Knowledge Base',            'ai-ischat' ) ); ?>,
		requestFailed:   <?php echo wp_json_encode( __( 'Request failed. Please try again.',        'ai-ischat' ) ); ?>,
		connectionFailed:<?php echo wp_json_encode( __( 'Connection failed.',                       'ai-ischat' ) ); ?>,
		syncFailed:      <?php echo wp_json_encode( __( 'Sync failed.',                             'ai-ischat' ) ); ?>,
		saveFailed:      <?php echo wp_json_encode( __( 'Save failed.',                             'ai-ischat' ) ); ?>,
		plan:            <?php echo wp_json_encode( __( 'Plan:',                                    'ai-ischat' ) ); ?>,
		documents:       <?php echo wp_json_encode( __( 'Documents:',                              'ai-ischat' ) ); ?>,
		syncsToday:      <?php echo wp_json_encode( __( 'Syncs today:',                            'ai-ischat' ) ); ?>
	};

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
				} else {
					showResult( $result, 'error', ( response.data && response.data.message ) ? response.data.message : i18n.syncFailed );
				}
			} )
			.fail( function () {
				showResult( $result, 'error', i18n.requestFailed );
			} )
			.always( function () {
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
