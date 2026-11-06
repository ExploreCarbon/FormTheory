<?php
defined( 'ABSPATH' ) || exit;

/**
 * Quiz-style scoring: per-choice points grouped by category, and routing to a
 * result page by the lowest-scoring category.
 */
class FormTheory_Score {

	/**
	 * Category scores as percentages (0-100) of best-possible points.
	 *
	 * Only radio/select fields with a category count. Hidden fields (per
	 * $visible, id => bool) add neither earned nor possible points. Categories
	 * whose best-possible total is 0 are omitted.
	 *
	 * @param array $fields  Form fields (sanitized config).
	 * @param array $values  Sanitized submitted values, id => value.
	 * @param array $visible Optional visibility map from FormTheory_Fields::visible_map().
	 * @return array<string,float> category => percentage, in field order.
	 */
	public static function percentages( array $fields, array $values, array $visible = array() ) {
		$totals = array();
		foreach ( $fields as $f ) {
			if ( ! in_array( $f['type'] ?? '', array( 'radio', 'select' ), true ) || '' === (string) ( $f['category'] ?? '' ) ) {
				continue;
			}
			if ( isset( $visible[ $f['id'] ] ) && ! $visible[ $f['id'] ] ) {
				continue;
			}
			$points = array_values( (array) ( $f['points'] ?? array() ) );
			$cat    = $f['category'];
			if ( ! isset( $totals[ $cat ] ) ) {
				$totals[ $cat ] = array( 'earned' => 0.0, 'max' => 0.0 );
			}
			$max = 0.0;
			foreach ( array_values( (array) $f['options'] ) as $i => $opt ) {
				$max = max( $max, (float) ( $points[ $i ] ?? 0 ) );
			}
			$idx = array_search( (string) ( $values[ $f['id'] ] ?? '' ), array_map( 'strval', array_values( (array) $f['options'] ) ), true );
			$totals[ $cat ]['max']    += $max;
			$totals[ $cat ]['earned'] += false === $idx ? 0.0 : (float) ( $points[ $idx ] ?? 0 );
		}
		$out = array();
		foreach ( $totals as $cat => $t ) {
			if ( $t['max'] > 0 ) {
				$out[ $cat ] = (float) ( $t['earned'] / $t['max'] * 100 );
			}
		}
		return $out;
	}

	/** Lowest category (first on a tie), or 'overall' when all are at or above the threshold. */
	public static function pick( array $percentages, $threshold ) {
		$lowest = 'overall';
		$low    = null;
		foreach ( $percentages as $cat => $pct ) {
			if ( null === $low || $pct < $low ) {
				$low    = $pct;
				$lowest = $cat;
			}
		}
		return null === $low || $low >= $threshold ? 'overall' : (string) $lowest;
	}

	/** Permalink of a published page, or null. */
	private static function page_url( $id ) {
		$id = (int) $id;
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : null;
	}

	/**
	 * Where to send the visitor, or null (show the message instead).
	 *
	 * @param array $form    Form row.
	 * @param array $values  Sanitized values, id => value.
	 * @param array $visible Optional visibility map.
	 */
	/** Score outcome: the lowest category key, "overall" when all pass, or null when scoring is off. */
	public static function outcome( array $form, array $values, array $visible = array() ) {
		$cfg     = $form['config'];
		$scoring = $cfg['scoring'] ?? array();
		if ( empty( $scoring['enabled'] ) ) {
			return null;
		}
		$pct = self::percentages( $cfg['fields'] ?? array(), $values, $visible );
		return self::pick( $pct, (int) ( $scoring['threshold'] ?? 80 ) );
	}

	/** Result page URL for the outcome, or null when scoring is off or no page is published. */
	public static function target( array $form, array $values, array $visible = array() ) {
		$cat = self::outcome( $form, $values, $visible );
		if ( null === $cat ) {
			return null;
		}
		$scoring = $form['config']['scoring'];
		$page    = 'overall' !== $cat ? self::page_url( $scoring['pages'][ $cat ] ?? 0 ) : null;
		return $page ?? self::page_url( $scoring['overall_page'] ?? 0 );
	}
}
