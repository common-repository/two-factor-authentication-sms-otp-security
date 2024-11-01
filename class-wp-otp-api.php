<?php
/**
 * Class to send requests to the RESTful API.
 *
 * @package wpotp
 */

if ( ! class_exists( 'WP_OTP_API' ) ) {

	/**
	 * Class to send requests to the RESTful API.
	 */
	class WP_OTP_API {

		/**
		 * Create a new account.
		 *
		 * @param string $email Email of the user.
		 * @param string $password Password of the user.
		 * @param string $site_url WordPress site URL.
		 *
		 * @return false|mixed
		 */
		public function create_account( $email, $password, $site_url ) {
			$response = $this->call(
				'post',
				'api/v1/users',
				array(
					'email'    => $email,
					'password' => $password,
					'site_url' => $site_url,
				)
			);
			if ( is_wp_error( $response ) ) {
				return false;
			} else {
				return json_decode( wp_remote_retrieve_body( $response ), true );
			}
		}

		/**
		 * Get user email and balance.
		 *
		 * @return false|mixed
		 */
		public function me() {
			$response = $this->call( 'get', 'api/v1/users/me', array(), true );
			if ( is_wp_error( $response ) ) {
				return false;
			} else {
				$body          = json_decode( wp_remote_retrieve_body( $response ), true );
				$response_code = wp_remote_retrieve_response_code( $response );
				if ( is_wp_error( $response_code ) || ( is_scalar( $response_code ) && $response_code < 200 || $response_code > 299 ) ) {
					return false;
				}

				return $body;
			}
		}

		/**
		 * Link a new phone number to an account.
		 *
		 * @param string $phone_number User phone number.
		 *
		 * @return false|mixed
		 */
		public function create_phone( $phone_number ) {
			$response = $this->call(
				'post',
				'api/v1/phones',
				array(
					'phone_number' => $phone_number,
				),
				true
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		/**
		 * Validate the code entered by the user for the new phone number.
		 *
		 * @param string $id Id (UUID) of the phone number.
		 * @param string $verification_code 6 digits code.
		 *
		 * @return false|mixed
		 */
		public function verify_phone( $id, $verification_code ) {
			$response = $this->call(
				'put',
				'api/v1/phones/' . $id,
				array(
					'verification_code' => $verification_code,
				),
				true
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		/**
		 * Destroy JWT token.
		 *
		 * @return void
		 */
		public function logout() {
			$this->call( 'post', 'api/v1/logout', array(), true );
		}

		/**
		 * Authenticate a user to get a valid JWT token.
		 *
		 * @param string $email User email.
		 * @param string $password User password.
		 * @param string $site_url WordPress site URL.
		 *
		 * @return false|mixed
		 */
		public function login( $email, $password, $site_url ) {
			$response = $this->call(
				'post',
				'api/v1/login',
				array(
					'email'    => $email,
					'password' => $password,
					'site_url' => $site_url,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['error'] ) ) {
				return false;
			}

			return $body;
		}

		/**
		 * Send One-time Password to the user trying to authenticate on WordPress.
		 *
		 * @param string $id ID (UUID) of the phone number.
		 *
		 * @return false|mixed
		 */
		public function otp( $id ) {
			$response = $this->call( 'post', 'api/v1/phones/' . $id . '/otp', array(), true );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		/**
		 * Validate the One-time Password entered by the user.
		 *
		 * @param string $id ID (UUID) of the phone number.
		 * @param string $otp 6 digits code.
		 *
		 * @return false|mixed
		 */
		public function authenticate( $id, $otp ) {
			$response = $this->call(
				'put',
				'api/v1/phones/' . $id . '/otp',
				array(
					'otp' => $otp,
				),
				true
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		/**
		 * Send HTTP requests to the API.
		 *
		 * @param string $method HTTP Method.
		 * @param string $uri URL.
		 * @param string $body Content (if POST or PUT request).
		 * @param string $auth Use JWT token.
		 *
		 * @return array|WP_Error|null
		 */
		protected function call( $method, $uri, $body = array(), $auth = false ) {
			$response = null;
			$params   = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
			);
			if ( ! empty( $body ) ) {
				$params['body'] = wp_json_encode( $body );
			}
			if ( $auth ) {
				$params['headers']['Authorization'] = 'Bearer ' . get_option( 'wpotp_token' );
			}

			switch ( $method ) {
				case 'post':
					$response = wp_remote_post( WPOTP_BASE_URL . $uri, $params );
					break;
				case 'put':
					$response = wp_remote_request( WPOTP_BASE_URL . $uri, $params + array( 'method' => 'PUT' ) );
					break;
				case 'get':
					$response = wp_remote_get( WPOTP_BASE_URL . $uri, $params );
					break;
			}

			if ( ! is_wp_error( $response ) && isset( $response['status_code'] ) ) {
				if ( 401 === $response['status_code'] ) {
					do_action( 'wpotp_unauthorized' );
				}
			}

			return $response;
		}

	}

	/**
	 * Global function for developers.
	 *
	 * @return mixed|WP_OTP_API
	 */
	function wpotp_api() {
		static $instance;

		if ( ! isset( $instance ) ) {
			$instance = new WP_OTP_API();
		}

		return $instance;
	}
}
