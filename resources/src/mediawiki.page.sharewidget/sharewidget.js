/*!
 * Scripts for section share link at domready
 */
( function () {
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		$content.find( '.mw-editsection-share' ).each( initPopup );
	} );

	function initPopup() {
		var $shareLink = $( this ), popup;

		// Already initialized
		if ( $shareLink.data( 'mw-share-init' ) ) {
			return;
		}

		$shareLink.data( 'mw-share-init', true ).on( 'click', function ( e ) {
			var modules = [
				'mediawiki.widgets',
				'mediawiki.jqueryMsg',
				//'mediawiki.editfont.styles',
			];
			e.preventDefault();

			if ( !popup ) {
				mw.loader.using( modules, function() {
					popup = createPopup( $shareLink );
				} );
			} else {
				popup.toggle();
			}
		} );
	}

	function createPopup( $shareLink ) {
		var elLink = $shareLink[0];
		var sectionFragment = $shareLink.attr( 'href' ) || '';
		var transcluded = sectionFragment && ( sectionFragment[0] !== '#' ); // Transcluded links have more than a fragment.
		var popupTitle = transcluded ? 'transcludedfrom-popup-title' : 'share-popup-title';
		var sectionTitle = $shareLink.closest( '.mw-editsection' ).siblings( '.mw-headline' ).text();
		var wikiLink = $shareLink.attr( 'data-mw-wikilink' ) ||
			mw.Title.newFromText( mw.config.get( 'wgPageName' ) ).getPrefixedText() + sectionFragment;
		//var inputClasses = [ 'mw-editfont-' + mw.user.options.get( 'editfont' ) ];
		var inputClasses = null;
		//var labelPosition = 'left';
		var labelPosition = 'top';
		var copiableLink = elLink && new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-link' ),
			copyText: elLink.href,
			readOnly: false,
			align: labelPosition,
			textInput: {
				title: mw.msg( 'share-link-tooltip', sectionTitle ),
				classes: inputClasses,
			}
		} );
		var elPermalink = !transcluded && $( '#t-permalink > a' )[0];
		var copiablePermalink = elPermalink && new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-permalink' ),
			copyText: elPermalink.href + sectionFragment,
			readOnly: false,
			align: labelPosition,
			textInput: {
				title: mw.msg( 'share-permalink-tooltip', sectionTitle ),
				classes: inputClasses,
			}
		} );
		var copiableWikilink = wikiLink && new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-wikilink' ),
			copyText: '[[' + wikiLink + ']]',
			readOnly: false,
			align: labelPosition,
			textInput: {
				title: mw.msg( 'share-wikilink-tooltip', sectionTitle ),
				classes: inputClasses,
			}
		} );

		var popup = new OO.ui.PopupWidget( {
			$floatableContainer: $shareLink,
			$content: $( '<div>' )
				.append(
					$( '<h4>' ).addClass( 'mw-editsection-share-popup-title' )
						.text( mw.msg( popupTitle ) ),
					( copiableLink ? copiableLink.$element : null ),
					( copiablePermalink ? copiablePermalink.$element : null ),
					( copiableWikilink ? copiableWikilink.$element : null ),
				),
			classes: [ 'mw-editsection-share' ],
			align: 'forwards',
			autoClose: true,
			padded: true,
			width: 'auto',
		} );

		// Disable auto-hiding of section links while open.
		popup.on( 'ready', function() {
			$shareLink.closest( '.mw-editsection' ).addClass( 'mw-pinned' );
		} );
		popup.on( 'closing', function() {
			$shareLink.closest( '.mw-editsection' ).removeClass( 'mw-pinned' );
		} );

		OO.ui.getDefaultOverlay().append( popup.$element );
		//$( e.target ).closest( '.mw-editsection-share' ).append( popup.$element );
		// Needs repositioning of the anchor triangle.

		popup.toggle();
		return popup;
	}

}() );
