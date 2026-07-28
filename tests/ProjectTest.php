<?php

declare(strict_types=1);

final class TT5_Project_Test extends TT5_Test_Case {
	protected function test_version_consistency(): void {
		$root    = dirname( __DIR__ );
		$version = TT5_CALDAV_VERSION;
		$plugin  = (string) file_get_contents( $root . '/tt5-caldav-calendar.php' );
		$readme  = (string) file_get_contents( $root . '/readme.txt' );
		$asset   = require $root . '/assets/editor.asset.php';

		$this->true( str_contains( $plugin, '* Version:           ' . $version ), 'Plugin header version' );
		$this->true( str_contains( $plugin, "define( 'TT5_CALDAV_VERSION', '" . $version . "' );" ), 'Runtime version' );
		$this->true( str_contains( $plugin, '* Update URI:        https://github.com/ooehme/tt5-caldav-calender' ), 'GitHub update URI' );
		$this->true( str_contains( $readme, 'Stable tag: ' . $version ), 'Readme stable tag' );
		$this->same( $version, $asset['version'] ?? null, 'Editor asset version' );

		foreach ( glob( $root . '/blocks/*/block.json' ) ?: array() as $file ) {
			$metadata = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
			$this->same( $version, $metadata['version'] ?? null, basename( dirname( $file ) ) . ' block version' );
		}
	}

	protected function test_editor_template_defaults_are_not_outer_locked(): void {
		$root   = dirname( __DIR__ );
		$editor = (string) file_get_contents( $root . '/assets/editor.js' );
		foreach ( glob( $root . '/assets/editor/*.js' ) ?: array() as $module ) {
			$editor .= (string) file_get_contents( $module );
		}

		$this->true(
			! str_contains( $editor, "columnCount: 1 } }, eventFieldsTemplate]" ),
			'Loop template must not constrain the custom event template structure'
		);
		$this->same(
			1,
			substr_count( $editor, 'template: eventFieldsTemplate' ),
			'Defaults are initialized only by the event template'
		);
	}

	protected function test_editor_is_split_into_responsibility_modules(): void {
		$root    = dirname( __DIR__ );
		$modules = glob( $root . '/assets/editor/*.js' ) ?: array();
		$entry   = (string) file_get_contents( $root . '/assets/editor.js' );

		$this->same( 4, count( $modules ), 'Editor has four responsibility modules' );
		$this->true( strlen( $entry ) < 500, 'Editor entry point stays small' );
	}
}
