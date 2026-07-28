<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_ICal_Parser {
	private const MAX_RECURRENCE_CANDIDATES = 12000;

	public function __construct( private ?TT5_CalDAV_Recurrence $recurrence = null ) {
		$this->recurrence ??= new TT5_CalDAV_Recurrence();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( string $ics, DateTimeZone $default_timezone, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$components = $this->event_components( $ics );
		$masters    = array();
		$overrides  = array();
		$standalone = array();

		foreach ( $components as $component ) {
			$event = $this->component_to_event( $component, $default_timezone );
			if ( null === $event ) {
				continue;
			}

			$uid = (string) $event['uid'];
			if ( isset( $event['recurrence_id'] ) ) {
				$overrides[ $uid ][ (string) $event['recurrence_id'] ] = $event;
			} elseif ( ! empty( $event['rrule'] ) ) {
				if ( empty( $event['cancelled'] ) ) {
					$masters[ $uid ] = $event;
				}
			} elseif ( empty( $event['cancelled'] ) ) {
				$standalone[] = $event;
			}
		}

		$output = $standalone;
		$used_overrides = array();

		foreach ( $masters as $uid => $master ) {
			$expanded = $this->recurrence->expand( $master, $range_start, $range_end );
			foreach ( $expanded as $occurrence ) {
				$key = (string) $occurrence['start'];
				if ( isset( $overrides[ $uid ][ $key ] ) ) {
					if ( empty( $overrides[ $uid ][ $key ]['cancelled'] ) ) {
						$output[] = $overrides[ $uid ][ $key ];
					}
					$used_overrides[ $uid ][ $key ] = true;
				} else {
					$output[] = $occurrence;
				}
			}
		}

		foreach ( $overrides as $uid => $items ) {
			foreach ( $items as $key => $override ) {
				if ( empty( $used_overrides[ $uid ][ $key ] ) && empty( $override['cancelled'] ) ) {
					$output[] = $override;
				}
			}
		}

		return array_values(
			array_filter(
				$output,
				static function ( array $event ) use ( $range_start, $range_end ): bool {
					return (int) $event['end'] > $range_start->getTimestamp() && (int) $event['start'] < $range_end->getTimestamp();
				}
			)
		);
	}

	/**
	 * @return array<int,array<string,array<int,array{value:string,params:array<string,string>}>>>
	 */
	private function event_components( string $ics ): array {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$normalized = preg_replace( "/\n[ \t]/", '', $normalized ) ?? $normalized;
		$lines      = explode( "\n", $normalized );
		$events     = array();
		$current    = null;

		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\n" );
			if ( 'BEGIN:VEVENT' === strtoupper( $line ) ) {
				$current = array();
				continue;
			}
			if ( 'END:VEVENT' === strtoupper( $line ) ) {
				if ( is_array( $current ) ) {
					$events[] = $current;
				}
				$current = null;
				continue;
			}
			if ( ! is_array( $current ) || '' === trim( $line ) ) {
				continue;
			}

			$colon = $this->property_colon_position( $line );
			if ( false === $colon ) {
				continue;
			}

			$left  = substr( $line, 0, $colon );
			$value = substr( $line, $colon + 1 );
			$parts = $this->split_quoted( $left, ';' );
			$name  = strtoupper( array_shift( $parts ) ?: '' );
			$params = array();

			foreach ( $parts as $part ) {
				if ( ! str_contains( $part, '=' ) ) {
					continue;
				}
				list( $param_name, $param_value ) = explode( '=', $part, 2 );
				$params[ strtoupper( trim( $param_name ) ) ] = trim( $param_value, " \t\n\r\0\x0B\"" );
			}

			$current[ $name ][] = array(
				'value'  => $value,
				'params' => $params,
			);
		}

		return $events;
	}

	private function property_colon_position( string $line ) {
		$quoted = false;
		$length = strlen( $line );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '"' === $line[ $i ] && ( 0 === $i || '\\' !== $line[ $i - 1 ] ) ) {
				$quoted = ! $quoted;
			}
			if ( ':' === $line[ $i ] && ! $quoted ) {
				return $i;
			}
		}
		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private function split_quoted( string $value, string $separator ): array {
		$out    = array();
		$part   = '';
		$quoted = false;
		$length = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( '"' === $char && ( 0 === $i || '\\' !== $value[ $i - 1 ] ) ) {
				$quoted = ! $quoted;
			}
			if ( $separator === $char && ! $quoted ) {
				$out[] = $part;
				$part  = '';
				continue;
			}
			$part .= $char;
		}
		$out[] = $part;
		return $out;
	}

	/**
	 * @param array<string,array<int,array{value:string,params:array<string,string>}>> $component Event properties.
	 * @return array<string,mixed>|null
	 */
	private function component_to_event( array $component, DateTimeZone $default_timezone ): ?array {
		$dtstart_property = $component['DTSTART'][0] ?? null;
		if ( ! is_array( $dtstart_property ) ) {
			return null;
		}

		$start_data = $this->parse_date_property( $dtstart_property, $default_timezone );
		if ( null === $start_data ) {
			return null;
		}

		$start   = $start_data['date'];
		$all_day = $start_data['all_day'];
		$end     = null;

		if ( isset( $component['DTEND'][0] ) ) {
			$end_data = $this->parse_date_property( $component['DTEND'][0], $default_timezone );
			$end      = null !== $end_data ? $end_data['date'] : null;
		} elseif ( isset( $component['DURATION'][0]['value'] ) ) {
			try {
				$end = $start->add( new DateInterval( (string) $component['DURATION'][0]['value'] ) );
			} catch ( Exception $e ) {
				$end = null;
			}
		}

		if ( null === $end ) {
			$end = $all_day ? $start->modify( '+1 day' ) : $start;
		}

		$uid = $this->first_value( $component, 'UID' );
		if ( '' === $uid ) {
			$uid = hash( 'sha256', $this->first_value( $component, 'SUMMARY' ) . '|' . $start->format( DATE_ATOM ) );
		}

		$event = array(
			'uid'         => $uid,
			'title'       => $this->decode_text( $this->first_value( $component, 'SUMMARY' ) ),
			'description' => $this->decode_text( $this->first_value( $component, 'DESCRIPTION' ) ),
			'location'    => $this->decode_text( $this->first_value( $component, 'LOCATION' ) ),
			'url'         => esc_url_raw( $this->first_value( $component, 'URL' ) ),
			'start'       => $start->getTimestamp(),
			'end'         => $end->getTimestamp(),
			'all_day'     => $all_day,
			'timezone'    => $start->getTimezone()->getName(),
			'rrule'       => $this->first_value( $component, 'RRULE' ),
			'exdates'     => $this->date_list( $component['EXDATE'] ?? array(), $default_timezone ),
			'rdates'      => $this->date_list( $component['RDATE'] ?? array(), $default_timezone ),
			'cancelled'   => 'CANCELLED' === strtoupper( $this->first_value( $component, 'STATUS' ) ),
		);

		if ( isset( $component['RECURRENCE-ID'][0] ) ) {
			$recurrence = $this->parse_date_property( $component['RECURRENCE-ID'][0], $default_timezone );
			if ( null !== $recurrence ) {
				$event['recurrence_id'] = (string) $recurrence['date']->getTimestamp();
			}
		}

		return $event;
	}

	/**
	 * @param array{value:string,params:array<string,string>} $property Property.
	 * @return array{date:DateTimeImmutable,all_day:bool}|null
	 */
	private function parse_date_property( array $property, DateTimeZone $default_timezone ): ?array {
		$value   = trim( $property['value'] );
		$params  = $property['params'];
		$all_day = 'DATE' === strtoupper( (string) ( $params['VALUE'] ?? '' ) ) || preg_match( '/^\d{8}$/', $value );

		try {
			if ( $all_day ) {
				$date = $this->date_from_format( '!Ymd', substr( $value, 0, 8 ), $default_timezone );
				return null === $date ? null : array( 'date' => $date, 'all_day' => true );
			}

			if ( str_ends_with( $value, 'Z' ) ) {
				$utc  = new DateTimeZone( 'UTC' );
				$date = $this->date_from_format( '!Ymd\THis\Z', $value, $utc );
				if ( null === $date ) {
					$date = $this->date_from_format( '!Ymd\THi\Z', $value, $utc );
				}
				return null === $date ? null : array( 'date' => $date, 'all_day' => false );
			}

			$timezone = $default_timezone;
			if ( ! empty( $params['TZID'] ) ) {
				try {
					$timezone = new DateTimeZone( $params['TZID'] );
				} catch ( Exception $e ) {
					$timezone = $default_timezone;
				}
			}

			$date = $this->date_from_format( '!Ymd\THis', $value, $timezone );
			if ( null === $date ) {
				$date = $this->date_from_format( '!Ymd\THi', $value, $timezone );
			}
			return null === $date ? null : array( 'date' => $date, 'all_day' => false );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private function date_from_format( string $format, string $value, DateTimeZone $timezone ): ?DateTimeImmutable {
		$date   = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if (
			false === $date ||
			( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
		) {
			return null;
		}

		return $date;
	}

	/**
	 * @param array<int,array{value:string,params:array<string,string>}> $properties Date-list properties.
	 * @return array<int,int>
	 */
	private function date_list( array $properties, DateTimeZone $default_timezone ): array {
		$out = array();
		foreach ( $properties as $property ) {
			foreach ( explode( ',', $property['value'], self::MAX_RECURRENCE_CANDIDATES + 1 ) as $value ) {
				$item = $this->parse_date_property(
					array(
						'value'  => trim( $value ),
						'params' => $property['params'],
					),
					$default_timezone
				);
				if ( null !== $item ) {
					$out[] = $item['date']->getTimestamp();
					if ( count( $out ) >= self::MAX_RECURRENCE_CANDIDATES ) {
						break 2;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string,array<int,array{value:string,params:array<string,string>}>> $component Properties.
	 */
	private function first_value( array $component, string $name ): string {
		return isset( $component[ $name ][0]['value'] ) ? (string) $component[ $name ][0]['value'] : '';
	}

	private function decode_text( string $value ): string {
		$value = str_replace( array( '\\n', '\\N' ), "\n", $value );
		$value = str_replace( array( '\\,', '\\;', '\\\\' ), array( ',', ';', '\\' ), $value );
		return trim( $value );
	}
}
