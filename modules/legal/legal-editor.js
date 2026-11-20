/* The legal document panel (modules/legal): type, effective from, last reviewed and version, in the document sidebar. */
( function ( wp ) {
	var el = wp.element.createElement;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! Panel ) {
		return;
	}

	function LegalPanel() {
		var postType = wp.data.select( 'core/editor' ).getCurrentPostType();
		var meta = wp.coreData.useEntityProp( 'postType', postType, 'meta' );
		var values = meta[ 0 ] || {};
		var setMeta = meta[ 1 ];
		var types = window.pkdeLegalTypes || {};
		var set = function ( key ) {
			return function ( value ) {
				var next = {};
				next[ key ] = value;
				setMeta( Object.assign( {}, values, next ) );
			};
		};

		return el(
			Panel,
			{ name: 'pkde-legal', title: 'Document details', className: 'pkde-legal-panel' },
			el( wp.components.SelectControl, {
				label: 'Document type',
				value: values._pkde_legal_type || 'other',
				options: Object.keys( types ).map( function ( key ) {
					return { value: key, label: types[ key ] };
				} ),
				onChange: set( '_pkde_legal_type' ),
				help: 'The Legal menu lists documents in this order.',
			} ),
			el( wp.components.TextControl, { label: 'Effective from', type: 'date', value: values._pkde_legal_effective || '', onChange: set( '_pkde_legal_effective' ) } ),
			el( wp.components.TextControl, { label: 'Last reviewed', type: 'date', value: values._pkde_legal_reviewed || '', onChange: set( '_pkde_legal_reviewed' ) } ),
			el( wp.components.TextControl, { label: 'Version', value: values._pkde_legal_version || '', onChange: set( '_pkde_legal_version' ), help: 'For example 1.3.' } )
		);
	}

	wp.plugins.registerPlugin( 'pkde-legal', { render: LegalPanel } );
}( window.wp ) );
