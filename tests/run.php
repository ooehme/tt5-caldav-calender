<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'TT5_CALDAV_VERSION', '1.2.1' );

final class WP_Error {
	public function __construct(
		private string $code,
		private string $message
	) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function esc_url_raw( string $url, ?array $protocols = null ): string {
	$parts = parse_url( trim( $url ) );
	if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	if ( null !== $protocols && ! in_array( strtolower( (string) $parts['scheme'] ), $protocols, true ) ) {
		return '';
	}
	return trim( $url );
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

/** @var array<int,array<string,mixed>|WP_Error> */
$GLOBALS['tt5_http_responses'] = array();
/** @var array<int,array{url:string,args:array<string,mixed>}> */
$GLOBALS['tt5_http_requests'] = array();

function wp_remote_request( string $url, array $args ): array|WP_Error {
	$GLOBALS['tt5_http_requests'][] = array( 'url' => $url, 'args' => $args );
	$response = array_shift( $GLOBALS['tt5_http_responses'] );
	return $response ?? new WP_Error( 'missing_test_response', 'No response queued.' );
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_header( array $response, string $name ): string {
	return (string) ( $response['headers'][ strtolower( $name ) ] ?? '' );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

final class TT5_CalDAV_Repository {}

require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-ical-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-client.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-timezone.php';

final class TT5_Test_Runner {
	private int $assertions = 0;

	public function run(): void {
		$this->test_version_consistency();
		$this->test_timezone_manual_offsets();
		$this->test_simple_event();
		$this->test_invalid_date_is_rejected();
		$this->test_rdate_does_not_consume_count();
		$this->test_old_unbounded_recurrence_reaches_current_range();
		$this->test_embedded_credentials_are_rejected();
		$this->test_cross_origin_redirect_is_blocked();
		$this->test_same_origin_redirect_is_followed();
		$this->test_oversized_response_is_blocked();

		echo "OK ({$this->assertions} assertions)\n";
	}

	private function test_timezone_manual_offsets(): void {
		$this->same( '+02:00', TT5_CalDAV_Timezone::normalize( '+2', 'UTC' ), 'Positive manual timezone offset' );
		$this->same( '-05:30', TT5_CalDAV_Timezone::normalize( '-5.5', 'UTC' ), 'Negative manual timezone offset' );
		$this->same( 'Europe/Berlin', TT5_CalDAV_Timezone::normalize( 'Europe/Berlin', 'UTC' ), 'Named timezone' );
		$this->same( 'UTC', TT5_CalDAV_Timezone::normalize( 'invalid', 'UTC' ), 'Invalid timezone fallback' );
		$this->same( '+2', TT5_CalDAV_Timezone::choice_value( '+02:00' ), 'Positive offset choice value' );
		$this->same( '-5.5', TT5_CalDAV_Timezone::choice_value( '-05:30' ), 'Negative offset choice value' );
	}

	private function test_version_consistency(): void {
		$root    = dirname( __DIR__ );
		$version = TT5_CALDAV_VERSION;
		$plugin  = (string) file_get_contents( $root . '/tt5-caldav-calendar.php' );
		$readme  = (string) file_get_contents( $root . '/readme.txt' );
		$asset   = require $root . '/assets/editor.asset.php';

		$this->true( str_contains( $plugin, '* Version:           ' . $version ), 'Plugin header version' );
		$this->true( str_contains( $plugin, "define( 'TT5_CALDAV_VERSION', '" . $version . "' );" ), 'Runtime version' );
		$this->true( str_contains( $readme, 'Stable tag: ' . $version ), 'Readme stable tag' );
		$this->same( $version, $asset['version'] ?? null, 'Editor asset version' );

		foreach ( glob( $root . '/blocks/*/block.json' ) ?: array() as $file ) {
			$metadata = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
			$this->same( $version, $metadata['version'] ?? null, basename( dirname( $file ) ) . ' block version' );
		}
	}

	private function test_simple_event(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTART:20260727T100000Z\r\nDTEND:20260727T110000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-07-27',
			'2026-07-28'
		);
		$this->same( 1, count( $events ), 'Simple event count' );
		$this->same( 'Test', $events[0]['title'], 'Simple event title' );
	}

	private function test_invalid_date_is_rejected(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:invalid\r\nDTSTART;VALUE=DATE:20260230\r\nSUMMARY:Invalid\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-02-01',
			'2026-04-01'
		);
		$this->same( 0, count( $events ), 'Invalid dates must not be normalized silently' );
	}

	private function test_rdate_does_not_consume_count(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:repeat\r\nDTSTART:20260101T090000Z\r\nDTEND:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nRDATE:20260110T090000Z\r\nSUMMARY:Repeat\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-01-01',
			'2026-01-11'
		);
		$starts = array_column( $events, 'start' );
		sort( $starts );
		$this->same( 3, count( $starts ), 'RDATE is additional to RRULE COUNT' );
		$this->same( strtotime( '2026-01-10 09:00:00 UTC' ), $starts[2], 'RDATE occurrence is retained' );
	}

	private function test_old_unbounded_recurrence_reaches_current_range(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:old-repeat\r\nDTSTART:19800101T090000Z\r\nDTEND:19800101T100000Z\r\nRRULE:FREQ=DAILY\r\nSUMMARY:Old repeat\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-07-27',
			'2026-07-28'
		);
		$this->same( 1, count( $events ), 'Old unbounded recurrences are fast-forwarded into the requested range' );
		$this->same( strtotime( '2026-07-27 09:00:00 UTC' ), $events[0]['start'], 'Fast-forwarded recurrence date' );
	}

	private function test_cross_origin_redirect_is_blocked(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response( 302, '', array( 'location' => 'https://evil.example/calendar' ) ),
		);
		$result = $this->request( 'https://calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Cross-origin redirect returns an error' );
		$this->same( 'caldav_cross_origin_redirect', $result->get_error_code(), 'Cross-origin redirect error code' );
		$this->same( 1, count( $GLOBALS['tt5_http_requests'] ), 'Credentials are never sent to the redirect target' );
	}

	private function test_embedded_credentials_are_rejected(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array();
		$result = $this->request( 'https://embedded:secret@calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Embedded URL credentials return an error' );
		$this->same( 'invalid_caldav_url', $result->get_error_code(), 'Embedded URL credentials error code' );
		$this->same( 0, count( $GLOBALS['tt5_http_requests'] ), 'Invalid URL is not requested' );
	}

	private function test_same_origin_redirect_is_followed(): void {
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

	private function test_oversized_response_is_blocked(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response( 207, 'partial', array( 'content-length' => (string) ( 8 * MB_IN_BYTES + 1 ) ) ),
		);
		$result = $this->request( 'https://calendar.example/source' );
		$this->true( is_wp_error( $result ), 'Oversized response returns an error' );
		$this->same( 'caldav_response_too_large', $result->get_error_code(), 'Oversized response error code' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parse( string $ics, string $start, string $end ): array {
		$timezone = new DateTimeZone( 'UTC' );
		$parser   = new TT5_CalDAV_ICal_Parser();
		return $parser->parse(
			$ics,
			$timezone,
			new DateTimeImmutable( $start, $timezone ),
			new DateTimeImmutable( $end, $timezone )
		);
	}

	private function request( string $url ): string|WP_Error {
		$client = new TT5_CalDAV_Client( new TT5_CalDAV_Repository(), new TT5_CalDAV_ICal_Parser() );
		$method = new ReflectionMethod( $client, 'request_credentials' );
		$method->setAccessible( true );
		return $method->invoke( $client, $url, 'user', 'secret', true, 'PROPFIND', '<xml />', '0' );
	}

	/**
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	private function response( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
			'body'     => $body,
		);
	}

	private function same( mixed $expected, mixed $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	private function true( bool $actual, string $message ): void {
		$this->same( true, $actual, $message );
	}
}

( new TT5_Test_Runner() )->run();
