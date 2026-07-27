<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Timezone {
	public static function normalize( string $value, string $fallback ): string {
		$value = self::normalize_manual_offset( trim( $value ) );

		try {
			new DateTimeZone( $value );
			return $value;
		} catch ( Exception $e ) {
			return self::valid_fallback( $fallback );
		}
	}

	public static function choice_value( string $value ): string {
		if ( ! preg_match( '/^([+-])(\d{2}):(00|15|30|45)$/', trim( $value ), $matches ) ) {
			return $value;
		}

		$fraction = array(
			'00' => '',
			'15' => '.25',
			'30' => '.5',
			'45' => '.75',
		);

		return $matches[1] . (int) $matches[2] . $fraction[ $matches[3] ];
	}

	private static function normalize_manual_offset( string $value ): string {
		if ( ! preg_match( '/^([+-])(\d{1,2})(?:\.(25|5|75))?$/', $value, $matches ) ) {
			return $value;
		}

		$minutes = array(
			''   => '00',
			'25' => '15',
			'5'  => '30',
			'75' => '45',
		);
		$fraction = (string) ( $matches[3] ?? '' );

		return sprintf( '%s%02d:%s', $matches[1], (int) $matches[2], $minutes[ $fraction ] );
	}

	private static function valid_fallback( string $fallback ): string {
		$fallback = trim( $fallback );

		try {
			new DateTimeZone( $fallback );
			return $fallback;
		} catch ( Exception $e ) {
			return 'UTC';
		}
	}
}
