<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

/** Block editor integration: picker data, editor script registration, inert preview. */
class BlockTest extends TestCase {

	private $front;

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'FORMTHEORY_URL' ) ) {
			define( 'FORMTHEORY_URL', 'http://example.test/wp-content/plugins/formtheory/' );
		}
		if ( ! defined( 'FORMTHEORY_VERSION' ) ) {
			define( 'FORMTHEORY_VERSION', '1.3.0' );
		}
		Functions\stubs( array(
			'esc_attr'      => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'esc_html__'    => function ( $s ) {
				return $s;
			},
			'esc_attr__'    => function ( $s ) {
				return $s;
			},
			'esc_textarea'  => function ( $s ) {
				return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
			},
			'checked'       => '',
			'selected'      => '',
			'wp_hash'       => function ( $s ) {
				return md5( $s );
			},
			'wp_nonce_field' => function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="NONCE123">';
			},
			'wp_style_is'   => false,
			'add_shortcode' => null,
			'add_action'    => null,
		) );
		$this->front = new \FormTheory_Frontend();
	}

	private function db( array $rows ) {
		global $wpdb;
		$wpdb         = \Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
	}

	public function test_block_forms_has_empty_first_option() {
		$this->db( array( array( 'id' => '3', 'title' => 'Contact', 'updated_at' => 'x' ) ) );
		$forms = \FormTheory_Frontend::block_forms();
		$this->assertSame( array( 'value' => 0, 'label' => '— Select a form —' ), $forms[0] );
		$this->assertSame( array( 'value' => 3, 'label' => 'Contact' ), $forms[1] );
	}

	public function test_block_forms_with_no_forms_still_has_empty_option() {
		$this->db( array() );
		$this->assertCount( 1, \FormTheory_Frontend::block_forms() );
	}

	public function test_block_forms_lists_titles_escaped() {
		$this->db( array( array( 'id' => '4', 'title' => '</script><script>alert(1)</script>&', 'updated_at' => 'x' ) ) );
		$sent = null;
		Functions\expect( 'wp_add_inline_script' )->once()->andReturnUsing( function ( $h, $code, $pos ) use ( &$sent ) {
			$sent = array( $h, $code, $pos );
			return true;
		} );
		$this->front->attach_editor_data();
		$this->assertSame( 'formtheory-block', $sent[0] );
		$this->assertSame( 'before', $sent[2] );
		$this->assertStringStartsWith( 'window.FormTheoryBlock = ', $sent[1] );
		$this->assertStringNotContainsString( '</script>', $sent[1] );
		$this->assertStringNotContainsString( '<', $sent[1] );
		$this->assertStringNotContainsString( '&', $sent[1] );
		// Decodes back to the exact title.
		$json = substr( $sent[1], strlen( 'window.FormTheoryBlock = ' ), -1 );
		$data = json_decode( $json, true );
		$this->assertSame( '</script><script>alert(1)</script>&', $data['forms'][1]['label'] );
		$this->assertArrayHasKey( 'missing', $data['i18n'] );
	}

	public function test_register_block_declares_editor_script() {
		$captured = null;
		$reg      = null;
		Functions\when( 'register_block_type' )->alias( function ( $name, $args ) use ( &$captured ) {
			$captured = array( $name, $args );
		} );
		$style = null;
		Functions\expect( 'wp_register_style' )->once()->andReturnUsing( function () use ( &$style ) {
			$style = func_get_args();
			return true;
		} );
		Functions\expect( 'wp_register_script' )->once()->andReturnUsing( function ( $h, $src, $deps, $ver, $foot ) use ( &$reg ) {
			$reg = func_get_args();
			return true;
		} );
		$this->front->register_block();
		$this->assertSame( 'formtheory/form', $captured[0] );
		$this->assertSame( 'formtheory-block', $captured[1]['editor_script'] );
		$this->assertSame( 'widgets', $captured[1]['category'] );
		$this->assertSame( 2, $captured[1]['api_version'] );
		$this->assertSame( 'formtheory', $captured[1]['editor_style'] );
		$this->assertSame( 'feedback', $captured[1]['icon'] );
		$this->assertSame( 'FormTheory Form', $captured[1]['title'] );
		$this->assertSame( 'number', $captured[1]['attributes']['id']['type'] );
		$this->assertSame( 0, $captured[1]['attributes']['id']['default'] );
		$this->assertIsCallable( $captured[1]['render_callback'] );
		$this->assertSame( 'formtheory-block', $reg[0] );
		$this->assertStringEndsWith( 'assets/block.js', $reg[1] );
		foreach ( array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ) as $dep ) {
			$this->assertContains( $dep, $reg[2] );
		}
		$this->assertNotContains( 'wp-i18n', $reg[2] );
		$this->assertSame( $captured[1]['editor_style'], $style[0] );
		$this->assertStringEndsWith( 'assets/front.css', $style[1] );
		$this->assertTrue( $reg[4] );
	}

	public function test_forms_are_not_queried_when_registering() {
		global $wpdb;
		$wpdb = \Mockery::mock();
		$wpdb->shouldNotReceive( 'get_results' );
		Functions\when( 'register_block_type' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\expect( 'wp_add_inline_script' )->never();
		$this->front->register_block();
		$this->addToAssertionCount( 1 );
	}

	public function test_editor_data_hooked_only_to_block_editor_assets() {
		$hooks = array();
		Functions\when( 'add_action' )->alias( function ( $hook, $cb ) use ( &$hooks ) {
			$hooks[] = array( $hook, is_array( $cb ) ? $cb[1] : '' );
		} );
		new \FormTheory_Frontend();
		$this->assertContains( array( 'enqueue_block_editor_assets', 'attach_editor_data' ), $hooks );
		$this->assertNotContains( array( 'wp_enqueue_scripts', 'attach_editor_data' ), $hooks );
	}

	private function form() {
		$field = function ( $id, $type, $label, array $over = array() ) {
			return array_merge( array(
				'id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'help' => '', 'placeholder' => '',
				'options' => array(), 'cond' => array( 'enabled' => false ), 'allowed' => 'jpg', 'max_mb' => 5, 'text' => '', 'content' => '', 'default' => '',
			), $over );
		};
		return array(
			'id'     => 9,
			'title'  => 'T',
			'config' => array(
				'fields'       => array(
					$field( 'a', 'text', 'Name' ),
					$field( 'b', 'select', 'Pick', array( 'options' => array( 'x' ) ) ),
					$field( 'c', 'textarea', 'Msg' ),
				),
				'submit_label' => 'Send',
				'ajax'         => true,
				'save_resume'  => true,
			),
		);
	}

	public function test_preview_is_inert_disabled_and_exposes_no_nonce() {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		$html = $this->front->render_form( $this->form(), true );
		$this->assertStringNotContainsString( 'NONCE123', $html );
		$this->assertStringNotContainsString( '_ft_nonce', $html );
		$this->assertStringNotContainsString( 'ft_ts', $html );
		$this->assertMatchesRegularExpression( '/<form inert\b/', $html );
		preg_match_all( '/<(input|select|textarea|button)\b[^>]*>/', $html, $m );
		$this->assertNotEmpty( $m[0] );
		foreach ( $m[0] as $tag ) {
			$this->assertStringContainsString( ' disabled', $tag, $tag );
		}
	}

	public function test_front_end_render_unchanged_has_nonce_and_enqueues() {
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'formtheory' );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'formtheory' );
		$html = $this->front->render_form( $this->form() );
		$this->assertStringContainsString( 'name="_ft_nonce" value="NONCE123"', $html );
		$this->assertStringContainsString( 'name="ft_ts"', $html );
		$this->assertStringNotContainsString( 'inert', $html );
		$this->assertStringNotContainsString( ' disabled', $html );
	}

	private function req( $route, $context ) {
		$r = \Mockery::mock();
		$r->shouldReceive( 'get_route' )->andReturn( $route );
		$r->shouldReceive( 'get_param' )->with( 'context' )->andReturn( $context );
		return $r;
	}

	public function test_preview_only_for_block_renderer_route_with_edit_context() {
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
		$this->front->flag_preview( null, null, $this->req( '/wp/v2/block-renderer/formtheory/form', 'edit' ) );
		$this->assertTrue( \FormTheory_Frontend::is_editor_context() );
		$this->front->clear_preview();
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
	}

	public function test_posts_rest_request_is_not_preview() {
		$this->front->flag_preview( null, null, $this->req( '/wp/v2/posts', 'view' ) );
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
		$this->front->flag_preview( null, null, $this->req( '/wp/v2/posts', 'edit' ) );
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
	}

	public function test_block_renderer_without_edit_context_or_other_block_is_not_preview() {
		$this->front->flag_preview( null, null, $this->req( '/wp/v2/block-renderer/formtheory/form', 'view' ) );
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
		$this->front->flag_preview( null, null, $this->req( '/wp/v2/block-renderer/core/archives', 'edit' ) );
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
	}

	public function test_admin_ajax_and_front_end_are_not_preview() {
		Functions\when( 'is_admin' )->justReturn( true ); // admin-ajax.php reports true.
		$this->assertFalse( \FormTheory_Frontend::is_editor_context() );
	}

	protected function tearDown(): void {
		$this->front->clear_preview();
		parent::tearDown();
	}
}
