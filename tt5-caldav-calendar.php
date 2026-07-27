<?php
/**
 * Plugin Name:       TT5 CalDAV Kalender
 * Plugin URI:        https://oliveroehme.de/werkzeuge/tt5-caldav-calender/
 * Description:       Zeigt CalDAV-Termine in einem dynamischen, blockbasierten Kalender-Loop an und übernimmt die globalen Stile des aktiven Block-Themes.
 * Version:           1.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.0
 * Author:            Oliver Oehme
 * Author URI:        https://oliveroehme.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tt5-caldav-calendar
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TT5_CALDAV_VERSION', '1.1.0' );
define( 'TT5_CALDAV_FILE', __FILE__ );
define( 'TT5_CALDAV_DIR', plugin_dir_path( __FILE__ ) );
define( 'TT5_CALDAV_URL', plugin_dir_url( __FILE__ ) );

require_once TT5_CALDAV_DIR . 'includes/class-tt5-caldav-plugin.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'TT5 CalDAV Kalender benötigt PHP 8.0 oder neuer.', 'tt5-caldav-calendar' ),
				esc_html__( 'Plugin konnte nicht aktiviert werden', 'tt5-caldav-calendar' ),
				array( 'back_link' => true )
			);
		}


		if ( ! function_exists( 'openssl_encrypt' ) && ! function_exists( 'sodium_crypto_secretbox' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Für die verschlüsselte Speicherung der CalDAV-Passwörter wird OpenSSL oder Sodium benötigt.', 'tt5-caldav-calendar' ),
				esc_html__( 'Plugin konnte nicht aktiviert werden', 'tt5-caldav-calendar' ),
				array( 'back_link' => true )
			);
		}

		update_option( 'tt5_caldav_version', TT5_CALDAV_VERSION, false );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		TT5_CalDAV_Plugin::instance()->init();
	}
);
