<?php
namespace FormTheory\Tests;

class AssetsTest extends TestCase {

	public function test_front_css_rating_glyph_escapes_intact() {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/front.css' );
		$this->assertStringContainsString( '\2606', $css );
		$this->assertStringContainsString( '\2605', $css );
		$this->assertStringNotContainsString( "\u{00B0}", $css );
	}

	public function test_front_js_set_error_tolerates_wraps_without_error_element() {
		// html/hidden fields render no .ft-error; page validation still visits them.
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/front.js' );
		$this->assertMatchesRegularExpression( "/var err = wrap\.querySelector\('\.ft-error'\);\s*if \(!err\) return;/", $js );
	}

	public function test_front_js_reports_answers_and_completion_without_free_text() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/front.js' );
		$this->assertStringContainsString( "track(form, 'answer'", $js );
		$this->assertStringContainsString( "track(form, 'complete'", $js );
		$this->assertStringContainsString( "'formtheory:' + name", $js );
		$this->assertStringContainsString( "window.dataLayer", $js );
		$this->assertStringContainsString( "res.score_result", $js );
		// Only choice fields are reported: free text (names, emails) must never reach analytics.
		$this->assertMatchesRegularExpression( "/TRACKED_TYPES\s*=\s*\['radio',\s*'select',\s*'checkbox',\s*'rating'\]/", $js );
	}

	public function test_scoring_builder_strings_are_registered_for_translation() {
		$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
		foreach ( array( 'points', 'score_category', 'redirect_by_score', 'score_threshold', 'score_page_for', 'score_overall_page', 'auto_advance', 'store_entries' ) as $key ) {
			$this->assertStringContainsString( "'" . $key . "'", $admin, $key );
		}
	}
}
