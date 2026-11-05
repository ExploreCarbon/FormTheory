<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class PortableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'FORMTHEORY_VERSION' ) ) {
			define( 'FORMTHEORY_VERSION', '1.2.0' );
		}
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
	}

	private function form() {
		$config = \FormTheory_Fields::sanitize_config( array(
			'fields'   => array(
				array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ),
				array( 'id' => 'mail', 'type' => 'email', 'label' => 'Email' ),
			),
			'webhooks' => array(
				array( 'url' => 'https://example.com/h', 'secret' => 'topsecret' ),
			),
		) );
		return array( 'id' => 5, 'title' => 'Contact', 'config' => $config );
	}

	public function test_export_blanks_secrets() {
		$json = \FormTheory_Portable::export( $this->form() );
		$this->assertStringNotContainsString( 'topsecret', $json );
		$data = json_decode( $json, true );
		$this->assertSame( 1, $data['formtheory'] );
		$this->assertSame( 'Contact', $data['title'] );
		$this->assertSame( '', $data['config']['webhooks'][0]['secret'] );
	}

	public function test_roundtrip_preserves_fields() {
		$res = \FormTheory_Portable::import( \FormTheory_Portable::export( $this->form() ) );
		$this->assertIsArray( $res );
		$this->assertSame( 'Contact', $res['title'] );
		$this->assertSame( array( 'name', 'mail' ), array_column( $res['config']['fields'], 'id' ) );
		$this->assertSame( array( 'text', 'email' ), array_column( $res['config']['fields'], 'type' ) );
	}

	public function test_import_rejects_wrong_marker() {
		foreach ( array( '{"title":"x","config":{}}', '{"formtheory":2,"title":"x","config":{}}' ) as $json ) {
			$res = \FormTheory_Portable::import( $json );
			$this->assertInstanceOf( \WP_Error::class, $res );
			$this->assertSame( 'ft_import_marker', $res->get_error_code() );
		}
	}

	public function test_import_rejects_invalid_json() {
		$this->assertInstanceOf( \WP_Error::class, \FormTheory_Portable::import( '{nope' ) );
	}

	public function test_import_rejects_oversize() {
		$res = \FormTheory_Portable::import( str_repeat( 'a', MB_IN_BYTES + 1 ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'ft_import_size', $res->get_error_code() );
	}

	public function test_import_sanitizes_html_field() {
		$json = wp_json_encode_stub( array(
			'formtheory' => 1,
			'title'      => '<b>T</b>',
			'config'     => array( 'fields' => array( array( 'id' => 'h', 'type' => 'html', 'content' => '<p>a</p><script>x</script>' ) ) ),
		) );
		$res = \FormTheory_Portable::import( $json );
		$this->assertIsArray( $res );
		$this->assertStringNotContainsString( '<script', $res['config']['fields'][0]['content'] );
		$this->assertSame( 'T', $res['title'] );
	}
}

function wp_json_encode_stub( $d ) {
	return json_encode( $d );
}
