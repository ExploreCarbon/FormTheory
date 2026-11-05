<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class ConfirmTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_permalink' )->alias( function ( $id ) {
			return 5 === $id ? 'https://site.test/thanks/' : false;
		} );
		Functions\when( 'get_post_status' )->alias( function ( $id ) {
			return 5 === $id ? 'publish' : false;
		} );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	private function form( array $over ) {
		$cfg = array_merge( array(
			'fields'            => array( array( 'id' => 'q', 'type' => 'text', 'label' => 'Q' ) ),
			'confirmation_type' => 'message',
			'confirmation_page' => 0,
			'confirmation_url'  => '',
		), $over );
		return array( 'id' => 1, 'title' => 'T', 'config' => $cfg );
	}

	private function target( array $over, array $data = array() ) {
		$form = $this->form( $over );
		return \FormTheory_Frontend::confirmation_target( $form, array( 'form' => $form, 'data' => $data, 'entry_id' => 9 ) );
	}

	public function test_message_returns_null() {
		$this->assertNull( $this->target( array() ) );
	}

	public function test_page_returns_permalink() {
		$this->assertSame( 'https://site.test/thanks/', $this->target( array( 'confirmation_type' => 'page', 'confirmation_page' => 5 ) ) );
	}

	public function test_missing_page_falls_back_to_message() {
		$this->assertNull( $this->target( array( 'confirmation_type' => 'page', 'confirmation_page' => 99 ) ) );
	}

	public function test_redirect_encodes_merge_values() {
		$url = $this->target( array( 'confirmation_type' => 'redirect', 'confirmation_url' => 'https://ext.test/p?q={field:q}&e={entry_id}' ), array( 'q' => 'a&b' ) );
		$this->assertSame( 'https://ext.test/p?q=a%26b&e=9', $url );
	}

	public function test_redirect_host_cannot_be_hijacked_by_value() {
		$url = $this->target( array( 'confirmation_type' => 'redirect', 'confirmation_url' => 'https://{field:q}' ), array( 'q' => 'evil.test' ) );
		$this->assertNull( $url );
	}

	public function test_sanitize_keeps_tags_in_url() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'confirmation_type' => 'redirect',
			'confirmation_url'  => 'https://ext.test/p?q={field:q}',
			'confirmation_page' => '5x',
		) );
		$this->assertSame( 'redirect', $cfg['confirmation_type'] );
		$this->assertSame( 'https://ext.test/p?q={field:q}', $cfg['confirmation_url'] );
		$this->assertSame( 5, $cfg['confirmation_page'] );
	}

	public function test_sanitize_rejects_bad_type_and_scheme() {
		$cfg = \FormTheory_Fields::sanitize_config( array( 'confirmation_type' => 'evil', 'confirmation_url' => 'javascript:alert(1)' ) );
		$this->assertSame( 'message', $cfg['confirmation_type'] );
		$this->assertSame( '', $cfg['confirmation_url'] );
	}
}
