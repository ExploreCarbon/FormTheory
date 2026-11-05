<?php
namespace FormTheory\Tests;

use PHPUnit\Framework\TestCase;

/** Release hygiene: version strings must agree across plugin header, readme.txt and README.md. */
class ReleaseTest extends TestCase {

	private function root() {
		return dirname( __DIR__ );
	}

	private function header_version() {
		$src = file_get_contents( $this->root() . '/formtheory.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $src, $m ), 'Plugin header has Version' );
		return $m[1];
	}

	public function test_readme_txt_stable_tag_matches_plugin_version() {
		$txt = file_get_contents( $this->root() . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/mi', $txt, $m ), 'readme.txt has Stable tag' );
		$this->assertSame( $this->header_version(), $m[1] );
	}

	public function test_readme_txt_has_required_headers_and_changelog() {
		$txt = file_get_contents( $this->root() . '/readme.txt' );
		foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'License', 'License URI' ) as $h ) {
			$this->assertSame( 1, preg_match( '/^' . preg_quote( $h, '/' ) . ':\s*\S+/m', $txt ), "Header $h" );
		}
		$this->assertStringContainsString( '= ' . $this->header_version() . ' =', $txt, 'Changelog entry for current version' );
	}

	public function test_readme_md_mentions_plugin_version() {
		$md = file_get_contents( $this->root() . '/README.md' );
		$this->assertStringContainsString( '**Version:** ' . $this->header_version(), $md );
	}
}
