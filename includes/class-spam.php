<?php
defined( 'ABSPATH' ) || exit;

/**
 * Spam checks: signed time-trap (rejects submits faster than MIN_SECONDS)
 * and optional Cloudflare Turnstile when both keys are configured.
 */
class FormTheory_Spam {

	const MIN_SECONDS = 3;
	const VERIFY_URL  = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/** Settings array: turnstile_site, turnstile_secret. */
	public static function settings() {
		$s = get_option( 'formtheory_settings', array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array( 'turnstile_site' => '', 'turnstile_secret' => '' ) );
	}

	public static function turnstile_enabled() {
		$s = self::settings();
		return '' !== $s['turnstile_site'] && '' !== $s['turnstile_secret'];
	}

	/** Signed render timestamp: "<time>.<hmac>". */
	public static function stamp( $time = null ) {
		$time = null === $time ? time() : (int) $time;
		return $time . '.' . wp_hash( 'formtheory_ts|' . $time );
	}

	/** @return true|WP_Error */
	public static function check( array $post ) {
		$fail  = new WP_Error( 'ft_spam', __( 'Spam check failed. Please try again.', 'formtheory' ) );
		$parts = explode( '.', (string) ( $post['ft_ts'] ?? '' ), 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! hash_equals( wp_hash( 'formtheory_ts|' . $parts[0] ), $parts[1] ) ) {
			return $fail;
		}
		if ( time() - (int) $parts[0] < self::MIN_SECONDS ) {
			return $fail;
		}
		if ( ! self::turnstile_enabled() ) {
			return true;
		}
		$token = (string) ( $post['cf-turnstile-response'] ?? '' );
		if ( '' === $token ) {
			return $fail;
		}
		$res = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => self::settings()['turnstile_secret'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $fail;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['success'] ) ? true : $fail;
	}
}
