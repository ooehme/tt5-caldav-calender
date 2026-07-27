=== TT5 CalDAV Kalender ===
Contributors: ooehme
Tags: caldav, calendar, gutenberg, block theme, twenty twenty-five
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://oliveroehme.de/werkzeuge/tt5-caldav-calender/

CalDAV-Termine als dynamische, blockbasierte Terminschleife mit zentraler Kontenverwaltung und echter Editor-Vorschau.

== Beschreibung ==

Das Plugin verwaltet CalDAV-Kalender zentral unter Einstellungen > CalDAV-Kalender. Zugangsdaten werden nicht in Beiträgen oder Blockattributen gespeichert. Passwörter werden mit WordPress-Salts und OpenSSL AES-256-GCM oder Sodium verschlüsselt.

Der Block „CalDAV-Terminschleife“ arbeitet nach dem Prinzip Abfrageschleife > Beitrags-Template: Er enthält eine eigene „Termin-Vorlage“, deren Inhalt für jeden gefundenen Termin wiederholt wird. Verfügbare dynamische Blöcke sind Termintitel, Datum, Zeit, Ort, Beschreibung und Link. Innerhalb der Termin-Vorlage können außerdem normale WordPress-Blöcke frei eingesetzt werden, darunter Gruppe, Zeile, Stapel, Spalten, Überschrift, Absatz, Trennlinie und Abstandshalter.

Im Editor ist jeweils ein echter Termin direkt bearbeitbar. Weitere Termine erscheinen als originalgetreue Blockvorschau und können per Klick zum aktiven Bearbeitungstermin gemacht werden. Vorhandene Inhalte aus Version 1.0 werden beim Bearbeiten automatisch in eine Termin-Vorlage migriert.

Funktionen:

* Zentrale Verwaltung mehrerer CalDAV-Kalender
* Automatische Ermittlung über Server-, Principal-, Calendar-Home- oder Kalender-URL
* Verbindungstest und manuelles Leeren des Termincaches
* Einstellbarer Zeitraum, Tagesversatz und maximale Terminanzahl
* Echte CalDAV-Daten im Block-Editor mit umschaltbarer Abfrageschleifen-Vorschau
* Frei zusammenstellbare Termin-Vorlage mit normalen WordPress-Blöcken
* Dynamische serverseitige Ausgabe
* Ganztägige und mehrtägige Termine
* CalDAV-seitige Wiederholungsexpansion nach RFC 4791
* Lokaler Fallback für häufige DAILY-, WEEKLY-, MONTHLY- und YEARLY-Regeln
* Block Supports für Farben, Typografie, Abstände, Ausrichtung und Layout
* Drei Blockmuster: Liste, kompakte Liste und Kartenraster
* Vollständige Deinstallationsroutine einschließlich Optionen und Transients

Das Plugin setzt auf Block Supports und CSS-Variablen des aktiven Block-Themes. Dadurch übernimmt es insbesondere die globalen Stile von Twenty Twenty-Five, ohne eigene Farb- oder Schriftvorgaben zu erzwingen.

== Installation ==

1. ZIP-Datei unter Plugins > Installieren > Plugin hochladen auswählen.
2. Plugin aktivieren.
3. Unter Einstellungen > CalDAV-Kalender Server-/Principal-URL, Benutzername und Passwort oder App-Passwort eingeben.
4. „Kalender suchen“ wählen und die gefundenen Kalender mit „Abonnieren“ übernehmen. Alternativ kann eine direkte Kalender-Collection manuell eingetragen werden.
5. Im Editor den Block „CalDAV-Terminschleife“ einfügen und den Kalender auswählen.
6. Zeitraum, Tagesversatz und maximale Terminanzahl einstellen.
7. In der Listenansicht die „Termin-Vorlage“ öffnen und den Terminaufbau aus beliebigen Blöcken zusammenstellen. Weitere Vorschautermine lassen sich direkt anklicken.

== Häufige Fragen ==

= Werden Passwörter im Beitrag gespeichert? =

Nein. Der Block speichert nur die zufällige Kalender-ID und Anzeigeparameter. Zugangsdaten liegen ausschließlich in einer WordPress-Option und das Passwort wird verschlüsselt gespeichert.

= Funktioniert das Plugin mit internen CalDAV-Servern? =

Ja. Die Verbindung wird serverseitig über die WordPress HTTP API aufgebaut. Für selbstsignierte Zertifikate kann die Zertifikatsprüfung pro Kalender deaktiviert werden; dies sollte nur in kontrollierten internen Netzen erfolgen.

= Welche Wiederholungen werden unterstützt? =

Vorrangig wird die standardisierte CalDAV-Expansion beim Server angefordert. Falls ein Server diese nicht unterstützt, verarbeitet der lokale Fallback häufige tägliche, wöchentliche, monatliche und jährliche Regeln einschließlich INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY, BYMONTH, EXDATE und RDATE. Sehr komplexe Kombinationen wie BYSETPOS können serverseitige Expansion erfordern.

= Was passiert bei geänderten WordPress-Salts? =

Dann kann das gespeicherte Passwort nicht mehr entschlüsselt werden. Das Passwort muss beim betroffenen Kalender erneut eingetragen und gespeichert werden.

== Changelog ==

= 1.2.1 =

* Manuell gewählte UTC-Zeitversätze werden korrekt gespeichert und im Auswahlfeld wiederhergestellt.

= 1.2.0 =

* CalDAV-Zugangsdaten bleiben bei Weiterleitungen auf denselben Server beschränkt.
* Größenlimit für CalDAV-Antworten zum Schutz vor übermäßigem Speicherverbrauch.
* Wiederholungsregeln mit COUNT, UNTIL und RDATE werden effizienter und korrekt verarbeitet.
* Abgelaufene Cache-Schlüssel werden automatisch aus dem Cache-Index entfernt.
* Testen und Löschen von Kalendern verwenden ausschließlich geschützte POST-Anfragen.
* Editor-Aktualisierungen umgehen den Cache nur noch für die ausdrücklich angeforderte Abfrage.
* Automatisierte Regressionstests, CI-Prüfungen und reproduzierbare GitHub-Releases.

= 1.1.0 =

* Eigener Termin-Vorlagenblock nach dem Prinzip der WordPress-Abfrageschleife.
* Frei kombinierbare normale WordPress-Blöcke innerhalb der wiederholten Vorlage.
* Ein echter Termin ist direkt bearbeitbar; weitere Termine werden als Blockvorschau angezeigt und sind anklickbar.
* Native Layout-Steuerung der Termin-Vorlage für Liste und Raster.
* Automatische Migration bestehender 1.0-Terminschleifen in die neue Vorlagenstruktur.

= 1.0.0 =

* Erste veröffentlichungsfähige Version.
