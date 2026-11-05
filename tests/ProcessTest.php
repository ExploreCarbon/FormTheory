<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class FakeWpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $inserts   = array();
	public function insert( $table, $data ) {
		$this->inserts[] = $data;
		$this->insert_id = count( $this->inserts );
		return 1;
	}
}

class ProcessTest extends TestCase {

	private $front;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\when( 'wp_verify_nonce' )->alias( function ( $n ) {
			return 'good' === $n ? 1 : false;
		} );
		Functions\when( 'wp_hash' )->alias( function ( $s ) {
			return md5( $s );
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		Functions\stubs( array(
			'get_current_user_id' => 0,
			'current_time'        => '2026-11-05 00:00:00',
			'wp_mail'             => true,
			'wpautop'             => function ( $s ) {
				return '<p>' . $s . '</p>';
			},
			'add_shortcode'       => null,
			'add_action'          => null,
		) );
		$this->front = new \FormTheory_Frontend();
	}

	private function form() {
		return array(
			'id'     => 2,
			'title'  => 'C',
			'config' => \FormTheory_Fields::migrate_config( array(
				'fields'            => array(
					array( 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => array(), 'cond' => array( 'enabled' => false, 'rules' => array() ) ),
				),
				'confirmation'      => 'Thanks {field:name}',
				'confirmation_type' => 'message',
				'notify_to'         => '',
			) ),
		);
	}

	private function post( array $ft ) {
		return array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => $ft );
	}

	public function test_process_returns_errors_without_insert() {
		$r = $this->front->process( $this->form(), $this->post( array( 'name' => '' ) ), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertArrayHasKey( 'name', $r['errors'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_bad_nonce_errors() {
		$p              = $this->post( array( 'name' => 'Ann' ) );
		$p['_ft_nonce'] = 'bad';
		$r              = $this->front->process( $this->form(), $p, array() );
		$this->assertFalse( $r['ok'] );
		$this->assertArrayHasKey( '_form', $r['errors'] );
	}

	public function test_process_success_returns_entry_id() {
		$r = $this->front->process( $this->form(), $this->post( array( 'name' => 'Ann' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 1, $r['entry_id'] );
		$this->assertNull( $r['redirect'] );
		$this->assertSame( '<p>Thanks Ann</p>', $r['message'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserts );
	}

	public function test_honeypot_returns_ok_without_insert() {
		$p          = $this->post( array( 'name' => 'Ann' ) );
		$p['ft_hp'] = 'bot';
		$r          = $this->front->process( $this->form(), $p, array() );
		$this->assertTrue( $r['ok'] );
		$this->assertNull( $r['entry_id'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
	}
}
