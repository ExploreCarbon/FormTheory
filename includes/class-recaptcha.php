<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google reCAPTCHA (v2 checkbox or v3 score) for every FormTheory form.
 * Configured under FormTheory > Settings; off until both keys are set.
 */
class FormTheory_Recaptcha {

	const OPTION = 'formtheory_recaptcha';
	const VERIFY = 'https://www.google.com/recaptcha/api/siteverify';
	const ACTION = 'formtheory_submit';

	public static function settings() {
		$s = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'version'    => '',
				'site_key'   => '',
				'secret_key' => '',
				'threshold'  => 0.5,
			)
		);
		// Keys may also come from wp-config.php so secrets stay out of the DB.
		if ( defined( 'FORMTHEORY_RECAPTCHA_SITE_KEY' ) ) {
			$s['site_key'] = (string) FORMTHEORY_RECAPTCHA_SITE_KEY;
		}
		if ( defined( 'FORMTHEORY_RECAPTCHA_SECRET_KEY' ) ) {
			$s['secret_key'] = (string) FORMTHEORY_RECAPTCHA_SECRET_KEY;
		}
		return $s;
	}

	/** 'v2', 'v3' or '' (off / incomplete). */
	public static function version() {
		$s = self::settings();
		if ( ! in_array( $s['version'], array( 'v2', 'v3' ), true ) || '' === $s['site_key'] || '' === $s['secret_key'] ) {
			return '';
		}
		return $s['version'];
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$key = function ( $v ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
		};
		$t   = isset( $in['threshold'] ) ? (float) $in['threshold'] : 0.5;
		return array(
			'version'    => in_array( $in['version'] ?? '', array( 'v2', 'v3' ), true ) ? $in['version'] : '',
			'site_key'   => $key( $in['site_key'] ?? '' ),
			'secret_key' => $key( $in['secret_key'] ?? '' ),
			'threshold'  => max( 0.0, min( 1.0, $t ) ),
		);
	}

	/* ---------- Front end ---------- */

	/** Enqueue Google's script (once) and return the markup placed in the form. */
	public static function render() {
		$v = self::version();
		if ( ! $v ) {
			return '';
		}
		$key = self::settings()['site_key'];
		$src = 'v3' === $v ? add_query_arg( 'render', rawurlencode( $key ), 'https://www.google.com/recaptcha/api.js' ) : 'https://www.google.com/recaptcha/api.js';
		wp_enqueue_script( 'formtheory-recaptcha', $src, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google-hosted.

		if ( 'v3' === $v ) {
			return '<input type="hidden" name="g-recaptcha-response" value="" data-ft-recaptcha-v3="' . esc_attr( $key ) . '" data-ft-recaptcha-action="' . esc_attr( self::ACTION ) . '">'
				. '<p class="ft-recaptcha-note ft-help">' . wp_kses(
					__( 'This site is protected by reCAPTCHA and the Google <a href="https://policies.google.com/privacy">Privacy Policy</a> and <a href="https://policies.google.com/terms">Terms of Service</a> apply.', 'formtheory' ),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>';
		}
		return '<div class="ft-field ft-recaptcha"><div class="g-recaptcha" data-sitekey="' . esc_attr( $key ) . '"></div></div>';
	}

	/**
	 * Verify the posted token. Returns '' on pass (or when disabled), else an error message.
	 * Fails closed if Google cannot be reached.
	 */
	public static function verify() {
		$v = self::version();
		if ( ! $v ) {
			return '';
		}
		$token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by caller.
		$fail  = 'v2' === $v ? __( 'Please complete the reCAPTCHA check.', 'formtheory' ) : __( 'Spam check failed. Please try again.', 'formtheory' );
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return $fail;
		}
		$s    = self::settings();
		$args = array(
			'secret'   => $s['secret_key'],
			'response' => $token,
		);
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
		if ( $ip ) {
			$args['remoteip'] = $ip;
		}
		$res = wp_remote_post( self::VERIFY, array( 'timeout' => 10, 'body' => $args ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return __( 'Could not verify the spam check. Please try again.', 'formtheory' );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return $fail;
		}
		if ( 'v3' === $v ) {
			if ( ( $body['action'] ?? '' ) !== self::ACTION ) {
				return $fail;
			}
			$threshold = (float) apply_filters( 'formtheory_recaptcha_threshold', $s['threshold'] );
			if ( (float) ( $body['score'] ?? 0 ) < $threshold ) {
				return $fail;
			}
		}
		return '';
	}

	/* ---------- Admin ---------- */

	public static function register_setting() {
		register_setting( 'formtheory_settings', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/** Settings section (no form wrapper); rendered by FormTheory_Admin::page_settings(). */
	public static function fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$o     = self::OPTION;
		$const = defined( 'FORMTHEORY_RECAPTCHA_SECRET_KEY' );
		?>
				<h2><?php esc_html_e( 'Google reCAPTCHA', 'formtheory' ); ?></h2>
				<p><?php esc_html_e( 'Applies to every form. Get keys at google.com/recaptcha/admin; key type must match the version chosen.', 'formtheory' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ft-rc-version"><?php esc_html_e( 'Version', 'formtheory' ); ?></label></th>
						<td><select id="ft-rc-version" name="<?php echo esc_attr( $o ); ?>[version]">
							<option value="" <?php selected( $s['version'], '' ); ?>><?php esc_html_e( 'Off', 'formtheory' ); ?></option>
							<option value="v2" <?php selected( $s['version'], 'v2' ); ?>><?php esc_html_e( 'v2 checkbox ("I\'m not a robot")', 'formtheory' ); ?></option>
							<option value="v3" <?php selected( $s['version'], 'v3' ); ?>><?php esc_html_e( 'v3 invisible (score)', 'formtheory' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="ft-rc-site"><?php esc_html_e( 'Site key', 'formtheory' ); ?></label></th>
						<td><input id="ft-rc-site" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr( $o ); ?>[site_key]" value="<?php echo esc_attr( $s['site_key'] ); ?>" <?php disabled( defined( 'FORMTHEORY_RECAPTCHA_SITE_KEY' ) ); ?>></td>
					</tr>
					<tr>
						<th scope="row"><label for="ft-rc-secret"><?php esc_html_e( 'Secret key', 'formtheory' ); ?></label></th>
						<td>
							<input id="ft-rc-secret" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $o ); ?>[secret_key]" value="<?php echo esc_attr( $const ? '' : $s['secret_key'] ); ?>" <?php disabled( $const ); ?>>
							<p class="description"><?php echo $const ? esc_html__( 'Set in wp-config.php (FORMTHEORY_RECAPTCHA_SECRET_KEY).', 'formtheory' ) : esc_html__( 'Or define FORMTHEORY_RECAPTCHA_SITE_KEY / FORMTHEORY_RECAPTCHA_SECRET_KEY in wp-config.php.', 'formtheory' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ft-rc-threshold"><?php esc_html_e( 'v3 score threshold', 'formtheory' ); ?></label></th>
						<td>
							<input id="ft-rc-threshold" type="number" min="0" max="1" step="0.1" name="<?php echo esc_attr( $o ); ?>[threshold]" value="<?php echo esc_attr( $s['threshold'] ); ?>">
							<p class="description"><?php esc_html_e( '0.0 (bot) to 1.0 (human). Submissions scoring below are rejected. Default 0.5.', 'formtheory' ); ?></p>
						</td>
					</tr>
				</table>
		<?php
	}
}
