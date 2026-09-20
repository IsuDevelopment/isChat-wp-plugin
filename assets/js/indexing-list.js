( function () {
	'use strict';

	if ( typeof window.acsIndexingList !== 'object' ) {
		return;
	}

	const config = window.acsIndexingList;

	function setBusy( row, busy ) {
		row.classList.toggle( 'is-busy', busy );
		row.querySelectorAll( 'input, button' ).forEach( ( control ) => {
			control.disabled =
				busy ||
				( control.classList.contains( 'acs-force-sync' ) &&
					control.dataset.available === '0' );
		} );
		const spinner = row.querySelector( '.spinner' );
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		}
	}

	function messageFromError( error ) {
		return error && error.message
			? error.message
			: config.i18n.requestError;
	}

	async function request( row, operation, enabled ) {
		const body = new URLSearchParams( {
			action: 'acs_indexing_list_action',
			nonce: config.nonce,
			post_id: row.dataset.postId,
			operation,
		} );
		if ( typeof enabled === 'boolean' ) {
			body.set( 'enabled', enabled ? '1' : '0' );
		}

		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) {
			throw new Error(
				payload.data && payload.data.message
					? payload.data.message
					: config.i18n.requestError
			);
		}

		return payload.data;
	}

	function renderState( row, state, message ) {
		const toggle = row.querySelector( '.acs-indexing-toggle-input' );
		const toggleLabel = row.querySelector( '.acs-indexing-toggle' );
		const force = row.querySelector( '.acs-force-sync' );
		const status = row.querySelector( '.acs-indexing-status' );
		const date = row.querySelector( '.acs-indexing-date' );
		const feedback = row.querySelector( '.acs-indexing-feedback' );

		row.dataset.enabled = state.enabled ? '1' : '0';
		toggle.checked = Boolean( state.enabled );
		const toggleText = state.enabled
			? config.i18n.disable
			: config.i18n.enable;
		toggleLabel.title = toggleText;
		toggleLabel.querySelector( '.screen-reader-text' ).textContent =
			toggleText;
		force.classList.toggle( 'is-hidden', ! state.enabled );
		force.dataset.available = state.can_force_sync ? '1' : '0';
		force.disabled = ! state.can_force_sync;
		status.dataset.state = state.status;
		status.textContent = state.status_label;
		date.textContent = state.last_indexed_label;
		feedback.classList.add( 'screen-reader-text' );
		feedback.textContent = message || '';
	}

	async function perform( row, operation, enabled ) {
		setBusy( row, true );
		try {
			const data = await request( row, operation, enabled );
			renderState( row, data.state, data.message );
		} catch ( error ) {
			const feedback = row.querySelector( '.acs-indexing-feedback' );
			feedback.classList.remove( 'screen-reader-text' );
			feedback.textContent = messageFromError( error );
			if ( operation === 'toggle' ) {
				row.querySelector( '.acs-indexing-toggle-input' ).checked =
					! enabled;
			}
		} finally {
			setBusy( row, false );
		}
	}

	document.addEventListener( 'change', ( event ) => {
		if (
			! event.target.classList.contains( 'acs-indexing-toggle-input' )
		) {
			return;
		}
		const row = event.target.closest( '.acs-indexing-list-actions' );
		if ( row ) {
			perform( row, 'toggle', event.target.checked );
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.acs-force-sync' );
		if ( ! button || button.disabled ) {
			return;
		}
		const row = button.closest( '.acs-indexing-list-actions' );
		if ( row ) {
			perform( row, 'force_sync' );
		}
	} );
} )();
