<?php
namespace FormTheory\Tests;

use Mockery;
use Brain\Monkey\Functions;

class WebhookRetryTest extends TestCase {

	private $wpdb;
	private $row;
	private $updates = array();
	private $events  = array();
	private $queries = array();
	private $claim   = 1;
	private $schedule_ok = true;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb         = Mockery::mock( 'stdClass' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id = 7;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( $sql, $args );
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->row       = array(
			'id'       => '7',
			'form_id'  => '4',
			'entry_id' => '12',
			'url'      => 'https://hook.test/in?token=abc',
			'request'  => wp_json_encode( array( 'body' => '{"a":1}', 'headers' => array( 'Content-Type' => 'application/json' ) ) ),
			'status'   => 'pending',
			'http_code' => '0',
			'attempts' => '0',
			'error'    => '',
		);
		$self = $this;
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( function () use ( $self ) {
			return $self->row;
		} );
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $fields ) use ( $self ) {
			$self->updates[] = $fields;
			return 1;
		} );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $sql ) use ( $self ) {
			$self->queries[] = $sql;
			return $self->claim;
		} );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_schedule_single_event' )->alias( function ( $ts, $hook, $args ) use ( $self ) {
			$self->events[] = compact( 'ts', 'hook', 'args' );
			return $self->schedule_ok;
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) {
			return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
		} );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias( function ( $r ) {
			return $r instanceof \WP_Error;
		} );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function respond( $code ) {
		Functions\when( 'wp_safe_remote_post' )->justReturn( array( 'response' => array( 'code' => $code ) ) );
	}

	private function last() {
		return end( $this->updates );
	}

	public function test_success_marks_ok() {
		$this->respond( 204 );
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 'ok', $this->last()['status'] );
		$this->assertSame( 204, $this->last()['http_code'] );
		$this->assertCount( 0, $this->events );
	}

	public function test_500_schedules_retry() {
		$this->respond( 500 );
		$before = time();
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 1, $this->last()['attempts'] );
		$this->assertSame( 'pending', $this->last()['status'] );
		$this->assertSame( 500, $this->last()['http_code'] );
		$this->assertCount( 1, $this->events );
		$this->assertSame( 'formtheory_deliver_webhook', $this->events[0]['hook'] );
		$this->assertSame( array( 7 ), $this->events[0]['args'] );
		$this->assertGreaterThanOrEqual( $before + 60, $this->events[0]['ts'] );
		$this->assertLessThanOrEqual( time() + 60, $this->events[0]['ts'] );
	}

	public function test_second_failure_waits_240() {
		$this->respond( 502 );
		$this->row['attempts'] = '1';
		$before                = time();
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 2, $this->last()['attempts'] );
		$this->assertGreaterThanOrEqual( $before + 240, $this->events[0]['ts'] );
		$this->assertLessThanOrEqual( time() + 240, $this->events[0]['ts'] );
	}

	public function test_third_failure_marks_failed_no_reschedule() {
		$this->respond( 500 );
		$this->row['attempts'] = '2';
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 3, $this->last()['attempts'] );
		$this->assertSame( 'failed', $this->last()['status'] );
		$this->assertCount( 0, $this->events );
	}

	public function test_wp_error_counts_as_failure() {
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'boom' ) );
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 1, $this->last()['attempts'] );
		$this->assertSame( 'boom', $this->last()['error'] );
		$this->assertSame( 'pending', $this->last()['status'] );
	}

	public function test_error_truncated_to_255() {
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'x', str_repeat( 'é', 400 ) ) );
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 255, mb_strlen( $this->last()['error'] ) );
	}

	public function test_deliver_skips_non_pending_row() {
		$this->row['status'] = 'ok';
		Functions\expect( 'wp_safe_remote_post' )->never();
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertCount( 0, $this->updates );
	}

	public function test_next_delay_values() {
		$this->assertSame( 60, \FormTheory_Webhooks::next_delay( 0 ) );
		$this->assertSame( 240, \FormTheory_Webhooks::next_delay( 1 ) );
		$this->assertSame( 960, \FormTheory_Webhooks::next_delay( 2 ) );
	}

	public function test_retry_resets_failed_row_and_reschedules() {
		$this->assertTrue( \FormTheory_Webhooks::retry( 7 ) );
		$this->assertStringContainsString( "status = 'pending', attempts = 0", $this->queries[0] );
		$this->assertStringContainsString( "AND status = 'failed'", $this->queries[0] );
		$this->assertCount( 1, $this->events );
		$this->assertSame( array( 7 ), $this->events[0]['args'] );
	}

	public function test_retry_ignores_non_failed_row() {
		$this->claim = 0;
		$this->assertFalse( \FormTheory_Webhooks::retry( 7 ) );
		$this->assertCount( 0, $this->events );
	}

	public function test_retry_false_when_schedule_fails() {
		$this->schedule_ok = false;
		$this->assertFalse( \FormTheory_Webhooks::retry( 7 ) );
		$this->assertSame( 'failed', $this->last()['status'] );
	}

	public function test_claim_miss_sends_nothing() {
		$this->claim = 0;
		Functions\expect( 'wp_safe_remote_post' )->never();
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertCount( 0, $this->updates );
		$this->assertCount( 0, $this->events );
	}

	public function test_claim_is_atomic_on_status_and_attempts() {
		$this->respond( 204 );
		$this->row['attempts'] = '1';
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertStringContainsString( "WHERE id = 7 AND status = 'pending' AND attempts = 1", $this->queries[0] );
	}

	public function test_endpoint_down_gives_exactly_three_attempts() {
		$this->respond( 500 );
		for ( $i = 0; $i < 3; $i++ ) {
			\FormTheory_Webhooks::deliver( 7 );
			$u                     = $this->last();
			$this->row['attempts'] = (string) $u['attempts'];
			$this->row['status']   = $u['status'];
		}
		$this->assertCount( 2, $this->events );
		$this->assertLessThanOrEqual( time() + 60, $this->events[0]['ts'] );
		$this->assertGreaterThanOrEqual( time() + 200, $this->events[1]['ts'] );
		$this->assertLessThanOrEqual( time() + 240, $this->events[1]['ts'] );
		$this->assertSame( 'failed', $this->row['status'] );
		$this->assertSame( '3', $this->row['attempts'] );
		$sent = 0;
		Functions\when( 'wp_safe_remote_post' )->alias( function () use ( &$sent ) {
			$sent++;
			return array( 'response' => array( 'code' => 500 ) );
		} );
		\FormTheory_Webhooks::deliver( 7 );
		$this->assertSame( 0, $sent );
		$this->assertCount( 2, $this->events );
	}

	public function test_display_url_strips_query_and_userinfo() {
		$this->assertSame( 'hook.test/in', \FormTheory_Webhooks::display_url( 'https://user:pw@hook.test/in?token=abc#x' ) );
	}

	public function test_dispatch_log_row_holds_signature_not_secret() {
		$captured = null;
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $t, $d ) use ( &$captured ) {
			$captured = $d;
			return 1;
		} );
		$form = array( 'id' => 4, 'title' => 'L', 'config' => array( 'webhooks' => array( array(
			'url' => 'https://hook.test/in', 'format' => 'json', 'secret' => 'topsecret',
			'cond' => array( 'enabled' => false, 'action' => 'show', 'logic' => 'all', 'rules' => array() ),
		) ) ) );
		\FormTheory_Webhooks::dispatch( 12, $form, array() );
		$this->assertStringNotContainsString( 'topsecret', $captured['request'] );
		$this->assertStringContainsString( 'sha256=', $captured['request'] );
		$this->assertSame( 'pending', $captured['status'] );
		$this->assertSame( array( 7 ), $this->events[0]['args'] );
	}
}
