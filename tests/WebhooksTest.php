<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class WebhooksTest extends TestCase {

	private $calls = array();
	private $events = array();

	protected function setUp(): void {
		parent::setUp();
		$calls = &$this->calls;
		Functions\when( 'wp_safe_remote_post' )->alias( function ( $url, $args ) use ( &$calls ) {
			$calls[] = compact( 'url', 'args' );
			return array();
		} );
		$events = &$this->events;
		Functions\when( 'wp_schedule_single_event' )->alias( function ( $ts, $hook, $args ) use ( &$events ) {
			$events[] = compact( 'ts', 'hook', 'args' );
			return true;
		} );
	}

	/** Dispatch, then run scheduled events like WP-Cron would. */
	private function fire( $entry_id, $form, $data ) {
		\FormTheory_Webhooks::dispatch( $entry_id, $form, $data );
		foreach ( $this->events as $e ) {
			\FormTheory_Webhooks::send( ...$e['args'] );
		}
	}

	public function test_dispatch_defers_to_cron() {
		\FormTheory_Webhooks::dispatch( 12, $this->form( array( $this->hook() ) ), array( 'name' => 'Ann' ) );
		$this->assertCount( 0, $this->calls );
		$this->assertCount( 1, $this->events );
		$this->assertSame( 'formtheory_send_webhook', $this->events[0]['hook'] );
		$this->assertSame( 'https://hook.test/in', $this->events[0]['args'][0] );
	}

	private function form( array $hooks ) {
		return array(
			'id'     => 4,
			'title'  => 'Lead',
			'config' => array(
				'fields'   => array( array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ) ),
				'webhooks' => $hooks,
			),
		);
	}

	private function hook( array $over = array() ) {
		return array_merge( array(
			'url'    => 'https://hook.test/in',
			'format' => 'json',
			'secret' => '',
			'cond'   => array( 'enabled' => false, 'action' => 'show', 'logic' => 'all', 'rules' => array() ),
		), $over );
	}

	public function test_http_url_rejected_on_sanitize() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'webhooks' => array(
				array( 'url' => 'http://hook.test/in' ),
				array( 'url' => 'https://hook.test/ok', 'format' => 'xml', 'secret' => " s3cret\n" ),
			),
		) );
		$this->assertCount( 1, $cfg['webhooks'] );
		$this->assertSame( 'https://hook.test/ok', $cfg['webhooks'][0]['url'] );
		$this->assertSame( 'json', $cfg['webhooks'][0]['format'] );
		$this->assertSame( 's3cret', $cfg['webhooks'][0]['secret'] );
	}

	public function test_payload_shape() {
		$p = \FormTheory_Webhooks::payload( $this->form( array() ), array( 'name' => 'Ann' ), 12 );
		$this->assertSame( 4, $p['form_id'] );
		$this->assertSame( 'Lead', $p['form_title'] );
		$this->assertSame( 12, $p['entry_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $p['submitted_at'] );
		$this->assertSame( array( 'name' => 'Ann' ), $p['fields'] );
	}

	public function test_nonblocking_args() {
		$this->fire( 12, $this->form( array( $this->hook() ) ), array( 'name' => 'Ann' ) );
		$this->assertCount( 1, $this->calls );
		$args = $this->calls[0]['args'];
		$this->assertTrue( $args['blocking'] );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'Ann', json_decode( $args['body'], true )['fields']['name'] );
	}

	public function test_signature_header() {
		$this->fire( 12, $this->form( array( $this->hook( array( 'secret' => 'k' ) ) ) ), array( 'name' => 'Ann' ) );
		$args = $this->calls[0]['args'];
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $args['body'], 'k' ), $args['headers']['X-FormTheory-Signature'] );
	}

	public function test_form_format_flattens_files() {
		$this->fire( 12, $this->form( array( $this->hook( array( 'format' => 'form' ) ) ) ), array( 'name' => array( 'file' => 'x.pdf', 'name' => 'cv.pdf' ) ) );
		$args = $this->calls[0]['args'];
		$this->assertSame( 'cv.pdf', $args['body']['name'] );
		$this->assertSame( 12, $args['body']['entry_id'] );
	}

	public function test_cond_false_skips() {
		$cond = array( 'enabled' => true, 'action' => 'show', 'logic' => 'all', 'rules' => array( array( 'field' => 'name', 'op' => 'is', 'value' => 'Bob' ) ) );
		$this->fire( 12, $this->form( array( $this->hook( array( 'cond' => $cond ) ) ) ), array( 'name' => 'Ann' ) );
		$this->assertCount( 0, $this->calls );
	}
}
