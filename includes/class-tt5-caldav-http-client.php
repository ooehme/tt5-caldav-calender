<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends authenticated CalDAV requests while keeping credentials on one origin.
 */
final class TT5_CalDAV_HTTP_Client {
	private const MAX_REDIRECTS      = 3;
	private const MAX_RESPONSE_BYTES = 8 * MB_IN_BYTES;

	/**
	 * @return string|WP_Error
	 */
	public function request( string $url, string $username, string $password, bool $verify_ssl, string $method, string $body, string $depth ) {
		$current_url = $this->validated_url( $url );
		if ( is_wp_error( $current_url ) ) {
			return $current_url;
		}
		$origin_url = $current_url;

		for ( $redirects = 0; ; ++$redirects ) {
			$headers = array(
				'Content-Type' => 'application/xml; charset=utf-8',
				'Depth'        => $depth,
				'User-Agent'   => 'TT5-CalDAV-Calendar/' . TT5_CALDAV_VERSION,
			);
			if ( '' !== $username || '' !== $password ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
			}

			$response = wp_remote_request(
				$current_url,
				array(
					'method'              => $method,
					'timeout'             => 20,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_RESPONSE_BYTES,
					'sslverify'           => $verify_ssl,
					'headers'             => $headers,
					'body'                => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'caldav_transport', $response->get_error_message() );
			}

			$status = wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				if ( $redirects >= self::MAX_REDIRECTS ) {
					return new WP_Error( 'caldav_too_many_redirects', __( 'Der CalDAV-Server hat zu oft weitergeleitet.', 'tt5-caldav-calendar' ) );
				}

				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$next_url = $this->validated_url( $this->resolve_href( $current_url, $location ) );
				if ( is_wp_error( $next_url ) ) {
					return $next_url;
				}
				if ( ! $this->same_origin( $origin_url, $next_url ) ) {
					return new WP_Error( 'caldav_cross_origin_redirect', __( 'Eine Weiterleitung an einen anderen Server wurde zum Schutz der Zugangsdaten blockiert.', 'tt5-caldav-calendar' ) );
				}

				$current_url = $next_url;
				continue;
			}

			if ( ! in_array( $status, array( 200, 207 ), true ) ) {
				return new WP_Error(
					'caldav_http_' . $status,
					sprintf(
						/* translators: %d HTTP status code. */
						__( 'Der CalDAV-Server antwortete mit HTTP-Status %d.', 'tt5-caldav-calendar' ),
						$status
					)
				);
			}

			$response_body = (string) wp_remote_retrieve_body( $response );
			$content_length = absint( wp_remote_retrieve_header( $response, 'content-length' ) );
			if ( $content_length > self::MAX_RESPONSE_BYTES || strlen( $response_body ) >= self::MAX_RESPONSE_BYTES ) {
				return new WP_Error( 'caldav_response_too_large', __( 'Die Antwort des CalDAV-Servers ist zu groß.', 'tt5-caldav-calendar' ) );
			}

			return $response_body;
		}
	}

	public function resolve_href( string $base_url, string $href ): string {
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

	public function same_origin( string $first_url, string $second_url ): bool {
		$first  = wp_parse_url( $first_url );
		$second = wp_parse_url( $second_url );
		if ( ! is_array( $first ) || ! is_array( $second ) || empty( $first['scheme'] ) || empty( $second['scheme'] ) || empty( $first['host'] ) || empty( $second['host'] ) ) {
			return false;
		}

		$first_scheme  = strtolower( (string) $first['scheme'] );
		$second_scheme = strtolower( (string) $second['scheme'] );
		$first_port    = isset( $first['port'] ) ? (int) $first['port'] : ( 'https' === $first_scheme ? 443 : 80 );
		$second_port   = isset( $second['port'] ) ? (int) $second['port'] : ( 'https' === $second_scheme ? 443 : 80 );

		return $first_scheme === $second_scheme
			&& strtolower( (string) $first['host'] ) === strtolower( (string) $second['host'] )
			&& $first_port === $second_port;
	}

	/**
	 * @return string|WP_Error
	 */
	private function validated_url( string $url ) {
		$url   = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if (
			'' === $url ||
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] ) ||
			! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] )
		) {
			return new WP_Error( 'invalid_caldav_url', __( 'Die CalDAV-Adresse ist ungültig.', 'tt5-caldav-calendar' ) );
		}

		return $url;
	}
}
