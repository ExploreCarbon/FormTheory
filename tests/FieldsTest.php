<?php
namespace FormTheory\Tests;

class FieldsTest extends TestCase {

	public function test_rule_matches_is() {
		$rule = array( 'op' => 'is', 'value' => 'a' );
		$this->assertTrue( \FormTheory_Fields::rule_matches( $rule, 'a' ) );
		$this->assertFalse( \FormTheory_Fields::rule_matches( $rule, 'b' ) );
	}

	private function one( array $f ) {
		$f   += array( 'id' => 'a', 'label' => 'L' );
		$cfg  = \FormTheory_Fields::sanitize_config( array( 'fields' => array( $f ) ) );
		return $cfg['fields'][0];
	}

	public function test_html_field_strips_script() {
		$f = $this->one( array( 'type' => 'html', 'content' => '<p>a</p><script>x</script>' ) );
		$this->assertSame( '<p>a</p>x', $f['content'] );
		$this->assertFalse( $f['required'] );
	}

	public function test_consent_required_flag_kept() {
		$f = $this->one( array( 'type' => 'consent', 'required' => true, 'text' => 'I agree to <a href="/t">terms</a>' ) );
		$this->assertTrue( $f['required'] );
		$this->assertSame( 'I agree to <a href="/t">terms</a>', $f['text'] );
	}

	public function test_hidden_default_sanitized() {
		$f = $this->one( array( 'type' => 'hidden', 'default' => '<b>v</b>' ) );
		$this->assertSame( 'v', $f['default'] );
	}

	public function test_non_data_types() {
		$this->assertSame( array( 'page', 'html' ), \FormTheory_Fields::NON_DATA );
	}

	public function test_hidden_default_query_population() {
		$_GET['utm'] = 'news<script>';
		$this->assertSame( 'src-news', \FormTheory_Fields::populate( 'src-{query:utm}' ) );
		unset( $_GET['utm'] );
		$this->assertSame( 'src-', \FormTheory_Fields::populate( 'src-{query:utm}' ) );
	}
}
