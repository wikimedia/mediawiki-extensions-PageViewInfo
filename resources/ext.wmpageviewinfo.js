( function ( $, mw ) {
	$( function () {
		var $count = $( '.mw-wmpvi-month' ),
			count = $count.text();

		// Turn it into an <a> tag so it's obvious you can click on it
		$count.html( mw.html.element( 'a', { href: '#' }, count ) );

		$count.click( function ( e ) {
			var myDialog, windowManager;
			e.preventDefault();

			// A simple dialog window.
			function MyDialog( config ) {
				MyDialog.parent.call( this, config );
			}
			OO.inheritClass( MyDialog, OO.ui.Dialog );
			MyDialog.prototype.initialize = function () {
				var def = mw.config.get( 'wgWMPageViewInfo' );
				MyDialog.parent.prototype.initialize.call( this );
				this.content = new OO.ui.PanelLayout( { padded: true, expanded: false } );
				this.$body.append( this.content.$element );
				mw.drawVegaGraph( this.content.$element[ 0 ], def );
			};
			myDialog = new MyDialog( {
				size: 'large'
			} );
			// Create and append a window manager, which opens and closes the window.
			windowManager = new OO.ui.WindowManager();
			$( 'body' ).append( windowManager.$element );
			windowManager.addWindows( [ myDialog ] );
			// Open the window!
			windowManager.openWindow( myDialog );
		} );

	} );
}( jQuery, mediaWiki ) );
