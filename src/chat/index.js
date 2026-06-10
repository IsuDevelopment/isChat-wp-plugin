import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps( {
			style: {
				border: '2px dashed #c3c4c7',
				borderRadius: '4px',
				padding: '24px 20px',
				textAlign: 'center',
				background: '#f6f7f7',
				color: '#1d2327',
			},
		} );

		return (
			<div { ...blockProps }>
				<span
					className="dashicons dashicons-format-chat"
					style={ { fontSize: '28px', color: '#2563eb', display: 'block', marginBottom: '8px' } }
				/>
				<strong style={ { display: 'block' } }>
					{ __( 'IsChat Chat', 'ai-ischat' ) }
				</strong>
				<p style={ { margin: '4px 0 0', color: '#757575', fontSize: '12px' } }>
					{ __( 'Chat widget will appear here on the frontend.', 'ai-ischat' ) }
				</p>
			</div>
		);
	},

	save: () => null,
} );
