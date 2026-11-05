<?php
defined( 'ABSPATH' ) || exit;

/**
 * Field schema, config sanitizing, conditional logic and validation.
 */
class FormTheory_Fields {

	const TYPES = array( 'text', 'email', 'phone', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'file', 'page' );
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
		$cfg['submit_label']   = sanitize_text_field( $raw['submit_label'] ?? 'Submit' );
		$cfg['confirmation']   = wp_kses_post( $raw['confirmation'] ?? '' );
		$cfg['notify_subject'] = sanitize_text_field( $raw['notify_subject'] ?? '' );
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
				'required'    => ! empty( $f['required'] ) && 'page' !== $type,
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
			if ( 'file' === $type ) {
				$exts = array_filter( array_map( function ( $e ) {
					return preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $e ) ) );
				}, explode( ',', (string) ( $f['allowed'] ?? 'jpg,jpeg,png,pdf' ) ) ) );
				$field['allowed'] = implode( ',', array_diff( $exts, self::BLOCKED_EXT ) );
				$field['max_mb']  = max( 1, min( 100, (int) ( $f['max_mb'] ?? 5 ) ) );
			}
			$field['cond'] = self::sanitize_cond( $f['cond'] ?? array() );
			$fields[]      = $field;
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
		return $cfg;
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
