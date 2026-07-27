<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Repository {
	public const OPTION      = 'tt5_caldav_calendars';
	public const CACHE_INDEX     = 'tt5_caldav_cache_keys';
	public const DISCOVERY_INDEX = 'tt5_caldav_discovery_keys';

	public function __construct( private TT5_CalDAV_Crypto $crypto ) {}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * @return array<int,array{id:string,name:string,timezone:string}>
	 */
	public function choices(): array {
		$out = array();
		foreach ( $this->all() as $id => $calendar ) {
			$out[] = array(
				'id'       => (string) $id,
				'name'     => (string) ( $calendar['name'] ?? $id ),
				'timezone' => (string) ( $calendar['timezone'] ?? wp_timezone_string() ),
			);
		}

		usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * @param array<string,mixed> $input Submitted values.
	 * @return string|WP_Error Calendar ID or error.
	 */
	public function save( array $input ) {
		$id       = isset( $input['id'] ) ? sanitize_key( (string) $input['id'] ) : '';
		$existing = '' !== $id ? $this->get( $id ) : null;

		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$url  = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ), array( 'http', 'https' ) );
		$user = sanitize_text_field( (string) ( $input['username'] ?? '' ) );

		if ( '' === $name || '' === $url ) {
			return new WP_Error( 'missing_fields', __( 'Name und Kalender-URL sind erforderlich.', 'tt5-caldav-calendar' ) );
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_url', __( 'Die Kalender-URL muss HTTP oder HTTPS verwenden.', 'tt5-caldav-calendar' ) );
		}

		$timezone = sanitize_text_field( (string) ( $input['timezone'] ?? wp_timezone_string() ) );
		if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			$timezone = wp_timezone_string();
		}

		$cache_minutes = min( 1440, max( 1, absint( $input['cache_minutes'] ?? 15 ) ) );
		$verify_ssl    = ! empty( $input['verify_ssl'] );
		$password      = (string) ( $input['password'] ?? '' );

		if ( '' === $id ) {
			$id = str_replace( '-', '', wp_generate_uuid4() );
		}

		try {
			$encrypted_password = '' !== $password
				? $this->crypto->encrypt( $password )
				: (string) ( $existing['password'] ?? '' );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'encryption_failed', __( 'Das Passwort konnte nicht verschlüsselt gespeichert werden.', 'tt5-caldav-calendar' ) );
		}

		$all        = $this->all();
		$all[ $id ] = array(
			'id'            => $id,
			'name'          => $name,
			'url'           => $url,
			'username'      => $user,
			'password'      => $encrypted_password,
			'timezone'      => $timezone,
			'cache_minutes' => $cache_minutes,
			'verify_ssl'    => $verify_ssl,
			'created_at'    => (string) ( $existing['created_at'] ?? current_time( 'mysql', true ) ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		update_option( self::OPTION, $all, false );
		$this->clear_cache();
		return $id;
	}

	public function delete( string $id ): bool {
		$all = $this->all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		$this->clear_cache();
		return true;
	}

	/**
	 * @param array<string,mixed> $calendar Calendar configuration.
	 */
	public function password( array $calendar ): string {
		return $this->crypto->decrypt( (string) ( $calendar['password'] ?? '' ) );
	}

	public function protect_secret( string $value ): string {
		return $this->crypto->encrypt( $value );
	}

	public function reveal_secret( string $value ): string {
		return $this->crypto->decrypt( $value );
	}

	public function remember_cache_key( string $key ): void {
		$keys = get_option( self::CACHE_INDEX, array() );
		$keys = is_array( $keys ) ? $keys : array();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::CACHE_INDEX, $keys, false );
		}
	}


	public function remember_discovery_key( string $key ): void {
		$keys = get_option( self::DISCOVERY_INDEX, array() );
		$keys = is_array( $keys ) ? $keys : array();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::DISCOVERY_INDEX, $keys, false );
		}
	}

	public function clear_cache(): void {
		$keys = get_option( self::CACHE_INDEX, array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( (string) $key );
			}
		}
		delete_option( self::CACHE_INDEX );
	}
}
