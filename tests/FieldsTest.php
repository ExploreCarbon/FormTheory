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

	public function test_legacy_config_keeps_classic_submit() {
		$this->assertFalse( \FormTheory_Fields::migrate_config( array( 'fields' => array() ) )['ajax'] );
		$this->assertTrue( \FormTheory_Fields::migrate_config( array( 'fields' => array(), 'ajax' => true ) )['ajax'] );
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

	public function test_rating_max_clamped() {
		$this->assertSame( 10, $this->one( array( 'type' => 'rating', 'max' => 99 ) )['max'] );
		$this->assertSame( 3, $this->one( array( 'type' => 'rating', 'max' => 1 ) )['max'] );
		$this->assertSame( 5, $this->one( array( 'type' => 'rating' ) )['max'] );
		$this->assertSame( 5, $this->one( array( 'type' => 'rating', 'max' => 'abc' ) )['max'] );
		$this->assertSame( 7, $this->one( array( 'type' => 'rating', 'max' => '7' ) )['max'] );
	}

	public function test_rating_max_sanitize_edge_values() {
		$this->assertSame( 3, $this->one( array( 'type' => 'rating', 'max' => -4 ) )['max'] );
		$this->assertSame( 6, $this->one( array( 'type' => 'rating', 'max' => '6.9' ) )['max'] );
		$this->assertSame( 10, $this->one( array( 'type' => 'rating', 'max' => '99999999999999999999' ) )['max'] );
	}

	public function test_rating_validate_range() {
		\Brain\Monkey\Functions\stubs( array( 'add_shortcode' => null, 'add_action' => null ) );
		$front = new \FormTheory_Frontend();
		$m     = new \ReflectionMethod( $front, 'validate' );
		$m->setAccessible( true );
		$f = array( 'type' => 'rating', 'max' => 5, 'required' => true, 'options' => array() );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, '6' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, '0' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, 'abc' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, '2.5' ) );
		$this->assertSame( '', $m->invoke( $front, $f, '3' ) );
		$this->assertSame( '', $m->invoke( $front, $f, '5' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, '05' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, '3abc' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, $f, array( '3' ) ) );
		$this->assertSame( '', $m->invoke( $front, $f, ' 3' ) );
		$this->assertSame( 'Choose a valid rating.', $m->invoke( $front, array( 'max' => 3 ) + $f, '4' ) );
		$this->assertSame( 'This field is required.', $m->invoke( $front, $f, '' ) );
		$f['required'] = false;
		$this->assertSame( '', $m->invoke( $front, $f, '' ) );
	}

	public function test_rating_value_works_with_gt_lt_conditions() {
		$this->assertTrue( \FormTheory_Fields::rule_matches( array( 'op' => 'gt', 'value' => '3' ), '4' ) );
		$this->assertFalse( \FormTheory_Fields::rule_matches( array( 'op' => 'gt', 'value' => '3' ), '3' ) );
		$this->assertTrue( \FormTheory_Fields::rule_matches( array( 'op' => 'lt', 'value' => '3' ), '2' ) );
		$this->assertFalse( \FormTheory_Fields::rule_matches( array( 'op' => 'gt', 'value' => '3' ), '' ) );
	}

	public function test_sanitize_keeps_points_and_category_for_radio_and_select_only() {
		$r = $this->one( array( 'type' => 'radio', 'options' => array( 'a', 'b', 'c' ), 'points' => array( '5', 'x', 99999 ), 'category' => ' Debt Plan! ' ) );
		$this->assertSame( array( 5.0, 0.0, 10000.0 ), $r['points'] );
		$this->assertSame( 'debtplan', $r['category'] );
		$s = $this->one( array( 'type' => 'select', 'options' => array( 'a' ), 'points' => array( 2 ), 'category' => 'x' ) );
		$this->assertSame( array( 2.0 ), $s['points'] );
		$c = $this->one( array( 'type' => 'checkbox', 'options' => array( 'a' ), 'points' => array( 2 ), 'category' => 'x' ) );
		$this->assertArrayNotHasKey( 'points', $c );
		$this->assertArrayNotHasKey( 'category', $c );
		$t = $this->one( array( 'type' => 'text', 'points' => array( 2 ), 'category' => 'x' ) );
		$this->assertArrayNotHasKey( 'category', $t );
	}

	public function test_points_pad_to_option_count() {
		$r = $this->one( array( 'type' => 'radio', 'options' => array( 'a', 'b' ), 'points' => array( 3 ) ) );
		$this->assertSame( array( 3.0, 0.0 ), $r['points'] );
	}

	public function test_sanitize_scoring_clamps_and_drops_unknown_categories() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'confirmation_type' => 'score',
			'fields'            => array(
				array( 'id' => 'q', 'type' => 'radio', 'label' => 'Q', 'options' => array( 'a' ), 'points' => array( 1 ), 'category' => 'debt' ),
			),
			'scoring'           => array( 'enabled' => true, 'threshold' => 250, 'overall_page' => '7', 'pages' => array( 'debt' => '5', 'ghost' => '9' ) ),
		) );
		$this->assertSame( 'score', $cfg['confirmation_type'] );
		$this->assertTrue( $cfg['scoring']['enabled'] );
		$this->assertSame( 100, $cfg['scoring']['threshold'] );
		$this->assertSame( 7, $cfg['scoring']['overall_page'] );
		$this->assertSame( array( 'debt' => 5 ), $cfg['scoring']['pages'] );
	}

	public function test_defaults_include_scoring_store_entries_auto_advance() {
		$cfg = \FormTheory_Fields::sanitize_config( array() );
		$this->assertSame( 80, $cfg['scoring']['threshold'] );
		$this->assertFalse( $cfg['scoring']['enabled'] );
		$this->assertTrue( $cfg['store_entries'] );
		$this->assertFalse( $cfg['auto_advance'] );
	}

	public function test_sanitize_store_entries_and_auto_advance_flags() {
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array(), 'store_entries' => false, 'auto_advance' => true ) );
		$this->assertFalse( $cfg['store_entries'] );
		$this->assertTrue( $cfg['auto_advance'] );
		$cfg = \FormTheory_Fields::sanitize_config( array( 'fields' => array() ) );
		$this->assertTrue( $cfg['store_entries'] );
	}
}
