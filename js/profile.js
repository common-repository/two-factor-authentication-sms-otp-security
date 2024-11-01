(
	function( $ ) {
		let $setPhoneNumber,
			$phoneNumber,
			$phoneNumberWrapper,
			$cancelButton,
			$sendCodeButton,
			$verificationCodeWrapper,
			$verificationCode,
			$verifyCodeButton,
			phoneUuid,
			$enabled,
			$removeButton;

		$enabled = $( 'input[name="wpotp_enabled"]' );
		if ( $enabled.val() === '1' ) {
			$( '#wpotp-show-phone' ).css( 'display', 'flex' );
		} else {
			$( '#wpotp-add-phone-form' ).show();
		}

		$setPhoneNumber = $( '.wpotp-add-number' );
		$phoneNumber = $( '#wpotp_phone_number' );
		$verificationCode = $( '#wpotp_verification_code' );
		$phoneNumberWrapper = $( '.wpotp-phone-number' );
		$verificationCodeWrapper = $( '.wpotp-verification-code' );

		if ( $phoneNumber.is( ':hidden' ) ) {
			$phoneNumber.prop( 'disabled', true );
			$verificationCode.prop( 'disabled', true );
		}

		$setPhoneNumber.show();
		$setPhoneNumber.on( 'click', function() {
			$setPhoneNumber.attr( 'aria-expanded', 'true' );
			$phoneNumberWrapper.css( 'display', 'flex' ).addClass( 'is-open' );
			$phoneNumber.attr( 'disabled', false ).focus();
		} );

		$phoneNumber.on( 'keydown', function( e ) {
			if ( e.keyCode === 13 ) {
				e.preventDefault();
				$sendCodeButton.trigger( 'click' );
			}
		} );

		$verificationCode.on( 'keydown', function( e ) {
			if ( e.keyCode === 13 ) {
				e.preventDefault();
				$verifyCodeButton.trigger( 'click' );
			}
		} );

		let resetForm = function() {
			$phoneNumber.prop( 'disabled', true );
			$phoneNumber.removeAttr( 'readonly' );
			$phoneNumber.val( '' );

			$verificationCode.prop( 'disabled', true );
			$verificationCode.removeAttr( 'readonly' );
			$verificationCode.val( '' );

			$phoneNumberWrapper.find( '.wpotp-cancel' ).show();
			$sendCodeButton.find( 'span.text' ).text( 'Send code' );
			$verificationCodeWrapper.hide().removeClass( 'is-open' );
			$phoneNumberWrapper.hide().removeClass( 'is-open' );
		};

		$cancelButton = $( 'button.wpotp-cancel' );
		$cancelButton.on( 'click', function() {
			resetForm();
		} );

		$sendCodeButton = $phoneNumberWrapper.find( 'button.wpotp-send-code' );
		$sendCodeButton.on( 'click', function() {
			$phoneNumber.attr( 'readonly', 'true' );
			$sendCodeButton.prop( 'disabled', true );
			$phoneNumberWrapper.find( '.spinner' ).addClass( 'is-active' );
			$.post( wpotp.ajax_url, {
				phone_number: $phoneNumber.val().replace( /\D/g, '' ),
				action: 'wpotp_send_code',
			} ).done( function( response ) {
				if ( !response.success ) {
					$phoneNumber.removeAttr( 'readonly' );
				} else {
					$phoneNumber.val( response.data.national_format );
					$phoneNumberWrapper.find( '.wpotp-cancel' ).hide();
					$sendCodeButton.find( 'span.text' ).text( 'Resend code' );
					$verificationCodeWrapper.css( 'display', 'flex' ).addClass( 'is-open' );
					$verificationCode.prop( 'disabled', false ).focus();
					phoneUuid = response.data.id;
				}
			} ).always( function() {
				$sendCodeButton.prop( 'disabled', false );
				$phoneNumberWrapper.find( '.spinner' ).removeClass( 'is-active' );
			} );
		} );

		$verifyCodeButton = $verificationCodeWrapper.find( 'button.wpotp-verify' );
		$verifyCodeButton.on( 'click', function() {
			$verificationCode.attr( 'readonly', true );
			$verifyCodeButton.prop( 'disabled', true );
			$verificationCodeWrapper.find( '.spinner' ).addClass( 'is-active' );
			$.post( wpotp.ajax_url, {
				verification_code: $verificationCode.val().replace( /\D/g, '' ),
				id: phoneUuid,
				action: 'wpotp_verify_code',
			} ).done( function( response ) {
				if ( !response.success ) {
					$verificationCode.removeAttr( 'readonly' );
				} else {
					$( '.wpotp-phone-number-value' ).text( response.data.phone_number );
					$( '#wpotp-show-phone' ).css( 'display', 'flex' );
					$( '#wpotp-add-phone-form' ).hide();
					resetForm();
				}
			} ).always( function() {
				$verifyCodeButton.prop( 'disabled', false );
				$verificationCodeWrapper.find( '.spinner' ).removeClass( 'is-active' );
			} );
		} );

		$removeButton = $( '.wpotp-remove' );
		$removeButton.on( 'click', function() {
			$removeButton.prop( 'disabled', true );
			$( '#wpotp-show-phone' ).find( '.spinner' ).addClass( 'is-active' );
			$.post( wpotp.ajax_url, {
				action: 'wpotp_remove_phone',
			} ).done( function( response ) {
				$( '#wpotp-show-phone' ).hide();
				$( '#wpotp-add-phone-form' ).show();
			} ).always( function() {
				$removeButton.prop( 'disabled', false );
				$( '#wpotp-show-phone' ).find( '.spinner' ).removeClass( 'is-active' );
			} );
		} );

	}
)( jQuery );
