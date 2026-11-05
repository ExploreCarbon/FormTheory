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

	public function test_rows_joined_with_semicolon() {
		$rows = array(
			array( 'n' => 'A', 'q' => '1', 'x' => '' ),
			array( 'n' => 'B', 'q' => '2' ),
		);
		$this->assertSame( 'A, 1; B, 2', \FormTheory_Fields::value_to_text( $rows ) );
	}
}
