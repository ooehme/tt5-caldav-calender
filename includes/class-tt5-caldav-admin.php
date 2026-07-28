<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Admin {
	public function __construct(
		private TT5_CalDAV_Repository $repository,
		private TT5_CalDAV_Client $client
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_tt5_caldav_save', array( $this, 'save' ) );
		add_action( 'admin_post_tt5_caldav_delete', array( $this, 'delete' ) );
		add_action( 'admin_post_tt5_caldav_test', array( $this, 'test' ) );
		add_action( 'admin_post_tt5_caldav_clear_cache', array( $this, 'clear_cache' ) );
		add_action( 'admin_post_tt5_caldav_discover', array( $this, 'discover' ) );
		add_action( 'admin_post_tt5_caldav_import_discovered', array( $this, 'import_discovered' ) );
	}

	public function assets( string $hook_suffix ): void {
		if ( 'settings_page_tt5-caldav' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'tt5-caldav-admin', TT5_CALDAV_URL . 'assets/admin.css', array(), TT5_CALDAV_VERSION );
		wp_enqueue_script( 'tt5-caldav-admin', TT5_CALDAV_URL . 'assets/admin.js', array(), TT5_CALDAV_VERSION, true );
	}

	public function menu(): void {
		add_options_page(
			__( 'CalDAV-Kalender', 'tt5-caldav-calendar' ),
			__( 'CalDAV-Kalender', 'tt5-caldav-calendar' ),
			'manage_options',
			'tt5-caldav',
			array( $this, 'page' )
		);
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$edit_id   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$editing   = '' !== $edit_id ? $this->repository->get( $edit_id ) : null;
		$calendars = $this->repository->all();
		$discovery = get_transient( $this->discovery_key() );
		$discovery = is_array( $discovery ) ? $discovery : array();
		$timezone_choice = TT5_CalDAV_Timezone::choice_value(
			(string) ( $editing['timezone'] ?? wp_timezone_string() )
		);
		$time_offset     = (int) ( $editing['time_offset_minutes'] ?? 0 );
		$this->notice();
		require TT5_CALDAV_DIR . 'includes/admin/views/calendar-page.php';
	}

	public function save(): void {
		$this->guard();
		check_admin_referer( 'tt5_caldav_save' );
		$offset_value = isset( $_POST['time_offset_hours'] ) ? str_replace( ',', '.', (string) wp_unslash( $_POST['time_offset_hours'] ) ) : '0';
		$offset_hours = is_numeric( $offset_value ) ? max( -24, min( 24, (float) $offset_value ) ) : 0;
		$input = array(
			'id'                  => isset( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : '',
			'name'                => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'url'                 => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
			'username'            => isset( $_POST['username'] ) ? wp_unslash( $_POST['username'] ) : '',
			'password'            => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '',
			'timezone'            => isset( $_POST['timezone'] ) ? wp_unslash( $_POST['timezone'] ) : '',
			'time_offset_minutes' => (int) round( $offset_hours * 60 ),
			'cache_minutes'       => isset( $_POST['cache_minutes'] ) ? wp_unslash( $_POST['cache_minutes'] ) : 15,
			'verify_ssl'          => isset( $_POST['verify_ssl'] ),
		);
		$id = $this->repository->save( $input );
		if ( is_wp_error( $id ) ) {
			$this->redirect( 'error', $id->get_error_message(), sanitize_key( (string) $input['id'] ) );
		}

		$mode = isset( $_POST['submit_mode'] ) ? sanitize_key( wp_unslash( $_POST['submit_mode'] ) ) : 'save';
		if ( 'save_test' === $mode ) {
			$result = $this->client->test( (string) $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect( 'error', $result->get_error_message(), (string) $id );
			}
			$this->redirect( 'success', __( 'Kalender gespeichert; die CalDAV-Verbindung funktioniert.', 'tt5-caldav-calendar' ) );
		}
		$this->redirect( 'success', __( 'Kalender gespeichert.', 'tt5-caldav-calendar' ) );
	}

	public function discover(): void {
		$this->guard();
		check_admin_referer( 'tt5_caldav_discover' );
		$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
		$username   = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password   = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$verify_ssl = isset( $_POST['verify_ssl'] );
		$result     = $this->client->discover( $url, $username, $password, $verify_ssl );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		try {
			$protected_password = $this->repository->protect_secret( $password );
		} catch ( RuntimeException $e ) {
			$this->redirect( 'error', __( 'Die Zugangsdaten konnten nicht sicher zwischengespeichert werden.', 'tt5-caldav-calendar' ) );
		}

		$key  = $this->discovery_key();
		$data = array(
			'token'      => wp_generate_password( 32, false, false ),
			'calendars'  => $result,
			'username'   => $username,
			'password'   => $protected_password,
			'verify_ssl' => $verify_ssl,
		);
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		$this->repository->remember_discovery_key( $key );
		$this->redirect(
			'success',
			sprintf(
				/* translators: %d number of discovered calendars. */
				_n( '%d Kalender gefunden.', '%d Kalender gefunden.', count( $result ), 'tt5-caldav-calendar' ),
				count( $result )
			)
		);
	}

	public function import_discovered(): void {
		$this->guard();
		check_admin_referer( 'tt5_caldav_import_discovered' );
		$data  = get_transient( $this->discovery_key() );
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$index = isset( $_POST['calendar_index'] ) ? absint( $_POST['calendar_index'] ) : -1;
		if ( ! is_array( $data ) || empty( $data['token'] ) || ! hash_equals( (string) $data['token'], $token ) || ! isset( $data['calendars'][ $index ] ) ) {
			$this->redirect( 'error', __( 'Die Ermittlung ist abgelaufen. Bitte Kalender erneut suchen.', 'tt5-caldav-calendar' ) );
		}

		try {
			$password = $this->repository->reveal_secret( (string) $data['password'] );
		} catch ( RuntimeException $e ) {
			$this->redirect( 'error', __( 'Die zwischengespeicherten Zugangsdaten konnten nicht entschlüsselt werden.', 'tt5-caldav-calendar' ) );
		}

		$found = $data['calendars'][ $index ];
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : (string) ( $found['name'] ?? '' );
		$id    = $this->repository->save(
			array(
				'name'                => $name,
				'url'                 => (string) ( $found['url'] ?? '' ),
				'username'            => (string) $data['username'],
				'password'            => $password,
				'timezone'            => wp_timezone_string(),
				'time_offset_minutes' => 0,
				'cache_minutes'       => 15,
				'verify_ssl'          => ! empty( $data['verify_ssl'] ),
			)
		);
		if ( is_wp_error( $id ) ) {
			$this->redirect( 'error', $id->get_error_message() );
		}
		$this->redirect( 'success', __( 'Kalender abonniert.', 'tt5-caldav-calendar' ) );
	}

	public function delete(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		check_admin_referer( 'tt5_caldav_delete_' . $id );
		$this->repository->delete( $id );
		$this->redirect( 'success', __( 'Kalender gelöscht.', 'tt5-caldav-calendar' ) );
	}

	public function test(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		check_admin_referer( 'tt5_caldav_test_' . $id );
		$result = $this->client->test( $id );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message(), $id );
		}
		$this->redirect( 'success', __( 'Die CalDAV-Verbindung funktioniert.', 'tt5-caldav-calendar' ) );
	}

	public function clear_cache(): void {
		$this->guard();
		check_admin_referer( 'tt5_caldav_clear_cache' );
		$this->repository->clear_cache();
		$this->redirect( 'success', __( 'Der Termincache wurde geleert.', 'tt5-caldav-calendar' ) );
	}

	private function discovery_key(): string {
		return 'tt5_caldav_discovery_' . get_current_user_id();
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'tt5-caldav-calendar' ) );
		}
	}

	private function format_offset_hours( int $minutes, bool $signed = false ): string {
		$formatted = rtrim( rtrim( number_format( $minutes / 60, 2, '.', '' ), '0' ), '.' );

		if ( $signed && $minutes > 0 ) {
			return '+' . $formatted;
		}

		return $formatted;
	}

	private function redirect( string $type, string $message, string $edit_id = '' ): void {
		$args = array(
			'page'        => 'tt5-caldav',
			'tt5_notice'  => $type,
			'tt5_message' => $message,
		);
		if ( '' !== $edit_id ) {
			$args['edit'] = $edit_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	private function notice(): void {
		$type    = isset( $_GET['tt5_notice'] ) ? sanitize_key( wp_unslash( $_GET['tt5_notice'] ) ) : '';
		$message = isset( $_GET['tt5_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tt5_message'] ) ) : '';
		if ( '' === $type || '' === $message ) {
			return;
		}
		$class = 'success' === $type ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
