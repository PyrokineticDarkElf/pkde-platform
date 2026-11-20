// Editors cannot move, duplicate or remove a Section (WP-100, platform editor-settings; loaded for users who are not Administrators only).
// A Section is the kit's sage/section and any block that carries a Brand section theme (class is-style-brand-*). Its CONTENT stays editable: the block `lock`
// ({ move, remove }) is applied to the block itself, never templateLock "all", which would also stop the writing inside it. Duplicate, Copy, Cut, Group and
// Ungroup are taken out of the block's options menu. An Administrator keeps every control and can unlock (the lock UI is theirs).
( function ( wp ) {
	const { select, dispatch, subscribe } = wp.data;
	const { __ } = wp.i18n;
	const isSection = ( block ) => block && ( block.name === 'sage/section' || /(?:^|\s)is-style-brand-/.test( ( block.attributes && block.attributes.className ) || '' ) );
	const locked = new Set();

	const lockSections = () => {
		const store = select( 'core/block-editor' );

		if ( ! store ) {
			return;
		}

		const walk = ( blocks ) => blocks.forEach( ( block ) => {
			const lock = block.attributes && block.attributes.lock;

			if ( isSection( block ) && ! ( lock && lock.move && lock.remove ) ) {
				// not persistent as a user change (no undo step, the post is not marked changed by it); the lock is in the block when the post is saved
				if ( dispatch( 'core/block-editor' ).__unstableMarkNextChangeAsNotPersistent ) {
					dispatch( 'core/block-editor' ).__unstableMarkNextChangeAsNotPersistent();
				}
				dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { lock: { move: true, remove: true } } );
				locked.add( block.clientId );
			}

			walk( block.innerBlocks || [] );
		} );

		walk( store.getBlocks() );
	};

	let busy = false;
	subscribe( () => {
		if ( busy ) {
			return;
		}
		busy = true;
		try {
			lockSections();
		} finally {
			busy = false;
		}
	} );

	// the options menu of a selected Section: hide what would copy, duplicate or restructure it
	const hidden = [ __( 'Duplicate' ), __( 'Copy' ), __( 'Cut' ), __( 'Group' ), __( 'Ungroup' ) ].map( ( text ) => text.trim().toLowerCase() );
	const hideItems = () => {
		const store = select( 'core/block-editor' );
		const id = store && store.getSelectedBlockClientId();

		if ( ! id || ! isSection( store.getBlock( id ) ) ) {
			return;
		}

		document.querySelectorAll( '.block-editor-block-settings-menu__popover [role="menuitem"], .components-dropdown-menu__menu [role="menuitem"]' ).forEach( ( item ) => {
			const label = ( item.textContent || '' ).trim().toLowerCase();

			if ( hidden.some( ( text ) => label.startsWith( text ) ) ) {
				item.style.display = 'none';
			}
		} );
	};

	new MutationObserver( hideItems ).observe( document.body, { childList: true, subtree: true } );
}( window.wp ) );
