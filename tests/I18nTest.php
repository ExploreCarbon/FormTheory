<?php
namespace FormTheory\Tests;

class I18nTest extends \PHPUnit\Framework\TestCase {

	const FUNCS = array( '__', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_e', '_n', '_x', '_ex', 'esc_html_x', 'esc_attr_x' );

	/**
	 * Return the source of every i18n call in $code that lacks the 'formtheory' domain.
	 * Token based, so multiline calls and nested parentheses are handled.
	 */
	private function calls_missing_domain( $code ) {
		$tokens = token_get_all( $code );
		$n      = count( $tokens );
		$bad    = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( $t[1], self::FUNCS, true ) ) {
				continue;
			}
			$prev = $i - 1;
			while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_WHITESPACE ), true ) ) {
				$prev--;
			}
			if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( $j >= $n || '(' !== $tokens[ $j ] ) {
				continue;
			}
			$depth = 0;
			$src   = '';
			$found = false;
			for ( ; $j < $n; $j++ ) {
				$x   = $tokens[ $j ];
				$txt = is_array( $x ) ? $x[1] : $x;
				if ( '(' === $x ) {
					$depth++;
				} elseif ( ')' === $x ) {
					$depth--;
				}
				// Domain must be a top-level argument.
				if ( 1 === $depth && is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] && in_array( $x[1], array( "'formtheory'", '"formtheory"' ), true ) ) {
					$found = true;
				}
				$src .= $txt;
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( ! $found ) {
				$bad[] = $t[1] . $src;
			}
		}
		return $bad;
	}

	public function test_scanner_flags_bad_sample() {
		$bad = $this->calls_missing_domain( "<?php echo esc_html__( sprintf( 'a (b)', 1 ),\n 'other' ); echo __( 'ok', 'formtheory' ); echo __( 'nodomain' );" );
		$this->assertCount( 2, $bad );
		$good = $this->calls_missing_domain( "<?php echo esc_html__(\n 'x (y)',\n 'formtheory'\n ); echo _n( 'a', 'b', \$n, 'formtheory' );" );
		$this->assertSame( array(), $good );
	}

	public function test_all_i18n_calls_use_text_domain() {
		$root = dirname( __DIR__ );
		$bad  = array();
		$files = array_merge( glob( $root . '/includes/*.php' ), array( $root . '/formtheory.php' ) );
		foreach ( $files as $file ) {
			foreach ( $this->calls_missing_domain( file_get_contents( $file ) ) as $b ) {
				$bad[] = basename( $file ) . ': ' . $b;
			}
		}
		$this->assertSame( array(), $bad );
	}

	public function test_plugin_header_declares_domain_path() {
		$src = file_get_contents( dirname( __DIR__ ) . '/formtheory.php' );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Domain Path:\s*\/languages\s*$/m', $src );
		$this->assertMatchesRegularExpression( '/load_plugin_textdomain\(\s*\'formtheory\'/', $src );
	}

	public function test_pot_contains_sample_strings() {
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/formtheory.pot' );
		foreach ( array( 'Delete entry', 'No entries.', 'This field is required.' ) as $id ) {
			$this->assertStringContainsString( 'msgid "' . $id . '"', $pot );
		}
	}
}
