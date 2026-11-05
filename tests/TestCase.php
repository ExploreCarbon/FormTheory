<?php
namespace FormTheory\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'sanitize_text_field'     => function ( $s ) {
					return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
				},
				'sanitize_textarea_field' => function ( $s ) {
					return trim( strip_tags( (string) $s ) );
				},
				'sanitize_email'          => function ( $s ) {
					return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL );
				},
				'is_email'                => function ( $s ) {
					return filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : false;
				},
				'wp_kses_post'            => function ( $s ) {
					return preg_replace( '#<(script|style)[^>]*>|</(script|style)>#i', '', (string) $s );
				},
				'esc_url_raw'             => function ( $s ) {
					return filter_var( trim( (string) $s ), FILTER_VALIDATE_URL ) ? trim( (string) $s ) : '';
				},
				'esc_html'                => function ( $s ) {
					return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
				},
				'wp_strip_all_tags'       => function ( $s ) {
					return trim( strip_tags( (string) $s ) );
				},
				'wp_json_encode'          => 'json_encode',
				'get_option'              => function ( $k, $d = false ) {
					return 'admin_email' === $k ? 'admin@example.com' : ( 'blogname' === $k ? 'Site' : $d );
				},
				'get_bloginfo'            => 'Site',
				'wp_parse_args'           => function ( $a, $d ) {
					return array_merge( $d, (array) $a );
				},
				'__'                      => null,
				'absint'                  => function ( $v ) {
					return abs( (int) $v );
				},
				'wp_unslash'              => null,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
