<?php
namespace FormTheory\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class ScoreProcessTest extends TestCase {

	private $front;
	private $mails = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb();
		$this->mails     = array();
		$mails           = &$this->mails;
		Functions\when( 'wp_verify_nonce' )->alias( function ( $n ) {
			return 'good' === $n ? 1 : false;
		} );
		Functions\when( 'wp_hash' )->alias( 'md5' );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) use ( &$mails ) {
			$mails[] = $body;
			return true;
		} );
		Functions\when( 'get_post_status' )->alias( function ( $id ) {
			return in_array( (int) $id, array( 10, 30 ), true ) ? 'publish' : false;
		} );
		Functions\when( 'get_permalink' )->alias( function ( $id ) {
			return 'https://x.test/p' . $id . '/';
		} );
		Functions\stubs( array(
			'get_current_user_id' => 0,
			'current_time'        => '2026-11-05 00:00:00',
			'wpautop'             => function ( $s ) {
				return '<p>' . $s . '</p>';
			},
			'add_shortcode'       => null,
			'add_action'          => null,
			'add_query_arg'       => function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			},
			'admin_url'           => 'https://x.test/wp-admin/admin.php',
		) );
		$this->front = new \FormTheory_Frontend();
	}

	private function form( array $extra = array() ) {
		$cfg = \FormTheory_Fields::sanitize_config( $extra + array(
			'confirmation_type' => 'score',
			'confirmation'      => 'Thanks',
			'notify_to'         => 'owner@example.com',
			'fields'            => array(
				array( 'id' => 'q1', 'type' => 'radio', 'label' => 'Q1', 'required' => true, 'options' => array( 'No', 'Yes' ), 'points' => array( 0, 10 ), 'category' => 'debt' ),
				array( 'id' => 'q2', 'type' => 'radio', 'label' => 'Q2', 'required' => true, 'options' => array( 'No', 'Yes' ), 'points' => array( 0, 10 ), 'category' => 'budget' ),
			),
			'scoring'           => array( 'enabled' => true, 'threshold' => 80, 'overall_page' => 30, 'pages' => array( 'debt' => 10, 'budget' => 20 ) ),
		) );
		return array( 'id' => 5, 'title' => 'Quiz', 'config' => \FormTheory_Fields::migrate_config( $cfg ) );
	}

	private function post( array $ft ) {
		return array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => $ft );
	}

	public function test_score_confirmation_redirects_to_lowest_category_page() {
		$r = $this->front->process( $this->form(), $this->post( array( 'q1' => 'No', 'q2' => 'Yes' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'https://x.test/p10/', $r['redirect'] );
		$this->assertNull( $r['message'] );
	}

	public function test_score_confirmation_all_high_goes_to_overall_page() {
		$r = $this->front->process( $this->form(), $this->post( array( 'q1' => 'Yes', 'q2' => 'Yes' ) ), array() );
		$this->assertSame( 'https://x.test/p30/', $r['redirect'] );
	}

	public function test_score_confirmation_without_published_pages_shows_message() {
		$form = $this->form( array( 'scoring' => array( 'enabled' => true, 'overall_page' => 99, 'pages' => array( 'debt' => 98 ) ) ) );
		$r    = $this->front->process( $form, $this->post( array( 'q1' => 'No', 'q2' => 'No' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertNull( $r['redirect'] );
		$this->assertSame( '<p>Thanks</p>', $r['message'] );
	}

	public function test_store_entries_false_inserts_nothing_but_notifies_and_fires_hook() {
		Actions\expectDone( 'formtheory_after_submission' )->once()->with( 0, \Mockery::type( 'array' ), \Mockery::type( 'array' ) );
		$r = $this->front->process( $this->form( array( 'store_entries' => false ) ), $this->post( array( 'q1' => 'No', 'q2' => 'Yes' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
		$this->assertNull( $r['entry_id'] );
		$this->assertSame( 'https://x.test/p10/', $r['redirect'] );
		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'entry=0', $this->mails[0] );
	}

	public function test_store_entries_default_still_inserts() {
		$r = $this->front->process( $this->form(), $this->post( array( 'q1' => 'No', 'q2' => 'Yes' ) ), array() );
		$this->assertSame( 1, $r['entry_id'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserts );
		$this->assertStringContainsString( 'entry=1', $this->mails[0] );
	}
}
