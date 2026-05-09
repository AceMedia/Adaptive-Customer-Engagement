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
		$breakdown = array();

		if ( ! empty( $session['is_bot'] ) || ! empty( $session['ignored'] ) ) {
			$score -= 50;
			$breakdown[] = $this->breakdown_item( 'Filtered traffic', -50 );
		}

		switch ( (string) ( $session['company_confidence'] ?? 'unknown' ) ) {
			case 'confirmed':
				$score += 18;
				$breakdown[] = $this->breakdown_item( 'Confirmed company match', 18 );
				break;
			case 'likely':
				$score += 12;
				$breakdown[] = $this->breakdown_item( 'Likely company match', 12 );
				break;
			case 'weak':
				$score += 4;
				$breakdown[] = $this->breakdown_item( 'Weak company match', 4 );
				break;
			case 'ignore':
				$score -= 20;
				$breakdown[] = $this->breakdown_item( 'Low-quality network signal', -20 );
				break;
		}

		$event_points = min( 12, max( 0, ( (int) ( $session['event_count'] ?? 0 ) - 1 ) * 2 ) );
		if ( $event_points > 0 ) {
			$score += $event_points;
			$breakdown[] = $this->breakdown_item( 'Repeated engagement', $event_points );
		}

		$call_points = (int) ( $session['call_clicks'] ?? 0 ) * 20;
		if ( $call_points > 0 ) {
			$score += $call_points;
			$breakdown[] = $this->breakdown_item( 'Call intent', $call_points );
		}

		$form_points = (int) ( $session['form_count'] ?? 0 ) * 30;
		if ( $form_points > 0 ) {
			$score += $form_points;
			$breakdown[] = $this->breakdown_item( 'Form submission intent', $form_points );
		}

		$download_points = (int) ( $session['download_count'] ?? 0 ) * 15;
		if ( $download_points > 0 ) {
			$score += $download_points;
			$breakdown[] = $this->breakdown_item( 'Download intent', $download_points );
		}

		if ( ! empty( $session['visitor_uuid'] ) ) {
			$score += 5;
			$breakdown[] = $this->breakdown_item( 'Repeatable visitor signal', 5 );
		}

		if ( ! empty( $session['utm_source'] ) || ! empty( $session['utm_campaign'] ) ) {
			$score += 4;
			$breakdown[] = $this->breakdown_item( 'Campaign attribution present', 4 );
		}

		return array(
			'score'            => $score,
			'score_label'      => $this->score_label( $score ),
			'score_breakdown'  => $breakdown,
			'score_summary'    => $this->summary_from_breakdown( $breakdown ),
		);
	}

	/**
	 * Score a company summary.
	 *
	 * @param array<string, mixed> $company Company data.
	 * @return array<string, mixed>
	 */
	public function score_company( array $company ): array {
		$score     = 0;
		$breakdown = array();

		switch ( (string) ( $company['confidence'] ?? 'unknown' ) ) {
			case 'confirmed':
				$score += 20;
				$breakdown[] = $this->breakdown_item( 'Confirmed company match', 20 );
				break;
			case 'likely':
				$score += 14;
				$breakdown[] = $this->breakdown_item( 'Likely company match', 14 );
				break;
			case 'weak':
				$score += 5;
				$breakdown[] = $this->breakdown_item( 'Weak company match', 5 );
				break;
			case 'ignore':
				$score -= 20;
				$breakdown[] = $this->breakdown_item( 'Low-quality network signal', -20 );
				break;
		}

		$session_points = min( 18, (int) ( $company['total_sessions'] ?? 0 ) * 3 );
		if ( $session_points > 0 ) {
			$score += $session_points;
			$breakdown[] = $this->breakdown_item( 'Repeat visits', $session_points );
		}

		$event_points = min( 15, (int) floor( (int) ( $company['total_events'] ?? 0 ) / 2 ) );
		if ( $event_points > 0 ) {
			$score += $event_points;
			$breakdown[] = $this->breakdown_item( 'Activity depth', $event_points );
		}

		$call_points = min( 20, (int) ( $company['total_calls'] ?? 0 ) * 10 );
		if ( $call_points > 0 ) {
			$score += $call_points;
			$breakdown[] = $this->breakdown_item( 'Call activity', $call_points );
		}

		if ( ! empty( $company['domain'] ) ) {
			$score += 5;
			$breakdown[] = $this->breakdown_item( 'Known company domain', 5 );
		}

		if ( ! empty( $company['last_seen'] ) && strtotime( (string) $company['last_seen'] ) >= strtotime( '-7 days' ) ) {
			$score += 8;
			$breakdown[] = $this->breakdown_item( 'Recent activity', 8 );
		}

		return array(
			'priority_score'      => $score,
			'priority_label'      => $this->score_label( $score ),
			'priority_breakdown'  => $breakdown,
			'priority_summary'    => $this->summary_from_breakdown( $breakdown ),
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

	/**
	 * Create a breakdown item.
	 *
	 * @param string $label Label.
	 * @param int    $points Points.
	 * @return array<string, mixed>
	 */
	private function breakdown_item( string $label, int $points ): array {
		return array(
			'label'  => $label,
			'points' => $points,
		);
	}

	/**
	 * Build a compact summary from score breakdown items.
	 *
	 * @param array<int, array<string, mixed>> $breakdown Breakdown items.
	 * @return string
	 */
	private function summary_from_breakdown( array $breakdown ): string {
		$positive = array_values(
			array_filter(
				$breakdown,
				static function ( array $item ): bool {
					return (int) ( $item['points'] ?? 0 ) > 0;
				}
			)
		);

		if ( $positive ) {
			$labels = array_map(
				static function ( array $item ): string {
					return (string) $item['label'];
				},
				array_slice( $positive, 0, 3 )
			);

			return implode( ', ', $labels );
		}

		if ( ! empty( $breakdown[0]['label'] ) ) {
			return (string) $breakdown[0]['label'];
		}

		return 'No strong signals yet';
	}
}
