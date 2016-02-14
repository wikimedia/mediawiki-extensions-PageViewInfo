( function ( $, mw ) {
	$( function () {
		$( '.mw-wmpvi-month' ).click( function () {
			var myDialog, windowManager;
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
