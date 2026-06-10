import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	ToggleControl,
	TextareaControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useState } from 'react';
import { __ } from '@wordpress/i18n';

function AcsIndexPanel() {
	const [ indexing, setIndexing ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ justIndexed, setJustIndexed ] = useState( false );

	const { editPost } = useDispatch( editorStore );

	const { meta, modified, postId, isSavingPost, postStatus, isDirty } = useSelect(
		( select ) => {
			const s = select( editorStore );
			return {
				meta:         s.getEditedPostAttribute( 'meta' ) || {},
				modified:     s.getEditedPostAttribute( 'modified' ),
				postId:       s.getCurrentPostId(),
				isSavingPost: s.isSavingPost(),
				postStatus:   s.getEditedPostAttribute( 'status' ),
				isDirty:      s.isEditedPostDirty(),
			};
		}
	);

	const isEnabled    = meta._acs_ai_index_enabled === '1';
	const isIndexed    = meta._acs_chatbot_indexed === '1';
	const lastIndexed  = meta._acs_last_indexed_at || '';
	const extraDesc    = meta._acs_extra_description || '';
	const isPublished  = postStatus === 'publish';

	const needsReindex =
		isIndexed &&
		lastIndexed &&
		modified &&
		new Date( modified ) > new Date( lastIndexed );

	const handleManualIndex = async () => {
		setIndexing( true );
		setError( null );
		setJustIndexed( false );
		try {
			const res = await apiFetch( {
				path:   `/acs/v1/index-post/${ postId }`,
				method: 'POST',
			} );
			editPost( {
				meta: {
					_acs_chatbot_indexed:  res.indexed,
					_acs_last_indexed_at:  res.last_indexed_at,
				},
			} );
			setJustIndexed( true );
		} catch ( e ) {
			setError( e?.message || __( 'Indexing failed. Check plugin configuration.', 'ai-ischat' ) );
		} finally {
			setIndexing( false );
		}
	};

	const formatDate = ( dateStr ) => {
		if ( ! dateStr ) return '';
		const d = new Date( dateStr );
		return isNaN( d ) ? dateStr : d.toLocaleString();
	};

	return (
		<PluginDocumentSettingPanel
			name="acs-index-panel"
			title={ __( 'AI Indexing', 'ai-ischat' ) }
		>
			<ToggleControl
				label={ __( 'Index in IsChat', 'ai-ischat' ) }
				checked={ isEnabled }
				onChange={ ( val ) =>
					editPost( {
						meta: { _acs_ai_index_enabled: val ? '1' : '0' },
					} )
				}
			/>

			{ isEnabled && (
				<>
					<TextareaControl
						label={ __( 'Extra AI description', 'ai-ischat' ) }
						help={ __(
							'Additional context for the AI — not visible on the page.',
							'ai-ischat'
						) }
						value={ extraDesc }
						onChange={ ( val ) =>
							editPost( {
								meta: { _acs_extra_description: val },
							} )
						}
						rows={ 3 }
					/>

					<div
						style={ {
							fontSize:     12,
							lineHeight:   1.6,
							marginBottom: 8,
							color:        '#757575',
						} }
					>
						<div>
							<strong>{ __( 'Indexed:', 'ai-ischat' ) }</strong>{ ' ' }
							{ isIndexed ? '✓' : '✕' }
						</div>
						{ lastIndexed && (
							<div>
								<strong>
									{ __( 'Last indexed:', 'ai-ischat' ) }
								</strong>{ ' ' }
								{ formatDate( lastIndexed ) }
							</div>
						) }
						{ modified && (
							<div>
								<strong>
									{ __( 'Last modified:', 'ai-ischat' ) }
								</strong>{ ' ' }
								{ formatDate( modified ) }
							</div>
						) }
					</div>

					{ needsReindex && ! justIndexed && (
						<Notice
							status="warning"
							isDismissible={ false }
							style={ { marginBottom: 8 } }
						>
							{ __(
								'Content changed since last index.',
								'ai-ischat'
							) }
						</Notice>
					) }

					{ justIndexed && (
						<Notice
							status="success"
							isDismissible={ false }
							style={ { marginBottom: 8 } }
						>
							{ __( 'Indexed successfully.', 'ai-ischat' ) }
						</Notice>
					) }

					{ error && (
						<Notice
							status="error"
							isDismissible={ true }
							onRemove={ () => setError( null ) }
							style={ { marginBottom: 8 } }
						>
							{ error }
						</Notice>
					) }

					<Button
						variant="primary"
						size="small"
						onClick={ handleManualIndex }
						disabled={ indexing || isSavingPost || ! isPublished || isDirty }
					>
						{ indexing ? (
							<>
								<Spinner />
								{ __( 'Indexing…', 'ai-ischat' ) }
							</>
						) : (
							__( 'Manual Index Now', 'ai-ischat' )
						) }
					</Button>

					{ isDirty && isPublished && (
						<p style={ { fontSize: 11, color: '#757575', marginTop: 4 } }>
							{ __( 'Save the post first to apply changes.', 'ai-ischat' ) }
						</p>
					) }

					{ ! isPublished && (
						<p style={ { fontSize: 11, color: '#757575', marginTop: 4 } }>
							{ __( 'Manual indexing is available for published posts only.', 'ai-ischat' ) }
						</p>
					) }
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'acs-index-panel', { render: AcsIndexPanel } );
