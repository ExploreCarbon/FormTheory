<?php
defined( 'ABSPATH' ) || exit;

/**
 * Calculated-field formulas: a tiny arithmetic language evaluated with a
 * hand-written tokenizer and shunting-yard (never eval()).
 *
 * Grammar: unsigned decimals (12, 3.5, .5), {field:ID} with ID [a-z0-9_]+,
 * binary + - * /, unary + -, parentheses. Anything else is invalid.
 * Mirrored by evaluateFormula() in assets/front.js.
 */
class FormTheory_Calc {

	const MAX_LENGTH = 500;
	const MAX_DEPTH  = 50;

	/** Binary operator precedence; 'neg' is unary minus (right-assoc, binds tightest). */
	const PREC = array( '+' => 1, '-' => 1, '*' => 2, '/' => 2, 'neg' => 3 );

	/**
	 * Result of the formula, or null on syntax error / division by zero.
	 * Field values: numeric string => float, anything else (or missing) => 0.
	 */
	public static function evaluate( $formula, array $values ) {
		$rpn = self::compile( (string) $formula );
		if ( null === $rpn ) {
			return null;
		}
		$stack = array();
		foreach ( $rpn as $t ) {
			if ( 'num' === $t[0] ) {
				$stack[] = $t[1];
			} elseif ( 'ref' === $t[0] ) {
				$v       = $values[ $t[1] ] ?? '';
				$stack[] = is_string( $v ) || is_int( $v ) || is_float( $v ) ? ( is_numeric( $v ) ? (float) $v : 0.0 ) : 0.0;
			} elseif ( 'neg' === $t[1] ) {
				$stack[] = -array_pop( $stack );
			} else {
				$b = array_pop( $stack );
				$a = array_pop( $stack );
				switch ( $t[1] ) {
					case '+':
						$stack[] = $a + $b;
						break;
					case '-':
						$stack[] = $a - $b;
						break;
					case '*':
						$stack[] = $a * $b;
						break;
					default:
						if ( 0.0 === (float) $b ) {
							return null;
						}
						$stack[] = $a / $b;
				}
			}
		}
		$r = (float) array_pop( $stack );
		return is_finite( $r ) ? $r : null;
	}

	/** True when the formula parses under the grammar (used at save time). */
	public static function is_valid( $formula ) {
		return null !== self::compile( (string) $formula );
	}

	/** Field ids referenced by a formula, in order of first use. */
	public static function refs( $formula ) {
		preg_match_all( '/\{field:([a-z0-9_]+)\}/', (string) $formula, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Recompute every calc field in field order. A formula sees the other
	 * values as given plus calc results computed so far; a calc field that
	 * comes later (or does not exist) reads as 0, so cycles are impossible.
	 * Results are plain dot-decimal strings with exactly `decimals` places,
	 * or '' when the formula fails.
	 *
	 * @param array      $visible Optional field id => bool map. A calc field
	 *                            hidden by its condition is '' (reads as 0 in
	 *                            later formulas) and is not evaluated.
	 */
	public static function compute( array $fields, array $values, $visible = null ) {
		$env = $values;
		foreach ( $fields as $f ) {
			if ( 'calc' === $f['type'] ) {
				$env[ $f['id'] ] = '';
			}
		}
		foreach ( $fields as $f ) {
			if ( 'calc' !== $f['type'] ) {
				continue;
			}
			if ( is_array( $visible ) && empty( $visible[ $f['id'] ] ) ) {
				$values[ $f['id'] ] = '';
				continue;
			}
			$r                  = self::evaluate( $f['formula'] ?? '', $env );
			$env[ $f['id'] ]    = null === $r ? '' : self::format( $r, (int) ( $f['decimals'] ?? 2 ) );
			$values[ $f['id'] ] = $env[ $f['id'] ];
		}
		return $values;
	}

	/** Round half away from zero, fixed decimals, no "-0". */
	public static function format( $v, $decimals ) {
		$decimals = max( 0, min( 6, (int) $decimals ) );
		$v        = round( (float) $v, $decimals );
		if ( 0.0 === $v ) {
			$v = 0.0; // Normalise -0.
		}
		return number_format( $v, $decimals, '.', '' );
	}

	/**
	 * Tokenize and convert to RPN in one iterative pass. Returns null on any
	 * syntax error, over-long formula or nesting deeper than MAX_DEPTH.
	 */
	private static function compile( $s ) {
		$len = strlen( $s );
		if ( 0 === $len || $len > self::MAX_LENGTH ) {
			return null;
		}
		$out    = array();
		$ops    = array();
		$depth  = 0;
		$expect = true; // Expecting an operand (or prefix operator / "(").
		$i      = 0;
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c ) {
				$i++;
				continue;
			}
			if ( $expect ) {
				if ( preg_match( '/\G(?:\d+(?:\.\d+)?|\.\d+)/', $s, $m, 0, $i ) ) {
					$out[]  = array( 'num', (float) $m[0] );
					$i     += strlen( $m[0] );
					$expect = false;
				} elseif ( preg_match( '/\G\{field:([a-z0-9_]+)\}/', $s, $m, 0, $i ) ) {
					$out[]  = array( 'ref', $m[1] );
					$i     += strlen( $m[0] );
					$expect = false;
				} elseif ( '(' === $c ) {
					if ( ++$depth > self::MAX_DEPTH ) {
						return null;
					}
					$ops[] = '(';
					$i++;
				} elseif ( '-' === $c ) {
					$ops[] = 'neg';
					$i++;
				} elseif ( '+' === $c ) {
					$i++; // Unary plus is a no-op.
				} else {
					return null;
				}
				continue;
			}
			if ( ')' === $c ) {
				if ( $depth < 1 ) {
					return null;
				}
				while ( '(' !== end( $ops ) ) {
					$out[] = array( 'op', array_pop( $ops ) );
				}
				array_pop( $ops );
				$depth--;
				$i++;
				continue;
			}
			if ( ! isset( self::PREC[ $c ] ) ) {
				return null;
			}
			while ( $ops && '(' !== end( $ops ) && self::PREC[ end( $ops ) ] >= self::PREC[ $c ] ) {
				$out[] = array( 'op', array_pop( $ops ) );
			}
			$ops[]  = $c;
			$expect = true;
			$i++;
		}
		if ( $expect || $depth ) {
			return null;
		}
		while ( $ops ) {
			$out[] = array( 'op', array_pop( $ops ) );
		}
		return $out;
	}
}
