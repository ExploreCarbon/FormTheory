<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

/** Accessibility assertions on server-rendered field markup. */
class RenderTest extends TestCase {

	private $front;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs( array(
			'esc_attr'         => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'esc_html__'       => function ( $s ) {
				return $s;
			},
			'esc_textarea'     => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'checked'          => function ( $a, $b = true, $echo = true ) {
				return (string) $a === (string) $b ? ' checked="checked"' : '';
			},
			'selected'         => function ( $a, $b = true, $echo = true ) {
				return (string) $a === (string) $b ? ' selected="selected"' : '';
			},
			'add_shortcode'    => null,
			'add_action'       => null,
		) );
		$this->front = new \FormTheory_Frontend();
	}

	private function render( array $over, $value = '', $error = '' ) {
		$f = array_merge( array(
			'id' => 'f1', 'type' => 'text', 'label' => 'Name', 'required' => false, 'help' => '', 'placeholder' => '',
			'options' => array(), 'cond' => array( 'enabled' => false ), 'allowed' => 'jpg,pdf', 'max_mb' => 5, 'text' => '', 'content' => '', 'default' => '',
		), $over );
		$m = new \ReflectionMethod( $this->front, 'render_field' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->front, 7, $f, $value, $error );
		return ob_get_clean();
	}

	public function test_calc_output_is_labelled_and_escaped() {
		$html = $this->render( array( 'type' => 'calc', 'label' => 'Total', 'formula' => '{field:qty}*2', 'decimals' => 2, 'prefix' => '<b>$', 'suffix' => '"x' ), '4.00' );
		$this->assertStringContainsString( '<label for="ft-7-f1">Total</label>', $html );
		$this->assertStringContainsString( '<output id="ft-7-f1"', $html );
		$this->assertStringContainsString( 'for="ft-7-qty"', $html );
		$this->assertStringContainsString( 'aria-live="off"', $html );
		$this->assertStringContainsString( '&lt;b&gt;$4.00&quot;x</output>', $html );
		$this->assertStringContainsString( 'data-ft-prefix="&lt;b&gt;$"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_html_and_hidden_fields_close_their_wrapper() {
		foreach ( array( 'html', 'hidden' ) as $type ) {
			$html = $this->render( array( 'type' => $type, 'content' => '<h1>Hi</h1>' ) );
			$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), $type . ' leaves a <div> unclosed' );
		}
	}

	public function test_label_is_associated_with_input() {
		$html = $this->render( array() );
		$this->assertStringContainsString( '<label for="ft-7-f1">Name</label>', $html );
		$this->assertStringContainsString( 'id="ft-7-f1"', $html );
	}

	public function test_error_has_aria_describedby() {
		$html = $this->render( array( 'help' => 'Hint' ), '', 'Bad value' );
		$this->assertStringContainsString( 'aria-describedby="ft-7-f1-help ft-7-f1-err"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-err">Bad value</p>', $html );
	}

	public function test_no_error_is_hidden_and_not_invalid() {
		$html = $this->render( array() );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-err" hidden>', $html );
	}

	public function test_required_text_input_is_indicated() {
		$html = $this->render( array( 'required' => true ) );
		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( '<span class="ft-req" aria-hidden="true">*</span>', $html );
	}

	public function test_radio_group_uses_fieldset_legend_and_radiogroup_role() {
		$html = $this->render( array( 'type' => 'radio', 'label' => 'Pick', 'required' => true, 'options' => array( 'A', 'B' ) ), '' );
		$this->assertStringContainsString( '<fieldset role="radiogroup" aria-required="true"><legend>Pick', $html );
		$this->assertStringContainsString( '<label for="ft-7-f1-0">A</label>', $html );
	}

	public function test_checkbox_group_has_no_unsupported_aria_required_but_hidden_required_text() {
		$html = $this->render( array( 'type' => 'checkbox', 'label' => 'Pick', 'required' => true, 'options' => array( 'A', 'B' ) ), array() );
		$this->assertStringContainsString( '<fieldset><legend>Pick', $html );
		$this->assertStringNotContainsString( 'fieldset aria-required', $html );
		$this->assertStringContainsString( '<span class="ft-sr"> (required)</span>', $html );
		$this->assertStringContainsString( 'name="ft[f1][]"', $html );
	}

	public function test_consent_required_is_on_the_checkbox() {
		$html = $this->render( array( 'type' => 'consent', 'label' => 'Agree', 'required' => true, 'text' => 'I agree' ) );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="ft-7-f1"[^>]*aria-required="true"/', $html );
		$this->assertStringNotContainsString( 'fieldset aria-required', $html );
		$this->assertStringContainsString( '<span class="ft-sr"> (required)</span>', $html );
	}

	public function test_file_hint_is_described_by() {
		$html = $this->render( array( 'type' => 'file', 'label' => 'Upload' ) );
		$this->assertStringContainsString( 'aria-describedby="ft-7-f1-hint"', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-hint"', $html );
	}

	public function test_email_and_phone_have_autocomplete() {
		$this->assertStringContainsString( 'autocomplete="email"', $this->render( array( 'type' => 'email' ) ) );
		$this->assertStringContainsString( 'autocomplete="tel"', $this->render( array( 'type' => 'phone' ) ) );
	}

	public function test_consent_error_state_is_on_the_checkbox_not_the_fieldset() {
		$html = $this->render( array( 'type' => 'consent', 'label' => 'Agree', 'required' => true, 'help' => 'Hint', 'text' => 'I agree' ), '', 'Required' );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="ft-7-f1"[^>]*aria-describedby="ft-7-f1-help ft-7-f1-err"[^>]*aria-invalid="true"/', $html );
		$this->assertStringContainsString( '<fieldset><legend>', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-err">Required</p>', $html );
	}

	public function test_checkbox_group_error_state_is_on_the_fieldset() {
		$html = $this->render( array( 'type' => 'checkbox', 'label' => 'Pick', 'required' => true, 'options' => array( 'A' ) ), array(), 'Required' );
		$this->assertStringContainsString( '<fieldset aria-describedby="ft-7-f1-err" aria-invalid="true"><legend>', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox"[^>]*aria-invalid/', $html );
	}

	public function test_rating_renders_radiogroup_of_real_radios_with_accessible_labels() {
		$html = $this->render( array( 'type' => 'rating', 'label' => 'Score', 'max' => 4, 'required' => true ), '2', 'Bad' );
		$this->assertStringContainsString( '<fieldset class="ft-rating" role="radiogroup"', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( '<legend>Score', $html );
		$this->assertSame( 4, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( 'name="ft[f1]"', $html );
		$this->assertStringContainsString( 'value="4"', $html );
		$this->assertStringNotContainsString( 'value="5"', $html );
		$this->assertStringContainsString( '<span class="ft-sr">3 of 4</span>', $html );
		$this->assertStringContainsString( 'id="ft-7-f1-2" name="ft[f1]" value="2" checked="checked"', $html );
		$this->assertSame( 4, substr_count( $html, '<label for="ft-7-f1-' ) );
	}

	public function test_autoadvance_attribute_only_when_enabled() {
		$this->assertSame( ' data-ft-autoadvance', \FormTheory_Frontend::autoadvance_attr( array( 'auto_advance' => true ) ) );
		$this->assertSame( '', \FormTheory_Frontend::autoadvance_attr( array( 'auto_advance' => false ) ) );
		$this->assertSame( '', \FormTheory_Frontend::autoadvance_attr( array() ) );
	}
}
