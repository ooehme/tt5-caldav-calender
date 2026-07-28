<?php

declare(strict_types=1);

final class TT5_Client_Test extends TT5_Test_Case {
	protected function test_timezone_manual_offsets(): void {
		$this->same( '+02:00', TT5_CalDAV_Timezone::normalize( '+2', 'UTC' ), 'Positive manual timezone offset' );
		$this->same( '-05:30', TT5_CalDAV_Timezone::normalize( '-5.5', 'UTC' ), 'Negative manual timezone offset' );
		$this->same( 'Europe/Berlin', TT5_CalDAV_Timezone::normalize( 'Europe/Berlin', 'UTC' ), 'Named timezone' );
		$this->same( 'UTC', TT5_CalDAV_Timezone::normalize( 'invalid', 'UTC' ), 'Invalid timezone fallback' );
		$this->same( '+2', TT5_CalDAV_Timezone::choice_value( '+02:00' ), 'Positive offset choice value' );
		$this->same( '-5.5', TT5_CalDAV_Timezone::choice_value( '-05:30' ), 'Negative offset choice value' );
	}

	protected function test_per_calendar_time_offset(): void {
		$timezone = new DateTimeZone( 'UTC' );
		$start    = new DateTimeImmutable( '2026-07-27 00:00:00', $timezone );
		$end      = $start->modify( '+1 day' );
		$client   = new TT5_CalDAV_Client( new TT5_CalDAV_Repository(), new TT5_CalDAV_ICal_Parser() );

		$query_range = new ReflectionMethod( $client, 'query_range' );
		$query_range->setAccessible( true );
		list( $query_start, $query_end ) = $query_range->invoke( $client, $start, $end, 120 );
		$this->same( $start->modify( '-2 hours' )->getTimestamp(), $query_start->getTimestamp(), 'Positive correction widens query start' );
		$this->same( $end->getTimestamp(), $query_end->getTimestamp(), 'Positive correction keeps query end' );

		list( $query_start, $query_end ) = $query_range->invoke( $client, $start, $end, -120 );
		$this->same( $start->getTimestamp(), $query_start->getTimestamp(), 'Negative correction keeps query start' );
		$this->same( $end->modify( '+2 hours' )->getTimestamp(), $query_end->getTimestamp(), 'Negative correction widens query end' );

		$events = array(
			array( 'uid' => 'timed', 'start' => $start->modify( '+10 hours' )->getTimestamp(), 'end' => $start->modify( '+11 hours' )->getTimestamp(), 'all_day' => false ),
			array( 'uid' => 'all-day', 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'all_day' => true ),
			array( 'uid' => 'incoming', 'start' => $start->modify( '-1 hour' )->getTimestamp(), 'end' => $start->modify( '-30 minutes' )->getTimestamp(), 'all_day' => false ),
			array( 'uid' => 'outgoing', 'start' => $end->modify( '-1 hour' )->getTimestamp(), 'end' => $end->getTimestamp(), 'all_day' => false ),
		);
		$apply_offset = new ReflectionMethod( $client, 'apply_time_offset' );
		$apply_offset->setAccessible( true );
		$corrected = $apply_offset->invoke( $client, $events, 120, $start, $end );
		$by_uid    = array();
		foreach ( $corrected as $event ) {
			$by_uid[ $event['uid'] ] = $event;
		}

		$this->same( 3, count( $corrected ), 'Corrected events are filtered against the requested range' );
		$this->same( $start->modify( '+12 hours' )->getTimestamp(), $by_uid['timed']['start'], 'Timed event is shifted' );
		$this->same( $start->getTimestamp(), $by_uid['all-day']['start'], 'All-day event is not shifted' );
		$this->same( $start->modify( '+1 hour' )->getTimestamp(), $by_uid['incoming']['start'], 'Event shifted into range is retained' );
		$this->true( ! isset( $by_uid['outgoing'] ), 'Event shifted out of range is removed' );
	}

	protected function test_cross_origin_redirect_is_blocked(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response( 302, '', array( 'location' => 'https://evil.example/calendar' ) ),
		);
		$result = $this->request( 'https://calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Cross-origin redirect returns an error' );
		$this->same( 'caldav_cross_origin_redirect', $result->get_error_code(), 'Cross-origin redirect error code' );
		$this->same( 1, count( $GLOBALS['tt5_http_requests'] ), 'Credentials are never sent to the redirect target' );
	}

	protected function test_embedded_credentials_are_rejected(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array();
		$result = $this->request( 'https://embedded:secret@calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Embedded URL credentials return an error' );
		$this->same( 'invalid_caldav_url', $result->get_error_code(), 'Embedded URL credentials error code' );
		$this->same( 0, count( $GLOBALS['tt5_http_requests'] ), 'Invalid URL is not requested' );
	}

	protected function test_same_origin_redirect_is_followed(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response( 301, '', array( 'location' => '/next' ) ),
			$this->response( 207, '<multistatus />' ),
		);
		$result = $this->request( 'https://calendar.example/source' );
		$this->same( '<multistatus />', $result, 'Same-origin redirect response' );
		$this->same( 'https://calendar.example/next', $GLOBALS['tt5_http_requests'][1]['url'], 'Same-origin redirect target' );
		$this->same( 0, $GLOBALS['tt5_http_requests'][0]['args']['redirection'], 'Native redirects are disabled' );
		$this->same( 8 * MB_IN_BYTES, $GLOBALS['tt5_http_requests'][0]['args']['limit_response_size'], 'Response size is capped' );
	}

	protected function test_oversized_response_is_blocked(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response( 207, 'partial', array( 'content-length' => (string) ( 8 * MB_IN_BYTES + 1 ) ) ),
		);
		$result = $this->request( 'https://calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Oversized response returns an error' );
		$this->same( 'caldav_response_too_large', $result->get_error_code(), 'Oversized response error code' );
	}

	protected function test_webdav_parser_resolves_calendar_collections(): void {
		$http   = new TT5_CalDAV_HTTP_Client();
		$parser = new TT5_CalDAV_WebDAV_Parser( $http );
		$xml    = '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:response><d:href>/calendars/team/</d:href><d:propstat><d:prop>'
			. '<d:displayname>Team &amp; Termine</d:displayname>'
			. '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>'
			. '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>'
			. '</d:prop></d:propstat></d:response></d:multistatus>';

		$items     = $parser->responses( $xml, 'https://calendar.example/root/' );
		$calendars = $parser->calendar_collections( $items, 'https://calendar.example/root/' );

		$this->same( 1, count( $calendars ), 'WebDAV calendar collection is recognized' );
		$this->same( 'Team & Termine', $calendars[0]['name'], 'WebDAV display name is decoded' );
		$this->same( 'https://calendar.example/calendars/team/', $calendars[0]['url'], 'WebDAV href is resolved' );
	}

	protected function test_calendar_data_nodes_support_cdata(): void {
		$parser = new TT5_CalDAV_WebDAV_Parser( new TT5_CalDAV_HTTP_Client() );
		$nodes  = $parser->calendar_data_nodes(
			'<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<c:calendar-data><![CDATA[BEGIN:VCALENDAR' . "\n" . 'END:VCALENDAR]]></c:calendar-data>'
			. '</d:multistatus>'
		);

		$this->same( array( "BEGIN:VCALENDAR\nEND:VCALENDAR" ), $nodes, 'CDATA calendar data is extracted' );
	}
}
