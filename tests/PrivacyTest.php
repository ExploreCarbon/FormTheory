<?php
namespace FormTheory\Tests;

use Mockery;
use Brain\Monkey\Functions;

class PrivacyTest extends TestCase {

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb         = Mockery::mock( 'stdClass' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( $sql, $args );
			}
		);
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( $s ) {
				return addcslashes( $s, '_%\\' );
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\stubs( array( 'esc_html__' => null, '__' => null ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function rows( array $rows ) {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
	}

	private function row( $id, $email ) {
		return array( 'id' => (string) $id, 'form_id' => '1', 'ip' => '1.2.3.4', 'user_id' => '0', 'created_at' => '2026-01-01 00:00:00', 'data' => wp_json_encode( array( 'email' => $email ) ) );
	}

	public function test_purge_zero_days_noop() {
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, \FormTheory_DB::purge_older_than( 0 ) );
	}

	public function test_purge_negative_days_noop() {
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->assertSame( 0, \FormTheory_DB::purge_older_than( -5 ) );
	}

	public function test_purge_deletes_old_entries_via_delete_entry() {
		Functions\when( 'current_time' )->justReturn( strtotime( '2026-01-31 12:00:00 UTC' ) );
		$this->wpdb->shouldReceive( 'get_col' )->once()
			->with( Mockery::on( function ( $sql ) {
				return false !== strpos( $sql, "created_at < 2026-01-21 12:00:00" );
			} ) )->andReturn( array( '3', '4' ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->twice()->andReturn( 1 );
		$this->assertSame( 2, \FormTheory_DB::purge_older_than( 10 ) );
	}

	public function test_email_match_is_exact_not_substring() {
		$this->rows( array( $this->row( 1, 'bob@example.com.au' ), $this->row( 2, 'BOB@example.com' ) ) );
		$found = \FormTheory_DB::find_entries_by_email( 'bob@example.com' );
		$this->assertSame( array( 2 ), array_map( function ( $e ) { return (int) $e['id']; }, $found ) );
	}

	public function test_exporter_empty_when_no_match() {
		$this->rows( array() );
		$r = \FormTheory_Privacy::export( 'nobody@example.com', 1 );
		$this->assertTrue( $r['done'] );
		$this->assertSame( array(), $r['data'] );
	}

	public function test_exporter_paginates() {
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = $this->row( $i, 'a@b.co' );
		}
		$this->rows( $rows );
		$p1 = \FormTheory_Privacy::export( 'a@b.co', 1 );
		$this->assertFalse( $p1['done'] );
		$this->assertCount( 50, $p1['data'] );
		$this->assertSame( 'entry-1', $p1['data'][0]['item_id'] );
		$p2 = \FormTheory_Privacy::export( 'a@b.co', 2 );
		$this->assertTrue( $p2['done'] );
		$this->assertCount( 1, $p2['data'] );
	}

	public function test_eraser_removes_matching_entries_only() {
		$this->rows( array( $this->row( 4, 'a@b.co' ), $this->row( 9, 'a@b.co' ), $this->row( 5, 'other@b.co' ) ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 4 ), Mockery::any() )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 9 ), Mockery::any() )->once()->andReturn( 1 );
		$r = \FormTheory_Privacy::erase( 'a@b.co', 1 );
		$this->assertSame( 2, $r['items_removed'] );
		$this->assertSame( 0, $r['items_retained'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_sanitize_retention_days() {
		Functions\when( 'get_option' )->justReturn( array() );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => '-4' ) );
		$this->assertSame( 4, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => '99999' ) );
		$this->assertSame( 3650, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array() );
		$this->assertSame( 0, $out['retention_days'] );
	}
}
