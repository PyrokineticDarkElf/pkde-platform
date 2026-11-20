/* Drag order of the FAQs in one group (modules/faq): the rows of the FAQs list, filtered by that group, are sortable; the new order is saved on the group. */
jQuery( function ( $ ) {
	var list = $( '#the-list' );
	var settings = window.pkdeFaqOrder;

	if ( ! list.length || ! settings ) {
		return;
	}

	var notice = $( '<span style="margin-left:1em;color:#1d2327"></span>' ).insertAfter( $( '.wp-heading-inline, h1' ).first() );

	list.sortable( {
		items: 'tr',
		handle: '.pkde-faq-handle',
		axis: 'y',
		helper: function ( event, row ) {
			row.children().each( function () {
				$( this ).width( $( this ).width() );
			} );
			return row;
		},
		update: function () {
			var ids = list.find( 'tr' ).map( function () {
				return ( this.id || '' ).replace( 'post-', '' );
			} ).get().filter( Boolean );

			notice.text( 'Saving the order…' );
			$.post( window.ajaxurl, { action: 'pkde_faq_reorder', nonce: settings.nonce, group: settings.group, ids: ids } )
				.done( function () {
					notice.text( 'Order saved.' );
				} )
				.fail( function () {
					notice.text( 'The order could not be saved. Reload the page and try again.' );
				} );
		},
	} );
} );
