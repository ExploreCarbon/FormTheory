<?php
namespace FormTheory\Tests;

class FieldsTest extends TestCase {

	public function test_rule_matches_is() {
		$rule = array( 'op' => 'is', 'value' => 'a' );
		$this->assertTrue( \FormTheory_Fields::rule_matches( $rule, 'a' ) );
		$this->assertFalse( \FormTheory_Fields::rule_matches( $rule, 'b' ) );
	}
}
