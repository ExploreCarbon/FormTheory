<?php
defined( 'ABSPATH' ) || exit;

/**
 * Field schema, config sanitizing, conditional logic and validation.
 */
class FormTheory_Fields {

	const TYPES = array( 'text', 'email', 'phone', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'file', 'page', 'hidden', 'consent', 'html', 'calc', 'rating', 'address', 'signature', 'repeater' );
	/** Types whose value is structured: never a conditional-logic source. */
	const NON_COND_SOURCE = array( 'address', 'signature', 'repeater' );
	/** Repeater column types, the column cap, and ids value_to_text() treats as markers. */
	const REPEATER_COL_TYPES = array( 'text', 'email', 'number', 'phone', 'date', 'textarea', 'select' );
	const REPEATER_MAX_COLS  = 6;
	const REPEATER_RESERVED  = array( 'file', 'sig', 'typed' );
	/** Address parts in canonical (render and storage) order. */
	const ADDRESS_PARTS = array( 'street', 'street2', 'city', 'state', 'zip', 'country' );
	/** Address parts enabled when none are configured. */
	const ADDRESS_DEFAULT_PARTS = array( 'street', 'city', 'state', 'zip', 'country' );
	/** Layout-only types: never validated or stored. */
	const NON_DATA = array( 'page', 'html' );
	const OPS   = array( 'is', 'isnot', 'contains', 'gt', 'lt', 'empty', 'notempty' );

	/** Hard-blocked extensions regardless of form config. */
	const BLOCKED_EXT = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'pht', 'exe', 'js', 'sh', 'bat', 'cmd', 'html', 'htm', 'svg', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'htaccess' );

	public static function has_options( $type ) {
		return in_array( $type, array( 'select', 'radio', 'checkbox' ), true );
	}

	/** Sanitize builder JSON into a trusted config. */
	public static function sanitize_config( $raw ) {
		$cfg = FormTheory_DB::default_config();
		if ( ! is_array( $raw ) ) {
			return $cfg;
		}
		$cfg['submit_label']   = sanitize_text_field( $raw['submit_label'] ?? __( 'Submit', 'formtheory' ) );
		$cfg['confirmation']   = wp_kses_post( $raw['confirmation'] ?? '' );
		$cfg['notify_subject'] = sanitize_text_field( $raw['notify_subject'] ?? '' );
		$cfg['ajax']              = ! array_key_exists( 'ajax', $raw ) || ! empty( $raw['ajax'] );
		$cfg['save_resume']       = ! empty( $raw['save_resume'] );
		$cfg['store_entries']     = ! array_key_exists( 'store_entries', $raw ) || ! empty( $raw['store_entries'] );
		$cfg['auto_advance']      = ! empty( $raw['auto_advance'] );
		$cfg['confirmation_type'] = in_array( $raw['confirmation_type'] ?? '', array( 'message', 'page', 'redirect', 'score' ), true ) ? $raw['confirmation_type'] : 'message';
		$cfg['confirmation_page'] = absint( $raw['confirmation_page'] ?? 0 );
		$cfg['confirmation_url']  = self::sanitize_url_template( $raw['confirmation_url'] ?? '' );
		$to                    = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) ( $raw['notify_to'] ?? '' ) ) ) ) );
		$cfg['notify_to']      = implode( ',', $to );

		$fields = array();
		$ids    = array();
		foreach ( (array) ( $raw['fields'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$type = $f['type'] ?? '';
			$id   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $f['id'] ?? '' ) ) );
			if ( ! in_array( $type, self::TYPES, true ) || '' === $id || isset( $ids[ $id ] ) ) {
				continue;
			}
			$ids[ $id ] = true;
			$field      = array(
				'id'          => $id,
				'type'        => $type,
				'label'       => sanitize_text_field( $f['label'] ?? '' ),
				'required'    => ! empty( $f['required'] ) && ! in_array( $type, array( 'page', 'html', 'hidden', 'calc' ), true ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
				'help'        => sanitize_text_field( $f['help'] ?? '' ),
				'options'     => array(),
			);
			if ( self::has_options( $type ) ) {
				foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
					$o = sanitize_text_field( $o );
					if ( '' !== $o ) {
						$field['options'][] = $o;
					}
				}
			}
			if ( in_array( $type, array( 'radio', 'select' ), true ) ) {
				$field['points'] = array();
				$given           = array_values( (array) ( $f['points'] ?? array() ) );
				foreach ( array_keys( $field['options'] ) as $i ) {
					$p                 = $given[ $i ] ?? 0;
					$field['points'][] = is_numeric( $p ) ? (float) max( -10000, min( 10000, $p ) ) : 0.0;
				}
				$field['category'] = mb_substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $f['category'] ?? '' ) ) ), 0, 40 );
			}
			if ( 'file' === $type ) {
				$exts = array_filter( array_map( function ( $e ) {
					return preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $e ) ) );
				}, explode( ',', (string) ( $f['allowed'] ?? 'jpg,jpeg,png,pdf' ) ) ) );
				$field['allowed'] = implode( ',', array_diff( $exts, self::BLOCKED_EXT ) );
				$field['max_mb']  = max( 1, min( 100, (int) ( $f['max_mb'] ?? 5 ) ) );
			}
			if ( 'rating' === $type ) {
				$field['max'] = self::rating_max( $f['max'] ?? 5 );
			}
			if ( 'address' === $type ) {
				$field['parts']           = self::address_parts( $f['parts'] ?? array() );
				$field['country_default'] = mb_substr( sanitize_text_field( $f['country_default'] ?? '' ), 0, 100 );
			}
			if ( 'repeater' === $type ) {
				$field['columns']   = self::repeater_columns( $f['columns'] ?? array() );
				$field['min_rows']  = is_numeric( $f['min_rows'] ?? null ) ? max( 0, min( 20, (int) $f['min_rows'] ) ) : 1;
				$field['max_rows']  = max( $field['min_rows'], is_numeric( $f['max_rows'] ?? null ) ? max( 1, min( 20, (int) $f['max_rows'] ) ) : 5 );
				$add_label          = mb_substr( sanitize_text_field( $f['add_label'] ?? '' ), 0, 60 );
				$field['add_label'] = '' !== $add_label ? $add_label : __( 'Add row', 'formtheory' );
				if ( ! $field['columns'] ) {
					$field['required'] = false; // Nothing to fill in, so it can never be satisfied.
				}
			}
			if ( 'hidden' === $type ) {
				$field['default'] = sanitize_text_field( $f['default'] ?? '' );
			}
			if ( 'consent' === $type ) {
				$field['text'] = wp_kses_post( $f['text'] ?? '' );
			}
			if ( 'html' === $type ) {
				$field['content'] = wp_kses_post( $f['content'] ?? '' );
			}
			if ( 'calc' === $type ) {
				$formula           = trim( (string) ( $f['formula'] ?? '' ) );
				$field['formula']  = FormTheory_Calc::is_valid( $formula ) ? $formula : '';
				$field['decimals'] = max( 0, min( 6, (int) ( $f['decimals'] ?? 2 ) ) );
				$field['prefix']   = mb_substr( sanitize_text_field( $f['prefix'] ?? '' ), 0, 20 );
				$field['suffix']   = mb_substr( sanitize_text_field( $f['suffix'] ?? '' ), 0, 20 );
			}
			$field['cond'] = self::sanitize_cond( $f['cond'] ?? array() );
			$fields[]      = $field;
		}
		// A calc field with no (valid) formula, and structured types (address, ...), are never condition sources.
		foreach ( $fields as $cf ) {
			if ( ( 'calc' === $cf['type'] && '' === $cf['formula'] ) || in_array( $cf['type'], self::NON_COND_SOURCE, true ) ) {
				unset( $ids[ $cf['id'] ] );
			}
		}
		// Drop condition rules pointing to unknown fields.
		foreach ( $fields as &$field ) {
			$field['cond']['rules'] = array_values( array_filter( $field['cond']['rules'], function ( $r ) use ( $ids, $field ) {
				return isset( $ids[ $r['field'] ] ) && $r['field'] !== $field['id'];
			} ) );
			if ( ! $field['cond']['rules'] ) {
				$field['cond']['enabled'] = false;
			}
		}
		$cfg['fields'] = $fields;

		$cats    = array();
		foreach ( $fields as $sf ) {
			if ( ! empty( $sf['category'] ) ) {
				$cats[ $sf['category'] ] = true;
			}
		}
		$sraw    = is_array( $raw['scoring'] ?? null ) ? $raw['scoring'] : array();
		$spages  = array();
		foreach ( (array) ( $sraw['pages'] ?? array() ) as $cat => $pid ) {
			if ( isset( $cats[ $cat ] ) && absint( $pid ) ) {
				$spages[ $cat ] = absint( $pid );
			}
		}
		$cfg['scoring'] = array(
			'enabled'      => ! empty( $sraw['enabled'] ),
			'threshold'    => max( 0, min( 100, (int) ( $sraw['threshold'] ?? 80 ) ) ),
			'overall_page' => absint( $sraw['overall_page'] ?? 0 ),
			'pages'        => $spages,
		);

		if ( array_key_exists( 'notifications', $raw ) ) {
			$cfg['notifications'] = array();
			foreach ( array_values( (array) $raw['notifications'] ) as $i => $n ) {
				if ( is_array( $n ) ) {
					$cfg['notifications'][] = self::sanitize_notification( $n, $i, $ids );
				}
			}
		}
		$cfg['webhooks'] = array();
		foreach ( (array) ( $raw['webhooks'] ?? array() ) as $h ) {
			$url = is_array( $h ) ? esc_url_raw( trim( (string) ( $h['url'] ?? '' ) ) ) : '';
			if ( ! preg_match( '#^https://#i', $url ) ) {
				continue;
			}
			$cond          = self::sanitize_cond( $h['cond'] ?? array() );
			$cond['rules'] = array_values( array_filter( $cond['rules'], function ( $r ) use ( $ids ) {
				return isset( $ids[ $r['field'] ] );
			} ) );
			$cond['enabled']   = $cond['enabled'] && $cond['rules'];
			$cfg['webhooks'][] = array(
				'url'    => $url,
				'format' => 'form' === ( $h['format'] ?? '' ) ? 'form' : 'json',
				'secret' => sanitize_text_field( $h['secret'] ?? '' ),
				'cond'   => $cond,
			);
		}
		return self::migrate_config( $cfg );
	}

	/**
	 * http(s) URL that may contain merge tags. Tags are shielded from
	 * esc_url_raw (which strips braces) and restored afterwards.
	 */
	public static function sanitize_url_template( $url ) {
		$tags = array();
		$url  = preg_replace_callback( '/\{[a-z_]+(?::[a-z0-9_]+)?\}/', function ( $m ) use ( &$tags ) {
			$key          = 'fttag' . count( $tags ) . 'x';
			$tags[ $key ] = $m[0];
			return $key;
		}, trim( (string) $url ) );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$url = esc_url_raw( $url );
		return '' === $url ? '' : strtr( $url, $tags );
	}

	/** Recipient list: emails or {field:ID} tags, comma separated. */
	private static function sanitize_recipients( $raw ) {
		$out = array();
		foreach ( explode( ',', (string) $raw ) as $r ) {
			$r = trim( $r );
			if ( preg_match( '/^\{(field:[a-z0-9_]+|admin_email)\}$/', $r ) || is_email( $r ) ) {
				$out[] = $r;
			}
		}
		return implode( ',', $out );
	}

	private static function sanitize_notification( array $n, $i, array $ids ) {
		$cond          = self::sanitize_cond( $n['cond'] ?? array() );
		$cond['rules'] = array_values( array_filter( $cond['rules'], function ( $r ) use ( $ids ) {
			return isset( $ids[ $r['field'] ] );
		} ) );
		$cond['enabled'] = $cond['enabled'] && $cond['rules'];
		$id              = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $n['id'] ?? '' ) ) );
		return array(
			'id'           => '' !== $id ? $id : 'n' . ( $i + 1 ),
			'to'           => self::sanitize_recipients( $n['to'] ?? '' ),
			'subject'      => sanitize_text_field( $n['subject'] ?? '' ),
			'body'         => sanitize_textarea_field( $n['body'] ?? '' ),
			'reply_to'     => self::sanitize_recipients( $n['reply_to'] ?? '' ),
			'attach_files' => ! empty( $n['attach_files'] ),
			'cond'         => $cond,
		);
	}

	/** Upgrade 1.0 configs (single notify_to/notify_subject) to the notifications list. */
	public static function migrate_config( array $cfg ) {
		if ( ! array_key_exists( 'ajax', $cfg ) ) {
			$cfg['ajax'] = false; // 1.0 forms keep the full-page POST they were built with.
		}
		if ( isset( $cfg['notifications'] ) && is_array( $cfg['notifications'] ) ) {
			return $cfg;
		}
		$cfg['notifications'] = array();
		if ( ! empty( $cfg['notify_to'] ) ) {
			$reply = '';
			foreach ( $cfg['fields'] ?? array() as $f ) {
				if ( 'email' === $f['type'] ) {
					$reply = '{field:' . $f['id'] . '}';
					break;
				}
			}
			$cfg['notifications'][] = array(
				'id'           => 'n1',
				'to'           => (string) $cfg['notify_to'],
				'subject'      => (string) ( $cfg['notify_subject'] ?? '' ),
				'body'         => '{all_fields}',
				'reply_to'     => $reply,
				'attach_files' => false,
				'cond'         => array( 'enabled' => false, 'action' => 'show', 'logic' => 'all', 'rules' => array() ),
			);
		}
		return $cfg;
	}

	/** True when the condition's rules match (ignores action). Disabled = true. */
	public static function cond_matches( array $cond, array $values ) {
		if ( empty( $cond['enabled'] ) || empty( $cond['rules'] ) ) {
			return true;
		}
		$hits = array();
		foreach ( $cond['rules'] as $r ) {
			$hits[] = self::rule_matches( $r, $values[ $r['field'] ] ?? '' );
		}
		return 'any' === ( $cond['logic'] ?? 'all' ) ? in_array( true, $hits, true ) : ! in_array( false, $hits, true );
	}

	/** Data-bearing fields (excludes page breaks and HTML blocks). */
	public static function data_fields( array $fields ) {
		return array_values( array_filter( $fields, function ( $f ) {
			return ! in_array( $f['type'], self::NON_DATA, true );
		} ) );
	}

	/** Dynamic population: replaces {query:KEY} with the sanitized GET param. */
	public static function populate( $text ) {
		return preg_replace_callback( '/\{query:([A-Za-z0-9_\-]+)\}/', function ( $m ) {
			$v = $_GET[ $m[1] ] ?? ''; // phpcs:ignore -- read-only, sanitized.
			return is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : '';
		}, (string) $text );
	}

	private static function sanitize_cond( $c ) {
		$out = array(
			'enabled' => ! empty( $c['enabled'] ),
			'action'  => ( ( $c['action'] ?? '' ) === 'hide' ) ? 'hide' : 'show',
			'logic'   => ( ( $c['logic'] ?? '' ) === 'any' ) ? 'any' : 'all',
			'rules'   => array(),
		);
		foreach ( (array) ( $c['rules'] ?? array() ) as $r ) {
			$op = $r['op'] ?? 'is';
			if ( ! in_array( $op, self::OPS, true ) ) {
				continue;
			}
			$out['rules'][] = array(
				'field' => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $r['field'] ?? '' ) ) ),
				'op'    => $op,
				'value' => sanitize_text_field( $r['value'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Plain-text form of any stored value (scalar, list, file, signature,
	 * address parts, repeater rows).
	 */
	public static function value_to_text( $value ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}
		if ( isset( $value['file'] ) ) {
			return (string) ( $value['name'] ?? '' );
		}
		if ( isset( $value['sig'] ) ) {
			/* translators: %s: name typed by the signer. */
			return trim( sprintf( __( '[signature] %s', 'formtheory' ), (string) ( $value['name'] ?? '' ) ) );
		}
		if ( isset( $value['typed'] ) ) {
			/* translators: %s: name typed as a signature. */
			return sprintf( __( '%s (typed)', 'formtheory' ), (string) $value['typed'] );
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list && $value && is_array( reset( $value ) ) ) {
			$rows = array();
			foreach ( $value as $row ) {
				$t = self::value_to_text( $row );
				if ( '' !== $t ) {
					$rows[] = $t;
				}
			}
			return implode( '; ', $rows );
		}
		$parts = array();
		foreach ( $value as $v ) {
			$t = self::value_to_text( $v );
			if ( '' !== $t ) {
				$parts[] = $t;
			}
		}
		return implode( ', ', $parts );
	}

	/** Whitelisted address parts in canonical order; empty or invalid input falls back to the defaults. */
	public static function address_parts( $raw ) {
		$parts = array_values( array_intersect( self::ADDRESS_PARTS, is_array( $raw ) ? array_filter( $raw, 'is_string' ) : array() ) );
		return $parts ? $parts : self::ADDRESS_DEFAULT_PARTS;
	}

	/**
	 * Repeater columns: 1-6 of { id, label, type, options, required }. Invalid
	 * types, reserved marker ids (file/sig/typed) and duplicate ids are dropped;
	 * a missing, unusable or all-digit id gets a server-generated cN id.
	 */
	public static function repeater_columns( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$used = array();
		// Ids the visitor-supplied columns ask for, so generated ids never collide with a later explicit one.
		foreach ( $raw as $c ) {
			if ( is_array( $c ) ) {
				$used[ self::column_id( $c['id'] ?? '' ) ] = true;
			}
		}
		$out  = array();
		$seen = array();
		$next = 0;
		foreach ( $raw as $c ) {
			if ( ! is_array( $c ) || ! in_array( $c['type'] ?? '', self::REPEATER_COL_TYPES, true ) ) {
				continue;
			}
			$id = self::column_id( $c['id'] ?? '' );
			if ( in_array( $id, self::REPEATER_RESERVED, true ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( '' === $id ) {
				do {
					$id = 'c' . ( ++$next );
				} while ( isset( $used[ $id ] ) || isset( $seen[ $id ] ) );
			}
			$seen[ $id ] = true;
			$options     = array();
			if ( 'select' === $c['type'] ) {
				foreach ( (array) ( $c['options'] ?? array() ) as $o ) {
					$o = is_string( $o ) ? sanitize_text_field( $o ) : '';
					if ( '' !== $o ) {
						$options[] = $o;
					}
				}
			}
			$out[] = array(
				'id'       => $id,
				'label'    => mb_substr( sanitize_text_field( is_string( $c['label'] ?? '' ) ? ( $c['label'] ?? '' ) : '' ), 0, 100 ),
				'type'     => $c['type'],
				'options'  => $options,
				'required' => ! empty( $c['required'] ),
			);
			if ( count( $out ) >= self::REPEATER_MAX_COLS ) {
				break;
			}
		}
		return $out;
	}

	/** Column id from the safe pattern [a-z0-9_]+; '' when empty or all digits (PHP would turn those into int keys). */
	private static function column_id( $raw ) {
		$id = preg_replace( '/[^a-z0-9_]/', '', strtolower( is_string( $raw ) ? $raw : '' ) );
		return ctype_digit( $id ) ? '' : $id;
	}

	/** Rating scale size: integer 3-10, default 5 for missing or non-numeric input. */
	public static function rating_max( $v ) {
		return is_numeric( $v ) ? max( 3, min( 10, (int) $v ) ) : 5;
	}

	/** Mirror of assets/front.js evaluateRule(). */
	public static function rule_matches( $rule, $value ) {
		$vals = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$vals = array_values( array_filter( $vals, 'strlen' ) );
		$t    = (string) $rule['value'];
		switch ( $rule['op'] ) {
			case 'is':
				return in_array( $t, $vals, true );
			case 'isnot':
				return ! in_array( $t, $vals, true );
			case 'contains':
				foreach ( $vals as $v ) {
					if ( '' !== $t && false !== stripos( $v, $t ) ) {
						return true;
					}
				}
				return false;
			case 'gt':
				return isset( $vals[0] ) && is_numeric( $vals[0] ) && (float) $vals[0] > (float) $t;
			case 'lt':
				return isset( $vals[0] ) && is_numeric( $vals[0] ) && (float) $vals[0] < (float) $t;
			case 'empty':
				return ! $vals;
			case 'notempty':
				return (bool) $vals;
		}
		return false;
	}

	/**
	 * Visible field ids given submitted values. Fields hidden by their own
	 * condition, or depending on hidden fields, are treated as empty.
	 */
	public static function visible_map( array $fields, array $values ) {
		$visible = array();
		// Iterate to a fixed point so chained conditions resolve regardless of order.
		for ( $pass = 0; $pass <= count( $fields ); $pass++ ) {
			$changed = false;
			foreach ( $fields as $f ) {
				$vis = true;
				if ( $f['cond']['enabled'] ) {
					$hits = array();
					foreach ( $f['cond']['rules'] as $r ) {
						$src    = ( $visible[ $r['field'] ] ?? true ) ? ( $values[ $r['field'] ] ?? '' ) : '';
						$hits[] = self::rule_matches( $r, $src );
					}
					$match = 'any' === $f['cond']['logic'] ? in_array( true, $hits, true ) : ! in_array( false, $hits, true );
					$vis   = 'show' === $f['cond']['action'] ? $match : ! $match;
				}
				if ( ! isset( $visible[ $f['id'] ] ) || $visible[ $f['id'] ] !== $vis ) {
					$visible[ $f['id'] ] = $vis;
					$changed             = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $visible;
	}
}
