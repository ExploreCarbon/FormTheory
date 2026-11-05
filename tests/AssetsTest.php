<?php
namespace FormTheory\Tests;

class AssetsTest extends TestCase {

	public function test_front_css_rating_glyph_escapes_intact() {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/front.css' );
		$this->assertStringContainsString( '\2606', $css );
		$this->assertStringContainsString( '\2605', $css );
		$this->assertStringNotContainsString( "\u{00B0}", $css );
	}
}
