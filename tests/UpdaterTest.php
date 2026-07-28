<?php

declare(strict_types=1);

final class TT5_Updater_Test extends TT5_Test_Case {
	protected function test_github_release_update(): void {
		$GLOBALS['tt5_http_requests']  = array();
		$GLOBALS['tt5_http_responses'] = array(
			$this->response(
				200,
				(string) json_encode(
					array(
						'tag_name'   => 'v1.2.8',
						'html_url'   => 'https://github.com/ooehme/tt5-caldav-calender/releases/tag/v1.2.8',
						'draft'      => false,
						'prerelease' => false,
						'assets'     => array(
							array(
								'name'                 => 'tt5-caldav-calendar-1.2.8.zip',
								'browser_download_url' => 'https://github.com/ooehme/tt5-caldav-calender/releases/download/v1.2.8/tt5-caldav-calendar-1.2.8.zip',
							),
						),
					)
				)
			),
		);

		$update = ( new TT5_CalDAV_Updater() )->filter_update(
			false,
			array( 'UpdateURI' => 'https://github.com/ooehme/tt5-caldav-calender' ),
			plugin_basename( TT5_CALDAV_FILE ),
			array( 'de_DE' )
		);

		$this->true( is_array( $update ), 'Published GitHub release is offered as an update' );
		$this->same( '1.2.8', $update['version'] ?? null, 'Release tag becomes update version' );
		$this->same(
			'https://github.com/ooehme/tt5-caldav-calender/releases/download/v1.2.8/tt5-caldav-calendar-1.2.8.zip',
			$update['package'] ?? null,
			'Installable release asset is selected'
		);
		$this->true( ! array_key_exists( 'autoupdate', $update ), 'WordPress controls the plugin auto-update setting' );
		$this->same( 1, count( $GLOBALS['tt5_http_requests'] ), 'GitHub API is queried once' );
		$this->same( MB_IN_BYTES, $GLOBALS['tt5_http_requests'][0]['args']['limit_response_size'], 'GitHub response size is capped' );
	}

	protected function test_current_github_release_marks_updates_as_supported(): void {
		$GLOBALS['tt5_http_responses'] = array(
			$this->response(
				200,
				(string) json_encode(
					array(
						'tag_name'   => 'v1.2.7',
						'html_url'   => 'https://github.com/ooehme/tt5-caldav-calender/releases/tag/v1.2.7',
						'draft'      => false,
						'prerelease' => false,
						'assets'     => array(
							array(
								'name'                 => 'tt5-caldav-calendar-1.2.7.zip',
								'browser_download_url' => 'https://github.com/ooehme/tt5-caldav-calender/releases/download/v1.2.7/tt5-caldav-calendar-1.2.7.zip',
							),
						),
					)
				)
			),
		);

		$update = ( new TT5_CalDAV_Updater() )->filter_update(
			false,
			array( 'UpdateURI' => 'https://github.com/ooehme/tt5-caldav-calender' ),
			plugin_basename( TT5_CALDAV_FILE ),
			array()
		);

		$this->true( is_array( $update ), 'Current release returns metadata for the WordPress no-update response' );
		$this->same( '1.2.7', $update['version'] ?? null, 'Current release version is returned' );
	}

	protected function test_github_release_requires_matching_zip(): void {
		$GLOBALS['tt5_http_responses'] = array(
			$this->response(
				200,
				'{"tag_name":"v1.2.8","html_url":"https://github.com/ooehme/tt5-caldav-calender/releases/tag/v1.2.8","assets":[]}'
			),
		);

		$update = ( new TT5_CalDAV_Updater() )->filter_update(
			false,
			array( 'UpdateURI' => 'https://github.com/ooehme/tt5-caldav-calender' ),
			plugin_basename( TT5_CALDAV_FILE ),
			array()
		);

		$this->same( false, $update, 'Release without the installable ZIP is rejected' );
	}
}
