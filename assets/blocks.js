( function ( blocks, element, blockEditor, components ) {
	const h = element.createElement;
	const { useBlockProps, InspectorControls } = blockEditor;
	const { PanelBody, TextControl } = components;

	blocks.registerBlockType( 'ace-nudge/follow', {
		edit: () => h( 'div', useBlockProps( { className: 'ace-nudge-block' } ), h( 'button', { type: 'button', className: 'ace-nudge-follow', disabled: true }, 'Follow…' ) ),
		save: () => null,
	} );

	[ 'alerts-button', 'newsletter-button' ].forEach( ( name ) => {
		blocks.registerBlockType( 'ace-nudge/' + name, {
			edit: ( { attributes, setAttributes } ) => h( element.Fragment, null,
				h( InspectorControls, null, h( PanelBody, { title: 'Button' }, h( TextControl, { label: 'Label', value: attributes.label, onChange: ( label ) => setAttributes( { label } ), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ) ) ),
				h( 'div', useBlockProps( { className: 'ace-nudge-block' } ), h( 'button', { type: 'button', disabled: true }, attributes.label ) )
			),
			save: () => null,
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
