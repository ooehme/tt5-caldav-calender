# TT5 CalDAV Kalender

[![Quality](https://github.com/ooehme/tt5-caldav-calender/actions/workflows/quality.yml/badge.svg)](https://github.com/ooehme/tt5-caldav-calender/actions/workflows/quality.yml)
[![Release](https://img.shields.io/github/v/release/ooehme/tt5-caldav-calender)](https://github.com/ooehme/tt5-caldav-calender/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5_6.7-21759B?logo=wordpress&logoColor=white)](#anforderungen)

Serverseitige CalDAV-Abonnements und frei gestaltbare, dynamische Terminblöcke für WordPress.

## Funktionen

- CalDAV-Server, Principals und Calendar Homes automatisch durchsuchen
- mehrere Kalender zentral und verschlüsselt verwalten
- Termine ausschließlich serverseitig abrufen und zwischenspeichern
- Terminlisten mit nativen WordPress-Blöcken frei gestalten
- wiederkehrende Termine, Ausnahmen und ganztägige Termine verarbeiten
- Zeitzone und Zeitkorrektur pro Kalender konfigurieren
- sichere Same-Origin-Weiterleitungen und begrenzte Antwortgrößen
- Updates über signierte GitHub-Releases in WordPress bereitstellen

## Anforderungen

- WordPress 6.7 oder neuer
- PHP 8.0 oder neuer
- OpenSSL oder Sodium

## Installation

1. Die aktuelle ZIP-Datei unter [Releases](https://github.com/ooehme/tt5-caldav-calender/releases) herunterladen.
2. In WordPress unter **Plugins → Installieren → Plugin hochladen** installieren.
3. Unter **Einstellungen → CalDAV-Kalender** ein Konto einrichten.
4. Im Block-Editor den Block **CalDAV-Terminschleife** einfügen und gestalten.

Direkte Calendar-Collection-URLs können alternativ manuell eingetragen werden.

## Blockaufbau

Die **CalDAV-Terminschleife** enthält genau eine **Termin-Vorlage**. Deren innere Blöcke werden für jeden Termin wiederholt. Verfügbar sind Titel, Datum, Zeit, Ort, Beschreibung und Link; normale WordPress-Blöcke können beliebig kombiniert werden.

Im Editor ist ein Termin direkt bearbeitbar. Weitere Termine erscheinen als Vorschau und können per Klick aktiviert werden. Inhalte aus Plugin-Version 1.0 werden beim Bearbeiten automatisch migriert.

## Entwicklung

```bash
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/run.php
find assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check
node tests/editor.test.cjs
```

Die CI führt diese Prüfungen unter der minimal unterstützten und einer aktuellen PHP-Version aus. Details zu Komponenten und Sicherheitsgrenzen stehen in der [Architekturdokumentation](docs/architecture.md). Hinweise für Beiträge enthält [CONTRIBUTING.md](CONTRIBUTING.md).

## Sicherheit

Zugangsdaten und Kalenderinhalte werden nicht an den Browser ausgeliefert. Passwörter werden authentifiziert verschlüsselt gespeichert. Sicherheitsprobleme bitte gemäß [SECURITY.md](SECURITY.md) privat melden.

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).
