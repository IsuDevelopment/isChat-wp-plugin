( function ( blocks, element, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;

	blocks.registerBlockType( 'ischat/search', {
		title: __( 'IsChat Search', 'ai-ischat' ),
		description: __( 'Inline contextual AI search block powered by IsChat.', 'ai-ischat' ),
		icon: 'search',
		category: 'widgets',
		supports: {
			html: false,
		},
		edit: function () {
			return el(
				'div',
				{
					className: 'acs-search-block-preview',
					style: {
						border: '1px solid #d0d7de',
						borderRadius: '12px',
						padding: '18px',
						background: '#fff',
					},
				},
				el(
					'strong',
					{ style: { display: 'block', marginBottom: '8px' } },
					__( 'IsChat Search', 'ai-ischat' )
				),
				el(
					'p',
					{ style: { margin: 0, color: '#667085' } },
					__( 'Visitors will see an inline AI search box here. Configure Site ID in IsChat plugin settings.', 'ai-ischat' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n );
