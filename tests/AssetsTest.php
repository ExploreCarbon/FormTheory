<?php
namespace FormTheory\Tests;

class AssetsTest extends TestCase {

	public function test_front_css_rating_glyph_escapes_intact() {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/front.css' );
		$this->assertStringContainsString( '\2606', $css );
		$this->assertStringContainsString( '\2605', $css );
		$this->assertStringNotContainsString( "\u{00B0}", $css );
	}

	public function test_scoring_builder_strings_are_registered_for_translation() {
		$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
		foreach ( array( 'points', 'score_category', 'redirect_by_score', 'score_threshold', 'score_page_for', 'score_overall_page', 'auto_advance', 'store_entries' ) as $key ) {
			$this->assertStringContainsString( "'" . $key . "'", $admin, $key );
		}
	}
}
