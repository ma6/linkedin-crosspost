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

		// "Post to LinkedIn now": build a standalone <form> on the fly,
		// appended to <body> (never nested inside whatever this meta box
		// happens to be rendered inside), carrying live copies of the
		// image/text/toggle — not whatever was last saved.
		$( '#lcp-run-now-button' ).on( 'click', function () {
			var $button = $( this );
			var $form = $( '<form>', {
				method: 'post',
				action: $button.data( 'url' ),
			} ).appendTo( 'body' );

			function field( name, value ) {
				$( '<input>', { type: 'hidden', name: name, value: value } ).appendTo( $form );
			}

			field( 'action', 'lcp_run_now' );
			field( 'post_id', $button.data( 'post-id' ) );
			field( '_wpnonce', $button.data( 'nonce' ) );
			field( 'lcp_image_id', $hidden.val() );
			field( 'lcp_text', $( '#lcp-text' ).val() );
			field( 'lcp_share_enabled', $( '#lcp-share-enabled' ).is( ':checked' ) ? '1' : '0' );

			// Native submit(), not $form.trigger('submit') — the block
			// editor almost certainly has its own document-level 'submit'
			// listener guarding against an accidental full-page navigation
			// out of the SPA, and a triggered jQuery event is exactly the
			// kind of thing that listener would catch and cancel (confirmed
			// live: trigger('submit') produced literally no response at
			// all). The native method doesn't dispatch a 'submit' event, so
			// nothing gets a chance to intercept it.
			$form.get( 0 ).submit();
		} );
	} );
}( jQuery ) );
