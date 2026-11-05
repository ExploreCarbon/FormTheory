<?php
defined( 'ABSPATH' ) || exit;

/**
 * Posts entries to configured HTTPS endpoints. Requests are queued with
 * WP-Cron so a slow endpoint never delays the visitor's submission.
 */
class FormTheory_Webhooks {

	public static function init() {
		add_action( 'formtheory_after_submission', array( __CLASS__, 'dispatch' ), 10, 3 );
		add_action( 'formtheory_send_webhook', array( __CLASS__, 'send' ), 10, 2 );
	}

	public static function payload( array $form, array $data, $entry_id ) {
		$fields = array();
		foreach ( $data as $id => $v ) {
			$fields[ $id ] = ( is_array( $v ) && isset( $v['file'] ) ) ? (string) ( $v['name'] ?? '' ) : $v;
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
			wp_schedule_single_event( time(), 'formtheory_send_webhook', array( $hook['url'], array( 'body' => $body, 'headers' => $headers ) ) );
		}
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
