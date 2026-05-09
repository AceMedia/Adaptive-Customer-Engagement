<?php
/**
 * Event logging service.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\Tracking;

use ACE\AdaptiveCustomerEngagement\Database\Repositories\EventRepository;

defined( 'ABSPATH' ) || exit;

final class EventLogger {
	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private $events;

	/**
	 * Constructor.
	 *
	 * @param EventRepository $events Event repository.
	 */
	public function __construct( EventRepository $events ) {
		$this->events = $events;
	}

	/**
	 * Log an event.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $payload    Event payload.
	 * @return int
	 */
	public function log( int $session_id, array $payload ): int {
		return $this->events->insert(
			$session_id,
			array(
				'event_uuid'       => sanitize_text_field( (string) $payload['event_uuid'] ),
				'event_type'       => sanitize_key( (string) $payload['event_type'] ),
				'event_name'       => sanitize_text_field( (string) $payload['event_name'] ),
				'url'              => esc_url_raw( (string) $payload['url'] ),
				'path'             => sanitize_text_field( (string) $payload['path'] ),
				'page_title'       => sanitize_text_field( (string) $payload['page_title'] ),
				'post_id'          => absint( $payload['post_id'] ),
				'post_type'        => sanitize_key( (string) $payload['post_type'] ),
				'taxonomy_context' => sanitize_text_field( (string) $payload['taxonomy_context'] ),
				'product_area'     => sanitize_text_field( (string) $payload['product_area'] ),
				'brand_context'    => sanitize_text_field( (string) $payload['brand_context'] ),
				'number_id'        => absint( $payload['number_id'] ),
				'metadata'         => $payload['metadata'],
			)
		);
	}
}
