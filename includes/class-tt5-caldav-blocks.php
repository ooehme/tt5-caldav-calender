<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Blocks {
	/** @var array<string,mixed>|null */
	private static ?array $current_event = null;

	/** @var array<int,array<string,mixed>>|null */
	private static ?array $current_events = null;

	public function __construct(
		private TT5_CalDAV_Repository $repository,
		private TT5_CalDAV_Client $client
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	public function blocks(): void {
		$asset = include TT5_CALDAV_DIR . 'assets/editor.asset.php';
		wp_register_script(
			'tt5-caldav-editor',
			TT5_CALDAV_URL . 'assets/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_localize_script(
			'tt5-caldav-editor',
			'tt5CaldavEditor',
			array(
				'settingsUrl' => admin_url( 'options-general.php?page=tt5-caldav' ),
				'canManage'   => current_user_can( 'manage_options' ),
			)
		);
		wp_set_script_translations( 'tt5-caldav-editor', 'tt5-caldav-calendar', TT5_CALDAV_DIR . 'languages' );

		wp_register_style( 'tt5-caldav-style', TT5_CALDAV_URL . 'assets/style.css', array(), TT5_CALDAV_VERSION );
		wp_register_style( 'tt5-caldav-editor-style', TT5_CALDAV_URL . 'assets/editor.css', array( 'wp-edit-blocks' ), TT5_CALDAV_VERSION );

		register_block_type_from_metadata(
			TT5_CALDAV_DIR . 'blocks/calendar-loop',
			array( 'render_callback' => array( $this, 'render_loop' ) )
		);
		register_block_type_from_metadata(
			TT5_CALDAV_DIR . 'blocks/event-template',
			array( 'render_callback' => array( $this, 'render_event_template' ) )
		);
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-title', array( 'render_callback' => array( $this, 'render_title' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-date', array( 'render_callback' => array( $this, 'render_date' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-time', array( 'render_callback' => array( $this, 'render_time' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-location', array( 'render_callback' => array( $this, 'render_location' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-description', array( 'render_callback' => array( $this, 'render_description' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-link', array( 'render_callback' => array( $this, 'render_link' ) ) );

		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'list', 'label' => __( 'Liste', 'tt5-caldav-calendar' ), 'is_default' => true ) );
		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'compact', 'label' => __( 'Kompakt', 'tt5-caldav-calendar' ) ) );
		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'cards', 'label' => __( 'Karten', 'tt5-caldav-calendar' ) ) );

		$this->patterns();
	}

	/**
	 * @param array<int,array<string,mixed>> $categories Categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function category( array $categories ): array {
		$categories[] = array( 'slug' => 'tt5-caldav', 'title' => __( 'CalDAV', 'tt5-caldav-calendar' ), 'icon' => 'calendar-alt' );
		return $categories;
	}

	/**
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render_loop( array $attributes, string $content, WP_Block $block ): string {
		if ( null !== self::$current_event || null !== self::$current_events ) {
			return current_user_can( 'edit_posts' )
				? '<p class="tt5-caldav-loop__notice">' . esc_html__( 'CalDAV-Terminschleifen können nicht ineinander verschachtelt werden.', 'tt5-caldav-calendar' ) . '</p>'
				: '';
		}

		$calendar_id  = sanitize_key( (string) ( $attributes['calendarId'] ?? '' ) );
		$empty_message = (string) ( $attributes['emptyMessage'] ?? __( 'Keine Termine im gewählten Zeitraum.', 'tt5-caldav-calendar' ) );
		$wrapper       = get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-loop' ) );

		if ( '' === $calendar_id ) {
			return current_user_can( 'edit_posts' )
				? '<div ' . $wrapper . '><p class="tt5-caldav-loop__notice">' . esc_html__( 'Bitte einen CalDAV-Kalender auswählen.', 'tt5-caldav-calendar' ) . '</p></div>'
				: '';
		}

		$days   = min( 730, max( 1, absint( $attributes['days'] ?? 30 ) ) );
		$offset = min( 365, max( -365, (int) ( $attributes['offsetDays'] ?? 0 ) ) );
		$limit  = min( 100, max( 1, absint( $attributes['maxEvents'] ?? 20 ) ) );
		$start  = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( sprintf( '%+d days', $offset ) );
		$end    = $start->modify( '+' . $days . ' days' );
		$events = $this->client->events( $calendar_id, $start, $end, $limit );

		if ( is_wp_error( $events ) ) {
			$message = current_user_can( 'edit_posts' ) ? $events->get_error_message() : __( 'Termine konnten derzeit nicht geladen werden.', 'tt5-caldav-calendar' );
			return '<div ' . $wrapper . '><p class="tt5-caldav-loop__notice">' . esc_html( $message ) . '</p></div>';
		}

		if ( empty( $events ) ) {
			return '<div ' . $wrapper . '><p class="tt5-caldav-loop__empty">' . esc_html( $empty_message ) . '</p></div>';
		}

		$inner_blocks   = $block->parsed_block['innerBlocks'] ?? array();
		$template_block = $this->find_event_template( $inner_blocks );

		if ( null !== $template_block ) {
			self::$current_events = $events;
			$output               = render_block( $template_block );
			self::$current_events = null;
			return '<div ' . $wrapper . '>' . $output . '</div>';
		}

		// Backward compatibility for version 1.0 content without a Termin-Vorlage block.
		$template_blocks = empty( $inner_blocks ) ? $this->default_inner_blocks() : $inner_blocks;
		$items           = '';
		foreach ( $events as $event ) {
			self::$current_event = $event;
			$items              .= '<article class="tt5-caldav-event" role="listitem">' . $this->render_template_blocks( $template_blocks ) . '</article>';
		}
		self::$current_event = null;

		return '<div ' . $wrapper . '><div class="tt5-caldav-loop__items tt5-caldav-loop__items--legacy" role="list">' . $items . '</div></div>';
	}

	/**
	 * Renders the reusable Termin-Vorlage once for every event supplied by the parent loop.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render_event_template( array $attributes, string $content, WP_Block $block ): string {
		$events = self::$current_events;
		if ( empty( $events ) ) {
			return '';
		}

		$template_blocks = $block->parsed_block['innerBlocks'] ?? array();
		if ( empty( $template_blocks ) ) {
			$template_blocks = $this->default_inner_blocks();
		}

		$items = '';
		foreach ( $events as $event ) {
			self::$current_event = $event;
			$items              .= '<article class="tt5-caldav-event" role="listitem">' . $this->render_template_blocks( $template_blocks ) . '</article>';
		}
		self::$current_event = null;

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-loop__items', 'role' => 'list' ) ) . '>' . $items . '</div>';
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_title( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event ) {
			return '';
		}
		$level = min( 6, max( 2, absint( $attributes['level'] ?? 3 ) ) );
		$title = '' !== (string) $event['title'] ? (string) $event['title'] : __( '(Ohne Titel)', 'tt5-caldav-calendar' );
		$text  = esc_html( $title );
		if ( ! empty( $attributes['linkToEvent'] ) && ! empty( $event['url'] ) ) {
			$text = '<a href="' . esc_url( (string) $event['url'] ) . '">' . $text . '</a>';
		}
		return sprintf( '<h%d %s>%s</h%d>', $level, get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__title' ) ), $text, $level );
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_date( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event ) {
			return '';
		}
		$format = sanitize_text_field( (string) ( $attributes['format'] ?? get_option( 'date_format' ) ) );
		$format = '' !== $format ? $format : get_option( 'date_format' );
		$tz     = $this->event_timezone( $event );
		$start  = wp_date( $format, (int) $event['start'], $tz );
		$end_ts = (int) $event['end'];
		if ( ! empty( $event['all_day'] ) ) {
			$end_ts = ( new DateTimeImmutable( '@' . (int) $event['end'] ) )->setTimezone( $tz )->modify( '-1 day' )->getTimestamp();
		}
		$end  = wp_date( $format, max( (int) $event['start'], $end_ts ), $tz );
		$text = $start;
		if ( ! empty( $attributes['showEnd'] ) && $end !== $start ) {
			$text .= (string) ( $attributes['separator'] ?? ' – ' ) . $end;
		}
		$prefix = sanitize_text_field( (string) ( $attributes['prefix'] ?? '' ) );
		return '<p ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__date' ) ) . '>' . esc_html( $prefix . $text ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_time( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event ) {
			return '';
		}
		if ( ! empty( $event['all_day'] ) ) {
			if ( ! empty( $attributes['hideAllDay'] ) ) {
				return '';
			}
			$text = (string) ( $attributes['allDayLabel'] ?? __( 'Ganztägig', 'tt5-caldav-calendar' ) );
		} else {
			$format = sanitize_text_field( (string) ( $attributes['format'] ?? get_option( 'time_format' ) ) );
			$tz     = $this->event_timezone( $event );
			$text   = wp_date( $format, (int) $event['start'], $tz );
			if ( (int) $event['end'] > (int) $event['start'] ) {
				$text .= (string) ( $attributes['separator'] ?? ' – ' ) . wp_date( $format, (int) $event['end'], $tz );
			}
		}
		$prefix = sanitize_text_field( (string) ( $attributes['prefix'] ?? '' ) );
		return '<p ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__time' ) ) . '>' . esc_html( $prefix . $text ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_location( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event || '' === trim( (string) $event['location'] ) ) {
			return '';
		}
		$prefix = sanitize_text_field( (string) ( $attributes['prefix'] ?? '' ) );
		return '<p ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__location' ) ) . '>' . esc_html( $prefix . (string) $event['location'] ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_description( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event || '' === trim( (string) $event['description'] ) ) {
			return '';
		}
		$text   = trim( wp_strip_all_tags( (string) $event['description'] ) );
		$length = min( 2000, max( 0, absint( $attributes['maxLength'] ?? 0 ) ) );
		if ( $length > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			$text = mb_substr( $text, 0, $length ) . '…';
		} elseif ( $length > 0 && strlen( $text ) > $length ) {
			$text = substr( $text, 0, $length ) . '…';
		}
		return '<p ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__description' ) ) . '>' . nl2br( esc_html( $text ) ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function render_link( array $attributes ): string {
		$event = self::$current_event;
		if ( null === $event || empty( $event['url'] ) ) {
			return '';
		}
		$label  = sanitize_text_field( (string) ( $attributes['label'] ?? __( 'Termin öffnen', 'tt5-caldav-calendar' ) ) );
		$target = ! empty( $attributes['newTab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
		return '<p ' . get_block_wrapper_attributes( array( 'class' => 'tt5-caldav-event__link' ) ) . '><a href="' . esc_url( (string) $event['url'] ) . '"' . $target . '>' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private function find_event_template( array $blocks ): ?array {
		foreach ( $blocks as $parsed_block ) {
			if ( 'tt5-caldav/event-template' === ( $parsed_block['blockName'] ?? '' ) ) {
				return $parsed_block;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private function render_template_blocks( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $parsed_block ) {
			$output .= render_block( $parsed_block );
		}
		return $output;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function default_inner_blocks(): array {
		return parse_blocks(
			'<!-- wp:tt5-caldav/event-date /-->' .
			'<!-- wp:tt5-caldav/event-title /-->' .
			'<!-- wp:tt5-caldav/event-time /-->' .
			'<!-- wp:tt5-caldav/event-location /-->'
		);
	}

	/**
	 * @param array<string,mixed> $event Event.
	 */
	private function event_timezone( array $event ): DateTimeZone {
		try {
			return new DateTimeZone( (string) $event['timezone'] );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	private function patterns(): void {
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
