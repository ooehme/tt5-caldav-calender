<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Client {
	public function __construct(
		private TT5_CalDAV_Repository $repository,
		private TT5_CalDAV_ICal_Parser $parser
	) {}

	/**
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function events( string $calendar_id, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 20, bool $force = false ) {
		$calendar = $this->repository->get( $calendar_id );
		if ( null === $calendar ) {
			return new WP_Error( 'calendar_not_found', __( 'Der ausgewählte CalDAV-Kalender existiert nicht.', 'tt5-caldav-calendar' ) );
		}

		$limit     = min( 100, max( 1, $limit ) );
		$cache_key = 'tt5cd_' . md5( $calendar_id . '|' . $start->format( 'c' ) . '|' . $end->format( 'c' ) . '|' . $limit );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = $this->calendar_query( $calendar, $start, $end, true );
		if ( is_wp_error( $response ) && in_array( $response->get_error_code(), array( 'caldav_http_400', 'caldav_http_403', 'caldav_http_409', 'caldav_http_422', 'caldav_http_501' ), true ) ) {
			$response = $this->calendar_query( $calendar, $start, $end, false );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$timezone = $this->timezone( (string) ( $calendar['timezone'] ?? wp_timezone_string() ) );
		$events   = array();
		foreach ( $this->calendar_data_nodes( $response ) as $ics ) {
			$events = array_merge( $events, $this->parser->parse( $ics, $timezone, $start, $end ) );
		}

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
		$this->repository->remember_cache_key( $cache_key );
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
	 * Discover VEVENT calendar collections from a server, principal or calendar-home URL.
	 *
	 * @return array<int,array{name:string,url:string}>|WP_Error
	 */
	public function discover( string $url, string $username, string $password, bool $verify_ssl = true ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new WP_Error( 'invalid_discovery_url', __( 'Bitte eine gültige HTTP- oder HTTPS-Adresse eingeben.', 'tt5-caldav-calendar' ) );
		}

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:displayname/><d:resourcetype/><d:current-user-principal/>'
			. '<c:calendar-home-set/><c:supported-calendar-component-set/></d:prop></d:propfind>';

		$initial = $this->request_credentials( $url, $username, $password, $verify_ssl, 'PROPFIND', $body, '0' );
		if ( is_wp_error( $initial ) ) {
			return $initial;
		}

		$initial_items = $this->webdav_responses( $initial, $url );
		$direct = $this->calendar_collections( $initial_items );
		if ( ! empty( $direct ) ) {
			return $direct;
		}

		$home_url      = '';
		$principal_url = '';
		foreach ( $initial_items as $item ) {
			if ( '' === $home_url && ! empty( $item['calendar_home'] ) ) {
				$home_url = (string) $item['calendar_home'];
			}
			if ( '' === $principal_url && ! empty( $item['principal'] ) ) {
				$principal_url = (string) $item['principal'];
			}
		}

		if ( '' === $home_url && '' !== $principal_url ) {
			$principal = $this->request_credentials( $principal_url, $username, $password, $verify_ssl, 'PROPFIND', $body, '0' );
			if ( ! is_wp_error( $principal ) ) {
				foreach ( $this->webdav_responses( $principal, $principal_url ) as $item ) {
					if ( ! empty( $item['calendar_home'] ) ) {
						$home_url = (string) $item['calendar_home'];
						break;
					}
				}
			}
		}

		if ( '' === $home_url ) {
			$home_url = $url;
		}

		$home = $this->request_credentials( $home_url, $username, $password, $verify_ssl, 'PROPFIND', $body, '1' );
		if ( is_wp_error( $home ) ) {
			return $home;
		}

		$calendars = $this->calendar_collections( $this->webdav_responses( $home, $home_url ) );
		if ( empty( $calendars ) ) {
			return new WP_Error( 'no_calendars_found', __( 'Unter dieser Adresse wurden keine VEVENT-Kalender gefunden. Prüfen Sie Server-/Principal-URL und Zugangsdaten.', 'tt5-caldav-calendar' ) );
		}

		return $calendars;
	}

	/**
	 * @param array<string,mixed> $calendar Calendar configuration.
	 * @return string|WP_Error
	 */
	private function calendar_query( array $calendar, DateTimeImmutable $start, DateTimeImmutable $end, bool $expand ) {
		$start_utc = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$end_utc   = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$expand_xml = $expand ? sprintf( '<c:expand start="%s" end="%s"/>', esc_attr( $start_utc ), esc_attr( $end_utc ) ) : '';
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
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

		return $this->request_credentials(
			(string) $calendar['url'],
			(string) $calendar['username'],
			$password,
			! empty( $calendar['verify_ssl'] ),
			$method,
			$body,
			$depth
		);
	}

	/**
	 * @return string|WP_Error
	 */
	private function request_credentials( string $url, string $username, string $password, bool $verify_ssl, string $method, string $body, string $depth ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => $verify_ssl,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Content-Type'  => 'application/xml; charset=utf-8',
					'Depth'         => $depth,
					'User-Agent'    => 'TT5-CalDAV-Calendar/' . TT5_CALDAV_VERSION . '; ' . home_url( '/' ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'caldav_transport', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $status, array( 200, 207 ), true ) ) {
			$message = sprintf(
				/* translators: %d HTTP status code. */
				__( 'Der CalDAV-Server antwortete mit HTTP-Status %d.', 'tt5-caldav-calendar' ),
				$status
			);
			return new WP_Error( 'caldav_http_' . $status, $message );
		}

		return (string) wp_remote_retrieve_body( $response );
	}


	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function webdav_responses( string $xml, string $base_url ): array {
		$matches = array();
		preg_match_all(
			'~<(?:[A-Za-z0-9_.-]+:)?response\b[^>]*>(.*?)</(?:[A-Za-z0-9_.-]+:)?response>~si',
			$xml,
			$matches
		);

		$out = array();
		foreach ( $matches[1] ?? array() as $fragment ) {
			$href = $this->nested_href( (string) $fragment, 'href' );
			if ( '' === $href ) {
				continue;
			}
			$type_fragment = $this->element_fragment( (string) $fragment, 'resourcetype' );
			$supported     = $this->element_fragment( (string) $fragment, 'supported-calendar-component-set' );
			$out[] = array(
				'url'           => $this->resolve_href( $base_url, $href ),
				'name'          => $this->element_text( (string) $fragment, 'displayname' ),
				'is_calendar'   => '' !== $type_fragment && (bool) preg_match( '~<(?:[A-Za-z0-9_.-]+:)?calendar(?:\s|/|>)~i', $type_fragment ),
				'supports_event'=> '' === $supported || (bool) preg_match( '~name=["\']VEVENT["\']~i', $supported ),
				'calendar_home' => $this->resolve_href( $base_url, $this->nested_href( (string) $fragment, 'calendar-home-set' ) ),
				'principal'     => $this->resolve_href( $base_url, $this->nested_href( (string) $fragment, 'current-user-principal' ) ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $items Parsed WebDAV responses.
	 * @return array<int,array{name:string,url:string}>
	 */
	private function calendar_collections( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( empty( $item['is_calendar'] ) || empty( $item['supports_event'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$url  = (string) $item['url'];
			$name = trim( (string) ( $item['name'] ?? '' ) );
			if ( '' === $name ) {
				$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
				$name = '' !== $path ? rawurldecode( basename( $path ) ) : __( 'CalDAV-Kalender', 'tt5-caldav-calendar' );
			}
			$out[ $url ] = array( 'name' => $name, 'url' => $url );
		}
		$out = array_values( $out );
		usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	private function nested_href( string $xml, string $element ): string {
		if ( 'href' === $element ) {
			return $this->element_text( $xml, 'href' );
		}
		$fragment = $this->element_fragment( $xml, $element );
		return '' !== $fragment ? $this->element_text( $fragment, 'href' ) : '';
	}

	private function element_fragment( string $xml, string $element ): string {
		$matches = array();
		if ( preg_match( '~<(?:[A-Za-z0-9_.-]+:)?' . preg_quote( $element, '~' ) . '\b[^>]*>(.*?)</(?:[A-Za-z0-9_.-]+:)?' . preg_quote( $element, '~' ) . '>~si', $xml, $matches ) ) {
			return (string) $matches[1];
		}
		return '';
	}

	private function element_text( string $xml, string $element ): string {
		$fragment = $this->element_fragment( $xml, $element );
		if ( '' === $fragment ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( $fragment ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}

	private function resolve_href( string $base_url, string $href ): string {
		$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		if ( '' === $href ) {
			return '';
		}
		if ( preg_match( '~^https?://~i', $href ) ) {
			return esc_url_raw( $href, array( 'http', 'https' ) );
		}

		$parts = wp_parse_url( $base_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		if ( str_starts_with( $href, '//' ) ) {
			return esc_url_raw( $parts['scheme'] . ':' . $href, array( 'http', 'https' ) );
		}
		if ( str_starts_with( $href, '/' ) ) {
			return esc_url_raw( $origin . $href, array( 'http', 'https' ) );
		}

		$base_path = (string) ( $parts['path'] ?? '/' );
		$directory = str_ends_with( $base_path, '/' ) ? $base_path : dirname( $base_path ) . '/';
		return esc_url_raw( $origin . $directory . $href, array( 'http', 'https' ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function calendar_data_nodes( string $xml ): array {
		if ( '' === trim( $xml ) ) {
			return array();
		}

		if ( str_starts_with( ltrim( $xml ), 'BEGIN:VCALENDAR' ) ) {
			return array( $xml );
		}

		$matches = array();
		preg_match_all(
			'~<(?:[A-Za-z0-9_.-]+:)?calendar-data\b[^>]*>(.*?)</(?:[A-Za-z0-9_.-]+:)?calendar-data>~si',
			$xml,
			$matches
		);

		$out = array();
		foreach ( $matches[1] ?? array() as $value ) {
			$value = trim( (string) $value );
			if ( str_starts_with( $value, '<![CDATA[' ) && str_ends_with( $value, ']]>' ) ) {
				$value = substr( $value, 9, -3 );
			} else {
				$value = html_entity_decode( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
			if ( str_contains( $value, 'BEGIN:VCALENDAR' ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	private function timezone( string $name ): DateTimeZone {
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}
}
