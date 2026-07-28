<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers block assets, metadata, styles, and patterns.
 */
final class TT5_CalDAV_Blocks {
	private TT5_CalDAV_Block_Renderer $renderer;
	private TT5_CalDAV_Block_Patterns $patterns;

	public function __construct( TT5_CalDAV_Client $client ) {
		$this->renderer = new TT5_CalDAV_Block_Renderer( $client );
		$this->patterns = new TT5_CalDAV_Block_Patterns();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	public function blocks(): void {
		$asset = include TT5_CALDAV_DIR . 'assets/editor.asset.php';
		wp_register_script(
			'tt5-caldav-editor-core',
			TT5_CALDAV_URL . 'assets/editor/core.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_register_script(
			'tt5-caldav-editor-calendar-loop',
			TT5_CALDAV_URL . 'assets/editor/calendar-loop.js',
			array( 'tt5-caldav-editor-core' ),
			$asset['version'],
			true
		);
		wp_register_script(
			'tt5-caldav-editor-event-template',
			TT5_CALDAV_URL . 'assets/editor/event-template.js',
			array( 'tt5-caldav-editor-core' ),
			$asset['version'],
			true
		);
		wp_register_script(
			'tt5-caldav-editor-event-fields',
			TT5_CALDAV_URL . 'assets/editor/event-fields.js',
			array( 'tt5-caldav-editor-core' ),
			$asset['version'],
			true
		);
		wp_register_script(
			'tt5-caldav-editor',
			TT5_CALDAV_URL . 'assets/editor.js',
			array(
				'tt5-caldav-editor-calendar-loop',
				'tt5-caldav-editor-event-template',
				'tt5-caldav-editor-event-fields',
			),
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
		foreach ( array( 'tt5-caldav-editor-core', 'tt5-caldav-editor-calendar-loop', 'tt5-caldav-editor-event-template', 'tt5-caldav-editor-event-fields' ) as $handle ) {
			wp_set_script_translations( $handle, 'tt5-caldav-calendar', TT5_CALDAV_DIR . 'languages' );
		}

		wp_register_style( 'tt5-caldav-style', TT5_CALDAV_URL . 'assets/style.css', array(), TT5_CALDAV_VERSION );
		wp_register_style( 'tt5-caldav-editor-style', TT5_CALDAV_URL . 'assets/editor.css', array( 'wp-edit-blocks' ), TT5_CALDAV_VERSION );

		register_block_type_from_metadata(
			TT5_CALDAV_DIR . 'blocks/calendar-loop',
			array( 'render_callback' => array( $this->renderer, 'render_loop' ) )
		);
		register_block_type_from_metadata(
			TT5_CALDAV_DIR . 'blocks/event-template',
			array( 'render_callback' => array( $this->renderer, 'render_event_template' ) )
		);
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-title', array( 'render_callback' => array( $this->renderer, 'render_title' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-date', array( 'render_callback' => array( $this->renderer, 'render_date' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-time', array( 'render_callback' => array( $this->renderer, 'render_time' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-location', array( 'render_callback' => array( $this->renderer, 'render_location' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-description', array( 'render_callback' => array( $this->renderer, 'render_description' ) ) );
		register_block_type_from_metadata( TT5_CALDAV_DIR . 'blocks/event-link', array( 'render_callback' => array( $this->renderer, 'render_link' ) ) );

		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'list', 'label' => __( 'Liste', 'tt5-caldav-calendar' ), 'is_default' => true ) );
		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'compact', 'label' => __( 'Kompakt', 'tt5-caldav-calendar' ) ) );
		register_block_style( 'tt5-caldav/calendar-loop', array( 'name' => 'cards', 'label' => __( 'Karten', 'tt5-caldav-calendar' ) ) );

		$this->patterns->register();
	}

	/**
	 * @param array<int,array<string,mixed>> $categories Categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function category( array $categories ): array {
		$categories[] = array(
			'slug'  => 'tt5-caldav',
			'title' => __( 'CalDAV', 'tt5-caldav-calendar' ),
			'icon'  => 'calendar-alt',
		);
		return $categories;
	}
}
