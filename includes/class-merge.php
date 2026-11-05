<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merge tags: {field:ID}, {all_fields}, {form_title}, {entry_id}, {date},
 * {site_name}, {admin_email}. Unknown tags are left untouched.
 *
 * Modes control how substituted values are escaped:
 * - text:   raw plain text (email bodies)
 * - html:   esc_html()
 * - url:    rawurlencode()
 * - header: single line, no CR/LF (email subject, headers)
 */
class FormTheory_Merge {

	const MODES = array( 'text', 'html', 'url', 'header' );

	/**
	 * @param string $text Template.
	 * @param array  $ctx  { form: array, data: array, entry_id: int }.
	 * @param string $mode text|html|url|header.
	 */
	public static function replace( $text, array $ctx, $mode = 'text' ) {
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'text';
		return preg_replace_callback(
			'/\{([a-z_]+)(?::([a-z0-9_]+))?\}/',
			function ( $m ) use ( $ctx, $mode ) {
				$val = self::resolve( $m[1], $m[2] ?? '', $ctx );
				return null === $val ? $m[0] : self::escape( $val, $mode );
			},
			(string) $text
		);
	}

	/** Plain-text value of a stored entry value. */
	public static function plain( $val ) {
		if ( is_array( $val ) ) {
			return isset( $val['file'] ) ? (string) ( $val['name'] ?? '' ) : implode( ', ', array_map( 'strval', $val ) );
		}
		return (string) $val;
	}

	/** Returns null for an unknown tag. */
	private static function resolve( $tag, $arg, array $ctx ) {
		$form = $ctx['form'] ?? array();
		$data = $ctx['data'] ?? array();
		switch ( $tag ) {
			case 'field':
				return array_key_exists( $arg, $data ) ? self::plain( $data[ $arg ] ) : null;
			case 'all_fields':
				$lines = array();
				foreach ( $form['config']['fields'] ?? array() as $f ) {
					if ( array_key_exists( $f['id'], $data ) ) {
						$lines[] = $f['label'] . ': ' . self::plain( $data[ $f['id'] ] );
					}
				}
				return implode( "\n", $lines );
			case 'form_title':
				return (string) ( $form['title'] ?? '' );
			case 'entry_id':
				return (string) (int) ( $ctx['entry_id'] ?? 0 );
			case 'date':
				return wp_date( get_option( 'date_format', 'Y-m-d' ) );
			case 'site_name':
				return (string) get_bloginfo( 'name' );
			case 'admin_email':
				return (string) get_option( 'admin_email' );
		}
		return null;
	}

	private static function escape( $val, $mode ) {
		switch ( $mode ) {
			case 'html':
				return esc_html( $val );
			case 'url':
				return rawurlencode( $val );
			case 'header':
				return trim( preg_replace( '/[\r\n\t ]+/', ' ', $val ) );
		}
		return $val;
	}
}
