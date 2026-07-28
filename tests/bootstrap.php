<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'TT5_CALDAV_VERSION', '1.2.7' );
define( 'TT5_CALDAV_FILE', dirname( __DIR__ ) . '/tt5-caldav-calendar.php' );

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

function plugin_basename( string $file ): string {
	return 'tt5-caldav-calendar/' . basename( $file );
}

function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text );
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

function wp_safe_remote_get( string $url, array $args ): array|WP_Error {
	return wp_remote_request( $url, $args );
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

require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-recurrence.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-ical-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-http-client.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-webdav-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-discovery.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-client.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-timezone.php';
require_once dirname( __DIR__ ) . '/includes/class-tt5-caldav-updater.php';
