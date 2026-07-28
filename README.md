# TT5 CalDAV Kalender

Installierbares WordPress-Plugin für zentrale CalDAV-Abonnements und eine frei gestaltbare, blockbasierte Terminschleife.

**Autor:** [Oliver Oehme](https://oliveroehme.de/)

**Projektseite:** [oliveroehme.de/werkzeuge/tt5-caldav-calender](https://oliveroehme.de/werkzeuge/tt5-caldav-calender/)

## Anforderungen

- WordPress 6.7 oder neuer
- PHP 8.0 oder neuer
- OpenSSL oder Sodium

## Installation

Die ZIP-Datei über **Plugins → Installieren → Plugin hochladen** installieren. Danach unter **Einstellungen → CalDAV-Kalender** eine Server- oder Principal-URL prüfen, verfügbare Kalender automatisch ermitteln und abonnieren. Direkte Kalender-Collection-URLs können weiterhin manuell eingetragen werden. Fehlerhaft verschobene Uhrzeiten lassen sich dort mit einer Zeitkorrektur je Kalender ausgleichen.

## Blockaufbau

Der Block **CalDAV-Terminschleife** enthält genau eine **Termin-Vorlage**. Alle Blöcke innerhalb dieser Vorlage werden für jeden gefundenen Termin wiederholt. Neben Termintitel, Datum, Zeit, Ort, Beschreibung und Link können normale WordPress-Blöcke wie Gruppe, Zeile, Stapel, Spalten, Überschrift, Absatz, Trennlinie oder Abstandshalter frei eingesetzt werden.

Im Editor ist jeweils ein echter Termin direkt bearbeitbar. Weitere Termine werden wie bei der WordPress-Abfrageschleife als Vorschau angezeigt und können per Klick zum aktiven Bearbeitungstermin gemacht werden. Bestehende Blöcke aus Version 1.0 werden beim Bearbeiten automatisch in eine Termin-Vorlage übernommen.

## Datenschutz und Sicherheit

Der Browser erhält weder Benutzername noch Passwort. CalDAV-Anfragen laufen serverseitig. Passwörter werden authentifiziert verschlüsselt gespeichert. Die Editor-REST-Routen sind auf angemeldete Benutzer mit `edit_posts` beschränkt; Kontenänderungen erfordern `manage_options` und Nonces.

Weiterleitungen und automatisch ermittelte CalDAV-Endpunkte dürfen den ursprünglichen Server nicht verlassen. Serverantworten sind auf 8 MiB begrenzt.

## Releases

Installierbare ZIP-Dateien und SHA-256-Prüfsummen werden für Versions-Tags automatisch unter [GitHub Releases](https://github.com/ooehme/tt5-caldav-calender/releases) erzeugt.
