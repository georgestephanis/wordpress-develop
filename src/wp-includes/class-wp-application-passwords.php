<?php
/**
 * Class for displaying, modifying, & sanitizing application passwords.
 *
 * @since ?.?.0
 *
 * @package Two_Factor
 */
class WP_Application_Passwords {

	/**
	 * The user meta application password key.
	 * @type string
	 */
	const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

	/**
	 * The length of generated application passwords.
	 *
	 * @type integer
	 */
	const PW_LENGTH = 24;

	/**
	 * Add various hooks.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 */
	public static function add_hooks() {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_api_init' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'rest_api_auth_handler' ), 20 );
	}

	/**
	 * Handle declaration of REST API endpoints.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 */
	public static function rest_api_init() {
		// Some hosts that run PHP in FastCGI mode won't be given the Authentication header.
		register_rest_route(
			'2fa/v1',
			'/test-basic-authorization-header/',
			array(
				'methods'             => WP_REST_Server::READABLE . ', ' . WP_REST_Server::CREATABLE,
				'callback'            => __CLASS__ . '::rest_test_basic_authorization_header',
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Loosely Based on https://github.com/WP-API/Basic-Auth/blob/master/basic-auth.php
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param $input_user
	 *
	 * @return WP_User|bool
	 */
	public static function rest_api_auth_handler( $input_user ) {
		// Don't authenticate twice.
		if ( ! empty( $input_user ) ) {
			return $input_user;
		}

		// Check that we're trying to authenticate
		if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return $input_user;
		}

		$user = self::authenticate( $input_user, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

		if ( $user instanceof WP_User ) {
			return $user->ID;
		}

		// If it wasn't a user what got returned, just pass on what we had received originally.
		return $input_user;
	}

	/**
	 * Test whether PHP can see Basic Authorization headers passed to the web server.
	 *
	 * @return WP_Error|array
	 */
	public static function rest_test_basic_authorization_header() {
		$response = array();

		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			$response['PHP_AUTH_USER'] = $_SERVER['PHP_AUTH_USER'];
		}

		if ( isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$response['PHP_AUTH_PW'] = $_SERVER['PHP_AUTH_PW'];
		}

		if ( empty( $response ) ) {
			return new WP_Error( 'no-credentials', __( 'No HTTP Basic Authorization credentials were found submitted with this request.' ), array( 'status' => 404 ) );
		}

		return $response;
	}

	/**
	 * Check if the current request is an API request
	 * for which we should check the HTTP Auth headers.
	 *
	 * @return boolean
	 */
	public static function is_api_request() {
		// Process the authentication only after the APIs have been initialized.
		return ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
	}

	/**
	 * Filter the user to authenticate.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param WP_User $input_user User to authenticate.
	 * @param string  $username   User login.
	 * @param string  $password   User password.
	 *
	 * @return mixed
	 */
	public static function authenticate( $input_user, $username, $password ) {
		if ( ! apply_filters( 'application_password_is_api_request', self::is_api_request() ) ) {
			return $input_user;
		}

		$user = get_user_by( 'login', $username );

		if ( ! $user && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}

		// If the login name is invalid, short circuit.
		if ( ! $user ) {
			return $input_user;
		}

		/*
		 * Strip out anything non-alphanumeric. This is so passwords can be used with
		 * or without spaces to indicate the groupings for readability.
		 *
		 * Generated application passwords are exclusively alphanumeric.
		 */
		$password = preg_replace( '/[^a-z\d]/i', '', $password );

		$hashed_passwords = get_user_meta( $user->ID, self::USERMETA_KEY_APPLICATION_PASSWORDS, true );

		// If there aren't any, there's nothing to return.  Avoid the foreach.
		if ( empty( $hashed_passwords ) ) {
			return $input_user;
		}

		foreach ( $hashed_passwords as $key => $item ) {
			if ( wp_check_password( $password, $item['password'], $user->ID ) ) {
				$item['last_used']        = time();
				$item['last_ip']          = $_SERVER['REMOTE_ADDR'];
				$hashed_passwords[ $key ] = $item;
				update_user_meta( $user->ID, self::USERMETA_KEY_APPLICATION_PASSWORDS, $hashed_passwords );

				do_action( 'application_password_did_authenticate', $user, $item );

				return $user;
			}
		}

		// By default, return what we've been passed.
		return $input_user;
	}

	/**
	 * Generate a new application password.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Password name.
	 * @return array          The first key in the array is the new password, the second is its row in the table.
	 */
	public static function create_new_application_password( $user_id, $name ) {
		$new_password    = wp_generate_password( self::PW_LENGTH, false );
		$hashed_password = wp_hash_password( $new_password );

		$new_item = array(
			'name'      => $name,
			'password'  => $hashed_password,
			'created'   => time(),
			'last_used' => null,
			'last_ip'   => null,
		);

		$passwords = self::get_user_application_passwords( $user_id );
		if ( ! $passwords ) {
			$passwords = array();
		}

		$passwords[] = $new_item;
		self::set_user_application_passwords( $user_id, $passwords );

		return array( $new_password, $new_item );
	}

	/**
	 * Delete a specified application password.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @see WP_Application_Passwords::password_unique_slug()
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug The generated slug of the password in question.
	 * @return bool Whether the password was successfully found and deleted.
	 */
	public static function delete_application_password( $user_id, $slug ) {
		$passwords = self::get_user_application_passwords( $user_id );

		foreach ( $passwords as $key => $item ) {
			if ( self::password_unique_slug( $item ) === $slug ) {
				unset( $passwords[ $key ] );
				self::set_user_application_passwords( $user_id, $passwords );
				return true;
			}
		}

		// Specified Application Password not found!
		return false;
	}

	/**
	 * Deletes all application passwords for the given user.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param int    $user_id User ID.
	 * @return int   The number of passwords that were deleted.
	 */
	public static function delete_all_application_passwords( $user_id ) {
		$passwords = self::get_user_application_passwords( $user_id );

		if ( is_array( $passwords ) ) {
			self::set_user_application_passwords( $user_id, array() );
			return sizeof( $passwords );
		}

		return 0;
	}

	/**
	 * Generate a unique repeatable slug from the hashed password, name, and when it was created.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function password_unique_slug( $item ) {
		$concat = $item['name'] . '|' . $item['password'] . '|' . $item['created'];
		$hash   = md5( $concat );
		return substr( $hash, 0, 12 );
	}

	/**
	 * Sanitize and then split a password into smaller chunks.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param string $raw_password Users raw password.
	 * @return string
	 */
	public static function chunk_password( $raw_password ) {
		$raw_password = preg_replace( '/[^a-z\d]/i', '', $raw_password );
		return trim( chunk_split( $raw_password, 4, ' ' ) );
	}

	/**
	 * Get a users application passwords.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_user_application_passwords( $user_id ) {
		$passwords = get_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, true );
		if ( ! is_array( $passwords ) ) {
			return array();
		}
		return $passwords;
	}

	/**
	 * Gets a user's application password with the given slug.
	 *
	 * @since ?.?.0
	 *
	 * @param int    $user_id The user id.
	 * @param string $slug    The slug.
	 * @return array|null
	 */
	public static function get_user_application_password( $user_id, $slug ) {
		$passwords = self::get_user_application_passwords( $user_id );

		foreach ( $passwords as $password ) {
			if ( self::password_unique_slug( $password ) === $slug ) {
				return $password;
			}
		}

		return null;
	}

	/**
	 * Set a users application passwords.
	 *
	 * @since ?.?.0
	 *
	 * @access public
	 * @static
	 *
	 * @param int   $user_id User ID.
	 * @param array $passwords Application passwords.
	 *
	 * @return bool
	 */
	public static function set_user_application_passwords( $user_id, $passwords ) {
		return update_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords );
	}
}
