<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

/** In-memory drafts table: prepare() returns [sql, args] so queries are interpreted by intent. */
class FakeDraftsWpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $rows      = array();
	public $entries   = array();
	public $fail_insert = false;

	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}

	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	public function insert( $table, $data ) {
		if ( $this->fail_insert ) {
			return false;
		}
		$this->insert_id++;
		if ( 'wp_formtheory_drafts' === $table ) {
			$this->rows[ $this->insert_id ] = array_merge( array( 'id' => (string) $this->insert_id ), array_map( 'strval', $data ) );
		} else {
			$this->entries[] = $data;
		}
		return 1;
	}

	public function get_row( $q ) {
		list( $sql, $args ) = $q;
		if ( false !== strpos( $sql, 'formtheory_drafts' ) && false !== strpos( $sql, 'token_hash = %s' ) ) {
			foreach ( $this->rows as $r ) {
				if ( $r['token_hash'] === $args[0] && $r['expires_at'] > $args[1] ) {
					return $r;
				}
			}
		}
		return null;
	}

	public function get_results( $q ) {
		list( $sql, $args ) = $q;
		$out = array();
		if ( false === strpos( $sql, 'formtheory_drafts' ) ) {
			return $out;
		}
		$last = end( $args );
		foreach ( $this->rows as $r ) {
			if ( (int) $r['id'] > (int) $last ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public function query( $q ) {
		list( $sql, $args ) = $q;
		$n = 0;
		if ( 0 === strpos( $sql, 'DELETE FROM wp_formtheory_drafts WHERE expires_at <= %s' ) ) {
			foreach ( $this->rows as $id => $r ) {
				if ( $r['expires_at'] <= $args[0] ) {
					unset( $this->rows[ $id ] );
					$n++;
				}
			}
		}
		return $n;
	}

	public function delete( $table, $where ) {
		if ( 'wp_formtheory_drafts' !== $table ) {
			return 1;
		}
		$n = 0;
		foreach ( $this->rows as $id => $r ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) $r[ $k ] !== (string) $v ) {
					$match = false;
				}
			}
			if ( $match ) {
				unset( $this->rows[ $id ] );
				$n++;
			}
		}
		return $n;
	}
}

class DraftsTest extends TestCase {

	private $db;
	private $front;
	private $transients = array();
	private $mails      = array();
	private $ttl        = null;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new FakeDraftsWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$n = 0;
		Functions\when( 'wp_generate_password' )->alias( function () use ( &$n ) {
			$n++;
			return substr( str_repeat( 'Tok' . $n . 'x', 20 ), 0, 32 );
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $v ) {
			return 'formtheory_draft_ttl' === $hook && null !== $this->ttl ? $this->ttl : $v;
		} );
		Functions\when( 'get_transient' )->alias( function ( $k ) {
			return $this->transients[ $k ] ?? false;
		} );
		Functions\when( 'set_transient' )->alias( function ( $k, $v ) {
			$this->transients[ $k ] = $v;
			return true;
		} );
		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) {
			$this->mails[] = array( $to, $subject, $body );
			return true;
		} );
		Functions\when( 'wp_verify_nonce' )->alias( function ( $n, $action ) {
			return 'good' === $n && 'formtheory_submit_2' === $action ? 1 : false;
		} );
		Functions\when( 'wp_hash' )->alias( function ( $s ) {
			return md5( $s );
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		Functions\when( 'wp_get_raw_referer' )->justReturn( 'https://site.test/contact/?a=1&ft_resume=OLD' );
		Functions\when( 'wp_validate_redirect' )->alias( function ( $url, $fallback ) {
			return 0 === strpos( (string) $url, 'https://site.test/' ) ? $url : $fallback;
		} );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'remove_query_arg' )->alias( function ( $keys, $url ) {
			$parts = explode( '?', $url, 2 );
			if ( 1 === count( $parts ) ) {
				return $url;
			}
			parse_str( $parts[1], $q );
			foreach ( (array) $keys as $k ) {
				unset( $q[ $k ] );
			}
			return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
		} );
		Functions\when( 'add_query_arg' )->alias( function ( $k, $v, $url ) {
			return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v;
		} );
		Functions\stubs( array(
			'get_current_user_id' => 0,
			'current_time'        => '2026-11-05 00:00:00',
			'wp_date'             => '2026-12-05',
			'wpautop'             => function ( $s ) {
				return '<p>' . $s . '</p>';
			},
			'wp_specialchars_decode' => function ( $s ) {
				return $s;
			},
			'esc_attr'            => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'esc_html__'          => function ( $s ) {
				return $s;
			},
			'add_shortcode'       => null,
			'add_action'          => null,
		) );
		$this->front = new \FormTheory_Frontend();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private function fields() {
		return \FormTheory_Fields::sanitize_config( array(
			'fields' => array(
				array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ),
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ),
				array( 'id' => 'cv', 'type' => 'file', 'label' => 'CV', 'allowed' => 'pdf' ),
				array( 'id' => 'sig', 'type' => 'signature', 'label' => 'Sign' ),
				array( 'id' => 'ok', 'type' => 'consent', 'label' => 'OK' ),
				array( 'id' => 'p2', 'type' => 'page', 'label' => 'Two' ),
				array( 'id' => 'addr', 'type' => 'address', 'label' => 'Addr' ),
				array( 'id' => 'kids', 'type' => 'repeater', 'label' => 'Kids', 'columns' => array(
					array( 'id' => 'kn', 'type' => 'text', 'label' => 'Kid' ),
					array( 'id' => 'ka', 'type' => 'number', 'label' => 'Age' ),
				) ),
			),
		) )['fields'];
	}

	private function form( $save = true ) {
		$cfg = \FormTheory_Fields::sanitize_config( array( 'save_resume' => $save, 'fields' => array() ) );
		$cfg['fields'] = $this->fields();
		return array( 'id' => 2, 'title' => 'C', 'config' => \FormTheory_Fields::migrate_config( $cfg ) );
	}

	private function data_fields() {
		return \FormTheory_Fields::data_fields( $this->fields() );
	}

	private function post( array $ft, array $extra = array() ) {
		return array_merge( array( 'formtheory_id' => '2', '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft_page' => '1', 'ft' => $ft ), $extra );
	}

	private function only_row() {
		$this->assertCount( 1, $this->db->rows );
		return reset( $this->db->rows );
	}

	/* ---------- Model ---------- */

	public function test_create_stores_hash_not_token() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 1, $this->data_fields() );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token );
		$row = $this->only_row();
		$this->assertSame( hash( 'sha256', $token ), $row['token_hash'] );
		foreach ( $row as $col ) {
			$this->assertStringNotContainsString( $token, $col );
		}
		$this->assertSame( '1', $row['page'] );
	}

	public function test_load_valid() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 1, $this->data_fields() );
		$this->assertSame( array( 'values' => array( 'name' => 'Ann' ), 'page' => 1 ), \FormTheory_Drafts::load( 2, $token ) );
	}

	public function test_load_unknown_null() {
		\FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$this->assertNull( \FormTheory_Drafts::load( 2, str_repeat( 'A', 32 ) ) );
	}

	public function test_load_expired_null() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$id    = key( $this->db->rows );
		$this->db->rows[ $id ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$this->assertNull( \FormTheory_Drafts::load( 2, $token ) );
		// Even if the query layer returned it, the PHP-side expiry check refuses it.
		$db = new class() extends FakeDraftsWpdb {
			public function get_row( $q ) {
				return reset( $this->rows );
			}
		};
		$db->rows        = $this->db->rows;
		$GLOBALS['wpdb'] = $db;
		$this->assertNull( \FormTheory_Drafts::load( 2, $token ) );
	}

	public function test_load_wrong_form_null() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$this->assertNull( \FormTheory_Drafts::load( 3, $token ) );
	}

	public function test_token_regex_rejects_junk() {
		foreach ( array( '', 'short', str_repeat( 'A', 31 ), str_repeat( 'A', 33 ), str_repeat( 'A', 31 ) . '!', str_repeat( 'A', 31 ) . "\n", array( 'x' ), null, "' OR 1=1 -- aaaaaaaaaaaaaaaaaaaa" ) as $bad ) {
			$this->assertFalse( \FormTheory_Drafts::valid_token( $bad ) );
			$this->assertNull( \FormTheory_Drafts::load( 2, $bad ) );
		}
		$this->assertTrue( \FormTheory_Drafts::valid_token( str_repeat( 'aZ9', 10 ) . 'ab' ) );
	}

	public function test_files_excluded_from_draft() {
		\FormTheory_Drafts::create( 2, array( 'name' => 'Ann', 'cv' => array( 'file' => 'x.pdf', 'name' => 'cv.pdf' ) ), 0, $this->data_fields() );
		$data = json_decode( $this->only_row()['data'], true );
		$this->assertSame( array( 'name' => 'Ann' ), $data );
	}

	public function test_signature_drawn_data_stripped_typed_kept() {
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'sig' => array( 'data' => 'data:image/png;base64,AAAA', 'typed' => 'Ann Lee' ) ) ) );
		$this->assertTrue( $r['ok'] );
		$raw = $this->only_row()['data'];
		$this->assertStringNotContainsString( 'base64', $raw );
		$this->assertSame( array( 'typed' => 'Ann Lee' ), json_decode( $raw, true )['sig'] );
		// Defensive on load: drawn data planted in a stored draft is not restored.
		$vals = $this->front->resume_values( $this->form(), array( 'sig' => array( 'data' => 'data:image/png;base64,AAAA', 'typed' => 'Ann Lee' ), 'cv' => array( 'file' => 'x.pdf' ) ) );
		$this->assertSame( array( 'typed' => 'Ann Lee' ), $vals['sig'] );
		$this->assertArrayNotHasKey( 'cv', $vals );
	}

	public function test_oversize_draft_rejected() {
		$r = \FormTheory_Drafts::create( 2, array( 'name' => str_repeat( 'x', \FormTheory_Drafts::MAX_BYTES ) ), 0, $this->data_fields() );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_ttl_filter_clamped() {
		$this->assertSame( 30 * 86400, \FormTheory_Drafts::ttl() );
		$this->ttl = 5;
		$this->assertSame( 3600, \FormTheory_Drafts::ttl() );
		$this->ttl = 365 * 86400;
		$this->assertSame( 90 * 86400, \FormTheory_Drafts::ttl() );
		$this->ttl = 7200;
		$this->assertSame( 7200, \FormTheory_Drafts::ttl() );
	}

	public function test_purge_expired_deletes_only_expired() {
		$keep = \FormTheory_Drafts::create( 2, array( 'name' => 'Keep' ), 0, $this->data_fields() );
		\FormTheory_Drafts::create( 2, array( 'name' => 'Old' ), 0, $this->data_fields() );
		end( $this->db->rows );
		$this->db->rows[ key( $this->db->rows ) ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$this->assertSame( 1, \FormTheory_Drafts::purge_expired() );
		$this->assertCount( 1, $this->db->rows );
		$this->assertNotNull( \FormTheory_Drafts::load( 2, $keep ) );
	}

	public function test_retention_zero_still_purges_drafts() {
		Functions\when( 'get_option' )->justReturn( array( 'retention_days' => 0 ) );
		\FormTheory_Drafts::create( 2, array( 'name' => 'Old' ), 0, $this->data_fields() );
		$this->db->rows[ key( $this->db->rows ) ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$this->assertSame( 0, \FormTheory_Privacy::run_purge() );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_eraser_deletes_drafts_containing_email_nested() {
		$f = $this->data_fields();
		\FormTheory_Drafts::create( 2, array( 'email' => 'A@B.co' ), 0, $f );
		\FormTheory_Drafts::create( 2, array( 'kids' => array( array( 'kn' => 'a@b.co', 'ka' => '' ) ) ), 0, $f );
		\FormTheory_Drafts::create( 2, array( 'email' => 'a@b.com' ), 0, $f );
		$this->assertSame( 2, \FormTheory_Drafts::erase_by_email( 'a@b.co' ) );
		$this->assertCount( 1, $this->db->rows );
	}

	/* ---------- AJAX save ---------- */

	public function test_save_returns_url_with_token_and_strips_old_params() {
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( '_wp_http_referer' => 'x' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertMatchesRegularExpression( '#^https://site\.test/contact/\?a=1&ft_resume=[A-Za-z0-9]{32}\#formtheory-2$#', $r['url'] );
		$this->assertNotEmpty( $r['expires'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_save_refused_when_save_resume_off() {
		$r = $this->front->save_draft( $this->form( false ), $this->post( array( 'name' => 'Ann' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array(), $this->db->rows );
		$r2 = $this->front->save_draft( null, $this->post( array( 'name' => 'Ann' ) ) );
		$this->assertSame( $r['error'], $r2['error'] );
	}

	public function test_save_nonce_failure() {
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( '_ft_nonce' => 'bad' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_save_honeypot_and_time_trap_refused() {
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( 'ft_hp' => 'bot' ) ) );
		$this->assertFalse( $r['ok'] );
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( 'ft_ts' => 'junk' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_email_rate_limit() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( 'email' => 'v@example.com' ) ) );
			$this->assertTrue( $r['ok'], "save $i" );
		}
		$this->assertCount( 5, $this->mails );
		$this->assertSame( 'v@example.com', $this->mails[0][0] );
		$this->assertStringContainsString( 'ft_resume=', $this->mails[0][2] );
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( 'email' => 'v@example.com' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Too many requests.', $r['error'] );
		$this->assertCount( 5, $this->mails );
		$this->assertCount( 5, $this->db->rows );
		$this->assertArrayHasKey( 'formtheory_draft_rl_' . md5( '203.0.113.9' ), $this->transients );
	}

	public function test_invalid_email_refused_without_mail() {
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'Ann' ), array( 'email' => 'nope' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_create_rate_limit_caps_drafts_per_ip() {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'A' ) ) )['ok'] );
		}
		$r = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'A' ) ) );
		$this->assertSame( 'Too many requests.', $r['error'] );
		$this->assertCount( 20, $this->db->rows );
	}

	public function test_resave_replaces_previous_draft() {
		$first = $this->front->save_draft( $this->form(), $this->post( array( 'name' => 'A' ) ) );
		preg_match( '/ft_resume=([A-Za-z0-9]{32})/', $first['url'], $m );
		$this->front->save_draft( $this->form(), $this->post( array( 'name' => 'B' ), array( 'ft_resume' => $m[1] ) ) );
		$this->assertNull( \FormTheory_Drafts::load( 2, $m[1] ) );
		$this->assertCount( 1, $this->db->rows );
	}

	public function test_repeater_and_address_round_trip_resanitized() {
		$r = $this->front->save_draft( $this->form(), $this->post( array(
			'addr' => array( 'street' => '1 <b>Main</b> St', 'city' => 'Town', 'bogus' => 'x' ),
			'kids' => array( array( 'kn' => 'Bo', 'ka' => '4' ), array( 'kn' => '', 'ka' => '' ), array( 'kn' => 'Cy', 'ka' => '7', 'zz' => 'q' ) ),
			'ok'   => '1',
		) ) );
		$this->assertTrue( $r['ok'] );
		preg_match( '/ft_resume=([A-Za-z0-9]{32})/', $r['url'], $m );
		$loaded = \FormTheory_Drafts::load( 2, $m[1] );
		$this->assertSame( 1, $loaded['page'] );
		// Tamper the stored JSON: load must re-sanitize, not trust it.
		$loaded['values']['addr']['city']           = '<script>x</script>Town';
		$loaded['values']['kids'][0]['kn']          = '<i>Bo</i>';
		$loaded['values']['name']                   = array( 'nested' );
		$vals = $this->front->resume_values( $this->form(), $loaded['values'] );
		$this->assertSame( array( 'street' => '1 Main St', 'city' => 'xTown' ), $vals['addr'] );
		$this->assertSame( array( array( 'kn' => 'Bo', 'ka' => '4' ), array( 'kn' => 'Cy', 'ka' => '7' ) ), $vals['kids'] );
		$this->assertSame( '', $vals['name'] );
		$this->assertSame( 'Accepted', $vals['ok'] );
	}

	/* ---------- Resume render ---------- */

	public function test_generic_notice_identical_for_unknown_expired_wrong_form_and_junk() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$other = \FormTheory_Drafts::create( 3, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$exp   = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		end( $this->db->rows );
		$this->db->rows[ key( $this->db->rows ) ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$out = array();
		foreach ( array( str_repeat( 'Q', 32 ), $other, $exp, 'garbage!', array( 'x' ) ) as $t ) {
			$out[] = $this->front->resume_state( $this->form(), $t );
		}
		foreach ( $out as $o ) {
			$this->assertSame( $out[0], $o );
			$this->assertSame( array(), $o['values'] );
		}
		$this->assertStringContainsString( 'role="status"', $out[0]['notice'] );
		$this->assertStringContainsString( 'This saved link has expired or is not valid.', $out[0]['notice'] );
		$good = $this->front->resume_state( $this->form(), $token );
		$this->assertSame( 'Ann', $good['values']['name'] );
		$this->assertSame( $token, $good['token'] );
	}

	/* ---------- Submit ---------- */

	public function test_submit_deletes_draft() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		\FormTheory_Drafts::create( 2, array( 'name' => 'Other' ), 0, $this->data_fields() );
		$form  = $this->form();
		$form['config']['fields'] = array_slice( $form['config']['fields'], 0, 1 );
		$r = $this->front->process( $form, $this->post( array( 'name' => 'Ann' ), array( 'ft_resume' => $token ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertNull( \FormTheory_Drafts::load( 2, $token ) );
		$this->assertCount( 1, $this->db->rows );
	}

	public function test_failed_submit_keeps_draft() {
		$token = \FormTheory_Drafts::create( 2, array( 'name' => 'Ann' ), 0, $this->data_fields() );
		$form  = $this->form();
		$form['config']['fields'] = array_slice( $form['config']['fields'], 0, 2 );
		$r = $this->front->process( $form, $this->post( array( 'name' => 'Ann', 'email' => 'bad' ), array( 'ft_resume' => $token ) ), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertNotNull( \FormTheory_Drafts::load( 2, $token ) );
	}
}
