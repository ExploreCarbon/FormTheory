<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class UninstallTest extends TestCase {

	private $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/ft-un-' . uniqid();
		mkdir( $this->tmp );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->tmp );
		parent::tearDown();
	}

	private function rrmdir( $d ) {
		if ( is_link( $d ) || is_file( $d ) ) {
			@unlink( $d );
			return;
		}
		if ( ! is_dir( $d ) ) {
			return;
		}
		foreach ( array_diff( scandir( $d ), array( '.', '..' ) ) as $f ) {
			$this->rrmdir( "$d/$f" );
		}
		@rmdir( $d );
	}

	public function test_uninstall_drops_tables_and_options() {
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$queries      = array();
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $q ) use ( &$queries ) {
			$queries[] = $q;
			return 1;
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->tmp ) );
		Functions\when( 'trailingslashit' )->alias( function ( $s ) {
			return rtrim( $s, '/' ) . '/';
		} );
		$deleted = array();
		Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$deleted ) {
			$deleted[] = $k;
			return true;
		} );
		$cleared = array();
		Functions\when( 'wp_unschedule_hook' )->alias( function ( $h ) use ( &$cleared ) {
			$cleared[] = $h;
		} );
		mkdir( $this->tmp . '/formtheory-private' );
		file_put_contents( $this->tmp . '/formtheory-private/a.txt', 'x' );

		\FormTheory_DB::uninstall();

		$this->assertSame(
			array(
				'DROP TABLE IF EXISTS wp_formtheory_forms',
				'DROP TABLE IF EXISTS wp_formtheory_entries',
				'DROP TABLE IF EXISTS wp_formtheory_webhook_log',
			),
			$queries
		);
		$this->assertSame( array( 'formtheory_settings', 'formtheory_recaptcha', 'formtheory_db_version' ), $deleted );
		$this->assertSame( array( 'formtheory_send_webhook', 'formtheory_deliver_webhook', 'formtheory_purge' ), $cleared );
		$this->assertDirectoryDoesNotExist( $this->tmp . '/formtheory-private' );
	}

	public function test_deactivation_unschedules_only_purge() {
		$cleared = array();
		Functions\when( 'wp_unschedule_hook' )->alias( function ( $h ) use ( &$cleared ) {
			$cleared[] = $h;
		} );
		\FormTheory_Privacy::unschedule();
		$this->assertSame( array( 'formtheory_purge' ), $cleared );
	}

	public function test_remove_dir_refuses_wrong_basename() {
		$dir = $this->tmp . '/not-ours';
		mkdir( $dir );
		file_put_contents( "$dir/keep.txt", 'x' );
		\FormTheory_DB::remove_dir( $dir );
		$this->assertFileExists( "$dir/keep.txt" );
	}

	public function test_remove_dir_removes_nested_but_skips_symlinks() {
		$dir     = $this->tmp . '/formtheory-private';
		$outside = $this->tmp . '/outside';
		mkdir( "$dir/sub", 0777, true );
		mkdir( $outside );
		file_put_contents( "$outside/precious.txt", 'x' );
		file_put_contents( "$dir/sub/f.txt", 'x' );
		$linked = @symlink( $outside, "$dir/link" );
		\FormTheory_DB::remove_dir( $dir );
		$this->assertFileExists( "$outside/precious.txt" );
		$this->assertFileDoesNotExist( "$dir/sub" );
		if ( ! $linked ) {
			$this->assertDirectoryDoesNotExist( $dir );
		}
	}

	public function test_settings_sanitizer_keeps_flag_false_by_default() {
		Functions\when( 'get_option' )->justReturn( array() );
		$out = \FormTheory_Admin::sanitize_settings( array() );
		$this->assertFalse( $out['delete_on_uninstall'] );
		$this->assertArrayHasKey( 'turnstile_site', $out );
	}

	public function test_settings_sanitizer_merges_existing_and_sets_flag() {
		Functions\when( 'get_option' )->justReturn( array( 'retention_days' => 30, 'turnstile_site' => 'old' ) );
		$out = \FormTheory_Admin::sanitize_settings( array( 'delete_on_uninstall' => '1', 'turnstile_site' => 'new' ) );
		$this->assertTrue( $out['delete_on_uninstall'] );
		$this->assertSame( 30, $out['retention_days'] );
		$this->assertSame( 'new', $out['turnstile_site'] );
	}
}
