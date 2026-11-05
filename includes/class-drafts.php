<?php
defined( 'ABSPATH' ) || exit;

/**
 * Save-and-resume drafts. A draft is reachable only through a random bearer
 * token; only its sha256 hash is stored. Files and drawn signatures are never
 * saved. Times are UTC (gmdate) so expiry does not depend on the site timezone.
 */
class FormTheory_Drafts {

	const DEFAULT_TTL  = 2592000; // 30 days.
	const MIN_TTL      = 3600;    // 1 hour.
	const MAX_TTL      = 7776000; // 90 days.
	const MAX_BYTES    = 262144;  // 256 KB of stored JSON per draft.
	const TOKEN_REGEX  = '/^[A-Za-z0-9]{32}$/';
	const RL_EMAIL     = 5;       // Saves that send an email, per IP per hour.
	const RL_CREATE    = 20;      // Draft saves of any kind, per IP per hour.

	public static function table() {
		return FormTheory_DB::drafts_table();
	}

	/** Lifetime in seconds: filter `formtheory_draft_ttl`, clamped to 1 hour .. 90 days. */
	public static function ttl() {
		$ttl = (int) apply_filters( 'formtheory_draft_ttl', self::DEFAULT_TTL );
		return max( self::MIN_TTL, min( $ttl, self::MAX_TTL ) );
	}

	public static function valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( self::TOKEN_REGEX, $token );
	}

	private static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Keep only values of known data fields; drop file fields; signatures keep the typed name only.
	 *
	 * @param array $fields Data fields (FormTheory_Fields::data_fields()).
	 */
	public static function strip( array $fields, array $values ) {
		$out = array();
		foreach ( $fields as $f ) {
			$id = $f['id'];
			if ( ! array_key_exists( $id, $values ) || 'file' === $f['type'] || 'calc' === $f['type'] ) {
				continue;
			}
			$v = $values[ $id ];
			if ( 'signature' === $f['type'] ) {
				$v = is_array( $v ) && isset( $v['typed'] ) && is_string( $v['typed'] ) && '' !== $v['typed'] ? array( 'typed' => $v['typed'] ) : '';
			}
			$out[ $id ] = $v;
		}
		return $out;
	}

	/**
	 * @param array|null $fields Data fields used to strip files/signatures; null = load the form's.
	 * @return string|WP_Error Raw token (shown to the visitor once, never stored).
	 */
	public static function create( $form_id, array $values, $page, $fields = null ) {
		global $wpdb;
		$form_id = (int) $form_id;
		if ( null === $fields ) {
			$form   = FormTheory_DB::get_form( $form_id );
			$fields = $form ? FormTheory_Fields::data_fields( $form['config']['fields'] ) : array();
		}
		$json = wp_json_encode( self::strip( $fields, $values ) );
		if ( false === $json || strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'ft_draft_size', __( 'There is too much to save. Please shorten some answers and try again.', 'formtheory' ) );
		}
		$token = wp_generate_password( 32, false );
		$ok    = $wpdb->insert(
			self::table(),
			array(
				'form_id'    => $form_id,
				'token_hash' => self::hash( $token ),
				'data'       => $json,
				'page'       => max( 0, min( 255, (int) $page ) ),
				'created_at' => self::now(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::ttl() ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'ft_draft_db', __( 'Your progress could not be saved. Please try again.', 'formtheory' ) );
		}
		return $token;
	}

	/** @return array|null { values: array, page: int } - null for unknown, expired, wrong-form or malformed tokens alike. */
	public static function load( $form_id, $token ) {
		global $wpdb;
		if ( ! self::valid_token( $token ) ) {
			return null;
		}
		$hash = self::hash( $token );
		$now  = self::now();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s AND expires_at > %s', $hash, $now ), ARRAY_A );
		if ( ! $row || ! hash_equals( (string) $row['token_hash'], $hash ) || (int) $row['form_id'] !== (int) $form_id || (string) $row['expires_at'] <= $now ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'values' => is_array( $data ) ? $data : array(),
			'page'   => max( 0, (int) $row['page'] ),
		);
	}

	/** Delete the draft for a token (optionally only if it belongs to $form_id). */
	public static function delete( $token, $form_id = 0 ) {
		global $wpdb;
		if ( ! self::valid_token( $token ) ) {
			return false;
		}
		$where = array( 'token_hash' => self::hash( $token ) );
		$fmt   = array( '%s' );
		if ( $form_id ) {
			$where['form_id'] = (int) $form_id;
			$fmt[]            = '%d';
		}
		return (bool) $wpdb->delete( self::table(), $where, $fmt );
	}

	public static function delete_for_form( $form_id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'form_id' => (int) $form_id ), array( '%d' ) );
	}

	/** Daily cron: remove expired drafts. Independent of entry retention. @return int */
	public static function purge_expired() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at <= %s', self::now() ) );
	}

	/**
	 * Privacy eraser: delete drafts holding $email as an exact (case-insensitive)
	 * value, at top level or nested (address parts, repeater cells). @return int
	 */
	public static function erase_by_email( $email ) {
		global $wpdb;
		$email = self::lower( trim( (string) $email ) );
		if ( '' === $email ) {
			return 0;
		}
		$table = self::table();
		$ascii = ! preg_match( '/[^\x20-\x7e]/', $email );
		$like  = $ascii ? '%' . $wpdb->esc_like( substr( wp_json_encode( $email ), 1, -1 ) ) . '%' : '';
		$last  = 0;
		$ids   = array();
		do {
			if ( $ascii ) {
				$sql = $wpdb->prepare( "SELECT id, data FROM $table WHERE data LIKE %s AND id > %d ORDER BY id ASC LIMIT 500", $like, $last );
			} else {
				$sql = $wpdb->prepare( "SELECT id, data FROM $table WHERE id > %d ORDER BY id ASC LIMIT 500", $last );
			}
			$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $rows as $r ) {
				$last = max( $last, (int) $r['id'] );
				$d    = json_decode( (string) $r['data'], true );
				if ( is_array( $d ) && self::contains( $d, $email ) ) {
					$ids[] = (int) $r['id'];
				}
			}
		} while ( count( $rows ) >= 500 );
		$n = 0;
		foreach ( $ids as $id ) {
			if ( $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function contains( array $d, $email ) {
		foreach ( $d as $v ) {
			if ( is_array( $v ) ? self::contains( $v, $email ) : ( is_scalar( $v ) && self::lower( trim( (string) $v ) ) === $email ) ) {
				return true;
			}
		}
		return false;
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/**
	 * Fixed-window per-IP counter (window starts at the first hit, never slides).
	 * Returns false (and does not count) once $max hits were used this hour.
	 *
	 * @param string $kind 'email' => formtheory_draft_rl_<md5(ip)>, else formtheory_draft_rlc_<md5(ip)>.
	 */
	public static function rate_hit( $kind, $max ) {
		$key  = ( 'email' === $kind ? 'formtheory_draft_rl_' : 'formtheory_draft_rlc_' ) . md5( FormTheory_DB::client_ip() );
		$cur  = get_transient( $key );
		$now  = time();
		$cur  = is_array( $cur ) && isset( $cur['n'], $cur['until'] ) && (int) $cur['until'] > $now ? $cur : array( 'n' => 0, 'until' => $now + 3600 );
		if ( (int) $cur['n'] >= (int) $max ) {
			return false;
		}
		$cur['n'] = (int) $cur['n'] + 1;
		set_transient( $key, $cur, max( 1, (int) $cur['until'] - $now ) );
		return true;
	}
}
