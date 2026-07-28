<?php

declare(strict_types=1);

final class TT5_Parser_Test extends TT5_Test_Case {
	protected function test_simple_event(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTART:20260727T100000Z\r\nDTEND:20260727T110000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-07-27',
			'2026-07-28'
		);
		$this->same( 1, count( $events ), 'Simple event count' );
		$this->same( 'Test', $events[0]['title'], 'Simple event title' );
	}

	protected function test_invalid_date_is_rejected(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:invalid\r\nDTSTART;VALUE=DATE:20260230\r\nSUMMARY:Invalid\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-02-01',
			'2026-04-01'
		);
		$this->same( 0, count( $events ), 'Invalid dates must not be normalized silently' );
	}

	protected function test_rdate_does_not_consume_count(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:repeat\r\nDTSTART:20260101T090000Z\r\nDTEND:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nRDATE:20260110T090000Z\r\nSUMMARY:Repeat\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-01-01',
			'2026-01-11'
		);
		$starts = array_column( $events, 'start' );
		sort( $starts );
		$this->same( 3, count( $starts ), 'RDATE is additional to RRULE COUNT' );
		$this->same( strtotime( '2026-01-10 09:00:00 UTC' ), $starts[2], 'RDATE occurrence is retained' );
	}

	protected function test_old_unbounded_recurrence_reaches_current_range(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:old-repeat\r\nDTSTART:19800101T090000Z\r\nDTEND:19800101T100000Z\r\nRRULE:FREQ=DAILY\r\nSUMMARY:Old repeat\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-07-27',
			'2026-07-28'
		);
		$this->same( 1, count( $events ), 'Old unbounded recurrences are fast-forwarded into the requested range' );
		$this->same( strtotime( '2026-07-27 09:00:00 UTC' ), $events[0]['start'], 'Fast-forwarded recurrence date' );
	}

	protected function test_minute_precision_until_is_supported(): void {
		$events = $this->parse(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:until-minutes\r\nDTSTART:20260101T090000Z\r\nDTEND:20260101T100000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260102T0900Z\r\nSUMMARY:Until minutes\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'2026-01-01',
			'2026-01-04'
		);
		$this->same( 2, count( $events ), 'RRULE UNTIL accepts minute precision' );
	}
}
