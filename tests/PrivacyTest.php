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

	/** Rows for the entries table; the drafts table (scanned by the eraser) is empty. */
	private function rows( array $rows ) {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $sql ) use ( $rows ) {
			return false !== strpos( (string) $sql, 'formtheory_drafts' ) ? array() : $rows;
		} );
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
		$this->wpdb->shouldReceive( 'delete' )->times( 4 )->andReturn( 1 );
		$sqls = array();
		$this->wpdb->shouldReceive( 'query' )->once()->andReturnUsing( function ( $sql ) use ( &$sqls ) {
			$sqls[] = $sql;
			return 5;
		} );
		$this->assertSame( 2, \FormTheory_DB::purge_older_than( 10 ) );
		$this->assertCount( 1, $sqls );
		$this->assertStringContainsString( 'DELETE FROM wp_formtheory_webhook_log', $sqls[0] );
		$this->assertStringContainsString( 'created_at < 2026-01-21 12:00:00', $sqls[0] );
	}

	public function test_delete_entry_also_deletes_webhook_log_rows_by_entry_id() {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_webhook_log', array( 'entry_id' => 8 ), array( '%d' ) )->once()->andReturn( 2 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 8 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->assertTrue( \FormTheory_DB::delete_entry( 8 ) );
	}

	public function test_delete_form_cascades_to_webhook_log() {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( array( 'id' => '21', 'form_id' => '3', 'data' => '{}' ) ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_webhook_log', array( 'entry_id' => 21 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 21 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_drafts', array( 'form_id' => 3 ), array( '%d' ) )->once()->andReturn( 0 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_forms', array( 'id' => 3 ), array( '%d' ) )->once()->andReturn( 1 );
		\FormTheory_DB::delete_form( 3 );
		$this->addToAssertionCount( 1 ); // Mockery expectations verify the cascade.
	}

	public function test_email_match_is_exact_not_substring() {
		$this->rows( array( $this->row( 1, 'bob@example.com.au' ), $this->row( 2, 'BOB@example.com' ) ) );
		$found = \FormTheory_DB::find_entries_by_email( 'bob@example.com' );
		$this->assertSame( array( 2 ), array_map( function ( $e ) { return (int) $e['id']; }, $found ) );
	}

	private function export_values( array $data ) {
		$this->rows( array( array( 'id' => '1', 'form_id' => '1', 'ip' => '1.2.3.4', 'user_id' => '0', 'created_at' => '2026-01-01 00:00:00', 'data' => wp_json_encode( array_merge( array( 'email' => 'a@b.co' ), $data ) ) ) ) );
		$r    = \FormTheory_Privacy::export( 'a@b.co', 1 );
		$out  = array();
		foreach ( $r['data'][0]['data'] as $item ) {
			$out[ $item['name'] ] = $item['value'];
		}
		return $out;
	}

	public function test_exporter_repeater_includes_row_values() {
		$v = $this->export_values( array( 'rep' => array( array( 'n' => 'Apple', 'q' => '2' ), array( 'n' => 'Pear', 'q' => '3' ) ) ) );
		$this->assertSame( 'Apple, 2; Pear, 3', $v['rep'] );
	}

	public function test_exporter_signature_hides_stored_filename() {
		$v = $this->export_values( array( 'sig' => array( 'sig' => 'abc.png', 'name' => 'signature.png' ) ) );
		$this->assertStringContainsString( '[signature]', $v['sig'] );
		$this->assertStringNotContainsString( 'abc.png', $v['sig'] );
	}

	public function test_exporter_address_joined() {
		$v = $this->export_values( array( 'addr' => array( 'street' => '1 A', 'city' => '', 'zip' => '9' ) ) );
		$this->assertSame( '1 A, 9', $v['addr'] );
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
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_webhook_log', Mockery::any(), Mockery::any() )->twice()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 4 ), Mockery::any() )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wp_formtheory_entries', array( 'id' => 9 ), Mockery::any() )->once()->andReturn( 1 );
		$r = \FormTheory_Privacy::erase( 'a@b.co', 1 );
		$this->assertSame( 2, $r['items_removed'] );
		$this->assertSame( 0, $r['items_retained'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_stored_negative_or_junk_retention_yields_zero_and_no_purge() {
		foreach ( array( -7, 'abc', null ) as $bad ) {
			Functions\when( 'get_option' )->justReturn( array( 'retention_days' => $bad ) );
			$this->assertSame( 0, \FormTheory_Privacy::retention_days() );
		}
		$this->wpdb->shouldNotReceive( 'get_col' );
		// Expired drafts are still purged when entry retention is off (only the drafts DELETE runs).
		$this->wpdb->shouldReceive( 'query' )->once()->with( \Mockery::pattern( '/^DELETE FROM wp_formtheory_drafts WHERE expires_at <= /' ) )->andReturn( 0 );
		$this->assertSame( 0, \FormTheory_Privacy::run_purge() );
	}

	public function test_eraser_done_when_nothing_actually_deleted() {
		$rows = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$rows[] = $this->row( $i, 'a@b.co' );
		}
		$this->rows( $rows );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( false );
		$r = \FormTheory_Privacy::erase( 'a@b.co', 1 );
		$this->assertSame( 0, $r['items_removed'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_ascii_email_uses_like_prefilter() {
		$sqls = array();
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $sql ) use ( &$sqls ) {
			$sqls[] = $sql;
			return array();
		} );
		\FormTheory_DB::find_entries_by_email( 'Bob_1@example.com' );
		$this->assertCount( 1, $sqls );
		$this->assertStringContainsString( 'data LIKE %bob\_1@example.com%', $sqls[0] );
	}

	public function test_non_ascii_email_scans_without_like_and_finds_uppercase_entry() {
		$sqls = array();
		$row  = $this->row( 1, "\u{c9}MILE@example.com" );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $sql ) use ( &$sqls, $row ) {
			$sqls[] = $sql;
			return array( $row );
		} );
		$found = \FormTheory_DB::find_entries_by_email( "\u{e9}mile@example.com" );
		$this->assertCount( 1, $found );
		$this->assertStringNotContainsString( 'LIKE', $sqls[0] );
	}

	public function test_email_match_non_ascii_case_insensitive() {
		$this->rows( array( $this->row( 1, "\u{c9}MILE@example.com" ) ) );
		$found = \FormTheory_DB::find_entries_by_email( "\u{e9}mile@example.com" );
		$this->assertCount( 1, $found );
	}

	public function test_sanitize_retention_days() {
		Functions\when( 'get_option' )->justReturn( array() );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => '-4' ) );
		$this->assertSame( 0, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => 'abc' ) );
		$this->assertSame( 0, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => '' ) );
		$this->assertSame( 0, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array( 'retention_days' => '99999' ) );
		$this->assertSame( 3650, $out['retention_days'] );
		$out = \FormTheory_Admin::sanitize_settings( array() );
		$this->assertSame( 0, $out['retention_days'] );
	}
}
