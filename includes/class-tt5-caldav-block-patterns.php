<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers ready-to-use block patterns.
 */
final class TT5_CalDAV_Block_Patterns {
	public function register(): void {
		register_block_pattern_category( 'tt5-caldav', array( 'label' => __( 'CalDAV', 'tt5-caldav-calendar' ) ) );

		register_block_pattern(
			'tt5-caldav/simple-list',
			array(
				'title'      => __( 'CalDAV: Einfache Terminliste', 'tt5-caldav-calendar' ),
				'categories' => array( 'tt5-caldav' ),
				'content'    => '<!-- wp:tt5-caldav/calendar-loop --><!-- wp:tt5-caldav/event-template {"layout":{"type":"grid","columnCount":1}} --><!-- wp:tt5-caldav/event-date /--><!-- wp:tt5-caldav/event-title /--><!-- wp:tt5-caldav/event-time /--><!-- wp:tt5-caldav/event-location /--><!-- /wp:tt5-caldav/event-template --><!-- /wp:tt5-caldav/calendar-loop -->',
			)
		);
		register_block_pattern(
			'tt5-caldav/compact-list',
			array(
				'title'      => __( 'CalDAV: Kompakte Terminliste', 'tt5-caldav-calendar' ),
				'categories' => array( 'tt5-caldav' ),
				'content'    => '<!-- wp:tt5-caldav/calendar-loop {"className":"is-style-compact"} --><!-- wp:tt5-caldav/event-template {"layout":{"type":"grid","columnCount":1}} --><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} --><div class="wp-block-group"><!-- wp:tt5-caldav/event-date {"format":"D, j. M."} /--><!-- wp:tt5-caldav/event-time {"hideAllDay":false} /--><!-- wp:tt5-caldav/event-title {"level":3} /--></div><!-- /wp:group --><!-- /wp:tt5-caldav/event-template --><!-- /wp:tt5-caldav/calendar-loop -->',
			)
		);
		register_block_pattern(
			'tt5-caldav/card-grid',
			array(
				'title'      => __( 'CalDAV: Kartenraster', 'tt5-caldav-calendar' ),
				'categories' => array( 'tt5-caldav' ),
				'content'    => '<!-- wp:tt5-caldav/calendar-loop {"className":"is-style-cards"} --><!-- wp:tt5-caldav/event-template {"layout":{"type":"grid","columnCount":3}} --><!-- wp:tt5-caldav/event-date /--><!-- wp:tt5-caldav/event-title /--><!-- wp:tt5-caldav/event-time /--><!-- wp:tt5-caldav/event-location /--><!-- wp:tt5-caldav/event-description {"maxLength":180} /--><!-- wp:tt5-caldav/event-link /--><!-- /wp:tt5-caldav/event-template --><!-- /wp:tt5-caldav/calendar-loop -->',
			)
		);
	}
}
