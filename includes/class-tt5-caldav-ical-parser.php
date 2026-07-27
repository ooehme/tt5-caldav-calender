<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_ICal_Parser {
	private const MAX_RECURRENCE_CANDIDATES = 12000;

	private const WEEKDAYS = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);

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
			$expanded = $this->expand_master( $master, $range_start, $range_end );
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

	/**
	 * @param array<string,mixed> $master Master event.
	 * @return array<int,array<string,mixed>>
	 */
	private function expand_master( array $master, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$rule = $this->parse_rrule( (string) $master['rrule'] );
		if ( empty( $rule['FREQ'] ) ) {
			return array( $master );
		}

		$timezone = new DateTimeZone( (string) $master['timezone'] );
		$start    = ( new DateTimeImmutable( '@' . (int) $master['start'] ) )->setTimezone( $timezone );
		$duration = max( 0, (int) $master['end'] - (int) $master['start'] );
		$until    = $this->rrule_until( (string) ( $rule['UNTIL'] ?? '' ), $timezone );
		$count    = isset( $rule['COUNT'] ) ? max( 1, absint( $rule['COUNT'] ) ) : null;
		$interval = min( 10000, max( 1, absint( $rule['INTERVAL'] ?? 1 ) ) );
		$limit    = min( self::MAX_RECURRENCE_CANDIDATES, $count ?? self::MAX_RECURRENCE_CANDIDATES );
		$seen     = 0;
		$period_offset = null === $count
			? $this->recurrence_period_offset( $start, $range_start, $duration, $interval, (string) $rule['FREQ'] )
			: 0;
		$candidates = array( $start );
		$candidate_budget = self::MAX_RECURRENCE_CANDIDATES - 1;
		$add_candidate = static function ( DateTimeImmutable $candidate ) use ( &$candidates, &$candidate_budget ): bool {
			if ( $candidate_budget <= 0 ) {
				return false;
			}
			$candidates[] = $candidate;
			--$candidate_budget;
			return true;
		};

		switch ( strtoupper( (string) $rule['FREQ'] ) ) {
			case 'DAILY':
				$current = $start->modify( '+' . ( $period_offset * $interval ) . ' days' );
				while ( $limit-- > 0 && $candidate_budget > 0 ) {
					if ( $current >= $range_end || ( null !== $until && $current > $until ) ) {
						break;
					}
					$add_candidate( $current );
					$current = $current->modify( '+' . $interval . ' days' );
				}
				break;

			case 'WEEKLY':
				$default_day = array_search( (int) $start->format( 'N' ), self::WEEKDAYS, true );
				$bydays = $this->byday_tokens( (string) ( $rule['BYDAY'] ?? ( $default_day ?: 'MO' ) ) );
				$week_start = $start->modify( 'monday this week' )->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) );
				for ( $week = $period_offset; $limit-- > 0 && $candidate_budget > 0; $week++ ) {
					$base = $week_start->modify( '+' . ( $week * $interval ) . ' weeks' );
					if ( $base >= $range_end || ( null !== $until && $base > $until ) ) {
						break;
					}
					foreach ( $bydays as $token ) {
						$weekday = self::WEEKDAYS[ $token['day'] ] ?? 1;
						$candidate = $base->modify( '+' . ( $weekday - 1 ) . ' days' );
						if ( $candidate >= $start ) {
							if ( ! $add_candidate( $candidate ) ) {
								break 2;
							}
						}
					}
				}
				break;

			case 'MONTHLY':
				for ( $month = $period_offset; $limit-- > 0 && $candidate_budget > 0; $month++ ) {
					$base = $start->modify( 'first day of this month' )->modify( '+' . ( $month * $interval ) . ' months' );
					if ( $base >= $range_end || ( null !== $until && $base > $until ) ) {
						break;
					}
					$month_candidates = $this->monthly_candidates( $base, $start, $rule );
					foreach ( $month_candidates as $candidate ) {
						if ( $candidate >= $start ) {
							if ( ! $add_candidate( $candidate ) ) {
								break 2;
							}
						}
					}
				}
				break;

			case 'YEARLY':
				$months = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $rule['BYMONTH'] ?? $start->format( 'n' ) ), 13 ) ), static fn( int $month ): bool => $month >= 1 && $month <= 12 ) ) );
				$days   = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) ( $rule['BYMONTHDAY'] ?? $start->format( 'j' ) ), 63 ) ), static fn( int $day ): bool => 0 !== $day && $day >= -31 && $day <= 31 ) ) );
				for ( $year = $period_offset; $limit-- > 0 && $candidate_budget > 0; $year++ ) {
					$year_number = (int) $start->format( 'Y' ) + ( $year * $interval );
					if ( $year_number > (int) $range_end->format( 'Y' ) || ( null !== $until && $year_number > (int) $until->format( 'Y' ) ) ) {
						break;
					}
					foreach ( $months as $month ) {
						foreach ( $days as $day ) {
							$candidate = $this->date_in_month( $year_number, $month, $day, $start );
							if ( null !== $candidate && $candidate >= $start ) {
								if ( ! $add_candidate( $candidate ) ) {
									break 3;
								}
							}
						}
					}
				}
				break;
		}

		usort( $candidates, static fn( DateTimeImmutable $a, DateTimeImmutable $b ): int => $a <=> $b );
		$exdates = array_flip( array_map( 'strval', (array) $master['exdates'] ) );
		$out          = array();
		$rrule_unique = array();
		$included     = array();

		foreach ( $candidates as $candidate ) {
			$timestamp = $candidate->getTimestamp();
			if ( isset( $rrule_unique[ $timestamp ] ) ) {
				continue;
			}
			$rrule_unique[ $timestamp ] = true;
			++$seen;

			if ( null !== $count && $seen > $count ) {
				break;
			}
			if ( null !== $until && $candidate > $until ) {
				break;
			}
			if ( isset( $exdates[ (string) $timestamp ] ) ) {
				continue;
			}

			$end_timestamp = $timestamp + $duration;
			if ( $end_timestamp <= $range_start->getTimestamp() || $timestamp >= $range_end->getTimestamp() ) {
				continue;
			}

			$event          = $master;
			$event['start'] = $timestamp;
			$event['end']   = $end_timestamp;
			$event['rrule'] = '';
			$out[]          = $event;
			$included[ $timestamp ] = true;
		}

		foreach ( (array) $master['rdates'] as $timestamp ) {
			$timestamp = (int) $timestamp;
			if ( isset( $included[ $timestamp ] ) || isset( $exdates[ (string) $timestamp ] ) ) {
				continue;
			}
			$included[ $timestamp ] = true;

			$end_timestamp = $timestamp + $duration;
			if ( $end_timestamp <= $range_start->getTimestamp() || $timestamp >= $range_end->getTimestamp() ) {
				continue;
			}

			$event          = $master;
			$event['start'] = $timestamp;
			$event['end']   = $end_timestamp;
			$event['rrule'] = '';
			$out[]          = $event;
		}

		return $out;
	}

	private function recurrence_period_offset(
		DateTimeImmutable $start,
		DateTimeImmutable $range_start,
		int $duration,
		int $interval,
		string $frequency
	): int {
		if ( $start >= $range_start ) {
			return 0;
		}

		$target_timestamp = max( $start->getTimestamp(), $range_start->getTimestamp() - $duration );
		$target           = ( new DateTimeImmutable( '@' . $target_timestamp ) )->setTimezone( $start->getTimezone() );
		$days             = $start->setTime( 0, 0 )->diff( $target->setTime( 0, 0 ) )->days;
		$days             = false === $days ? 0 : $days;

		switch ( strtoupper( $frequency ) ) {
			case 'DAILY':
				$distance = $days;
				break;
			case 'WEEKLY':
				$distance = intdiv( $days, 7 );
				break;
			case 'MONTHLY':
				$distance = ( (int) $target->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12
					+ (int) $target->format( 'n' ) - (int) $start->format( 'n' );
				break;
			case 'YEARLY':
				$distance = (int) $target->format( 'Y' ) - (int) $start->format( 'Y' );
				break;
			default:
				return 0;
		}

		return max( 0, intdiv( max( 0, $distance ), $interval ) - 1 );
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_rrule( string $rrule ): array {
		$out = array();
		foreach ( explode( ';', $rrule ) as $part ) {
			if ( ! str_contains( $part, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $part, 2 );
			$out[ strtoupper( trim( $key ) ) ] = strtoupper( trim( $value ) );
		}
		return $out;
	}

	private function rrule_until( string $value, DateTimeZone $timezone ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}
		$property = array( 'value' => $value, 'params' => array() );
		$parsed   = $this->parse_date_property( $property, $timezone );
		return null !== $parsed ? $parsed['date'] : null;
	}

	/**
	 * @return array<int,array{ordinal:int,day:string}>
	 */
	private function byday_tokens( string $value ): array {
		$out = array();
		foreach ( explode( ',', $value, 65 ) as $token ) {
			if ( preg_match( '/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', trim( $token ), $matches ) ) {
				$out[] = array(
					'ordinal' => isset( $matches[1] ) && '' !== $matches[1] ? (int) $matches[1] : 0,
					'day'     => $matches[2],
				);
				if ( count( $out ) >= 64 ) {
					break;
				}
			}
		}
		return $out ?: array( array( 'ordinal' => 0, 'day' => 'MO' ) );
	}

	/**
	 * @param array<string,string> $rule Recurrence rule.
	 * @return array<int,DateTimeImmutable>
	 */
	private function monthly_candidates( DateTimeImmutable $base, DateTimeImmutable $start, array $rule ): array {
		$out = array();
		if ( ! empty( $rule['BYMONTHDAY'] ) ) {
			foreach ( array_map( 'intval', explode( ',', $rule['BYMONTHDAY'], 63 ) ) as $day ) {
				if ( 0 === $day || $day < -31 || $day > 31 ) {
					continue;
				}
				$candidate = $this->date_in_month( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), $day, $start );
				if ( null !== $candidate ) {
					$out[] = $candidate;
				}
			}
			return $out;
		}

		if ( ! empty( $rule['BYDAY'] ) ) {
			foreach ( $this->byday_tokens( $rule['BYDAY'] ) as $token ) {
				if ( 0 === $token['ordinal'] ) {
					$days_in_month = (int) $base->format( 't' );
					for ( $day = 1; $day <= $days_in_month; $day++ ) {
						$candidate = $this->date_in_month( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), $day, $start );
						if ( null !== $candidate && (int) $candidate->format( 'N' ) === ( self::WEEKDAYS[ $token['day'] ] ?? 1 ) ) {
							$out[] = $candidate;
						}
					}
				} else {
					$candidate = $this->nth_weekday_of_month( $base, $token['day'], $token['ordinal'], $start );
					if ( null !== $candidate ) {
						$out[] = $candidate;
					}
				}
			}
			return $out;
		}

		$candidate = $this->date_in_month( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), (int) $start->format( 'j' ), $start );
		return null !== $candidate ? array( $candidate ) : array();
	}

	private function date_in_month( int $year, int $month, int $day, DateTimeImmutable $prototype ): ?DateTimeImmutable {
		if ( $month < 1 || $month > 12 || 0 === $day ) {
			return null;
		}
		$days_in_month = (int) $prototype->setDate( $year, $month, 1 )->format( 't' );
		if ( $day < 0 ) {
			$day = $days_in_month + $day + 1;
		}
		if ( $day < 1 || $day > $days_in_month ) {
			return null;
		}
		return $prototype->setDate( $year, $month, $day );
	}

	private function nth_weekday_of_month( DateTimeImmutable $base, string $weekday_token, int $ordinal, DateTimeImmutable $prototype ): ?DateTimeImmutable {
		$target = self::WEEKDAYS[ $weekday_token ] ?? 1;
		$year   = (int) $base->format( 'Y' );
		$month  = (int) $base->format( 'n' );
		$days   = array();

		for ( $day = 1, $max = (int) $base->format( 't' ); $day <= $max; $day++ ) {
			$candidate = $prototype->setDate( $year, $month, $day );
			if ( (int) $candidate->format( 'N' ) === $target ) {
				$days[] = $candidate;
			}
		}

		$index = $ordinal > 0 ? $ordinal - 1 : count( $days ) + $ordinal;
		return $days[ $index ] ?? null;
	}
}
