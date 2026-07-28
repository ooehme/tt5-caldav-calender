<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates cached calendar queries and turns CalDAV responses into events.
 */
final class TT5_CalDAV_Client {
	private TT5_CalDAV_HTTP_Client $http;
	private TT5_CalDAV_WebDAV_Parser $webdav;
	private TT5_CalDAV_Discovery $discovery;

	public function __construct(
		private TT5_CalDAV_Repository $repository,
		private TT5_CalDAV_ICal_Parser $parser,
		?TT5_CalDAV_HTTP_Client $http = null,
		?TT5_CalDAV_WebDAV_Parser $webdav = null,
		?TT5_CalDAV_Discovery $discovery = null
	) {
		$this->http      = $http ?? new TT5_CalDAV_HTTP_Client();
		$this->webdav    = $webdav ?? new TT5_CalDAV_WebDAV_Parser( $this->http );
		$this->discovery = $discovery ?? new TT5_CalDAV_Discovery( $this->http, $this->webdav );
	}

	/**
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function events( string $calendar_id, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 20, bool $force = false ) {
		$calendar = $this->repository->get( $calendar_id );
		if ( null === $calendar ) {
			return new WP_Error( 'calendar_not_found', __( 'Der ausgewählte CalDAV-Kalender existiert nicht.', 'tt5-caldav-calendar' ) );
		}

		$limit          = min( 100, max( 1, $limit ) );
		$offset_minutes = $this->time_offset_minutes( $calendar );
		list( $query_start, $query_end ) = $this->query_range( $start, $end, $offset_minutes );
		$cache_key = 'tt5cd_' . md5( $calendar_id . '|' . $start->format( 'c' ) . '|' . $end->format( 'c' ) . '|' . $limit . '|' . $offset_minutes );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = $this->calendar_query( $calendar, $query_start, $query_end, true );
		if ( is_wp_error( $response ) && in_array( $response->get_error_code(), array( 'caldav_http_400', 'caldav_http_403', 'caldav_http_409', 'caldav_http_422', 'caldav_http_501' ), true ) ) {
			$response = $this->calendar_query( $calendar, $query_start, $query_end, false );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$timezone = $this->timezone( (string) ( $calendar['timezone'] ?? wp_timezone_string() ) );
		$events   = array();
		foreach ( $this->webdav->calendar_data_nodes( $response ) as $ics ) {
			$events = array_merge( $events, $this->parser->parse( $ics, $timezone, $query_start, $query_end ) );
		}
		$events = $this->apply_time_offset( $events, $offset_minutes, $start, $end );

		$deduped = array();
		foreach ( $events as $event ) {
			$key             = (string) $event['uid'] . '|' . (string) $event['start'];
			$deduped[ $key ] = $event;
		}
		$events = array_values( $deduped );

		usort(
			$events,
			static function ( array $a, array $b ): int {
				$start_compare = (int) $a['start'] <=> (int) $b['start'];
				return 0 !== $start_compare ? $start_compare : strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);
		$events = array_slice( $events, 0, $limit );

		$ttl = max( 60, absint( $calendar['cache_minutes'] ?? 15 ) * MINUTE_IN_SECONDS );
		set_transient( $cache_key, $events, $ttl );
		$this->repository->remember_cache_key( $cache_key, $ttl );
		return $events;
	}

	/**
	 * @return true|WP_Error
	 */
	public function test( string $calendar_id ) {
		$calendar = $this->repository->get( $calendar_id );
		if ( null === $calendar ) {
			return new WP_Error( 'calendar_not_found', __( 'Der Kalender wurde nicht gefunden.', 'tt5-caldav-calendar' ) );
		}

		$timezone = $this->timezone( (string) ( $calendar['timezone'] ?? wp_timezone_string() ) );
		$start    = new DateTimeImmutable( 'now', $timezone );
		$end      = $start->modify( '+1 day' );
		$result   = $this->calendar_query( $calendar, $start, $end, true );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'caldav_http_400', 'caldav_http_403', 'caldav_http_409', 'caldav_http_422', 'caldav_http_501' ), true ) ) {
			$result = $this->calendar_query( $calendar, $start, $end, false );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return array<int,array{name:string,url:string}>|WP_Error
	 */
	public function discover( string $url, string $username, string $password, bool $verify_ssl = true ) {
		return $this->discovery->discover( $url, $username, $password, $verify_ssl );
	}

	private function time_offset_minutes( array $calendar ): int {
		return max( -1440, min( 1440, (int) ( $calendar['time_offset_minutes'] ?? 0 ) ) );
	}

	/**
	 * Include events which cross a range boundary after correction.
	 *
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
	 */
	private function query_range( DateTimeImmutable $start, DateTimeImmutable $end, int $offset_minutes ): array {
		if ( 0 === $offset_minutes ) {
			return array( $start, $end );
		}

		$raw_start   = $start->modify( sprintf( '%+d minutes', -$offset_minutes ) );
		$raw_end     = $end->modify( sprintf( '%+d minutes', -$offset_minutes ) );
		$query_start = $raw_start < $start ? $raw_start : $start;
		$query_end   = $raw_end > $end ? $raw_end : $end;

		return array( $query_start, $query_end );
	}

	/**
	 * @param array<int,array<string,mixed>> $events Parsed events.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_time_offset( array $events, int $offset_minutes, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$corrected      = array();
		$range_start_ts = $range_start->getTimestamp();
		$range_end_ts   = $range_end->getTimestamp();
		$seconds        = $offset_minutes * MINUTE_IN_SECONDS;

		foreach ( $events as $event ) {
			if ( 0 !== $seconds && empty( $event['all_day'] ) ) {
				$event['start'] = (int) ( $event['start'] ?? 0 ) + $seconds;
				$event['end']   = (int) ( $event['end'] ?? 0 ) + $seconds;
			}

			$event_start = (int) ( $event['start'] ?? 0 );
			$event_end   = max( $event_start + 1, (int) ( $event['end'] ?? 0 ) );
			if ( $event_end <= $range_start_ts || $event_start >= $range_end_ts ) {
				continue;
			}

			$corrected[] = $event;
		}

		return $corrected;
	}

	/**
	 * @param array<string,mixed> $calendar Calendar configuration.
	 * @return string|WP_Error
	 */
	private function calendar_query( array $calendar, DateTimeImmutable $start, DateTimeImmutable $end, bool $expand ) {
		$start_utc  = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$end_utc    = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$expand_xml = $expand ? sprintf( '<c:expand start="%s" end="%s"/>', esc_attr( $start_utc ), esc_attr( $end_utc ) ) : '';
		$body       = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data content-type="text/calendar" version="2.0">'
			. $expand_xml
			. '</c:calendar-data></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
			. sprintf( '<c:time-range start="%s" end="%s"/>', esc_attr( $start_utc ), esc_attr( $end_utc ) )
			. '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';

		return $this->request( $calendar, 'REPORT', $body, '1' );
	}

	/**
	 * @param array<string,mixed> $calendar Calendar configuration.
	 * @return string|WP_Error
	 */
	private function request( array $calendar, string $method, string $body, string $depth ) {
		try {
			$password = $this->repository->password( $calendar );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'password_decryption_failed', __( 'Das gespeicherte Passwort konnte nicht entschlüsselt werden. Bitte erneut speichern.', 'tt5-caldav-calendar' ) );
		}

		return $this->http->request(
			(string) $calendar['url'],
			(string) $calendar['username'],
			$password,
			! empty( $calendar['verify_ssl'] ),
			$method,
			$body,
			$depth
		);
	}

	private function timezone( string $name ): DateTimeZone {
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}
}
