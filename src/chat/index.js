/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: function Edit( { attributes, setAttributes } ) {
		const questions = Array.isArray( attributes.suggestedQuestions )
			? attributes.suggestedQuestions
			: [];
		const [ questionsDraft, setQuestionsDraft ] = useState(
			questions.join( '\n' )
		);
		const updateQuestions = ( value ) => {
			setQuestionsDraft( value );
			const normalized = value
				.split( /\r?\n/ )
				.map( ( question ) => question.trim().slice( 0, 160 ) )
				.filter( Boolean )
				.slice( 0, 5 );
			setAttributes( { suggestedQuestions: normalized } );
		};
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
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Example questions', 'ai-ischat' ) }
						initialOpen
					>
						<TextareaControl
							label={ __( 'Questions', 'ai-ischat' ) }
							help={ __(
								'One question per line, up to 5. Leave empty to use the questions configured for this site.',
								'ai-ischat'
							) }
							value={ questionsDraft }
							onChange={ updateQuestions }
							rows={ 6 }
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<span
						className="dashicons dashicons-format-chat"
						style={ {
							fontSize: '28px',
							color: '#2563eb',
							display: 'block',
							marginBottom: '8px',
						} }
					/>
					<strong style={ { display: 'block' } }>
						{ __( 'IsChat Chat', 'ai-ischat' ) }
					</strong>
					<p
						style={ {
							margin: '4px 0 12px',
							color: '#757575',
							fontSize: '12px',
						} }
					>
						{ __(
							'Chat widget will appear here on the frontend.',
							'ai-ischat'
						) }
					</p>
					{ questions.length > 0 && (
						<div
							style={ {
								display: 'flex',
								flexWrap: 'wrap',
								justifyContent: 'center',
								gap: '6px',
							} }
						>
							{ questions.map( ( question ) => (
								<span
									key={ question }
									style={ {
										padding: '6px 10px',
										borderRadius: '999px',
										background: '#eaf1fb',
										color: '#17365f',
										fontSize: '12px',
									} }
								>
									{ question }
								</span>
							) ) }
						</div>
					) }
				</div>
			</>
		);
	},

	save: () => null,
} );
