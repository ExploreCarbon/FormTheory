<?php
namespace FormTheory\Tests;

use Mockery;

class AdminBulkTest extends TestCase {

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
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function test_bulk_delete_calls_db() {
		$this->wpdb->shouldReceive( 'get_col' )->once()
			->with( Mockery::on( function ( $sql ) {
				return false !== strpos( $sql, 'form_id = 5' ) && false !== strpos( $sql, 'IN (1,2)' );
			} ) )
			->andReturn( array( '1', '2' ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'delete' )->twice()->andReturn( 1 );
		$this->assertSame( 2, ( new \FormTheory_Admin() )->bulk_apply( 5, 'delete', array( 1, 2 ) ) );
	}

	public function test_bulk_mark_read_scoped() {
		$this->wpdb->shouldReceive( 'query' )->once()
			->with( Mockery::on( function ( $sql ) {
				return false !== strpos( $sql, 'status = read' ) && false !== strpos( $sql, 'form_id = 5' );
			} ) )
			->andReturn( 2 );
		$this->assertSame( 2, ( new \FormTheory_Admin() )->bulk_apply( 5, 'read', array( 1, 2 ) ) );
	}

	public function test_bulk_unknown_action_noop() {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->wpdb->shouldNotReceive( 'delete' );
		$this->assertSame( 0, ( new \FormTheory_Admin() )->bulk_apply( 5, 'purge', array( 1 ) ) );
	}

	public function test_bulk_ignores_non_numeric_ids() {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->assertSame( 0, ( new \FormTheory_Admin() )->bulk_apply( 5, 'delete', array( 'x', '' ) ) );
	}

	public function test_bulk_message_key_zero_is_none() {
		$this->assertSame( 'bulk_none', \FormTheory_Admin::bulk_message_key( 0 ) );
	}

	public function test_bulk_message_key_positive_is_bulk() {
		$this->assertSame( 'bulk', \FormTheory_Admin::bulk_message_key( 3 ) );
	}
}
