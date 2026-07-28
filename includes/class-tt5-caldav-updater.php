<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Updater {
	private const UPDATE_URI  = 'https://github.com/ooehme/tt5-caldav-calender';
	private const RELEASE_API = 'https://api.github.com/repos/ooehme/tt5-caldav-calender/releases/latest';
	private const PACKAGE_URL = 'https://github.com/ooehme/tt5-caldav-calender/releases/download/';

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );
	}

	/**
	 * @param array<string,mixed>|false $update Existing update data.
	 * @param array<string,mixed>       $plugin_data Plugin headers.
	 * @param array<int,string>         $locales Installed locales.
	 * @return array<string,mixed>|false
	 */
	public function filter_update( array|false $update, array $plugin_data, string $plugin_file, array $locales ): array|false {
		unset( $locales );

		if (
			plugin_basename( TT5_CALDAV_FILE ) !== $plugin_file
			|| self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' )
		) {
			return $update;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return false;
		}

		$tag = (string) ( $release['tag_name'] ?? '' );
		if ( 1 !== preg_match( '/^v?([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $matches ) ) {
			return false;
		}

		$version = $matches[1];
		if ( ! version_compare( $version, TT5_CALDAV_VERSION, '>' ) ) {
			return false;
		}

		$package = $this->package_url( $release['assets'] ?? null, $version );
		if ( null === $package ) {
			return false;
		}

		$details_url = esc_url_raw( (string) ( $release['html_url'] ?? '' ), array( 'https' ) );
		if ( ! str_starts_with( $details_url, self::UPDATE_URI . '/releases/' ) ) {
			$details_url = self::UPDATE_URI . '/releases/latest';
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => 'tt5-caldav-calendar',
			'version'      => $version,
			'url'          => $details_url,
			'package'      => $package,
			'tested'       => '7.0',
			'requires_php' => '8.0',
			'autoupdate'   => true,
		);
	}

	public function enable_auto_update( bool|null $update, object $item ): bool|null {
		if ( self::UPDATE_URI === ( $item->id ?? '' ) ) {
			return true;
		}

		return $update;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function latest_release(): ?array {
		$response = wp_safe_remote_get(
			self::RELEASE_API,
			array(
				'timeout'             => 10,
				'redirection'         => 2,
				'limit_response_size' => MB_IN_BYTES,
				'headers'             => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'TT5-CalDAV-Calendar/' . TT5_CALDAV_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}

		return $release;
	}

	private function package_url( mixed $assets, string $version ): ?string {
		if ( ! is_array( $assets ) ) {
			return null;
		}

		$expected_name = 'tt5-caldav-calendar-' . $version . '.zip';
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || $expected_name !== ( $asset['name'] ?? '' ) ) {
				continue;
			}

			$url = esc_url_raw( (string) ( $asset['browser_download_url'] ?? '' ), array( 'https' ) );
			if ( str_starts_with( $url, self::PACKAGE_URL ) && str_ends_with( $url, '/' . $expected_name ) ) {
				return $url;
			}
		}

		return null;
	}
}
