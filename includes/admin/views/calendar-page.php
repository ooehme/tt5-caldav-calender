<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @var string                  $edit_id
 * @var array<string,mixed>|null $editing
 * @var array<string,array<string,mixed>> $calendars
 * @var array<string,mixed>     $discovery
 * @var string                  $timezone_choice
 * @var int                     $time_offset
 */
?>
		<div class="wrap tt5-caldav-admin">
			<h1><?php esc_html_e( 'CalDAV-Kalender', 'tt5-caldav-calendar' ); ?></h1>
			<p><?php esc_html_e( 'Hier werden Kalender zentral abonniert. Im Block werden anschließend nur Kalender, Zeitraum und Versatz gewählt.', 'tt5-caldav-calendar' ); ?></p>

			<section class="tt5-caldav-admin__panel tt5-caldav-admin__discovery">
				<h2><?php esc_html_e( 'Kalender automatisch ermitteln', 'tt5-caldav-calendar' ); ?></h2>
				<p><?php esc_html_e( 'Geben Sie eine CalDAV-Server-, Principal-, Kalender-Home- oder direkte Kalender-URL ein. Das Plugin sucht die verfügbaren VEVENT-Kalender.', 'tt5-caldav-calendar' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt5-caldav-admin__discover-form">
					<input type="hidden" name="action" value="tt5_caldav_discover">
					<?php wp_nonce_field( 'tt5_caldav_discover' ); ?>
					<p><label for="tt5-discovery-url"><strong><?php esc_html_e( 'Server- oder Principal-URL', 'tt5-caldav-calendar' ); ?></strong></label><br><input class="large-text code" id="tt5-discovery-url" name="url" type="url" required placeholder="https://cloud.example.org/.well-known/caldav"></p>
					<div class="tt5-caldav-admin__discover-credentials">
						<p><label for="tt5-discovery-user"><strong><?php esc_html_e( 'Benutzername', 'tt5-caldav-calendar' ); ?></strong></label><br><input class="regular-text" id="tt5-discovery-user" name="username" autocomplete="username"></p>
						<p><label for="tt5-discovery-password"><strong><?php esc_html_e( 'Passwort / App-Passwort', 'tt5-caldav-calendar' ); ?></strong></label><br><input class="regular-text" id="tt5-discovery-password" name="password" type="password" autocomplete="new-password"></p>
					</div>
					<p><label><input name="verify_ssl" type="checkbox" value="1" checked> <?php esc_html_e( 'SSL-Zertifikat prüfen', 'tt5-caldav-calendar' ); ?></label></p>
					<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Kalender suchen', 'tt5-caldav-calendar' ); ?></button></p>
				</form>

				<?php if ( ! empty( $discovery['calendars'] ) && ! empty( $discovery['token'] ) ) : ?>
					<h3><?php esc_html_e( 'Gefundene Kalender', 'tt5-caldav-calendar' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Die Zugangsdaten werden für diesen Import verschlüsselt und höchstens zehn Minuten zwischengespeichert.', 'tt5-caldav-calendar' ); ?></p>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Anzeigename', 'tt5-caldav-calendar' ); ?></th><th><?php esc_html_e( 'Kalender-URL', 'tt5-caldav-calendar' ); ?></th><th><?php esc_html_e( 'Aktion', 'tt5-caldav-calendar' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $discovery['calendars'] as $index => $found ) : ?>
							<?php $form_id = 'tt5-import-' . absint( $index ); ?>
							<tr>
								<td><input class="regular-text" form="<?php echo esc_attr( $form_id ); ?>" name="name" required value="<?php echo esc_attr( (string) ( $found['name'] ?? '' ) ); ?>"></td>
								<td><code><?php echo esc_html( (string) ( $found['url'] ?? '' ) ); ?></code></td>
								<td>
									<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="tt5_caldav_import_discovered">
										<input type="hidden" name="token" value="<?php echo esc_attr( (string) $discovery['token'] ); ?>">
										<input type="hidden" name="calendar_index" value="<?php echo esc_attr( (string) $index ); ?>">
										<?php wp_nonce_field( 'tt5_caldav_import_discovered' ); ?>
										<button class="button" type="submit"><?php esc_html_e( 'Abonnieren', 'tt5-caldav-calendar' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<div class="tt5-caldav-admin__grid">
				<section class="tt5-caldav-admin__panel">
					<h2><?php esc_html_e( 'Abonnierte Kalender', 'tt5-caldav-calendar' ); ?></h2>
					<?php if ( empty( $calendars ) ) : ?>
						<p><?php esc_html_e( 'Noch kein Kalender eingerichtet.', 'tt5-caldav-calendar' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Name', 'tt5-caldav-calendar' ); ?></th>
								<th><?php esc_html_e( 'URL', 'tt5-caldav-calendar' ); ?></th>
								<th><?php esc_html_e( 'Cache', 'tt5-caldav-calendar' ); ?></th>
								<th><?php esc_html_e( 'Aktionen', 'tt5-caldav-calendar' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $calendars as $id => $calendar ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( (string) $calendar['name'] ); ?></strong><br>
										<small><?php echo esc_html( (string) $calendar['timezone'] ); ?></small>
										<?php if ( ! empty( $calendar['time_offset_minutes'] ) ) : ?>
											<br><small><?php echo esc_html( sprintf( __( 'Zeitkorrektur: %s Stunden', 'tt5-caldav-calendar' ), $this->format_offset_hours( (int) $calendar['time_offset_minutes'], true ) ) ); ?></small>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( (string) $calendar['url'] ); ?></code></td>
									<td><?php echo esc_html( sprintf( _n( '%d Minute', '%d Minuten', (int) $calendar['cache_minutes'], 'tt5-caldav-calendar' ), (int) $calendar['cache_minutes'] ) ); ?></td>
									<td class="tt5-caldav-admin__actions">
										<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'tt5-caldav', 'edit' => $id ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'tt5-caldav-calendar' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="tt5_caldav_test">
											<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
											<?php wp_nonce_field( 'tt5_caldav_test_' . $id ); ?>
											<button class="button button-small" type="submit"><?php esc_html_e( 'Testen', 'tt5-caldav-calendar' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tt5-confirm="<?php esc_attr_e( 'Diesen Kalender wirklich löschen?', 'tt5-caldav-calendar' ); ?>">
											<input type="hidden" name="action" value="tt5_caldav_delete">
											<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
											<?php wp_nonce_field( 'tt5_caldav_delete_' . $id ); ?>
											<button class="button button-small button-link-delete" type="submit"><?php esc_html_e( 'Löschen', 'tt5-caldav-calendar' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt5-caldav-admin__cache-form">
						<input type="hidden" name="action" value="tt5_caldav_clear_cache">
						<?php wp_nonce_field( 'tt5_caldav_clear_cache' ); ?>
						<button class="button" type="submit"><?php esc_html_e( 'Termincache leeren', 'tt5-caldav-calendar' ); ?></button>
					</form>
				</section>

				<section class="tt5-caldav-admin__panel">
					<h2><?php echo $editing ? esc_html__( 'Kalender bearbeiten', 'tt5-caldav-calendar' ) : esc_html__( 'Kalender manuell hinzufügen', 'tt5-caldav-calendar' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tt5_caldav_save">
						<input type="hidden" name="id" value="<?php echo esc_attr( $edit_id ); ?>">
						<?php wp_nonce_field( 'tt5_caldav_save' ); ?>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="tt5-name"><?php esc_html_e( 'Anzeigename', 'tt5-caldav-calendar' ); ?></label></th><td><input class="regular-text" id="tt5-name" name="name" required value="<?php echo esc_attr( (string) ( $editing['name'] ?? '' ) ); ?>"></td></tr>
							<tr><th scope="row"><label for="tt5-url"><?php esc_html_e( 'CalDAV-Kalender-URL', 'tt5-caldav-calendar' ); ?></label></th><td><input class="large-text code" id="tt5-url" name="url" type="url" required placeholder="https://cloud.example.org/remote.php/dav/calendars/user/calendar/" value="<?php echo esc_attr( (string) ( $editing['url'] ?? '' ) ); ?>"><p class="description"><?php esc_html_e( 'Direkte URL der Kalender-Collection.', 'tt5-caldav-calendar' ); ?></p></td></tr>
							<tr><th scope="row"><label for="tt5-user"><?php esc_html_e( 'Benutzername', 'tt5-caldav-calendar' ); ?></label></th><td><input class="regular-text" id="tt5-user" name="username" autocomplete="username" value="<?php echo esc_attr( (string) ( $editing['username'] ?? '' ) ); ?>"></td></tr>
							<tr><th scope="row"><label for="tt5-password"><?php esc_html_e( 'Passwort / App-Passwort', 'tt5-caldav-calendar' ); ?></label></th><td><input class="regular-text" id="tt5-password" name="password" type="password" autocomplete="new-password"><p class="description"><?php echo $editing ? esc_html__( 'Leer lassen, um das gespeicherte Passwort beizubehalten.', 'tt5-caldav-calendar' ) : esc_html__( 'Wird verschlüsselt in der WordPress-Datenbank gespeichert.', 'tt5-caldav-calendar' ); ?></p></td></tr>
							<tr><th scope="row"><label for="tt5-timezone"><?php esc_html_e( 'Kalender-Zeitzone', 'tt5-caldav-calendar' ); ?></label></th><td><select id="tt5-timezone" name="timezone"><?php echo wp_timezone_choice( $timezone_choice, get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></td></tr>
							<tr><th scope="row"><label for="tt5-time-offset"><?php esc_html_e( 'Zeitkorrektur', 'tt5-caldav-calendar' ); ?></label></th><td><input id="tt5-time-offset" name="time_offset_hours" type="number" min="-24" max="24" step="0.25" value="<?php echo esc_attr( $this->format_offset_hours( $time_offset ) ); ?>"> <?php esc_html_e( 'Stunden', 'tt5-caldav-calendar' ); ?><p class="description"><?php esc_html_e( 'Verschiebt nur Termine mit Uhrzeit. Beispiel: +2 korrigiert Termine, die zwei Stunden zu früh angezeigt werden. Ganztägige Termine bleiben unverändert.', 'tt5-caldav-calendar' ); ?></p></td></tr>
							<tr><th scope="row"><label for="tt5-cache"><?php esc_html_e( 'Cache-Dauer', 'tt5-caldav-calendar' ); ?></label></th><td><input id="tt5-cache" name="cache_minutes" type="number" min="1" max="1440" value="<?php echo esc_attr( (string) ( $editing['cache_minutes'] ?? 15 ) ); ?>"> <?php esc_html_e( 'Minuten', 'tt5-caldav-calendar' ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'TLS-Prüfung', 'tt5-caldav-calendar' ); ?></th><td><label><input name="verify_ssl" type="checkbox" value="1" <?php checked( ! isset( $editing['verify_ssl'] ) || ! empty( $editing['verify_ssl'] ) ); ?>> <?php esc_html_e( 'SSL-Zertifikat prüfen', 'tt5-caldav-calendar' ); ?></label><p class="description"><?php esc_html_e( 'Nur bei internen Testservern mit selbstsigniertem Zertifikat abschalten.', 'tt5-caldav-calendar' ); ?></p></td></tr>
						</table>
						<p class="submit">
							<button class="button button-primary" type="submit" name="submit_mode" value="save"><?php esc_html_e( 'Kalender speichern', 'tt5-caldav-calendar' ); ?></button>
							<button class="button" type="submit" name="submit_mode" value="save_test"><?php esc_html_e( 'Speichern und Verbindung testen', 'tt5-caldav-calendar' ); ?></button>
							<?php if ( $editing ) : ?><a class="button-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=tt5-caldav' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'tt5-caldav-calendar' ); ?></a><?php endif; ?>
						</p>
					</form>
				</section>
			</div>
		</div>
