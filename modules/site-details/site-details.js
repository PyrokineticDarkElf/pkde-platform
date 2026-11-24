/* The Site details screen (modules/site-details): add and remove rows, and drag the social profiles into order. Plain JS, no build step. */
( function ( $ ) {
	var renumber = function ( $list ) {
		$list.children( '[data-row]' ).each( function ( i ) {
			$( this ).find( '[name]' ).each( function () {
				// the row number is the last [n] before the field name: details[phones][2][number]
				this.name = this.name.replace( /\[\d+\](\[[a-z_]+\])$/, '[' + i + ']$1' );
			} );
		} );
	};

	$( document ).on( 'click', '[data-add]', function () {
		var $list = $( '[data-list="' + $( this ).data( 'add' ) + '"]' );
		var $row = $list.children( '[data-row]' ).last().clone();
		$row.find( 'input' ).val( '' );
		$row.find( 'select' ).prop( 'selectedIndex', 0 );
		$list.append( $row );
		renumber( $list );
		$row.find( 'input, select' ).first().trigger( 'focus' );
	} );

	$( document ).on( 'click', '[data-remove]', function () {
		var $list = $( this ).closest( '[data-list]' );
		if ( $list.children( '[data-row]' ).length > 1 ) {
			$( this ).closest( '[data-row]' ).remove();
		} else {
			$( this ).closest( '[data-row]' ).find( 'input' ).val( '' );
		}
		renumber( $list );
	} );

	$( '[data-sortable]' ).sortable( { handle: '.pkde-sd-handle', items: '> [data-row]', update: function () { renumber( $( this ) ); } } );
}( window.jQuery ) );
