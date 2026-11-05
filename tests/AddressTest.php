<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class AddressTest extends TestCase {

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
		) );
		$this->front = new \FormTheory_Frontend();
	}

	private function one( array $f ) {
		$f   += array( 'id' => 'a', 'label' => 'L', 'type' => 'address' );
		$cfg  = \FormTheory_Fields::sanitize_config( array( 'fields' => array( $f ) ) );
		return $cfg['fields'][0];
	}

	private function form( array $over = array() ) {
		$addr = $this->one( $over + array( 'id' => 'addr' ) );
		return array(
			'id'     => 2,
			'title'  => 'C',
			'config' => \FormTheory_Fields::migrate_config( array(
				'fields'            => array( $addr ),
				'confirmation'      => 'Thanks',
				'confirmation_type' => 'message',
				'notify_to'         => '',
			) ),
		);
	}

	private function submit( array $form, array $ft ) {
		return $this->front->process( $form, array( '_ft_nonce' => 'good', 'ft_ts' => \FormTheory_Spam::stamp( time() - 10 ), 'ft' => $ft ), array() );
	}

	public function test_parts_whitelist() {
		$this->assertSame( array( 'city' ), $this->one( array( 'parts' => array( 'city', 'evil' ) ) )['parts'] );
		$default = array( 'street', 'city', 'state', 'zip', 'country' );
		$this->assertSame( $default, $this->one( array() )['parts'] );
		$this->assertSame( $default, $this->one( array( 'parts' => array() ) )['parts'] );
		$this->assertSame( $default, $this->one( array( 'parts' => array( 'evil' ) ) )['parts'] );
		$this->assertSame( $default, $this->one( array( 'parts' => 'city' ) )['parts'] );
		// Canonical order, de-duplicated.
		$this->assertSame( array( 'street', 'city', 'zip' ), $this->one( array( 'parts' => array( 'zip', 'city', 'city', 'street' ) ) )['parts'] );
	}

	public function test_country_default_sanitized_and_capped() {
		$this->assertSame( 'USA', $this->one( array( 'country_default' => '<b>USA</b>' ) )['country_default'] );
		$this->assertSame( 100, strlen( $this->one( array( 'country_default' => str_repeat( 'x', 300 ) ) )['country_default'] ) );
		$this->assertSame( '', $this->one( array() )['country_default'] );
	}

	public function test_sanitize_value_keeps_only_enabled_parts() {
		$form = $this->form( array( 'parts' => array( 'city', 'zip' ) ) );
		$r    = $this->submit( $form, array( 'addr' => array( 'city' => ' <i>Paris</i> ', 'zip' => '75001', 'street' => 'nope', 'evil' => 'x' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'city' => 'Paris', 'zip' => '75001' ), $r['values']['addr'] );
		$this->assertSame( array( 'city' => 'Paris', 'zip' => '75001' ), json_decode( $GLOBALS['wpdb']->inserts[0]['data'], true )['addr'] );
	}

	public function test_value_in_canonical_order_empty_parts_dropped_and_capped() {
		$form = $this->form( array( 'parts' => array( 'street', 'city', 'zip' ) ) );
		$r    = $this->submit( $form, array( 'addr' => array( 'zip' => '1', 'city' => '', 'street' => str_repeat( 'a', 300 ) ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'street', 'zip' ), array_keys( $r['values']['addr'] ) );
		$this->assertSame( 200, strlen( $r['values']['addr']['street'] ) );
	}

	public function test_scalar_or_nested_posts_are_ignored() {
		$form = $this->form( array( 'parts' => array( 'city' ) ) );
		$r    = $this->submit( $form, array( 'addr' => 'Paris' ) );
		$this->assertSame( '', $r['values']['addr'] );
		$r = $this->submit( $form, array( 'addr' => array( 'city' => array( 'x' ) ) ) );
		$this->assertSame( '', $r['values']['addr'] );
	}

	public function test_required_partial_fails() {
		$form = $this->form( array( 'required' => true ) );
		$r    = $this->submit( $form, array( 'addr' => array( 'street' => '1 Main', 'city' => 'Town' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Please complete the address.', $r['errors']['addr'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_required_all_empty_fails_with_required_message() {
		$r = $this->submit( $this->form( array( 'required' => true ) ), array( 'addr' => array() ) );
		$this->assertSame( 'This field is required.', $r['errors']['addr'] );
	}

	public function test_required_complete_ok_street2_not_needed() {
		$form = $this->form( array( 'required' => true, 'parts' => array( 'street', 'street2', 'city' ) ) );
		$r    = $this->submit( $form, array( 'addr' => array( 'street' => '1 Main', 'city' => 'Town' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'street' => '1 Main', 'city' => 'Town' ), $r['values']['addr'] );
	}

	public function test_required_with_only_street2_enabled_needs_something() {
		$form = $this->form( array( 'required' => true, 'parts' => array( 'street2' ) ) );
		$r    = $this->submit( $form, array( 'addr' => array() ) );
		$this->assertSame( 'This field is required.', $r['errors']['addr'] );
		$r = $this->submit( $form, array( 'addr' => array( 'street2' => 'Apt 2' ) ) );
		$this->assertTrue( $r['ok'] );
	}

	public function test_optional_all_empty_ok_and_stored_as_empty_string() {
		$r = $this->submit( $this->form(), array( 'addr' => array( 'street' => '', 'city' => ' ' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['values']['addr'] );
	}

	public function test_optional_partial_ok() {
		$r = $this->submit( $this->form(), array( 'addr' => array( 'city' => 'Town' ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'city' => 'Town' ), $r['values']['addr'] );
	}

	public function test_value_to_text_renders_joined() {
		$this->assertSame( '1 Main, Town', \FormTheory_Fields::value_to_text( array( 'street' => '1 Main', 'city' => 'Town' ) ) );
	}

	public function test_cond_rule_on_address_dropped() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'fields' => array(
				array( 'id' => 'addr', 'type' => 'address', 'label' => 'A' ),
				array( 'id' => 'name', 'type' => 'text', 'label' => 'N' ),
				array( 'id' => 'x', 'type' => 'text', 'label' => 'X', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'addr', 'op' => 'notempty' ), array( 'field' => 'name', 'op' => 'notempty' ) ) ) ),
				array( 'id' => 'y', 'type' => 'text', 'label' => 'Y', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'addr', 'op' => 'notempty' ) ) ) ),
			),
			'notifications' => array( array( 'to' => 'a@b.co', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'addr', 'op' => 'notempty' ) ) ) ) ),
			'webhooks'      => array( array( 'url' => 'https://example.com/h', 'cond' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'addr', 'op' => 'notempty' ) ) ) ) ),
		) );
		$this->assertCount( 1, $cfg['fields'][2]['cond']['rules'] );
		$this->assertSame( 'name', $cfg['fields'][2]['cond']['rules'][0]['field'] );
		$this->assertSame( array(), $cfg['fields'][3]['cond']['rules'] );
		$this->assertFalse( $cfg['fields'][3]['cond']['enabled'] );
		$this->assertSame( array(), $cfg['notifications'][0]['cond']['rules'] );
		$this->assertFalse( $cfg['notifications'][0]['cond']['enabled'] );
		$this->assertSame( array(), $cfg['webhooks'][0]['cond']['rules'] );
		$this->assertFalse( (bool) $cfg['webhooks'][0]['cond']['enabled'] );
		$this->assertContains( 'address', \FormTheory_Fields::NON_COND_SOURCE );
	}

	private function render( array $over, $value = '', $error = '' ) {
		$f = $this->one( $over + array( 'id' => 'f1', 'label' => 'Ship to' ) ) + array( 'placeholder' => '', 'help' => '', 'cond' => array( 'enabled' => false ) );
		if ( isset( $over['help'] ) ) {
			$f['help'] = $over['help'];
		}
		$m = new \ReflectionMethod( $this->front, 'render_field' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->front, 7, $f, $value, $error );
		return ob_get_clean();
	}

	public function test_render_fieldset_legend_and_labelled_inputs_with_autocomplete() {
		$html = $this->render( array( 'required' => true, 'parts' => array( 'street', 'street2', 'city', 'state', 'zip', 'country' ) ) );
		$this->assertStringContainsString( '<fieldset class="ft-address"', $html );
		$this->assertStringContainsString( '<legend>Ship to', $html );
		$this->assertStringContainsString( '(required)', $html );
		$this->assertStringNotContainsString( 'aria-required="true"><legend', $html );
		foreach ( array(
			'street'  => 'address-line1',
			'street2' => 'address-line2',
			'city'    => 'address-level2',
			'state'   => 'address-level1',
			'zip'     => 'postal-code',
			'country' => 'country-name',
		) as $part => $token ) {
			$this->assertStringContainsString( '<label for="ft-7-f1-' . $part . '">', $html );
			$this->assertMatchesRegularExpression( '/<input type="text" id="ft-7-f1-' . $part . '" name="ft\[f1\]\[' . $part . '\]"[^>]*autocomplete="' . $token . '"/', $html );
		}
		// street2 is never required; the others are.
		$this->assertMatchesRegularExpression( '/id="ft-7-f1-city"[^>]*aria-required="true"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="ft-7-f1-street2"[^>]*aria-required/', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-err"', $html );
	}

	public function test_render_error_state_on_fieldset_and_values_and_country_default() {
		$html = $this->render( array( 'help' => 'Where to?', 'country_default' => 'Canada', 'parts' => array( 'city', 'country' ) ), array( 'city' => 'A"B' ), 'Please complete the address.' );
		$this->assertMatchesRegularExpression( '/<fieldset class="ft-address"[^>]*aria-describedby="ft-7-f1-help ft-7-f1-err"[^>]*aria-invalid="true"/', $html );
		$this->assertStringContainsString( 'value="A&quot;B"', $html );
		$this->assertStringContainsString( 'value="Canada"', $html );
		$this->assertStringNotContainsString( 'ft-7-f1-street', $html );
	}

	public function test_render_country_default_not_applied_to_submitted_value() {
		$html = $this->render( array( 'country_default' => 'Canada', 'parts' => array( 'city', 'country' ) ), array( 'city' => 'X' ) );
		$this->assertStringContainsString( 'value="Canada"', $html );
		$html = $this->render( array( 'country_default' => 'Canada', 'parts' => array( 'city', 'country' ) ), array( 'country' => 'France' ) );
		$this->assertStringNotContainsString( 'Canada', $html );
	}
}
