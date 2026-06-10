( function ( blocks, element, blockEditor, i18n ) {
	const el = element.createElement;
	const useBlockProps = blockEditor.useBlockProps;
	const __ = i18n.__;

	blocks.registerBlockType( 'ischat/chat', {
		title: __( 'IsChat Chat', 'ai-ischat' ),
		description: __( 'Inline chat window powered by IsChat. Visitors can chat directly on the page.', 'ai-ischat' ),
		icon: 'format-chat',
		category: 'widgets',
		supports: {
			html: false,
		},
		attributes: {
			height: {
				type: 'string',
				default: '500px',
			},
		},
		edit: function () {
			const blockProps = useBlockProps( {
				style: {
					border: '2px dashed #c3c4c7',
					borderRadius: '4px',
					padding: '24px 20px',
					textAlign: 'center',
					background: '#f6f7f7',
				},
			} );

			return el(
				'div',
				blockProps,
				el( 'span', {
					className: 'dashicons dashicons-format-chat',
					style: { fontSize: '28px', color: '#2563eb', display: 'block', marginBottom: '8px' },
				} ),
				el( 'strong', { style: { display: 'block', color: '#1d2327' } }, __( 'IsChat Chat', 'ai-ischat' ) ),
				el( 'p', {
					style: { margin: '4px 0 0', color: '#757575', fontSize: '12px' },
				}, __( 'Chat widget will appear here on the frontend.', 'ai-ischat' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
