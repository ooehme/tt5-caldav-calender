<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_REST {
	public function __construct(
		private TT5_CalDAV_Repository $repository,
		private TT5_CalDAV_Client $client
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'tt5-caldav/v1',
			'/calendars',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'calendars' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			'tt5-caldav/v1',
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'events' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'calendar_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'days'        => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 730 ),
					'offset'      => array( 'type' => 'integer', 'default' => 0, 'minimum' => -365, 'maximum' => 365 ),
					'limit'       => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
					'refresh'     => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	public function calendars(): WP_REST_Response {
		return rest_ensure_response( $this->repository->choices() );
	}

	public function events( WP_REST_Request $request ) {
		$timezone = wp_timezone();
		$start    = ( new DateTimeImmutable( 'today', $timezone ) )->modify( sprintf( '%+d days', (int) $request['offset'] ) );
		$end      = $start->modify( '+' . (int) $request['days'] . ' days' );
		$refresh  = (bool) $request['refresh'] && current_user_can( 'manage_options' );
		$events   = $this->client->events( (string) $request['calendar_id'], $start, $end, (int) $request['limit'], $refresh );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		return rest_ensure_response(
			array_map(
				function ( array $event ): array {
					$timezone = $this->event_timezone( $event );
					$end_display = (int) $event['end'];
					if ( ! empty( $event['all_day'] ) ) {
						$end_display = ( new DateTimeImmutable( '@' . (int) $event['end'] ) )->setTimezone( $timezone )->modify( '-1 day' )->getTimestamp();
					}
					return array(
						'uid'         => (string) $event['uid'],
						'title'       => (string) $event['title'],
						'description' => (string) $event['description'],
						'location'    => (string) $event['location'],
						'url'         => (string) $event['url'],
						'allDay'      => (bool) $event['all_day'],
						'startTimestamp' => (int) $event['start'],
						'endTimestamp'   => (int) $event['end'],
						'endDisplayTimestamp' => max( (int) $event['start'], $end_display ),
						'timezone'       => $timezone->getName(),
						'startIso'    => wp_date( DATE_ATOM, (int) $event['start'], $timezone ),
						'endIso'      => wp_date( DATE_ATOM, (int) $event['end'], $timezone ),
						'date'        => wp_date( get_option( 'date_format' ), (int) $event['start'], $timezone ),
						'endDate'     => wp_date( get_option( 'date_format' ), max( (int) $event['start'], $end_display ), $timezone ),
						'time'        => wp_date( get_option( 'time_format' ), (int) $event['start'], $timezone ),
						'endTime'     => wp_date( get_option( 'time_format' ), (int) $event['end'], $timezone ),
					);
				},
				$events
			)
		);
	}

	/**
	 * @param array<string,mixed> $event Event.
	 */
	private function event_timezone( array $event ): DateTimeZone {
		try {
			return new DateTimeZone( (string) $event['timezone'] );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}
}
