/* The FAQ answer editor (modules/faq): paragraphs, lists, h3/h4 headings, and bold, italic and links; nothing else. */
( function ( wp ) {
	var removable = [ 'core/code', 'core/image', 'core/keyboard', 'core/language', 'core/strikethrough', 'core/subscript', 'core/superscript', 'core/text-color', 'core/underline', 'core/footnote' ];

	wp.domReady( function () {
		removable.forEach( function ( name ) {
			if ( wp.richText.getFormatType( name ) ) {
				wp.richText.unregisterFormatType( name );
			}
		} );
	} );

	// h3 and h4 only (the question is the title, the answer sits under it)
	wp.hooks.addFilter( 'editor.BlockEdit', 'pkde/faq-heading-levels', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( props.name !== 'core/heading' ) {
				return wp.element.createElement( BlockEdit, props );
			}

			return wp.element.createElement( BlockEdit, Object.assign( {}, props, { attributes: Object.assign( {}, props.attributes, { levelOptions: [ 3, 4 ] } ) } ) );
		};
	}, 'withFaqHeadingLevels' ) );
}( window.wp ) );
