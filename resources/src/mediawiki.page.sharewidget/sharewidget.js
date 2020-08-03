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
		var copiableLink = new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-link' ),
			copyText: $shareLink[0].href,
			readOnly: false,
			align: 'top',
			textInput: {
				title: mw.msg( 'share-link-tooltip', sectionTitle ),
				classes: [ 'mw-editfont-' + mw.user.options.get( 'editfont' ) ]
			}
		} ),
		copiableWikilink = new mw.widgets.CopyTextLayout( {
			label: mw.msg( 'share-wikilink' ),
			copyText: linkWikitext,
			readOnly: false,
			align: 'top',
			textInput: {
				title: mw.msg( 'share-wikilink-tooltip', sectionTitle ),
				classes: [ 'mw-editfont-' + mw.user.options.get( 'editfont' ) ]
			}
		} );

		var popup = new OO.ui.PopupWidget( {
			$floatableContainer: $shareLink,
			$content: $( '<div>' )
				.append(
					$( '<h4>' ).addClass( 'mw-editsection-share-popup-title' )
						.text( mw.msg( 'share-popup-title' ) ),
					copiableLink.$element,
					copiableWikilink.$element
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
