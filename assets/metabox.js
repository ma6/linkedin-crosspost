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

		// "Post to LinkedIn now" is its own small form so it can post to a
		// different destination than the editor's save — sync in whatever
		// is currently in the box right before it submits, so it always
		// posts what's actually in the fields, not just what was last saved.
		$( '#lcp-run-now-form' ).on( 'submit', function () {
			$( '#lcp-run-now-image-id' ).val( $hidden.val() );
			$( '#lcp-run-now-text' ).val( $( '#lcp-text' ).val() );
			$( '#lcp-run-now-share-enabled' ).val( $( '#lcp-share-enabled' ).is( ':checked' ) ? '1' : '0' );
		} );
	} );
}( jQuery ) );
