<?php
namespace FormTheory\Tests;

class ValueTextTest extends TestCase {

	public function test_scalar() {
		$this->assertSame( 'hi', \FormTheory_Fields::value_to_text( 'hi' ) );
		$this->assertSame( '5', \FormTheory_Fields::value_to_text( 5 ) );
		$this->assertSame( '', \FormTheory_Fields::value_to_text( null ) );
	}

	public function test_list_joined() {
		$this->assertSame( 'a, b', \FormTheory_Fields::value_to_text( array( 'a', 'b' ) ) );
	}

	public function test_file_uses_name() {
		$this->assertSame( 'cv.pdf', \FormTheory_Fields::value_to_text( array( 'file' => 'x.pdf', 'name' => 'cv.pdf' ) ) );
	}

	public function test_signature_markers() {
		$this->assertSame( '[signature] Jo', \FormTheory_Fields::value_to_text( array( 'sig' => 's.png', 'name' => 'Jo' ) ) );
		$this->assertSame( 'Jo (typed)', \FormTheory_Fields::value_to_text( array( 'typed' => 'Jo' ) ) );
	}

	public function test_assoc_joined_skips_empty() {
		$this->assertSame( '1 A, 9', \FormTheory_Fields::value_to_text( array( 'street' => '1 A', 'city' => '', 'zip' => '9' ) ) );
	}

	public function test_empty_array() {
		$this->assertSame( '', \FormTheory_Fields::value_to_text( array() ) );
	}

	public function test_bool_float() {
		$this->assertSame( '1', \FormTheory_Fields::value_to_text( true ) );
		$this->assertSame( '', \FormTheory_Fields::value_to_text( false ) );
		$this->assertSame( '1.5', \FormTheory_Fields::value_to_text( 1.5 ) );
	}

	public function test_non_sequential_keys_treated_as_assoc() {
		$this->assertSame( 'a, b', \FormTheory_Fields::value_to_text( array( 3 => 'a', 7 => 'b' ) ) );
	}

	public function test_mixed_list() {
		$this->assertSame( 'a, b, c', \FormTheory_Fields::value_to_text( array( 'a', array( 'b', 'c' ) ) ) );
	}

	public function test_signature_empty_name_trimmed() {
		$this->assertSame( '[signature]', \FormTheory_Fields::value_to_text( array( 'sig' => 's.png', 'name' => '' ) ) );
	}

	public function test_webhook_payload_shapes() {
		$addr = array( 'street' => '1 A', 'zip' => '9' );
		$rows = array( array( 'n' => 'A' ), array( 'n' => 'B' ) );
		$p    = \FormTheory_Webhooks::payload(
			array( 'id' => 1, 'title' => 'T' ),
			array(
				'f' => array( 'file' => 'x.pdf', 'name' => 'cv.pdf' ),
				's' => array( 'sig' => 's.png', 'name' => 'signature.png' ),
				't' => array( 'typed' => 'Jo' ),
				'a' => $addr,
				'r' => $rows,
			),
			5
		);
		$this->assertSame( 'cv.pdf', $p['fields']['f'] );
		$this->assertSame( 'signature.png', $p['fields']['s'] );
		$this->assertSame( 'Jo', $p['fields']['t'] );
		$this->assertSame( $addr, $p['fields']['a'] );
		$this->assertSame( $rows, $p['fields']['r'] );
	}

	public function test_csv_cell_prefix_applied_after_conversion() {
		$admin = ( new \ReflectionClass( 'FormTheory_Admin' ) )->newInstanceWithoutConstructor();
		$m     = new \ReflectionMethod( $admin, 'csv_cell' );
		$m->setAccessible( true );
		$this->assertSame( "'=1+1, x", $m->invoke( $admin, array( '=1+1', 'x' ) ) );
		$this->assertSame( "'=SUM(A1)", $m->invoke( $admin, array( 'file' => 'f', 'name' => '=SUM(A1)' ) ) );
	}

	public function test_rows_joined_with_semicolon() {
		$rows = array(
			array( 'n' => 'A', 'q' => '1', 'x' => '' ),
			array( 'n' => 'B', 'q' => '2' ),
		);
		$this->assertSame( 'A, 1; B, 2', \FormTheory_Fields::value_to_text( $rows ) );
	}
}
