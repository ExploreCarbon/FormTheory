<?php
namespace FormTheory\Tests;

use Mockery;

class EntriesDbTest extends TestCase {

	/** @var \Mockery\MockInterface */
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_search_escapes_like_wildcards() {
		list( , $params ) = \FormTheory_DB::entries_query( 5, array( 'search' => '100%_' ) );
		$this->assertContains( '%100\%\_%', $params );
	}

	public function test_status_filter_adds_clause() {
		list( $where, $params ) = \FormTheory_DB::entries_query( 5, array( 'status' => 'unread' ) );
		$this->assertStringContainsString( 'status = %s', $where );
		$this->assertContains( 'unread', $params );
	}

	public function test_invalid_status_ignored() {
		list( $where, $params ) = \FormTheory_DB::entries_query( 5, array( 'status' => 'x' ) );
		$this->assertStringNotContainsString( 'status', $where );
		$this->assertNotContains( 'x', $params );
	}

	public function test_ids_sanitized_and_scoped() {
		$this->wpdb->shouldReceive( 'get_col' )->once()
			->with( Mockery::on( function ( $sql ) {
				return false !== strpos( $sql, 'form_id = 5' ) && false !== strpos( $sql, 'IN (3,2)' );
			} ) )
			->andReturn( array( '3', '2' ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->times( 4 )->andReturn( 1 );
		$this->assertSame( 2, \FormTheory_DB::delete_entries( 5, array( '3', 'x', '0', '-2' ) ) );
	}

	public function test_empty_ids_noop() {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, \FormTheory_DB::delete_entries( 5, array( 'x', '0' ) ) );
		$this->assertSame( 0, \FormTheory_DB::set_entries_status( 5, array(), 'read' ) );
	}

	public function test_search_json_escapes_quotes() {
		list( , $params ) = \FormTheory_DB::entries_query( 5, array( 'search' => '"x"' ) );
		// Stored JSON has \"x\"; esc_like doubles the backslashes for LIKE.
		$this->assertContains( '%\\\\"x\\\\"%', $params );
	}

	public function test_search_non_ascii_matches_json_escape() {
		list( , $params ) = \FormTheory_DB::entries_query( 5, array( 'search' => "\u{e9}" ) );
		// wp_json_encode stores e-acute as the escape u00e9; esc_like doubles the backslash.
		$this->assertContains( '%\\\\u00e9%', $params );
	}

	public function test_search_invalid_utf8_matches_nothing() {
		list( $where, $params ) = \FormTheory_DB::entries_query( 5, array( 'search' => "\xff\xfe" ) );
		$this->assertStringContainsString( '1=0', $where );
		$this->assertSame( array( 5 ), $params );
	}

	public function test_clean_ids_drops_non_scalars() {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, \FormTheory_DB::delete_entries( 5, array( array( 7 ), null ) ) );
	}

	public function test_insert_entry_includes_notes_and_status() {
		\Brain\Monkey\Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		\Brain\Monkey\Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->wpdb->insert_id = 9;
		$captured              = null;
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturnUsing(
			function ( $table, $data, $fmt ) use ( &$captured ) {
				$captured = array( $data, $fmt );
				return 1;
			}
		);
		\FormTheory_DB::insert_entry( 5, array( 'a' => 'b' ) );
		$this->assertSame( '', $captured[0]['notes'] );
		$this->assertSame( 'unread', $captured[0]['status'] );
		$this->assertCount( count( $captured[0] ), $captured[1] );
	}
}
