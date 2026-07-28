<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands the recurrence subset supported by the plugin.
 */
final class TT5_CalDAV_Recurrence {
	private const MAX_CANDIDATES = 12000;

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
	 * @param array<string,mixed> $master Master event.
	 * @return array<int,array<string,mixed>>
	 */
	public function expand( array $master, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$rule = $this->parse_rule( (string) $master['rrule'] );
		if ( empty( $rule['FREQ'] ) ) {
			return array( $master );
		}

		$timezone = new DateTimeZone( (string) $master['timezone'] );
		$start    = ( new DateTimeImmutable( '@' . (int) $master['start'] ) )->setTimezone( $timezone );
		$duration = max( 0, (int) $master['end'] - (int) $master['start'] );
		$until    = $this->until( (string) ( $rule['UNTIL'] ?? '' ), $timezone );
		$count    = isset( $rule['COUNT'] ) ? max( 1, absint( $rule['COUNT'] ) ) : null;
		$interval = min( 10000, max( 1, absint( $rule['INTERVAL'] ?? 1 ) ) );
		$limit    = min( self::MAX_CANDIDATES, $count ?? self::MAX_CANDIDATES );
		$seen     = 0;
		$period_offset = null === $count
			? $this->period_offset( $start, $range_start, $duration, $interval, (string) $rule['FREQ'] )
			: 0;
		$candidates       = array( $start );
		$candidate_budget = self::MAX_CANDIDATES - 1;
		$add_candidate    = static function ( DateTimeImmutable $candidate ) use ( &$candidates, &$candidate_budget ): bool {
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
				$bydays      = $this->byday_tokens( (string) ( $rule['BYDAY'] ?? ( $default_day ?: 'MO' ) ) );
				$week_start  = $start->modify( 'monday this week' )->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) );
				for ( $week = $period_offset; $limit-- > 0 && $candidate_budget > 0; ++$week ) {
					$base = $week_start->modify( '+' . ( $week * $interval ) . ' weeks' );
					if ( $base >= $range_end || ( null !== $until && $base > $until ) ) {
						break;
					}
					foreach ( $bydays as $token ) {
						$weekday   = self::WEEKDAYS[ $token['day'] ] ?? 1;
						$candidate = $base->modify( '+' . ( $weekday - 1 ) . ' days' );
						if ( $candidate >= $start && ! $add_candidate( $candidate ) ) {
							break 2;
						}
					}
				}
				break;

			case 'MONTHLY':
				for ( $month = $period_offset; $limit-- > 0 && $candidate_budget > 0; ++$month ) {
					$base = $start->modify( 'first day of this month' )->modify( '+' . ( $month * $interval ) . ' months' );
					if ( $base >= $range_end || ( null !== $until && $base > $until ) ) {
						break;
					}
					foreach ( $this->monthly_candidates( $base, $start, $rule ) as $candidate ) {
						if ( $candidate >= $start && ! $add_candidate( $candidate ) ) {
							break 2;
						}
					}
				}
				break;

			case 'YEARLY':
				$months = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $rule['BYMONTH'] ?? $start->format( 'n' ) ), 13 ) ), static fn( int $month ): bool => $month >= 1 && $month <= 12 ) ) );
				$days   = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) ( $rule['BYMONTHDAY'] ?? $start->format( 'j' ) ), 63 ) ), static fn( int $day ): bool => 0 !== $day && $day >= -31 && $day <= 31 ) ) );
				for ( $year = $period_offset; $limit-- > 0 && $candidate_budget > 0; ++$year ) {
					$year_number = (int) $start->format( 'Y' ) + ( $year * $interval );
					if ( $year_number > (int) $range_end->format( 'Y' ) || ( null !== $until && $year_number > (int) $until->format( 'Y' ) ) ) {
						break;
					}
					foreach ( $months as $month ) {
						foreach ( $days as $day ) {
							$candidate = $this->date_in_month( $year_number, $month, $day, $start );
							if ( null !== $candidate && $candidate >= $start && ! $add_candidate( $candidate ) ) {
								break 3;
							}
						}
					}
				}
				break;
		}

		usort( $candidates, static fn( DateTimeImmutable $a, DateTimeImmutable $b ): int => $a <=> $b );
		$exdates      = array_flip( array_map( 'strval', (array) $master['exdates'] ) );
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

	private function period_offset(
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
	private function parse_rule( string $rrule ): array {
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

	private function until( string $value, DateTimeZone $timezone ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^\d{8}$/', $value ) ) {
			$formats = array( '!Ymd' );
		} elseif ( str_ends_with( $value, 'Z' ) ) {
			$formats  = array( '!Ymd\THis\Z', '!Ymd\THi\Z' );
			$timezone = new DateTimeZone( 'UTC' );
		} else {
			$formats = array( '!Ymd\THis', '!Ymd\THi' );
		}

		foreach ( $formats as $format ) {
			$date   = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $date && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date;
			}
		}
		return null;
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
					for ( $day = 1; $day <= $days_in_month; ++$day ) {
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

		for ( $day = 1, $max = (int) $base->format( 't' ); $day <= $max; ++$day ) {
			$candidate = $prototype->setDate( $year, $month, $day );
			if ( (int) $candidate->format( 'N' ) === $target ) {
				$days[] = $candidate;
			}
		}

		$index = $ordinal > 0 ? $ordinal - 1 : count( $days ) + $ordinal;
		return $days[ $index ] ?? null;
	}
}
