<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class SpamTest extends TestCase {

	private $keys = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_hash' )->alias( function ( $s ) {
			return hash_hmac( 'sha256', $s, 'salt' );
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		$keys = &$this->keys;
		Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$keys ) {
			return 'formtheory_settings' === $k ? $keys : $d;
		} );
	}

	private function post( $age = 10 ) {
		return array( 'ft_ts' => \FormTheory_Spam::stamp( time() - $age ) );
	}

	public function test_valid_stamp_passes_without_keys() {
		$this->assertTrue( \FormTheory_Spam::check( $this->post() ) );
	}

	public function test_too_fast_rejected() {
		$this->assertInstanceOf( \WP_Error::class, \FormTheory_Spam::check( $this->post( 1 ) ) );
	}

	public function test_bad_signature_rejected() {
		$this->assertInstanceOf( \WP_Error::class, \FormTheory_Spam::check( array( 'ft_ts' => ( time() - 10 ) . '.deadbeef' ) ) );
		$this->assertInstanceOf( \WP_Error::class, \FormTheory_Spam::check( array() ) );
	}

	public function test_no_keys_skips_turnstile() {
		Functions\expect( 'wp_remote_post' )->never();
		$this->assertTrue( \FormTheory_Spam::check( $this->post() ) );
	}

	public function test_turnstile_success_passes() {
		$this->keys = array( 'turnstile_site' => 's', 'turnstile_secret' => 'k' );
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $url, $args ) {
			$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $url );
			$this->assertSame( 5, $args['timeout'] );
			$this->assertSame( 'tok', $args['body']['response'] );
			return array( 'body' => '{"success":true}' );
		} );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
			return $r['body'];
		} );
		$this->assertTrue( \FormTheory_Spam::check( $this->post() + array( 'cf-turnstile-response' => 'tok' ) ) );
	}

	public function test_turnstile_failure_rejected() {
		$this->keys = array( 'turnstile_site' => 's', 'turnstile_secret' => 'k' );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'body' => '{"success":false}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
			return $r['body'];
		} );
		$this->assertInstanceOf( \WP_Error::class, \FormTheory_Spam::check( $this->post() + array( 'cf-turnstile-response' => 'tok' ) ) );
	}

	public function test_turnstile_network_error_fails_closed() {
		$this->keys = array( 'turnstile_site' => 's', 'turnstile_secret' => 'k' );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http', 'down' ) );
		$err = \FormTheory_Spam::check( $this->post() + array( 'cf-turnstile-response' => 'tok' ) );
		$this->assertSame( 'Spam check failed. Please try again.', $err->get_error_message() );
	}
}
