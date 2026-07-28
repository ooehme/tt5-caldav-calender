<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts WebDAV XML responses into the small data structures used by the plugin.
 */
final class TT5_CalDAV_WebDAV_Parser {
	public function __construct( private TT5_CalDAV_HTTP_Client $http ) {}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function responses( string $xml, string $base_url ): array {
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
			$out[]         = array(
				'url'            => $this->http->resolve_href( $base_url, $href ),
				'name'           => $this->element_text( (string) $fragment, 'displayname' ),
				'is_calendar'    => '' !== $type_fragment && (bool) preg_match( '~<(?:[A-Za-z0-9_.-]+:)?calendar(?:\s|/|>)~i', $type_fragment ),
				'supports_event' => '' === $supported || (bool) preg_match( '~name=["\']VEVENT["\']~i', $supported ),
				'calendar_home'  => $this->http->resolve_href( $base_url, $this->nested_href( (string) $fragment, 'calendar-home-set' ) ),
				'principal'      => $this->http->resolve_href( $base_url, $this->nested_href( (string) $fragment, 'current-user-principal' ) ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $items Parsed WebDAV responses.
	 * @return array<int,array{name:string,url:string}>
	 */
	public function calendar_collections( array $items, string $allowed_origin ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( empty( $item['is_calendar'] ) || empty( $item['supports_event'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$url = (string) $item['url'];
			if ( ! $this->http->same_origin( $allowed_origin, $url ) ) {
				continue;
			}
			$name = trim( (string) ( $item['name'] ?? '' ) );
			if ( '' === $name ) {
				$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
				$name = '' !== $path ? rawurldecode( basename( $path ) ) : __( 'CalDAV-Kalender', 'tt5-caldav-calendar' );
			}
			$out[ $url ] = array(
				'name' => $name,
				'url'  => $url,
			);
		}
		$out = array_values( $out );
		usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	public function calendar_data_nodes( string $xml ): array {
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
}
