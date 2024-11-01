<?php
/**
 * Main file of the plugin.
 *
 * @package wpotp
 */

if ( ! class_exists( 'WP_OTP' ) ) {

	/**
	 * Handle all features of the plugin.
	 */
	class WP_OTP {

		/**
		 * Register hooks.
		 */
		public function __construct() {
			add_action( 'show_user_profile', array( $this, 'add_phone_number' ) );
			add_action( 'admin_init', array( $this, 'enqueue_script' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_post_wpotp_create_account', array( $this, 'create_account' ) );
			add_action( 'admin_post_wpotp_logout', array( $this, 'logout' ) );
			add_action( 'admin_post_wpotp_login', array( $this, 'login' ) );
			add_action( 'wp_ajax_wpotp_send_code', array( $this, 'send_code' ) );
			add_action( 'wp_ajax_wpotp_verify_code', array( $this, 'verify_code' ) );
			add_action( 'wp_ajax_wpotp_remove_phone', array( $this, 'remove_phone' ) );
			add_filter( 'authenticate', array( $this, 'maybe_prompt_otp' ), 30 );
			add_action( 'login_form_wpotp_login', array( $this, 'prompt_sms_code' ) );
			add_action( 'wpotp_unauthorized', array( $this, 'logout_unauthorized' ) );
		}

		/**
		 * If the request to get balance and email is unauthorized, we disable the plugin.
		 *
		 * @return void
		 */
		public function logout_unauthorized() {
			// We do not remove the phone numbers from user account since it might be a token issue.
			update_option( 'wpotp_email', null );
			update_option( 'wpotp_token', null );
			update_option( 'wpotp_balance', null );
		}

		/**
		 * If the SMS 2FA is enabled, send and ask the OTP.
		 *
		 * @param string $user Object of the user trying to log in.
		 *
		 * @return mixed|void|WP_User
		 */
		public function maybe_prompt_otp( $user ) {
			if ( is_a( $user, 'WP_User' ) ) {
				$this->refresh_data();
				if ( $this->user_otp_enabled( $user->ID ) && ! defined( 'WPOTP_DISABLED' ) ) {
					$login_nonce  = $this->create_login_nonce( $user->ID );
					$redirect_url = sprintf(
						'%s?action=wpotp_login&user_id=%d&wpotp_login_nonce=%s%s%s',
						wp_login_url(),
						$user->ID,
						$login_nonce['nonce'],
						isset( $_REQUEST['redirect_to'] ) ? '&redirect_to=' . wp_unslash( urlencode( sanitize_url( $_REQUEST['redirect_to'] ) ) ) : '',
						isset( $_REQUEST['rememberme'] ) ? '&remember_me=' . sanitize_text_field( $_REQUEST['rememberme'] ) : ''
					);

					wp_safe_redirect( $redirect_url );
					die;
				}
			}

			return $user;
		}

		protected function create_login_nonce( $user_id ) {
			$login_nonce = array(
				'nonce'      => wp_hash( $user_id . wp_rand() . microtime(), 'nonce' ),
				'expiration' => time() + apply_filters( 'wpotp_login_nonce', MINUTE_IN_SECONDS * 5 )
			);

			update_user_meta( $user_id, 'wpotp_login_nonce', $login_nonce );

			return $login_nonce;
		}

		public function prompt_sms_code() {
			$redirect_to   = sanitize_url( $_REQUEST['redirect_to'] ?? '' );
			$remember_me   = isset( $_REQUEST['remember_me'] ) ? sanitize_text_field( $_REQUEST['remember_me'] ) : '';
			$action_url    = add_query_arg( array( 'action' => 'wpotp_login' ), wp_login_url( $redirect_to ) );
			$action_url    = add_query_arg( array( 'remember_me' => $remember_me ), $action_url );
			$error_message = null;

			if ( ! isset( $_REQUEST['user_id'] ) || ! isset( $_REQUEST['wpotp_login_nonce'] ) ) {
				return;
			}

			$user = get_user_by( 'id', absint( $_REQUEST['user_id'] ) );
			if ( ! $user || $this->user_otp_enabled() ) {
				return;
			}

			if ( isset( $_POST['wpotp_validate_sms_code'] ) ) {
				$sms_code = trim( preg_replace( '/\D/', '', sanitize_text_field( $_POST['sms_code'] ) ) );
				if ( empty( $sms_code ) || strlen( $sms_code ) !== 6 ) {
					$error_message = __( 'Invalid OTP code. Must be 6 digits.', 'wpotp' );
				} else {
					$result = wpotp_api()->authenticate( $this->get_user_phone_number_id( $user->ID ), $sms_code );
					if ( ! $result || ! $result['authenticated'] ) {
						$error_message = __( 'Wrong OTP code. Try again.', 'wpotp' );
					} else {
						if ( isset( $result['balance'] ) ) {
							update_option( 'wpotp_balance', $result['balance'] );
						}

						$login_nonce = get_user_meta( $user->ID, 'wpotp_login_nonce', true );
						$valid       = false;
						if ( isset( $login_nonce['nonce'] ) && hash_equals( $_REQUEST['wpotp_login_nonce'], $login_nonce['nonce'] ) ) {
							if ( time() < $login_nonce['expiration'] ) {
								delete_user_meta( $user->ID, 'wpotp_login_nonce' );
								$valid = true;
							}
						}

						if ( ! $valid ) {
							$redirect_url = sprintf(
								'%s%s',
								wp_login_url(),
								isset( $_REQUEST['redirect_to'] ) ? '&redirect_to=' . urlencode( sanitize_url( $_REQUEST['redirect_to'] ) ) : ''
							);

							wp_safe_redirect( $redirect_url );
							die();
						} else {
							delete_transient( 'wpotp_otp_sent_' . $user->ID );
							remove_filter( 'authenticate', array( $this, 'maybe_prompt_otp' ), 30 );
							add_filter( 'authenticate', function ( $user, $username ) {
								return get_user_by( 'login', $username );
							}, 40, 2 );
							$credentials = array( 'user_login' => $user->user_login );
							if ( ! empty ( $_REQUEST['remember_me'] ) ) {
								$credentials['remember'] = sanitize_text_field( $_REQUEST['remember_me'] );
							}
							wp_signon( $credentials );
							$redirect_url = isset( $_REQUEST['redirect_to'] ) ? sanitize_url( $_REQUEST['redirect_to'] ) : admin_url();
							wp_safe_redirect( $redirect_url );
							do_action( 'wpotp_authentication_successful', $user );
							die();
						}
					}
				}
			} elseif ( isset( $_POST['wpotp_resend_code'] ) ) {
				$result = wpotp_api()->otp( $this->get_user_phone_number_id( $user->ID ) );
				if ( isset( $result['error'] ) && 'BALANCE_EMPTY' === $result['error'] ) {
					$error_message = __( 'Your balance is empty. <a href="https://wpotp.com/" target="_blank">Visit our website and log in to your account to add funds</a>.', 'wpotp' );
				}
			} else {
				if ( ! get_transient( 'wpotp_otp_sent_' . $user->ID ) ) {
					$result = wpotp_api()->otp( $this->get_user_phone_number_id( $user->ID ) );
					if ( false !== $result ) {
						set_transient( 'wpotp_otp_sent_' . $user->ID, true );
					}

					if ( isset( $result['error'] ) && 'BALANCE_EMPTY' === $result['error'] ) {
						$error_message = __( 'Your balance is empty. <a href="https://wpotp.com/" target="_blank">Visit our website and log in to your account to add funds</a>.', 'wpotp' );
					}
				}
			}

			login_header();
			?>

			<?php if ( $error_message ): ?>
                <div id="login_error">
					<?php esc_html_e( $error_message ); ?>
                </div>
			<?php endif; ?>

            <form name="loginform" id="loginform" action="<?php echo esc_url( $action_url ) ?>" method="post"
                  autocomplete="off">
                <p>
                    <label for="sms_code"><?php _e( 'SMS OTP', 'wpotp' ) ?></label>
                    <input type="text" name="sms_code" id="sms_code" class="input" value="" size="20"
                           autocapitalize="off"
                           autocomplete="off">
                </p>

                <div style="display: flex;justify-content:space-between;align-items:center;flex-direction:row-reverse;">
                    <p class="submit">
                        <input type="submit" name="wpotp_validate_sms_code" id="wpotp_validate_sms_code"
                               class="button button-primary button-large"
                               value="Log In">

                        <input type="hidden" name="user_id" value="<?php echo absint( $user->ID ); ?>"/>
                        <input type="hidden" name="wpotp_login_nonce"
                               value="<?php echo esc_attr( $_REQUEST['wpotp_login_nonce'] ) ?>"/>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ) ?>"/>
                    </p>
                    <p class="submit">
                        <input type="submit" name="wpotp_resend_code" id="wpotp_resend_code"
                               class="button-link"
                               value="<?php _e( 'Resend code', 'wpotp' ) ?>">
                    </p>

                </div>
            </form>

            <p id="nav">
                <a href="https://wpotp.com/documentation" target="_blank"><?php _e( 'Need help? Documentation', 'wpotp' ) ?> &rightarrow;</a>
            </p>

			<?php
			login_footer( 'sms_code' );
			exit;
		}

		public function register_settings() {
			register_setting( 'wpotp', 'wpotp_email' );
			register_setting( 'wpotp', 'wpotp_token' );
			register_setting( 'wpotp', 'wpotp_balance' );
		}

		public function send_code() {
			$data = wpotp_api()->create_phone( sanitize_text_field( $_POST['phone_number'] ) );
			if ( isset( $data['error'] ) ) {
				wp_send_json_error( $data );
			} else {
				update_option( 'wpotp_balance', $data['balance'] );
				wp_send_json_success( $data );
			}
			wp_die();
		}

		public function remove_phone() {
			delete_user_option( get_current_user_id(), 'wpotp_phone_number_id' );
			delete_user_option( get_current_user_id(), 'wpotp_phone_number' );
			delete_user_option( get_current_user_id(), 'wpotp_enabled' );
			wp_send_json_success();
			wp_die();
		}

		public function verify_code() {
			$data = wpotp_api()->verify_phone( sanitize_text_field( $_POST['id'] ), sanitize_text_field( $_POST['verification_code'] ) );
			if ( isset( $data['error'] ) ) {
				wp_send_json_error( $data );
			} else {
				if ( $data['verified'] ) {
					update_user_option( get_current_user_id(), 'wpotp_phone_number_id', $data['id'] );
					update_user_option( get_current_user_id(), 'wpotp_phone_number', $data['phone_number'] );
					update_user_option( get_current_user_id(), 'wpotp_enabled', true );
					wp_send_json_success( $data );
				} else {
					wp_send_json_error( $data );
				}
			}
			wp_die();
		}

		protected function validate_email( $email ) {
			$email = sanitize_email( strtolower( $email ) );
			if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return false;
			}

			return $email;
		}

		protected function validate_password( $password ) {
			$password = trim( $password );

			return strlen( $password ) >= 8 ? $password : false;
		}

		public function logout() {
			wpotp_api()->logout();

			update_option( 'wpotp_email', null );
			update_option( 'wpotp_token', null );
			update_option( 'wpotp_balance', null );
			delete_metadata( 'user', 0, 'wp_wpotp_phone_number_id', '', true );
			delete_metadata( 'user', 0, 'wp_wpotp_phone_number', '', true );
			delete_metadata( 'user', 0, 'wp_wpotp_enabled', '', true );

			return wp_redirect( '/wp-admin/admin.php?page=wpotp' );
		}

		public function login() {
			$email    = sanitize_email( $_POST['wpotp_existing_email'] );
			$password = trim( $_POST['wpotp_existing_password'] );

			if ( ! $data = wpotp_api()->login( $email, $password, site_url() ) ) {
				add_settings_error( 'general', 'login', __( 'Wrong email or password.', 'wpotp' ) );
			} else {
				update_option( 'wpotp_token', $data['token'] );
				update_option( 'wpotp_balance', $data['balance'] );
				update_option( 'wpotp_email', $data['email'] );
			}
			set_transient( 'settings_errors', get_settings_errors(), 30 );

			return wp_redirect( '/wp-admin/admin.php?page=wpotp&settings-updated=1' );
		}

		public function create_account() {
			$email    = $this->validate_email( $_POST['wpotp_email'] );
			$password = $this->validate_password( $_POST['wpotp_password'] );

			if ( $email && $password ) {
				$data = wpotp_api()->create_account( $email, $password, site_url() );
				if ( isset( $data['message'] ) && ! empty( $data['errors'] ) ) {
					add_settings_error( 'general', 'create_account', esc_html( $data['message'] ) );
				} else {
					update_option( 'wpotp_token', $data['token'] );
					update_option( 'wpotp_balance', $data['balance'] );
					update_option( 'wpotp_email', $data['email'] );
				}
			} else {
				add_settings_error( 'general', 'create_account', __( 'Invalid email or password, please try again.', 'wpotp' ) );
			}

			set_transient( 'settings_errors', get_settings_errors(), 30 );

			return wp_redirect( '/wp-admin/admin.php?page=wpotp&settings-updated=1' );
		}

		public function admin_menu() {
			add_menu_page( 'WP OTP', 'WP OTP', 'manage_options', 'wpotp', array(
				$this,
				'admin_page'
			), 'dashicons-admin-network' );
		}

		public function admin_page() {
			$this->refresh_data();
			?>
            <div class="wrap">
                <h1>
                    <span class="screen-reader-text">WP OTP</span>
                    <img src="<?php echo plugin_dir_url( __FILE__ ) . 'img/logo-cropped.svg' ?>" width="180" height="67" alt="WP OTP Logo">
                </h1>
				<?php settings_errors(); ?>
				<?php if ( ! $this->ready() ): ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wpotp_create_account">
						<?php wp_nonce_field( 'wpotp_create_account' ) ?>
                        <h2 class="title"><?php _e( 'New account', 'wpotp' ) ?></h2>

                        <p><?php _e( 'Creating an account is 100% free and you get 25 SMS ($0.25) for free to test on your site. When your balance reaches 0, you can add funds to your account.', 'wpotp' ) ?></p>
                        <p><?php _e( 'For the moment, we only accept phone numbers from the following countries:', 'wpotp' ) ?> <strong><?php _e( 'Canada and the United States' ) ?></strong>.</p>
                        <p><?php _e( 'The account creation is done through WordPress.', 'wpotp' ) ?></p>

                        <table class="form-table" role="presentation">
                            <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="wpotp_email"><?php _e( 'Email', 'wpotp' ) ?></label>
                                </th>
                                <td>
                                    <input autofocus autocomplete="username" name="wpotp_email" type="email" required id="wpotp_email" placeholder="login@example.com" value="<?php esc_attr_e( get_option( 'wpotp_email' ) ); ?>" class="regular-text ltr">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wpotp_password"><?php _e( 'Password', 'wpotp' ) ?></label>
                                </th>
                                <td>
                                    <input name="wpotp_password" minlength="8" type="password" required id="wpotp_password" autocomplete="new-password" value="" class="regular-text ltr">
                                    <p class="description" id="tagline-description"><?php _e( 'Must be at least 8 characters.', 'wpotp' ) ?></p>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                        <p class="submit">
							<?php submit_button( __( 'Create account', 'wpotp' ), 'primary', 'create_account', false ); ?>
                        </p>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wpotp_login">
                        <h2 class="title"><?php _e( 'Existing account', 'wpotp' ) ?></h2>
                        <table class="form-table" role="presentation">
                            <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="wpotp_existing_email"><?php _e( 'Email', 'wpotp' ) ?></label>
                                </th>
                                <td>
                                    <input autocomplete="username" name="wpotp_existing_email" type="email" required id="wpotp_existing_email" class="regular-text ltr">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wpotp_existing_password"><?php _e( 'Password', 'wpotp' ) ?></label>
                                </th>
                                <td>
                                    <input autocomplete="current-password" name="wpotp_existing_password" minlength="8" type="password" required id="wpotp_existing_password" value="" class="regular-text ltr">
                                </td>
                            </tr>
                            </tbody>
                        </table>
                        <p class="submit">
							<?php submit_button( __( 'Connect', 'wpotp' ), 'primary', 'connect', false ); ?>
                        </p>
                    </form>
				<?php else: ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><?php _e( 'Email', 'wpotp' ) ?></th>
                            <td><?php esc_html_e( get_option( 'wpotp_email' ) ) ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e( 'Your balance', 'wpotp' ) ?></th>
                            <td>$<?php esc_html_e( number_format( get_option( 'wpotp_balance' ) / 100, 2 ) ) ?></td>
                        </tr>
                        </tbody>
                    </table>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wpotp_logout">
                        <p class="submit">
							<?php submit_button( __( 'Logout', 'wpotp' ), 'primary', 'submit', false ); ?>
                        </p>
                    </form>
                    <a href="https://wpotp.com/" target="_blank"><?php _e( 'Add funds to your account', 'wpotp' ) ?> &rightarrow;</a>
				<?php endif; ?>
            </div>
			<?php
		}

		public function enqueue_script() {
			if ( defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE ) {
				wp_enqueue_script( 'wpotp-profile', plugin_dir_url( __FILE__ ) . 'js/profile.js', array(
					'jquery',
					'user-profile'
				), WPOTP_VERSION, true );
				wp_enqueue_style( 'wpotp-profile', plugin_dir_url( __FILE__ ) . 'css/profile.css', array(), WPOTP_VERSION );
				wp_localize_script( 'wpotp-profile', 'wpotp', array(
					'ajax_url' => admin_url( 'admin-ajax.php' )
				) );
			}
		}

		public function add_phone_number() {
			if ( $this->ready() ):
				?>
                <h2><?php _e( 'SMS 2FA', 'wpotp' ); ?></h2>

                <table class="form-table" role="presentation">
                    <tr id="wpotp" class="wpotp-wrapper">
                        <th><label for="wpotp_phone_number"><?php _e( 'Phone Number', 'wpotp' ); ?></label></th>
                        <td>
                            <input type="hidden" name="wpotp_enabled" value="<?php echo $this->user_otp_enabled() ? '1' : '0' ?>">
                            <div id="wpotp-add-phone-form" class="hide-if-js">
                                <button type="button" class="button wpotp-add-number hide-if-no-js"
                                        aria-expanded="false"><?php _e( 'Set Phone Number', 'wpotp' ); ?></button>
                                <div class="wpotp-phone-number hide-if-js">
                                    <input type="text" name="wpotp_phone_number" id="wpotp_phone_number"
                                           class="regular-text" value="" autocomplete="tel"/>
                                    <button type="button" class="button wpotp-send-code hide-if-no-js" data-toggle="0"
                                            aria-label="<?php esc_attr_e( 'Send verification code', 'wpotp' ); ?>">
                                        <span class="text"><?php _e( 'Send code', 'wpotp' ); ?></span>
                                    </button>
                                    <button type="button" class="button wpotp-cancel hide-if-no-js" data-toggle="0"
                                            aria-label="<?php esc_attr_e( 'Cancel phone number', 'wpotp' ); ?>">
                                        <span class="text"><?php _e( 'Cancel', 'wpotp' ); ?></span>
                                    </button>
                                    <span class="spinner no-float" aria-hidden="true"></span>
                                </div>
                                <div class="wpotp-verification-code hide-if-js">
                                    <div style="margin-top: 1em;margin-bottom:0.5em;font-weight: 600;flex: 0 0 100%;">
                                        <label for="wpotp_verification_code"><?php _e( 'Verification code (6 digits)', 'wpotp' ) ?></label>
                                    </div>
                                    <input type="text" name="wpotp_verification_code" id="wpotp_verification_code"
                                           class="regular-text" value="" autocomplete="tel"/>
                                    <button type="button" class="button wpotp-verify hide-if-no-js" data-toggle="0"
                                            aria-label="<?php esc_attr_e( 'Verify phone number', 'wpotp' ); ?>">
                                        <span class="text"><?php _e( 'Verify', 'wpotp' ); ?></span>
                                    </button>
                                    <button type="button" class="button wpotp-cancel hide-if-no-js" data-toggle="0"
                                            aria-label="<?php esc_attr_e( 'Cancel phone number', 'wpotp' ); ?>">
                                        <span class="text"><?php _e( 'Cancel', 'wpotp' ); ?></span>
                                    </button>
                                    <span class="spinner no-float" aria-hidden="true"></span>
                                </div>
                            </div>
                            <div id="wpotp-show-phone" class="hide-if-js">
                                <p class="wpotp-phone-number-value"><?php esc_html_e( $this->get_user_phone_number() ) ?></p>
                                <button type="button" class="button wpotp-remove hide-if-no-js" data-toggle="0"
                                        aria-label="<?php esc_attr_e( 'Remove phone number', 'wpotp' ); ?>">
                                    <span class="text"><?php _e( 'Remove', 'wpotp' ); ?></span>
                                </button>
                                <span class="spinner no-float" aria-hidden="true"></span>
                            </div>
                        </td>
                    </tr>
                </table>
			<?php
			endif;
		}

		/**
		 * Update user balance and email.
		 *
		 * @return void
		 */
		public function refresh_data() {
			$data = wpotp_api()->me();
			if ( ! $data ) {
				wpotp_api()->logout();

				update_option( 'wpotp_email', null );
				update_option( 'wpotp_token', null );
				update_option( 'wpotp_balance', null );
			} else {
				update_option( 'wpotp_balance', $data['balance'] );
				update_option( 'wpotp_email', $data['email'] );
			}
		}

		/**
		 * Tells if the plugin is enabled (user authenticated).
		 *
		 * @return bool
		 */
		public function ready() {
			$email = get_option( 'wpotp_email' );
			$token = get_option( 'wpotp_token' );

			return ! empty( $email ) && ! empty( $token );
		}

		/**
		 * Get the formatted phone number.
		 *
		 * @param string $user_id WordPress user ID.
		 *
		 * @return false|mixed
		 */
		public function get_user_phone_number( $user_id = null ) {
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}

			return get_user_option( 'wpotp_phone_number', $user_id );
		}

		/**
		 * Get the phone number UUID for API requests.
		 *
		 * @param string $user_id WordPress user ID.
		 *
		 * @return false|mixed
		 */
		public function get_user_phone_number_id( $user_id = null ) {
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}

			return get_user_option( 'wpotp_phone_number_id', $user_id );
		}

		/**
		 * Tells if the user is using 2FA.
		 *
		 * @param string $user_id WordPress user ID.
		 *
		 * @return bool
		 */
		public function user_otp_enabled( $user_id = null ) {
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}

			return get_user_option( 'wpotp_enabled', $user_id ) && $this->ready();
		}

		/**
		 * Get the user balance.
		 *
		 * @return false|int|mixed
		 */
		public function balance() {
			$balance = get_option( 'wpotp_balance' );

			return $balance ?? 0;
		}

	}

}
