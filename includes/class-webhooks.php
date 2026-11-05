<?php
defined( 'ABSPATH' ) || exit;

/**
 * Posts entries to configured HTTPS endpoints. Requests are queued with
 * WP-Cron so a slow endpoint never delays the visitor's submission.
 */
class FormTheory_Webhooks {

	public static function init() {
		add_action( 'formtheory_after_submission', array( __CLASS__, 'dispatch' ), 10, 3 );
		add_action( 'formtheory_send_webhook', array( __CLASS__, 'send' ), 10, 2 ); // Legacy: events queued by 1.1.
		add_action( 'formtheory_deliver_webhook', array( __CLASS__, 'deliver' ), 10, 1 );
	}

	public static function payload( array $form, array $data, $entry_id ) {
		$fields = array();
		foreach ( $data as $id => $v ) {
			if ( is_array( $v ) && ( isset( $v['file'] ) || isset( $v['sig'] ) ) ) {
				$v = (string) ( $v['name'] ?? '' );
			} elseif ( is_array( $v ) && isset( $v['typed'] ) ) {
				$v = (string) $v['typed'];
			}
			$fields[ $id ] = $v;
		}
		return array(
			'form_id'      => (int) $form['id'],
			'form_title'   => (string) $form['title'],
			'entry_id'     => (int) $entry_id,
			'submitted_at' => gmdate( 'c' ),
			'fields'       => $fields,
		);
	}

	public static function dispatch( $entry_id, $form, $data ) {
		foreach ( $form['config']['webhooks'] ?? array() as $hook ) {
			if ( empty( $hook['url'] ) || ! FormTheory_Fields::cond_matches( $hook['cond'] ?? array(), $data ) ) {
				continue;
			}
			$payload = self::payload( $form, $data, $entry_id );
			if ( 'form' === ( $hook['format'] ?? 'json' ) ) {
				$body    = array_merge( $payload['fields'], array( 'form_id' => $payload['form_id'], 'entry_id' => $payload['entry_id'] ) );
				$signed  = http_build_query( $body );
				$headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
			} else {
				$body    = wp_json_encode( $payload );
				$signed  = $body;
				$headers = array( 'Content-Type' => 'application/json' );
			}
			if ( ! empty( $hook['secret'] ) ) {
				$headers['X-FormTheory-Signature'] = 'sha256=' . hash_hmac( 'sha256', $signed, $hook['secret'] );
			}
			// The log row holds the signed header, never the secret itself.
			$log_id = FormTheory_DB::log_webhook( (int) $form['id'], (int) $entry_id, $hook['url'], array( 'body' => $body, 'headers' => $headers ) );
			wp_schedule_single_event( time(), 'formtheory_deliver_webhook', array( $log_id ) );
		}
	}

	/** Delay for failure number $attempts + 1: 60, 240, 960 s. */
	public static function next_delay( $attempts ) {
		return 60 * 4 ** max( 0, (int) $attempts );
	}

	/** Sanitized, length-capped error text for the log. */
	private static function clean_error( $text ) {
		$text = sanitize_text_field( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255, 'UTF-8' ) : substr( $text, 0, 255 );
	}

	/** Display form of a stored URL: host + path only (query strings may hold tokens; userinfo dropped). */
	public static function display_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		return $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( $p['path'] ?? '' );
	}

	/** Cron callback. 3 total attempts: failures 1 and 2 reschedule (60 s, 240 s); failure 3 marks failed. */
	public static function deliver( $log_id ) {
		$row = FormTheory_DB::get_webhook_log( (int) $log_id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return;
		}
		if ( ! FormTheory_DB::claim_webhook( (int) $log_id, (int) $row['attempts'] ) ) {
			return; // Another worker owns this attempt.
		}
		$request = json_decode( (string) $row['request'], true );
		$request = is_array( $request ) ? $request : array();
		$res     = wp_safe_remote_post( $row['url'], array(
			'body'     => $request['body'] ?? '',
			'headers'  => $request['headers'] ?? array(),
			'timeout'  => 5,
			'blocking' => true,
		) );
		$error = '';
		$code  = 0;
		if ( is_wp_error( $res ) ) {
			$error = self::clean_error( $res->get_error_message() );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
		}
		if ( $code >= 200 && $code < 300 ) {
			FormTheory_DB::update_webhook_log( (int) $log_id, array( 'status' => 'ok', 'http_code' => $code, 'error' => '' ) );
			return;
		}
		$attempts = (int) $row['attempts'] + 1;
		$fields   = array( 'attempts' => $attempts, 'http_code' => $code, 'error' => $error );
		if ( $attempts < 3 ) {
			$fields['status'] = 'pending';
			// attempts is already incremented, so attempts - 1 gives 60 s then 240 s.
			wp_schedule_single_event( time() + self::next_delay( $attempts - 1 ), 'formtheory_deliver_webhook', array( (int) $log_id ) );
		} else {
			$fields['status'] = 'failed';
		}
		FormTheory_DB::update_webhook_log( (int) $log_id, $fields );
	}

	/** Admin retry: failed rows only. Resets and reschedules. @return bool */
	public static function retry( $log_id ) {
		if ( ! FormTheory_DB::reset_failed_webhook( (int) $log_id ) ) {
			return false;
		}
		if ( ! wp_schedule_single_event( time(), 'formtheory_deliver_webhook', array( (int) $log_id ) ) ) {
			FormTheory_DB::update_webhook_log( (int) $log_id, array( 'status' => 'failed', 'attempts' => 3 ) );
			return false;
		}
		return true;
	}

	/** Cron callback. wp_safe_remote_post refuses private/loopback hosts (SSRF guard). */
	public static function send( $url, $request ) {
		wp_safe_remote_post( $url, array(
			'body'     => $request['body'] ?? '',
			'headers'  => $request['headers'] ?? array(),
			'timeout'  => 5,
			'blocking' => true,
		) );
	}
}
