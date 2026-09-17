( function ( $ ) {
	'use strict';

	$( function () {
		var frame;
		var $preview = $( '#lcp-image-preview' );
		var $hidden = $( '#lcp-image-id' );
		var $remove = $( '#lcp-image-remove' );

		$( '#lcp-image-choose' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: lcpMetabox.pickTitle,
				button: { text: lcpMetabox.pickButton },
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = ( attachment.sizes && attachment.sizes.medium )
					? attachment.sizes.medium.url
					: attachment.url;

				$preview.attr( 'src', url ).show();
				$hidden.val( attachment.id );
				$remove.show();
			} );

			frame.open();
		} );

		$remove.on( 'click', function ( e ) {
			e.preventDefault();
			$preview.attr( 'src', '' ).hide();
			$hidden.val( '' );
			$remove.hide();
		} );
	} );
}( jQuery ) );
