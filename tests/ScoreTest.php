<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class ScoreTest extends TestCase {

	private function radio( $id, $cat, array $opts, array $points, array $extra = array() ) {
		return $extra + array(
			'id'       => $id,
			'type'     => 'radio',
			'label'    => $id,
			'options'  => $opts,
			'points'   => $points,
			'category' => $cat,
			'cond'     => array( 'enabled' => false, 'action' => 'show', 'logic' => 'all', 'rules' => array() ),
		);
	}

	public function test_percentages_sum_earned_over_max_per_category() {
		$fields = array(
			$this->radio( 'q1', 'debt', array( 'a', 'b' ), array( 0, 10 ) ),
			$this->radio( 'q2', 'debt', array( 'a', 'b' ), array( 0, 10 ) ),
			$this->radio( 'q3', 'budget', array( 'a', 'b' ), array( 0, 5 ) ),
		);
		$pct = \FormTheory_Score::percentages( $fields, array( 'q1' => 'b', 'q2' => 'a', 'q3' => 'b' ) );
		$this->assertSame( array( 'debt' => 50.0, 'budget' => 100.0 ), $pct );
	}

	public function test_hidden_fields_excluded_from_earned_and_max() {
		$fields = array(
			$this->radio( 'q1', 'debt', array( 'a', 'b' ), array( 0, 10 ) ),
			$this->radio( 'q2', 'debt', array( 'a', 'b' ), array( 0, 10 ) ),
		);
		$pct = \FormTheory_Score::percentages( $fields, array( 'q1' => 'b', 'q2' => 'a' ), array( 'q1' => true, 'q2' => false ) );
		$this->assertSame( array( 'debt' => 100.0 ), $pct );
	}

	public function test_category_with_zero_max_is_skipped() {
		$fields = array( $this->radio( 'q1', 'debt', array( 'a' ), array( 0 ) ) );
		$this->assertSame( array(), \FormTheory_Score::percentages( $fields, array( 'q1' => 'a' ) ) );
	}

	public function test_unknown_option_scores_zero() {
		$fields = array( $this->radio( 'q1', 'debt', array( 'a', 'b' ), array( 0, 10 ) ) );
		$this->assertSame( array( 'debt' => 0.0 ), \FormTheory_Score::percentages( $fields, array( 'q1' => 'tampered' ) ) );
	}

	public function test_unscored_and_non_option_fields_ignored() {
		$fields = array(
			$this->radio( 'q1', '', array( 'a' ), array( 5 ) ),
			array( 'id' => 't', 'type' => 'text', 'category' => 'debt', 'points' => array( 5 ), 'options' => array( 'x' ) ),
		);
		$this->assertSame( array(), \FormTheory_Score::percentages( $fields, array( 'q1' => 'a', 't' => 'x' ) ) );
	}

	public function test_pick_lowest_wins_first_on_tie() {
		$this->assertSame( 'a', \FormTheory_Score::pick( array( 'a' => 40.0, 'b' => 40.0, 'c' => 90.0 ), 80 ) );
		$this->assertSame( 'b', \FormTheory_Score::pick( array( 'a' => 70.0, 'b' => 10.0 ), 80 ) );
	}

	public function test_pick_all_at_threshold_returns_overall() {
		$this->assertSame( 'overall', \FormTheory_Score::pick( array( 'a' => 80.0, 'b' => 100.0 ), 80 ) );
		$this->assertSame( 'a', \FormTheory_Score::pick( array( 'a' => 79.9, 'b' => 100.0 ), 80 ) );
		$this->assertSame( 'overall', \FormTheory_Score::pick( array(), 80 ) );
	}

	private function form( array $scoring, array $fields ) {
		return array(
			'id'     => 1,
			'config' => array(
				'confirmation_type' => 'score',
				'fields'            => $fields,
				'scoring'           => $scoring + array( 'enabled' => true, 'threshold' => 80, 'overall_page' => 0, 'pages' => array() ),
			),
		);
	}

	public function test_target_falls_back_to_overall_page_then_null() {
		Functions\when( 'get_post_status' )->alias( function ( $id ) {
			return in_array( $id, array( 10, 30 ), true ) ? 'publish' : 'draft';
		} );
		Functions\when( 'get_permalink' )->alias( function ( $id ) {
			return 'https://x.test/p' . $id . '/';
		} );
		$fields = array( $this->radio( 'q1', 'debt', array( 'a', 'b' ), array( 0, 10 ) ) );
		$values = array( 'q1' => 'a' );

		$mapped = $this->form( array( 'pages' => array( 'debt' => 10 ), 'overall_page' => 30 ), $fields );
		$this->assertSame( 'https://x.test/p10/', \FormTheory_Score::target( $mapped, $values ) );

		$unpub = $this->form( array( 'pages' => array( 'debt' => 20 ), 'overall_page' => 30 ), $fields );
		$this->assertSame( 'https://x.test/p30/', \FormTheory_Score::target( $unpub, $values ) );

		$none = $this->form( array( 'pages' => array( 'debt' => 20 ), 'overall_page' => 40 ), $fields );
		$this->assertNull( \FormTheory_Score::target( $none, $values ) );

		$high = $this->form( array( 'pages' => array( 'debt' => 10 ), 'overall_page' => 30 ), $fields );
		$this->assertSame( 'https://x.test/p30/', \FormTheory_Score::target( $high, array( 'q1' => 'b' ) ) );
	}

	public function test_outcome_names_the_category_or_overall_and_is_null_when_disabled() {
		$fields = array( $this->radio( 'q1', 'debt', array( 'a', 'b' ), array( 0, 10 ) ) );
		$form   = $this->form( array(), $fields );
		$this->assertSame( 'debt', \FormTheory_Score::outcome( $form, array( 'q1' => 'a' ) ) );
		$this->assertSame( 'overall', \FormTheory_Score::outcome( $form, array( 'q1' => 'b' ) ) );
		$this->assertNull( \FormTheory_Score::outcome( $this->form( array( 'enabled' => false ), $fields ), array() ) );
	}

	public function test_target_null_when_scoring_disabled() {
		$f = $this->form( array( 'enabled' => false ), array() );
		$this->assertNull( \FormTheory_Score::target( $f, array() ) );
	}
}
