<?php
/**
 * Basic lead scoring.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

defined( 'ABSPATH' ) || exit;

final class LeadScorer {
	/**
	 * Score a session summary.
	 *
	 * @param array<string, mixed> $session Session data.
	 * @return array<string, mixed>
	 */
	public function score_session( array $session ): array {
		$score = 0;

		if ( ! empty( $session['is_bot'] ) || ! empty( $session['ignored'] ) ) {
			$score -= 50;
		}

		switch ( (string) ( $session['company_confidence'] ?? 'unknown' ) ) {
			case 'confirmed':
				$score += 15;
				break;
			case 'likely':
				$score += 10;
				break;
			case 'weak':
				$score += 3;
				break;
			case 'ignore':
				$score -= 20;
				break;
		}

		$score += min( 10, max( 0, ( (int) ( $session['event_count'] ?? 0 ) - 1 ) * 2 ) );
		$score += (int) ( $session['call_clicks'] ?? 0 ) * 20;
		$score += (int) ( $session['form_count'] ?? 0 ) * 30;
		$score += (int) ( $session['download_count'] ?? 0 ) * 15;

		if ( ! empty( $session['visitor_uuid'] ) ) {
			$score += 5;
		}

		return array(
			'score'       => $score,
			'score_label' => $this->score_label( $score ),
		);
	}

	/**
	 * Get the score label.
	 *
	 * @param int $score Score value.
	 * @return string
	 */
	private function score_label( int $score ): string {
		if ( $score >= 40 ) {
			return 'hot';
		}

		if ( $score >= 20 ) {
			return 'warm';
		}

		if ( $score > 0 ) {
			return 'low';
		}

		return 'noise';
	}
}
