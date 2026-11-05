<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class RepeaterTest extends TestCase {

	private $front;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new class() {
			public $prefix    = 'wp_';
			public $insert_id = 0;
			public $inserts   = array();
			public function insert( $table, $data ) {
				$this->inserts[] = $data;
				$this->insert_id = count( $this->inserts );
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
			'esc_textarea'        => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'selected'            => function ( $a, $b ) {
				return (string) $a === (string) $b ? ' selected="selected"' : '';
			},
		) );
		$this->front = new \FormTheory_Frontend();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function cols() {
		return array(
			array( 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true ),
			array( 'id' => 'mail', 'label' => 'Email', 'type' => 'email' ),
		);
	}

	private function one( array $f = array() ) {
		$f  += array( 'id' => 'rep', 'label' => 'People', 'type' => 'repeater', 'columns' => $this->cols() );
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array( $f ) ) );
		return $cfg['fields'][0];
	}

	private function form( array $over = array() ) {
		return array(
			'id'     => 2,
			'title'  => 'C',
			'config' => \FormTheory_Fields::migrate_config( array(
				'fields'            => array( $this->one( $over ) ),
				'confirmation'      => 'Thanks',
				'confirmation_type' => 'message',
				'notify_to'         => '',
			) ),
		);
	}

	private function submit( array $form, $rows ) {
		return $this->front->process( $form, array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => array( 'rep' => $rows ) ), array() );
	}

	/* ---------- config ---------- */

	public function test_type_registered_and_not_cond_source() {
		$this->assertContains( 'repeater', \FormTheory_Fields::TYPES );
		$this->assertContains( 'repeater', \FormTheory_Fields::NON_COND_SOURCE );
	}

	public function test_columns_sanitized() {
		$f = $this->one( array( 'columns' => array(
			array( 'id' => 'a', 'label' => ' <b>A</b> ', 'type' => 'text' ),
			array( 'id' => 'bad', 'label' => 'Bad', 'type' => 'file' ),     // Invalid type: dropped.
			array( 'id' => 'a', 'label' => 'Dup', 'type' => 'text' ),        // Duplicate id: dropped.
			array( 'id' => 'B Ö!', 'label' => 'B', 'type' => 'select', 'options' => array( ' x ', '', '<i>y</i>' ), 'required' => 1 ),
			'junk',
		) ) );
		$this->assertCount( 2, $f['columns'] );
		$this->assertSame( 'a', $f['columns'][0]['id'] );
		$this->assertSame( 'A', $f['columns'][0]['label'] );
		$this->assertFalse( $f['columns'][0]['required'] );
		$this->assertSame( 'b', $f['columns'][1]['id'] );
		$this->assertSame( array( 'x', 'y' ), $f['columns'][1]['options'] );
		$this->assertTrue( $f['columns'][1]['required'] );
		$this->assertSame( array(), $f['columns'][0]['options'] );
	}

	public function test_more_than_six_columns_truncated() {
		$cols = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$cols[] = array( 'id' => 'c' . $i, 'label' => 'C' . $i, 'type' => 'text' );
		}
		$f = $this->one( array( 'columns' => $cols ) );
		$this->assertSame( array( 'c1', 'c2', 'c3', 'c4', 'c5', 'c6' ), array_column( $f['columns'], 'id' ) );
	}

	public function test_reserved_marker_ids_dropped() {
		$f = $this->one( array( 'columns' => array(
			array( 'id' => 'file', 'label' => 'F', 'type' => 'text' ),
			array( 'id' => 'SIG', 'label' => 'S', 'type' => 'text' ),
			array( 'id' => 'typed', 'label' => 'T', 'type' => 'text' ),
			array( 'id' => 'ok', 'label' => 'O', 'type' => 'text' ),
		) ) );
		$this->assertSame( array( 'ok' ), array_column( $f['columns'], 'id' ) );
	}

	public function test_missing_or_numeric_ids_generated_safe_and_unique() {
		$f = $this->one( array( 'columns' => array(
			array( 'label' => 'One', 'type' => 'text' ),
			array( 'id' => '7', 'label' => 'Two', 'type' => 'text' ),
			array( 'id' => 'c1', 'label' => 'Three', 'type' => 'text' ),
			array( 'id' => '!!!', 'label' => 'Four', 'type' => 'text' ),
		) ) );
		$ids = array_column( $f['columns'], 'id' );
		$this->assertCount( 4, $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $id );
			$this->assertDoesNotMatchRegularExpression( '/^[0-9]+$/', $id );
			$this->assertNotContains( $id, array( 'file', 'sig', 'typed' ) );
		}
		$this->assertContains( 'c1', $ids );
		$this->assertSame( 'Three', $f['columns'][ array_search( 'c1', $ids, true ) ]['label'] );
	}

	public function test_non_array_columns_ok() {
		$f = $this->one( array( 'columns' => 'nope' ) );
		$this->assertSame( array(), $f['columns'] );
	}

	public function test_row_limits_defaults_and_clamps() {
		$f = $this->one();
		$this->assertSame( 1, $f['min_rows'] );
		$this->assertSame( 5, $f['max_rows'] );
		$this->assertSame( 'Add row', $f['add_label'] );
		$f = $this->one( array( 'min_rows' => '-4', 'max_rows' => 99 ) );
		$this->assertSame( 0, $f['min_rows'] );
		$this->assertSame( 20, $f['max_rows'] );
		$f = $this->one( array( 'min_rows' => 50, 'max_rows' => 2 ) );
		$this->assertSame( 20, $f['min_rows'] );
		$this->assertSame( 20, $f['max_rows'] ); // max never below min.
		$f = $this->one( array( 'min_rows' => 4, 'max_rows' => 0 ) );
		$this->assertSame( 4, $f['min_rows'] );
		$this->assertSame( 4, $f['max_rows'] );
		$f = $this->one( array( 'min_rows' => 'x', 'max_rows' => 'y' ) );
		$this->assertSame( array( 1, 5 ), array( $f['min_rows'], $f['max_rows'] ) );
	}

	public function test_add_label_sanitized_capped() {
		$this->assertSame( 'Add person', $this->one( array( 'add_label' => '<b>Add person</b>' ) )['add_label'] );
		$this->assertSame( 60, strlen( $this->one( array( 'add_label' => str_repeat( 'x', 100 ) ) )['add_label'] ) );
		$this->assertSame( 'Add row', $this->one( array( 'add_label' => '  ' ) )['add_label'] );
	}

	public function test_zero_valid_columns_kept_and_never_required() {
		$f = $this->one( array( 'required' => true, 'columns' => array( array( 'id' => 'x', 'type' => 'bogus' ) ) ) );
		$this->assertSame( array(), $f['columns'] );
		$this->assertFalse( $f['required'] );
		$form = $this->form( array( 'required' => true, 'columns' => array() ) );
		$r    = $this->submit( $form, array( array( 'x' => 'y' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['values']['rep'] );
	}

	public function test_cond_rule_on_repeater_dropped() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'fields' => array(
				array( 'id' => 'rep', 'type' => 'repeater', 'label' => 'R', 'columns' => $this->cols() ),
				array( 'id' => 'y', 'type' => 'text', 'label' => 'Y', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'rep', 'op' => 'notempty' ) ) ) ),
			),
			'notifications' => array( array( 'to' => 'a@b.co', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'rep', 'op' => 'notempty' ) ) ) ) ),
			'webhooks'      => array( array( 'url' => 'https://example.com/h', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'rep', 'op' => 'notempty' ) ) ) ) ),
		) );
		$this->assertSame( array(), $cfg['fields'][1]['cond']['rules'] );
		$this->assertFalse( $cfg['fields'][1]['cond']['enabled'] );
		$this->assertSame( array(), $cfg['notifications'][0]['cond']['rules'] );
		$this->assertSame( array(), $cfg['webhooks'][0]['cond']['rules'] );
	}

	/* ---------- sanitize_repeater ---------- */

	public function test_empty_rows_dropped_and_reindexed() {
		$f = $this->one();
		$r = \FormTheory_Frontend::sanitize_repeater( $f, array(
			5 => array( 'name' => '', 'mail' => '  ' ),
			9 => array( 'name' => ' <i>Ann</i> ', 'mail' => 'ann@x.co' ),
			12 => array(),
			13 => array( 'name' => 'Bob' ),
		) );
		$this->assertSame( array(
			array( 'name' => 'Ann', 'mail' => 'ann@x.co' ),
			array( 'name' => 'Bob', 'mail' => '' ),
		), $r );
	}

	public function test_unknown_column_keys_ignored() {
		$r = \FormTheory_Frontend::sanitize_repeater( $this->one(), array( array( 'name' => 'Ann', 'evil' => 'x', 'file' => 'y', 'sig' => 'z' ) ) );
		$this->assertSame( array( array( 'name' => 'Ann', 'mail' => '' ) ), $r );
		// A row with only unknown keys is empty.
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $this->one(), array( array( 'evil' => 'x' ) ) ) );
	}

	public function test_hostile_shapes_do_not_error() {
		$f = $this->one();
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $f, 'str' ) );
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $f, null ) );
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $f, array( 'a', 7, null ) ) );
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $f, array( array( 'name' => array( 'x' ), 'mail' => array( array( 'y' ) ) ) ) ) );
		$this->assertSame( array( array( 'name' => 'ok', 'mail' => '' ) ), \FormTheory_Frontend::sanitize_repeater( $f, array( array( 'name' => 'ok', 'mail' => array( 'x' ) ) ) ) );
	}

	public function test_rows_examined_are_capped() {
		$rows = array_fill( 0, 100, array( 'name' => '' ) );
		$rows[] = array( 'name' => 'late' ); // Row 101 is never examined.
		$this->assertSame( '', \FormTheory_Frontend::sanitize_repeater( $this->one(), $rows ) );
		$many = array_fill( 0, 150, array( 'name' => 'x' ) );
		$this->assertCount( 100, \FormTheory_Frontend::sanitize_repeater( $this->one(), $many ) );
	}

	public function test_cells_use_column_type_rules() {
		$f = $this->one( array( 'columns' => array(
			array( 'id' => 'e', 'label' => 'E', 'type' => 'email' ),
			array( 'id' => 't', 'label' => 'T', 'type' => 'textarea' ),
		) ) );
		$r = \FormTheory_Frontend::sanitize_repeater( $f, array( array( 'e' => ' a@b.co ', 't' => "line1\nline2" ) ) );
		$this->assertSame( 'a@b.co', $r[0]['e'] );
		$this->assertSame( "line1\nline2", $r[0]['t'] );
	}

	/* ---------- process / validate ---------- */

	public function test_stored_value_and_entry() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'Ann', 'mail' => 'a@b.co' ), array( 'name' => 'Bob' ) ) );
		$this->assertTrue( $r['ok'] );
		$expected = array( array( 'name' => 'Ann', 'mail' => 'a@b.co' ), array( 'name' => 'Bob', 'mail' => '' ) );
		$this->assertSame( $expected, $r['values']['rep'] );
		$this->assertSame( $expected, json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true )['rep'] );
	}

	public function test_all_empty_stored_as_empty_string() {
		$r = $this->submit( $this->form( array( 'min_rows' => 0 ) ), array( array( 'name' => '' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['values']['rep'] );
	}

	public function test_rows_limited_to_max() {
		$rows = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$rows[] = array( 'name' => 'n' . $i );
		}
		$r = $this->submit( $this->form(), $rows );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Too many rows.', $r['errors']['rep'] );
		$this->assertCount( 8, $r['values']['rep'] ); // Not silently truncated: re-rendered for the visitor.
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
		$this->assertTrue( $this->submit( $this->form(), array_slice( $rows, 0, 5 ) )['ok'] );
	}

	public function test_required_needs_min_rows() {
		$form = $this->form( array( 'required' => true, 'min_rows' => 2 ) );
		$r    = $this->submit( $form, array() );
		$this->assertSame( 'This field is required.', $r['errors']['rep'] );
		$r = $this->submit( $form, array( array( 'name' => 'Ann' ) ) );
		$this->assertSame( 'Add at least 2 rows.', $r['errors']['rep'] );
		$r = $this->submit( $form, array( array( 'name' => 'Ann' ), array( 'name' => 'Bob' ) ) );
		$this->assertTrue( $r['ok'] );
	}

	public function test_required_with_min_zero_needs_one_row() {
		$form = $this->form( array( 'required' => true, 'min_rows' => 0 ) );
		$this->assertSame( 'This field is required.', $this->submit( $form, array( array( 'name' => '' ) ) )['errors']['rep'] );
		$this->assertTrue( $this->submit( $form, array( array( 'name' => 'Ann' ) ) )['ok'] );
	}

	public function test_min_rows_enforced_when_optional_only_if_a_row_has_content() {
		$form = $this->form( array( 'min_rows' => 2 ) );
		$this->assertTrue( $this->submit( $form, array() )['ok'] );
		$this->assertTrue( $this->submit( $form, array( array( 'name' => '' ) ) )['ok'] );
		$r = $this->submit( $form, array( array( 'name' => 'Ann' ) ) );
		$this->assertSame( 'Add at least 2 rows.', $r['errors']['rep'] );
	}

	public function test_min_rows_one_message_singular() {
		$form = $this->form( array( 'required' => true, 'min_rows' => 1 ) );
		$this->assertSame( 'This field is required.', $this->submit( $form, array() )['errors']['rep'] );
	}

	public function test_cell_validation_email() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'Ann', 'mail' => 'a@b.co' ), array( 'name' => 'Bob', 'mail' => 'nope' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Row 2, Email: Enter a valid email address.', $r['errors']['rep'] );
	}

	public function test_cell_validation_number_phone_date_select() {
		$cols = array(
			array( 'id' => 'n', 'label' => 'Qty', 'type' => 'number' ),
			array( 'id' => 'p', 'label' => 'Tel', 'type' => 'phone' ),
			array( 'id' => 'd', 'label' => 'When', 'type' => 'date' ),
			array( 'id' => 's', 'label' => 'Size', 'type' => 'select', 'options' => array( 'S', 'M' ) ),
		);
		$form = $this->form( array( 'columns' => $cols ) );
		$this->assertSame( 'Row 1, Qty: Enter a number.', $this->submit( $form, array( array( 'n' => 'abc' ) ) )['errors']['rep'] );
		$this->assertSame( 'Row 1, Tel: Enter a valid phone number.', $this->submit( $form, array( array( 'p' => 'x' ) ) )['errors']['rep'] );
		$this->assertSame( 'Row 1, When: Enter a valid date.', $this->submit( $form, array( array( 'd' => '5/5/2026' ) ) )['errors']['rep'] );
		$this->assertSame( 'Row 1, Size: Choose a valid option.', $this->submit( $form, array( array( 's' => 'XL' ) ) )['errors']['rep'] );
		$this->assertTrue( $this->submit( $form, array( array( 'n' => '3', 'p' => '+1 555 1234', 'd' => '2026-11-05', 's' => 'M' ) ) )['ok'] );
	}

	public function test_column_required_cell_error_names_row() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'Ann' ), array( 'mail' => 'b@c.co' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Row 2, Name: This field is required.', $r['errors']['rep'] );
	}

	public function test_empty_row_does_not_trigger_column_required() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'Ann' ), array( 'name' => '', 'mail' => '' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 1, $r['values']['rep'] );
	}

	public function test_error_row_number_is_posted_position_and_rerender_keeps_it() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'Ann' ), array( 'name' => '', 'mail' => '' ), array( 'name' => 'Cy', 'mail' => 'bad' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Row 3, Email: Enter a valid email address.', $r['errors']['rep'] );
		// The failed-submit re-render keeps the empty row, so the third group is titled "Row 3".
		$this->assertCount( 3, $r['values']['rep'] );
		$html = $this->render( array(), $r['values']['rep'], $r['errors']['rep'] );
		$this->assertStringContainsString( 'id="ft-7-f1-r2-label" data-ft-rep-title>Row 3</span>', $html );
		$this->assertMatchesRegularExpression( '/id="ft-7-f1-2-mail" name="ft\[f1\]\[2\]\[mail\]" value="bad"/', $html );
		// Leading empty row.
		$r = $this->submit( $this->form(), array( array( 'name' => '' ), array( 'name' => 'Ann', 'mail' => 'bad' ) ) );
		$this->assertSame( 'Row 2, Email: Enter a valid email address.', $r['errors']['rep'] );
	}

	public function test_successful_submit_still_stores_compacted_rows() {
		$r = $this->submit( $this->form(), array( array( 'name' => 'A' ), array( 'name' => '' ), array( 'name' => 'C' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'A', 'C' ), array_column( $r['values']['rep'], 'name' ) );
	}

	public function test_filled_row_past_scan_cap_is_too_many_rows() {
		$many = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$many[] = array( 'name' => 'x' . $i );
		}
		$this->assertSame( 'Too many rows.', $this->submit( $this->form(), $many )['errors']['rep'] );
		// First 100 empty, a filled row afterwards: still an error, not silently ignored.
		$late = array_fill( 0, 100, array( 'name' => '' ) );
		$late[] = array( 'name' => 'late' );
		$r = $this->submit( $this->form(), $late );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Too many rows.', $r['errors']['rep'] );
		// Bounded work: sanitize_repeater itself never returns more than the cap.
		$this->assertCount( 100, \FormTheory_Frontend::sanitize_repeater( $this->one(), $many ) );
		// Empty rows past the cap are harmless.
		$empties = array_fill( 0, 150, array( 'name' => '' ) );
		$this->assertTrue( $this->submit( $this->form(), $empties )['ok'] );
	}

	/* ---------- text / webhook ---------- */

	public function test_value_to_text_rows() {
		$this->assertSame( 'Ann, a@b.co; Bob', \FormTheory_Fields::value_to_text( array( array( 'name' => 'Ann', 'mail' => 'a@b.co' ), array( 'name' => 'Bob', 'mail' => '' ) ) ) );
	}

	public function test_webhook_payload_keeps_structured_rows() {
		$rows = array( array( 'name' => 'Ann', 'mail' => 'a@b.co' ), array( 'name' => 'Bob', 'mail' => '' ) );
		$p    = \FormTheory_Webhooks::payload( array( 'id' => 2, 'title' => 'C' ), array( 'rep' => $rows ), 5 );
		$this->assertSame( $rows, $p['fields']['rep'] );
		$this->assertSame( $rows, json_decode( wp_json_encode( $p ), true )['fields']['rep'] );
	}

	/* ---------- render ---------- */

	private function render( array $over = array(), $value = '', $error = '' ) {
		$f = $this->one( $over + array( 'id' => 'f1' ) ) + array( 'placeholder' => '', 'help' => '', 'cond' => array( 'enabled' => false ) );
		if ( isset( $over['help'] ) ) {
			$f['help'] = $over['help'];
		}
		$m = new \ReflectionMethod( $this->front, 'render_field' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->front, 7, $f, $value, $error );
		return ob_get_clean();
	}

	public function test_render_fieldset_rows_groups_and_unique_ids() {
		$html = $this->render( array( 'required' => true, 'min_rows' => 2 ) );
		$this->assertStringContainsString( '<fieldset class="ft-repeater"', $html );
		$this->assertStringContainsString( '<legend>People', $html );
		$this->assertStringContainsString( '(required)', $html );
		$this->assertSame( 2, preg_match_all( '/<div class="ft-rep-row" role="group" aria-labelledby="ft-7-f1-r(\d)-label"/', $html, $m ) );
		$this->assertSame( array( '0', '1' ), $m[1] );
		foreach ( array( 0, 1 ) as $i ) {
			$this->assertStringContainsString( '<span class="ft-rep-title" id="ft-7-f1-r' . $i . '-label" data-ft-rep-title>Row ' . ( $i + 1 ) . '</span>', $html );
			foreach ( array( 'name', 'mail' ) as $c ) {
				$this->assertStringContainsString( '<label for="ft-7-f1-' . $i . '-' . $c . '">', $html );
				$this->assertMatchesRegularExpression( '/id="ft-7-f1-' . $i . '-' . $c . '" name="ft\[f1\]\[' . $i . '\]\[' . $c . '\]"/', $html );
			}
		}
		// Required column input is aria-required; optional is not.
		$this->assertMatchesRegularExpression( '/id="ft-7-f1-0-name"[^>]*aria-required="true"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="ft-7-f1-0-mail"[^>]*aria-required/', $html );
		$this->assertMatchesRegularExpression( '/id="ft-7-f1-0-mail"[^>]*autocomplete="email"/', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-err"', $html );
	}

	public function test_render_min_rows_zero_still_renders_one_row_for_no_js() {
		$html = $this->render( array( 'min_rows' => 0 ) );
		$this->assertSame( 1, substr_count( $html, 'data-ft-rep-idx="0"' ) );
		$this->assertSame( 0, substr_count( $html, 'data-ft-rep-idx="1"' ) );
	}

	public function test_render_buttons_template_and_live_region() {
		$html = $this->render( array( 'min_rows' => 1, 'max_rows' => 3, 'add_label' => 'Add person' ) );
		$this->assertMatchesRegularExpression( '/<button type="button" class="ft-btn ft-rep-add" data-ft-rep-add[^>]*hidden[^>]*>Add person<\/button>/', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="ft-btn ft-rep-remove" data-ft-rep-remove[^>]*aria-label="Remove row 1"[^>]*hidden/', $html );
		$this->assertStringContainsString( '<template data-ft-rep-template>', $html );
		$this->assertStringContainsString( 'name="ft[f1][__ROW__][name]"', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-__ROW__-name"', $html );
		$this->assertStringContainsString( 'data-ft-rep-live', $html );
		$this->assertMatchesRegularExpression( '/role="status" aria-live="polite" data-ft-rep-live/', $html );
		$this->assertStringContainsString( 'data-ft-min="1"', $html );
		$this->assertStringContainsString( 'data-ft-max="3"', $html );
		$this->assertStringContainsString( 'data-ft-rep-prefix="ft-7-f1"', $html );
		$this->assertStringContainsString( 'data-ft-rep-name="ft[f1]"', $html );
		$this->assertStringContainsString( 'Maximum of 3 rows reached.', $html );
	}

	public function test_render_posted_rows_and_error_state() {
		$rows = array( array( 'name' => 'A"B', 'mail' => 'a@b.co' ), array( 'name' => 'Bob', 'mail' => '' ), array( 'name' => 'Cy', 'mail' => '' ) );
		$html = $this->render( array( 'min_rows' => 1, 'help' => 'Who?' ), $rows, 'Row 2, Email: bad' );
		$this->assertMatchesRegularExpression( '/<fieldset class="ft-repeater"[^>]*aria-describedby="ft-7-f1-help ft-7-f1-err"[^>]*aria-invalid="true"/', $html );
		$this->assertStringContainsString( 'value="A&quot;B"', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-2-name"', $html );
		$this->assertStringNotContainsString( 'id="ft-7-f1-3-name"', $html );
	}

	public function test_render_over_max_keeps_all_posted_rows() {
		$rows = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$rows[] = array( 'name' => 'n' . $i, 'mail' => '' );
		}
		$html = $this->render( array( 'max_rows' => 5 ), $rows, 'Too many rows.' );
		$this->assertStringContainsString( 'id="ft-7-f1-6-name"', $html );
	}

	public function test_render_column_types_and_select_options() {
		$html = $this->render( array( 'columns' => array(
			array( 'id' => 'a', 'label' => 'A', 'type' => 'number' ),
			array( 'id' => 'b', 'label' => 'B', 'type' => 'textarea' ),
			array( 'id' => 'c', 'label' => 'C', 'type' => 'select', 'options' => array( 'S', 'M' ) ),
			array( 'id' => 'd', 'label' => 'D', 'type' => 'phone' ),
			array( 'id' => 'e', 'label' => 'E', 'type' => 'date' ),
		) ), array( array( 'a' => '1', 'b' => 'x', 'c' => 'M', 'd' => '', 'e' => '' ) ) );
		$this->assertMatchesRegularExpression( '/<input type="number" id="ft-7-f1-0-a"[^>]*step="any"/', $html );
		$this->assertStringContainsString( '<textarea id="ft-7-f1-0-b"', $html );
		$this->assertStringContainsString( '<option value="M" selected="selected">M</option>', $html );
		$this->assertMatchesRegularExpression( '/<input type="tel" id="ft-7-f1-0-d"/', $html );
		$this->assertMatchesRegularExpression( '/<input type="date" id="ft-7-f1-0-e"/', $html );
	}

	public function test_render_no_columns_renders_nothing_interactive() {
		$html = $this->render( array( 'columns' => array() ) );
		$this->assertStringNotContainsString( '<fieldset', $html );
		$this->assertStringNotContainsString( 'ft-rep-add', $html );
	}
}
