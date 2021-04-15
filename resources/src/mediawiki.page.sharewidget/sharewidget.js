/*!
 * Scripts for section share link at domready
 */
( function () {
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		$content.find( '.mw-editsection-share[data-mw-share-section]' ).each( initPopup );
	} );

	function initPopup() {
		var $shareLink = $( this ), popup;

		// Already initialized
		if ( $shareLink.data( 'mw-share-init' ) ) {
			return;
		}

		$shareLink.data( 'mw-share-init', true ).on( 'click', function ( e ) {
			var modules = [
				'mediawiki.widgets', 'mediawiki.editfont.styles', 'mediawiki.jqueryMsg'
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
		var linkWikitext = mw.Title.newFromText( mw.config.get( 'wgPageName' ) )
			.getPrefixedText() + '#' + $shareLink.attr( 'data-mw-share-section' );
		var copiableURL = new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-url' ),
			copyText: $shareLink[0].href,
			align: 'top',
			textInput: {
				classes: [ 'mw-editfont-' + mw.user.options.get( 'editfont' ) ]
			}
		} ),
		copiableWikitext = new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-wikitext' ),
			copyText: linkWikitext,
			align: 'top',
			textInput: {
				classes: [ 'mw-editfont-' + mw.user.options.get( 'editfont' ) ]
			}
		} );

		var popup = new OO.ui.PopupWidget( {
			$floatableContainer: $shareLink,
			$content: $( '<div>' )
				.append(
					$( '<h4>' ).addClass( 'mw-editsection-share-popup-title' )
						.text( mw.msg( 'share-popup-title' ) ),
					copiableURL.$element,
					copiableWikitext.$element
				),
			classes: [ 'mw-editsection-share' ],
			align: 'forwards',
			autoClose: true,
			padded: true,
			width: 380
		} );

		OO.ui.getDefaultOverlay().append( popup.$element );

		popup.toggle();
		return popup;
	}

}() );
