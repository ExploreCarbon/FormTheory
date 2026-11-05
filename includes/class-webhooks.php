<?php
defined( 'ABSPATH' ) || exit;

/** Posts entries to configured HTTPS endpoints after submission (fire-and-forget). */
class FormTheory_Webhooks {

	public static function init() {
		add_action( 'formtheory_after_submission', array( __CLASS__, 'dispatch' ), 10, 3 );
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
			// wp_safe_remote_post refuses private/loopback hosts (SSRF guard).
			wp_safe_remote_post( $hook['url'], array(
				'body'     => $body,
				'headers'  => $headers,
				'timeout'  => 5,
				'blocking' => false,
			) );
		}
	}
}
