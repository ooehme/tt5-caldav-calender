<?php

declare(strict_types=1);

abstract class TT5_Test_Case {
	private int $assertions = 0;

	final public function run(): int {
		foreach ( get_class_methods( $this ) as $method ) {
			if ( str_starts_with( $method, 'test_' ) ) {
				$this->{$method}();
			}
		}
		return $this->assertions;
	}

	protected function parse( string $ics, string $start, string $end ): array {
		$timezone = new DateTimeZone( 'UTC' );
		$parser   = new TT5_CalDAV_ICal_Parser();
		return $parser->parse(
			$ics,
			$timezone,
			new DateTimeImmutable( $start, $timezone ),
			new DateTimeImmutable( $end, $timezone )
		);
	}

	protected function request( string $url ): string|WP_Error {
		return ( new TT5_CalDAV_HTTP_Client() )->request( $url, 'user', 'secret', true, 'PROPFIND', '<xml />', '0' );
	}

	protected function response( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
			'body'     => $body,
		);
	}

	protected function same( mixed $expected, mixed $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	protected function true( bool $actual, string $message ): void {
		$this->same( true, $actual, $message );
	}
}
