<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class MergeTest extends TestCase {

	private function ctx( array $data ) {
		return array(
			'form'     => array(
				'id'     => 3,
				'title'  => 'Contact',
				'config' => array(
					'fields' => array(
						array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ),
						array( 'id' => 'q', 'type' => 'text', 'label' => 'Q' ),
						array( 'id' => 'tags', 'type' => 'checkbox', 'label' => 'Tags' ),
						array( 'id' => 'doc', 'type' => 'file', 'label' => 'Doc' ),
					),
				),
			),
			'data'     => $data,
			'entry_id' => 42,
		);
	}

	public function test_field_tag() {
		$this->assertSame( 'Hi Ann', \FormTheory_Merge::replace( 'Hi {field:name}', $this->ctx( array( 'name' => 'Ann' ) ) ) );
	}

	public function test_html_mode_escapes() {
		$this->assertSame( '&lt;b&gt;x&lt;/b&gt;', \FormTheory_Merge::replace( '{field:name}', $this->ctx( array( 'name' => '<b>x</b>' ) ), 'html' ) );
	}

	public function test_header_mode_strips_newlines() {
		$out = \FormTheory_Merge::replace( '{field:name}', $this->ctx( array( 'name' => "a\r\nBcc: x@y" ) ), 'header' );
		$this->assertSame( 'a Bcc: x@y', $out );
	}

	public function test_url_mode_encodes() {
		$this->assertSame( 'a%20b%26c', \FormTheory_Merge::replace( '{field:q}', $this->ctx( array( 'q' => 'a b&c' ) ), 'url' ) );
	}

	public function test_checkbox_array_joined() {
		$this->assertSame( 'x, y', \FormTheory_Merge::replace( '{field:tags}', $this->ctx( array( 'tags' => array( 'x', 'y' ) ) ) ) );
	}

	public function test_file_renders_original_name() {
		$this->assertSame( 'cv.pdf', \FormTheory_Merge::replace( '{field:doc}', $this->ctx( array( 'doc' => array( 'file' => 'abc.pdf', 'name' => 'cv.pdf', 'size' => 1 ) ) ) ) );
	}

	public function test_unknown_tag_untouched() {
		$this->assertSame( '{nope} {field:missing}', \FormTheory_Merge::replace( '{nope} {field:missing}', $this->ctx( array() ) ) );
	}

	public function test_globals() {
		$out = \FormTheory_Merge::replace( '{form_title}#{entry_id} {admin_email}', $this->ctx( array() ) );
		$this->assertSame( 'Contact#42 admin@example.com', $out );
	}

	public function test_all_fields_lists_labels() {
		$out = \FormTheory_Merge::replace( '{all_fields}', $this->ctx( array( 'name' => 'Ann', 'tags' => array( 'x' ) ) ) );
		$this->assertSame( "Name: Ann\nTags: x", $out );
	}
}
