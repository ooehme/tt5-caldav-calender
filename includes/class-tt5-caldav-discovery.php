<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds VEVENT calendar collections starting from common CalDAV entry points.
 */
final class TT5_CalDAV_Discovery {
	public function __construct(
		private TT5_CalDAV_HTTP_Client $http,
		private TT5_CalDAV_WebDAV_Parser $webdav
	) {}

	/**
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

		$initial = $this->http->request( $url, $username, $password, $verify_ssl, 'PROPFIND', $body, '0' );
		if ( is_wp_error( $initial ) ) {
			return $initial;
		}

		$initial_items = $this->webdav->responses( $initial, $url );
		$direct        = $this->webdav->calendar_collections( $initial_items, $url );
		if ( ! empty( $direct ) ) {
			return $direct;
		}

		$home_url      = '';
		$principal_url = '';
		foreach ( $initial_items as $item ) {
			if ( '' === $home_url && ! empty( $item['calendar_home'] ) && $this->http->same_origin( $url, (string) $item['calendar_home'] ) ) {
				$home_url = (string) $item['calendar_home'];
			}
			if ( '' === $principal_url && ! empty( $item['principal'] ) && $this->http->same_origin( $url, (string) $item['principal'] ) ) {
				$principal_url = (string) $item['principal'];
			}
		}

		if ( '' === $home_url && '' !== $principal_url ) {
			$principal = $this->http->request( $principal_url, $username, $password, $verify_ssl, 'PROPFIND', $body, '0' );
			if ( ! is_wp_error( $principal ) ) {
				foreach ( $this->webdav->responses( $principal, $principal_url ) as $item ) {
					if ( ! empty( $item['calendar_home'] ) && $this->http->same_origin( $url, (string) $item['calendar_home'] ) ) {
						$home_url = (string) $item['calendar_home'];
						break;
					}
				}
			}
		}

		if ( '' === $home_url ) {
			$home_url = $url;
		}

		$home = $this->http->request( $home_url, $username, $password, $verify_ssl, 'PROPFIND', $body, '1' );
		if ( is_wp_error( $home ) ) {
			return $home;
		}

		$calendars = $this->webdav->calendar_collections( $this->webdav->responses( $home, $home_url ), $url );
		if ( empty( $calendars ) ) {
			return new WP_Error( 'no_calendars_found', __( 'Unter dieser Adresse wurden keine VEVENT-Kalender gefunden. Prüfen Sie Server-/Principal-URL und Zugangsdaten.', 'tt5-caldav-calendar' ) );
		}

		return $calendars;
	}
}
