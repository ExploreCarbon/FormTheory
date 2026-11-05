<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class SignatureTest extends TestCase {

	const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	private $front;
	private $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/ft-sig-' . uniqid();
		mkdir( $this->tmp );
		$GLOBALS['wpdb'] = new class() {
			public $prefix    = 'wp_';
			public $insert_id = 0;
			public $inserts   = array();
			public $row       = null;
			public $deleted   = array();
			public function insert( $table, $data ) {
				$this->inserts[] = $data;
				$this->insert_id = count( $this->inserts );
				return 1;
			}
			public function prepare( $q ) {
				return $q;
			}
			public function get_row() {
				return $this->row;
			}
			public function delete( $table, $where ) {
				$this->deleted[] = $table;
				return 1;
			}
		};
		Functions\when( 'wp_verify_nonce' )->alias( function ( $n ) {
			return 'good' === $n ? 1 : false;
		} );
		Functions\when( 'wp_hash' )->alias( function ( $s ) {
			return md5( $s );
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->tmp ) );
		Functions\when( 'trailingslashit' )->alias( function ( $s ) {
			return rtrim( $s, '/' ) . '/';
		} );
		Functions\when( 'wp_mkdir_p' )->alias( function ( $d ) {
			return is_dir( $d ) || mkdir( $d, 0777, true );
		} );
		Functions\when( 'wp_delete_file' )->alias( function ( $p ) {
			@unlink( $p );
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
			'esc_attr'            => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'esc_html__'          => function ( $s ) {
				return $s;
			},
			'esc_attr__'          => function ( $s ) {
				return $s;
			},
		) );
		$this->front = new \FormTheory_Frontend();
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

	private function dir() {
		return $this->tmp . '/formtheory-private';
	}

	private function pngs() {
		return is_dir( $this->dir() ) ? glob( $this->dir() . '/*.png' ) : array();
	}

	private function form( array $over = array(), array $extra = array() ) {
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array_merge( array( $over + array( 'id' => 'sig', 'type' => 'signature', 'label' => 'Sign here' ) ), $extra ) ) );
		return array(
			'id'     => 3,
			'title'  => 'S',
			'config' => \FormTheory_Fields::migrate_config( array(
				'fields'            => $cfg['fields'],
				'confirmation'      => 'Thanks',
				'confirmation_type' => 'message',
				'notify_to'         => '',
			) ),
		);
	}

	private function submit( array $form, array $ft ) {
		return $this->front->process( $form, array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => $ft ), array() );
	}

	private function url( $bytes ) {
		return 'data:image/png;base64,' . base64_encode( $bytes );
	}

	private function png() {
		return base64_decode( self::PNG_1X1 );
	}

	private function assert_rejected( $data_url ) {
		$r = $this->front->store_signature( array( 'id' => 'sig' ), $data_url );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$r = $this->submit( $this->form(), array( 'sig' => array( 'data' => $data_url ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertNotEmpty( $r['errors']['sig'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
		$this->assertSame( array(), $this->pngs() );
		$this->assertFalse( is_dir( $this->dir() ), 'failed validation must not even create the upload dir' );
	}

	public function test_type_registered_and_not_cond_source() {
		$this->assertContains( 'signature', \FormTheory_Fields::TYPES );
		$this->assertContains( 'signature', \FormTheory_Fields::NON_COND_SOURCE );
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array(
			array( 'id' => 's', 'type' => 'signature', 'label' => 'S' ),
			array( 'id' => 'x', 'type' => 'text', 'label' => 'X', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 's', 'op' => 'notempty' ) ) ) ),
		) ) );
		$this->assertFalse( $cfg['fields'][1]['cond']['enabled'] );
	}

	public function test_valid_png_stored() {
		$r = $this->front->store_signature( array( 'id' => 'sig' ), $this->url( $this->png() ) );
		$this->assertIsArray( $r );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\.png$/', $r['sig'] );
		$this->assertSame( 'signature.png', $r['name'] );
		$this->assertFileExists( $this->dir() . '/' . $r['sig'] );
		$this->assertSame( $this->png(), file_get_contents( $this->dir() . '/' . $r['sig'] ) );
	}

	public function test_valid_png_via_process_drawn_wins_over_typed() {
		$r = $this->submit( $this->form( array( 'required' => true ) ), array( 'sig' => array( 'data' => $this->url( $this->png() ), 'typed' => 'Ann Lee' ) ) );
		$this->assertTrue( $r['ok'] );
		$stored = json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true )['sig'];
		$this->assertSame( array( 'sig', 'name' ), array_keys( $stored ) );
		$this->assertSame( 'signature.png', $stored['name'] );
		$this->assertCount( 1, $this->pngs() );
	}

	public function test_rejects_svg_data_url() {
		$this->assert_rejected( 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ) );
	}

	public function test_rejects_wrong_prefix() {
		$this->assert_rejected( 'data:image/png;charset=utf-8;base64,' . self::PNG_1X1 );
		$this->assert_rejected( ' data:image/png;base64,' . self::PNG_1X1 );
		$this->assert_rejected( self::PNG_1X1 );
	}

	public function test_rejects_bad_base64() {
		$this->assert_rejected( 'data:image/png;base64,' . self::PNG_1X1 . '!!@@' );
	}

	public function test_rejects_oversize() {
		$big = $this->png() . str_repeat( "\0", 210 * 1024 );
		$this->assert_rejected( $this->url( $big ) );
	}

	public function test_rejects_long_base64_string_before_decode() {
		$this->assert_rejected( 'data:image/png;base64,' . str_repeat( 'A', 280004 ) );
	}

	public function test_rejects_png_magic_mismatch() {
		$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
		$this->assert_rejected( $this->url( $jpeg ) );
	}

	public function test_rejects_truncated_png() {
		$this->assert_rejected( $this->url( substr( $this->png(), 0, 12 ) ) );
	}

	public function test_rejects_huge_dimensions() {
		$bytes = $this->png();
		$bytes = substr( $bytes, 0, 16 ) . pack( 'NN', 5000, 5000 ) . substr( $bytes, 24 );
		$this->assertSame( 5000, getimagesizefromstring( $bytes )[0] );
		$this->assert_rejected( $this->url( $bytes ) );
		$wide = substr( $this->png(), 0, 16 ) . pack( 'NN', 2001, 10 ) . substr( $this->png(), 24 );
		$this->assert_rejected( $this->url( $wide ) );
	}

	public function test_typed_alternative_satisfies_required() {
		$r = $this->submit( $this->form( array( 'required' => true ) ), array( 'sig' => array( 'data' => '', 'typed' => ' <b>Ann Lee</b> ' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'typed' => 'Ann Lee' ), json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true )['sig'] );
		$this->assertSame( array(), $this->pngs() );
	}

	public function test_typed_capped_at_120_chars() {
		$r = $this->submit( $this->form(), array( 'sig' => array( 'typed' => str_repeat( 'é', 300 ) ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 120, mb_strlen( $r['values']['sig']['typed'] ) );
	}

	public function test_neither_given_required_error() {
		$r = $this->submit( $this->form( array( 'required' => true ) ), array( 'sig' => array( 'data' => '', 'typed' => '  ' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'This field is required.', $r['errors']['sig'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_neither_given_optional_stores_empty() {
		$r = $this->submit( $this->form(), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true )['sig'] );
	}

	public function test_failed_validation_writes_nothing() {
		// Valid signature, but another field fails: no file may be written.
		$form = $this->form( array(), array( array( 'id' => 'em', 'type' => 'email', 'label' => 'E', 'required' => true ) ) );
		$r    = $this->submit( $form, array( 'sig' => array( 'data' => $this->url( $this->png() ) ), 'em' => 'nope' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertArrayHasKey( 'em', $r['errors'] );
		$this->assertSame( array(), $this->pngs() );
		$this->assertFalse( is_dir( $this->dir() ) );
	}

	public function test_render_canvas_typed_input_and_hidden_data() {
		$f = $this->form( array( 'required' => true, 'id' => 'f1' ) )['config']['fields'][0];
		$m = new \ReflectionMethod( $this->front, 'render_field' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->front, 7, $f, array( 'typed' => 'A"B' ), 'This field is required.' );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<fieldset class="ft-signature"', $html );
		$this->assertMatchesRegularExpression( '/<fieldset class="ft-signature"[^>]*aria-invalid="true"/', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ft[f1][data]"', $html );
		$this->assertStringContainsString( '<label for="ft-7-f1-typed">Or type your full name to sign</label>', $html );
		$this->assertMatchesRegularExpression( '/<input type="text" id="ft-7-f1-typed" name="ft\[f1\]\[typed\]"[^>]*maxlength="120"/', $html );
		$this->assertStringContainsString( 'value="A&quot;B"', $html );
		$this->assertMatchesRegularExpression( '/<canvas[^>]*role="img"[^>]*aria-label="/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<canvas[^>]*aria-hidden/', $html );
		$this->assertMatchesRegularExpression( '/<button type="button"[^>]*data-ft-sig-clear/', $html );
	}

	/* ---------- Entry deletion (Ruling H) ---------- */

	public function test_delete_entry_unlinks_signature_file() {
		$r = $this->front->store_signature( array( 'id' => 'sig' ), $this->url( $this->png() ) );
		$this->assertFileExists( $this->dir() . '/' . $r['sig'] );
		$GLOBALS['wpdb']->row = array( 'id' => 5, 'data' => json_encode( array( 'sig' => $r ) ) );
		$this->assertTrue( \FormTheory_DB::delete_entry( 5 ) );
		$this->assertFileDoesNotExist( $this->dir() . '/' . $r['sig'] );
	}

	public function test_delete_entry_signature_traversal_is_basenamed() {
		mkdir( $this->dir() );
		file_put_contents( $this->tmp . '/x.png', 'keep' );
		$GLOBALS['wpdb']->row = array( 'id' => 5, 'data' => json_encode( array( 'sig' => array( 'sig' => '../x.png', 'name' => 'signature.png' ) ) ) );
		\FormTheory_DB::delete_entry( 5 );
		$this->assertFileExists( $this->tmp . '/x.png' );
	}

	/* ---------- Download headers ---------- */

	public function test_file_headers_png_inline_only_for_sig_with_magic() {
		$magic = "\x89PNG\r\n\x1a\n";
		$h     = \FormTheory_Admin::file_headers( array( 'sig' => 'a.png', 'name' => 'signature.png' ), $magic );
		$this->assertContains( 'Content-Type: image/png', $h );
		$this->assertContains( 'Content-Disposition: inline; filename="signature.png"', $h );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $h );

		$h = \FormTheory_Admin::file_headers( array( 'sig' => 'a.png', 'name' => 'signature.png' ), '<svg xml' );
		$this->assertContains( 'Content-Type: application/octet-stream', $h );
		$this->assertContains( 'Content-Disposition: attachment; filename="signature.png"', $h );

		$h = \FormTheory_Admin::file_headers( array( 'file' => 'a.png', 'name' => "x\"y\r\n.png" ), $magic );
		$this->assertContains( 'Content-Type: application/octet-stream', $h );
		$this->assertContains( 'Content-Disposition: attachment; filename="xy.png"', $h );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $h );
	}

	/* ---------- Fix round 1 ---------- */

	/** Frontend whose writer writes half the bytes on the Nth call (1-based) and reports the short count. */
	private function failing_front( $fail_on ) {
		return new class( $fail_on ) extends \FormTheory_Frontend {
			private $fail_on;
			private $calls = 0;
			public function __construct( $fail_on ) {
				$this->fail_on = $fail_on;
			}
			protected function write_bytes( $path, $bytes ) {
				if ( ++$this->calls === $this->fail_on ) {
					return file_put_contents( $path, substr( $bytes, 0, 10 ) );
				}
				return parent::write_bytes( $path, $bytes );
			}
		};
	}

	public function test_short_write_returns_error_and_leaves_nothing() {
		$r = $this->failing_front( 1 )->store_signature( array( 'id' => 'sig' ), $this->url( $this->png() ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( array(), $this->pngs() );
	}

	public function test_later_store_failure_removes_earlier_written_files() {
		$form = $this->form( array( 'id' => 's1' ), array( array( 'id' => 's2', 'type' => 'signature', 'label' => 'Two' ) ) );
		$url  = $this->url( $this->png() );
		$r    = $this->failing_front( 2 )->process( $form, array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => array( 's1' => array( 'data' => $url ), 's2' => array( 'data' => $url ) ) ), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertArrayHasKey( 's2', $r['errors'] );
		$this->assertSame( array(), $this->pngs() );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_polyglot_tail_after_iend_is_dropped() {
		$r = $this->front->store_signature( array( 'id' => 'sig' ), $this->url( $this->png() . "<?php system('x'); ?>" ) );
		$this->assertIsArray( $r );
		$saved = file_get_contents( $this->dir() . '/' . $r['sig'] );
		$this->assertSame( $this->png(), $saved );
		$this->assertStringNotContainsString( '<?php', $saved );
		$this->assertSame( 'IEND', substr( $saved, -8, 4 ) );
	}

	public function test_png_without_iend_rejected() {
		$this->assert_rejected( $this->url( substr( $this->png(), 0, -12 ) ) );
	}

	public function test_png_with_bad_chunk_length_rejected() {
		$bytes = $this->png();
		$bytes = substr( $bytes, 0, 33 ) . pack( 'N', 0x7FFFFFFF ) . substr( $bytes, 37 );
		$this->assert_rejected( $this->url( $bytes ) );
	}

	public function test_privacy_erase_unlinks_signature_file() {
		$r  = $this->front->store_signature( array( 'id' => 'sig' ), $this->url( $this->png() ) );
		$db = \Mockery::mock( 'wpdb' );
		$db->prefix = 'wp_';
		$db->shouldReceive( 'prepare' )->andReturnUsing( function ( $q ) {
			return $q;
		} );
		$db->shouldReceive( 'esc_like' )->andReturnUsing( function ( $s ) {
			return $s;
		} );
		$db->shouldReceive( 'get_results' )->andReturn( array( array( 'id' => 9, 'data' => json_encode( array( 'em' => 'a@b.co', 'sig' => $r ) ) ) ) );
		$db->shouldReceive( 'get_row' )->andReturn( array( 'id' => 9, 'data' => json_encode( array( 'em' => 'a@b.co', 'sig' => $r ) ) ) );
		$db->shouldReceive( 'delete' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $db;
		$out = \FormTheory_Privacy::erase( 'a@b.co' );
		$this->assertSame( 1, $out['items_removed'] );
		$this->assertFileDoesNotExist( $this->dir() . '/' . $r['sig'] );
	}

	public function test_webhook_payload_omits_stored_signature_filename() {
		$p = \FormTheory_Webhooks::payload( array( 'id' => 1, 'title' => 'T' ), array( 'sig' => array( 'sig' => 'abc123.png', 'name' => 'signature.png' ) ), 1 );
		$this->assertStringNotContainsString( 'abc123', json_encode( $p ) );
	}
}
