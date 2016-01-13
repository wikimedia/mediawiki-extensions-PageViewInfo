( function( $, mw ) {
	$( '.mw-pvi-month' ).click( function( e ) {
		// A simple dialog window.
		function MyDialog( config ) {
			MyDialog.parent.call( this, config );
		}
		OO.inheritClass( MyDialog, OO.ui.Dialog );
		MyDialog.prototype.initialize = function () {
			var def = JSON.parse( mw.config.get( 'wgPVIDefinition' ) );
			def.data[0].values = mw.config.get( 'wgPageViewInfo' ).items;
			MyDialog.parent.prototype.initialize.call( this );
			this.content = new OO.ui.PanelLayout( { padded: true, expanded: false } );
			this.$body.append( this.content.$element );
			mw.drawVegaGraph( this.content.$element[0], def );
		};
		var myDialog = new MyDialog( {
			size: 'large'
		} );
		// Create and append a window manager, which opens and closes the window.
		var windowManager = new OO.ui.WindowManager();
		$( 'body' ).append( windowManager.$element );
		windowManager.addWindows( [ myDialog ] );
		// Open the window!
		windowManager.openWindow( myDialog );
	} );
}( jQuery, mediaWiki ) );
