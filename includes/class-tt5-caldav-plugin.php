<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Plugin {
	private static ?self $instance = null;

	private TT5_CalDAV_Repository $repository;
	private TT5_CalDAV_Client $client;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		$this->load_dependencies();
		$this->repository = new TT5_CalDAV_Repository( new TT5_CalDAV_Crypto() );
		$this->client     = new TT5_CalDAV_Client( $this->repository, new TT5_CalDAV_ICal_Parser() );

		( new TT5_CalDAV_Admin( $this->repository, $this->client ) )->register();
		( new TT5_CalDAV_REST( $this->repository, $this->client ) )->register();
		( new TT5_CalDAV_Blocks( $this->repository, $this->client ) )->register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TT5_CALDAV_FILE ), array( $this, 'action_links' ) );
	}

	private function load_dependencies(): void {
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-crypto.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-timezone.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-repository.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-ical-parser.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-client.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-admin.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-rest.php';
		require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-blocks.php';
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'tt5-caldav-calendar', false, dirname( plugin_basename( TT5_CALDAV_FILE ) ) . '/languages' );
	}

	/**
	 * @param array<int,string> $links Plugin action links.
	 * @return array<int,string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=tt5-caldav' ) ) . '">' . esc_html__( 'Kalender', 'tt5-caldav-calendar' ) . '</a>'
		);

		return $links;
	}
}
