<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class CalcTest extends TestCase {

	public function test_precedence() {
		$this->assertSame( 14.0, \FormTheory_Calc::evaluate( '2+3*4', array() ) );
		$this->assertSame( 20.0, \FormTheory_Calc::evaluate( '(2+3)*4', array() ) );
		$this->assertSame( 2.0, \FormTheory_Calc::evaluate( '8/2/2', array() ) );
		$this->assertSame( 5.0, \FormTheory_Calc::evaluate( '10-3-2', array() ) );
		$this->assertSame( 1.0, \FormTheory_Calc::evaluate( '.5*2', array() ) );
	}

	public function test_field_refs() {
		$this->assertSame( 13.5, \FormTheory_Calc::evaluate( '{field:a}*{field:b}', array( 'a' => '3', 'b' => '4.5' ) ) );
	}

	public function test_non_numeric_is_zero() {
		$this->assertSame( 1.0, \FormTheory_Calc::evaluate( '{field:a}+1', array( 'a' => 'abc' ) ) );
		$this->assertSame( 1.0, \FormTheory_Calc::evaluate( '{field:a}+1', array( 'a' => array( 'x' ) ) ) );
	}

	public function test_missing_field_is_zero() {
		$this->assertSame( 0.0, \FormTheory_Calc::evaluate( '{field:a}', array() ) );
	}

	public function test_div_by_zero_null() {
		$this->assertNull( \FormTheory_Calc::evaluate( '1/0', array() ) );
		$this->assertNull( \FormTheory_Calc::evaluate( '{field:a}/{field:b}', array( 'a' => '1', 'b' => '0' ) ) );
	}

	public function test_unary_minus() {
		$this->assertSame( 3.0, \FormTheory_Calc::evaluate( '-{field:a}+5', array( 'a' => '2' ) ) );
		$this->assertSame( 4.0, \FormTheory_Calc::evaluate( '2*-(-2)', array() ) );
		$this->assertSame( 2.0, \FormTheory_Calc::evaluate( '+2', array() ) );
		$this->assertSame( -4.0, \FormTheory_Calc::evaluate( '-2*2', array() ) );
	}

	public function test_rejects_injection() {
		$this->assertNull( \FormTheory_Calc::evaluate( '1;system("x")', array() ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '1+' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '{field:A B}' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( 'abs(1)' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '(1' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '1)' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '1 2' ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( '' ) );
		$this->assertTrue( \FormTheory_Calc::is_valid( '( {field:qty} * 2.5 ) - 1' ) );
	}

	public function test_long_formula_null() {
		$f = str_repeat( '1+', 250 ) . '1';
		$this->assertNull( \FormTheory_Calc::evaluate( $f, array() ) );
		$this->assertFalse( \FormTheory_Calc::is_valid( $f ) );
	}

	public function test_deep_nesting_null() {
		$f = str_repeat( '(', 60 ) . '1' . str_repeat( ')', 60 );
		$this->assertNull( \FormTheory_Calc::evaluate( $f, array() ) );
		$this->assertSame( 1.0, \FormTheory_Calc::evaluate( str_repeat( '(', 50 ) . '1' . str_repeat( ')', 50 ), array() ) );
	}

	public function test_format_matches_js_vectors() {
		$this->assertSame( '6.00', \FormTheory_Calc::format( 6, 2 ) );
		$this->assertSame( '1.01', \FormTheory_Calc::format( 1.005, 2 ) );
		$this->assertSame( '0.00', \FormTheory_Calc::format( -0.0001, 2 ) );
		$this->assertSame( '3', \FormTheory_Calc::format( 2.5, 0 ) );
		$this->assertSame( '-3', \FormTheory_Calc::format( -2.5, 0 ) );
		$this->assertSame( '0.333333', \FormTheory_Calc::format( 1 / 3, 6 ) );
	}

	public function test_sanitize_config_calc() {
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array(
			array( 'id' => 'a', 'type' => 'calc', 'label' => 'T', 'required' => true, 'formula' => '{field:x}*2', 'decimals' => 9, 'prefix' => '$', 'suffix' => str_repeat( 'x', 30 ) ),
			array( 'id' => 'b', 'type' => 'calc', 'label' => 'U', 'formula' => 'abs(1)', 'decimals' => -1 ),
		) ) );
		$a = $cfg['fields'][0];
		$this->assertFalse( $a['required'] );
		$this->assertSame( '{field:x}*2', $a['formula'] );
		$this->assertSame( 6, $a['decimals'] );
		$this->assertSame( '$', $a['prefix'] );
		$this->assertSame( 20, strlen( $a['suffix'] ) );
		$this->assertSame( '', $cfg['fields'][1]['formula'] );
		$this->assertSame( 0, $cfg['fields'][1]['decimals'] );
	}

	public function test_compute_feeds_earlier_calc() {
		$fields = array(
			array( 'id' => 'q', 'type' => 'number' ),
			array( 'id' => 'sub', 'type' => 'calc', 'formula' => '{field:q}*2', 'decimals' => 2 ),
			array( 'id' => 'tot', 'type' => 'calc', 'formula' => '{field:sub}+{field:later}', 'decimals' => 1 ),
			array( 'id' => 'later', 'type' => 'calc', 'formula' => '5', 'decimals' => 0 ),
			array( 'id' => 'bad', 'type' => 'calc', 'formula' => '1/{field:zero}', 'decimals' => 0 ),
		);
		$v = \FormTheory_Calc::compute( $fields, array( 'q' => '3', 'sub' => '999', 'tot' => '', 'later' => '', 'bad' => 'x' ) );
		$this->assertSame( '6.00', $v['sub'] );
		$this->assertSame( '6.0', $v['tot'] );
		$this->assertSame( '5', $v['later'] );
		$this->assertSame( '', $v['bad'] );
	}

	/* ---------- process() ---------- */

	private function front() {
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_hash' )->alias( 'md5' );
		Functions\when( 'is_wp_error' )->alias( function ( $x ) {
			return $x instanceof \WP_Error;
		} );
		Functions\stubs( array(
			'get_current_user_id' => 0,
			'current_time'        => '2026-11-05 00:00:00',
			'wp_mail'             => true,
			'wpautop'             => function ( $s ) {
				return $s;
			},
			'add_shortcode'       => null,
			'add_action'          => null,
		) );
		return new \FormTheory_Frontend();
	}

	private function form( array $fields ) {
		return array( 'id' => 3, 'title' => 'C', 'config' => \FormTheory_Fields::migrate_config( array( 'fields' => $fields, 'confirmation' => 'ok', 'confirmation_type' => 'message', 'notify_to' => '' ) ) );
	}

	private function post( array $ft ) {
		return array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => $ft );
	}

	private function stored() {
		return json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true );
	}

	private function off() {
		return array( 'enabled' => false, 'rules' => array() );
	}

	public function test_process_ignores_posted_calc_value() {
		$front = $this->front();
		$form  = $this->form( array( array( 'id' => 'total', 'type' => 'calc', 'label' => 'T', 'required' => false, 'options' => array(), 'formula' => '1+1', 'decimals' => 0, 'cond' => $this->off() ) ) );
		$r     = $front->process( $form, $this->post( array( 'total' => '999' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '2', $this->stored()['total'] );
	}

	public function test_hidden_calc_not_stored() {
		$front = $this->front();
		$cond  = array( 'enabled' => true, 'action' => 'show', 'logic' => 'all', 'rules' => array( array( 'field' => 'n', 'op' => 'is', 'value' => 'yes' ) ) );
		$form  = $this->form( array(
			array( 'id' => 'n', 'type' => 'text', 'label' => 'N', 'required' => false, 'options' => array(), 'cond' => $this->off() ),
			array( 'id' => 'total', 'type' => 'calc', 'label' => 'T', 'required' => false, 'options' => array(), 'formula' => '1+1', 'decimals' => 0, 'cond' => $cond ),
		) );
		$r = $front->process( $form, $this->post( array( 'n' => 'no', 'total' => '5' ) ), array() );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'total', $this->stored() );
	}

	public function test_calc_value_drives_condition() {
		$front = $this->front();
		$cond  = array( 'enabled' => true, 'action' => 'show', 'logic' => 'all', 'rules' => array( array( 'field' => 'total', 'op' => 'gt', 'value' => '10' ) ) );
		$form  = $this->form( array(
			array( 'id' => 'q', 'type' => 'number', 'label' => 'Q', 'required' => false, 'options' => array(), 'cond' => $this->off() ),
			array( 'id' => 'total', 'type' => 'calc', 'label' => 'T', 'required' => false, 'options' => array(), 'formula' => '{field:q}*5', 'decimals' => 2, 'cond' => $this->off() ),
			array( 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'options' => array(), 'cond' => $cond ),
		) );
		$r = $front->process( $form, $this->post( array( 'q' => '3', 'total' => '0' ) ), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertArrayHasKey( 'note', $r['errors'] );
		$this->assertSame( '15.00', $r['values']['total'] );
	}
}
