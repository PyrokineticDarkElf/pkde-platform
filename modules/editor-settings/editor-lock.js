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

	// Duplicate itself is blocked, not only its menu item: the options menu, the keyboard shortcut (Ctrl/Cmd+Shift+D) and anything else that duplicates go through the
	// block editor's duplicateBlocks action, which is wrapped here to skip Sections.
	wp.data.use( ( registry ) => ( {
		dispatch: ( namespace ) => {
			const actions = Object.assign( {}, registry.dispatch( namespace ) );

			if ( namespace === 'core/block-editor' && actions.duplicateBlocks ) {
				const original = actions.duplicateBlocks;

				actions.duplicateBlocks = ( clientIds, ...rest ) => {
					const store = registry.select( 'core/block-editor' );
					const allowed = ( clientIds || [] ).filter( ( id ) => ! isSection( store.getBlock( id ) ) );

					return allowed.length ? original( allowed, ...rest ) : undefined;
				};
			}

			return actions;
		},
	} ) );

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

	// The keyboard shortcut (Ctrl/Cmd+Shift+D) does not pass through the wrapped action in every WordPress version, so it is also stopped where it is pressed: in the page and in
	// the editor canvas (an iframe), while a Section is selected.
	const stopDuplicateShortcut = ( event ) => {
		if ( ! ( event.shiftKey && ( event.ctrlKey || event.metaKey ) && ( event.key || '' ).toLowerCase() === 'd' ) ) {
			return;
		}

		const store = select( 'core/block-editor' );
		const ids = store ? ( store.getSelectedBlockClientIds ? store.getSelectedBlockClientIds() : [] ) : [];

		if ( ids.some( ( id ) => isSection( store.getBlock( id ) ) ) ) {
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();
		}
	};
	window.addEventListener( 'keydown', stopDuplicateShortcut, true );
	const guardCanvas = () => {
		const doc = document.querySelector( 'iframe[name="editor-canvas"]' )?.contentDocument;

		if ( doc && ! doc.pkdeDuplicateGuard ) {
			doc.pkdeDuplicateGuard = true;
			doc.addEventListener( 'keydown', stopDuplicateShortcut, true );
		}
	};
	// the canvas iframe appears after this script runs, and its document is replaced when it loads: guard it when it is added and again when it loads (no polling)
	const watchCanvas = () => {
		const frame = document.querySelector( 'iframe[name="editor-canvas"]' );

		if ( frame && ! frame.pkdeWatched ) {
			frame.pkdeWatched = true;
			frame.addEventListener( 'load', guardCanvas );
		}

		guardCanvas();
	};

	// the options menu of a selected Section: what would copy, duplicate or restructure it is taken out, and, whatever the markup of the menu turns out to be in this
	// WordPress version or what a script does to it, a click or Enter on such an item is stopped before the editor sees it (the action wrapper above is not on every path:
	// the options menu duplicated through a different one on bb.test)
	const hidden = [ __( 'Duplicate' ), __( 'Copy' ), __( 'Cut' ), __( 'Group' ), __( 'Ungroup' ) ].map( ( text ) => text.trim().toLowerCase() );
	const selectionHasSection = () => {
		const store = select( 'core/block-editor' );
		const ids = store ? ( store.getSelectedBlockClientIds ? store.getSelectedBlockClientIds() : [] ) : [];

		return ids.some( ( id ) => isSection( store.getBlock( id ) ) );
	};
	const isHiddenItem = ( item ) => {
		const label = ( item.textContent || '' ).trim().toLowerCase();

		return hidden.some( ( text ) => label.startsWith( text ) ) && ! label.startsWith( 'copy styles' ) && ! label.startsWith( 'copy all' );
	};
	// Hidden with a class that carries !important (not an inline style a stylesheet or a re-render could undo), on every menu item that matches, wherever the menu is
	// rendered (no assumption about the popover's markup, which differs between WordPress versions), and again at every change of the page.
	const style = document.createElement( 'style' );
	style.textContent = '.pkde-section-menu-hidden{display:none !important;}';
	document.head.appendChild( style );
	const hideItems = () => {
		const hideNow = selectionHasSection();

		document.querySelectorAll( '[role="menuitem"]' ).forEach( ( item ) => {
			if ( hideNow && isHiddenItem( item ) ) {
				item.classList.add( 'pkde-section-menu-hidden' );
				item.setAttribute( 'aria-hidden', 'true' );
			} else if ( item.classList.contains( 'pkde-section-menu-hidden' ) ) {
				item.classList.remove( 'pkde-section-menu-hidden' );
				item.removeAttribute( 'aria-hidden' );
			}
		} );
	};
	const stopMenuClick = ( event ) => {
		const item = event.target && event.target.closest ? event.target.closest( '[role="menuitem"]' ) : null;

		if ( item && isHiddenItem( item ) && selectionHasSection() ) {
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();
		}
	};
	window.addEventListener( 'click', stopMenuClick, true );
	window.addEventListener( 'keydown', ( event ) => {
		if ( ( event.key === 'Enter' || event.key === ' ' ) && event.target && event.target.closest && event.target.closest( '[role="menuitem"]' ) ) {
			stopMenuClick( event );
		}
	}, true );

	// No timer: the menu is hidden when it is added to the page (a MutationObserver), when the selection changes (the block editor store), and right after a click or key.
	const refresh = () => {
		watchCanvas();
		hideItems();
	};
	new MutationObserver( refresh ).observe( document.body, { childList: true, subtree: true } );
	subscribe( refresh );
	[ 'mousedown', 'mouseup', 'click', 'keyup', 'focusin' ].forEach( ( type ) => window.addEventListener( type, () => window.setTimeout( hideItems, 0 ), true ) );
	refresh();
}( window.wp ) );
