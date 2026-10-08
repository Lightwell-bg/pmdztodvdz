/**
 * Выбор фото консультанта через медиатеку WordPress (wp.media).
 * Подключается только на странице «Настройки консультаций».
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var frame = null;
		var $id = $( '#bgdvzh-photo-id' );
		var $preview = $( '#bgdvzh-photo-preview' );

		if ( ! $id.length || ! window.wp || ! window.wp.media ) {
			return;
		}

		$( '#bgdvzh-photo-pick' ).on( 'click', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: 'Фото консультанта',
					button: { text: 'Использовать это фото' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var url = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;

					$id.val( attachment.id );
					$preview.empty().append(
						$( '<img>', { src: url, alt: '' } ).css( { maxWidth: '120px', height: 'auto' } )
					);
				} );
			}

			frame.open();
		} );

		$( '#bgdvzh-photo-clear' ).on( 'click', function ( event ) {
			event.preventDefault();
			$id.val( '0' );
			$preview.empty();
		} );
	} );
}( window.jQuery ) );
