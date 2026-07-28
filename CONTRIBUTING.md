# Mitwirken

Danke für Beiträge zu TT5 CalDAV Kalender.

## Lokale Prüfung

Benötigt werden PHP 8.0 oder neuer und Node.js 18 oder neuer.

```bash
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/run.php
find assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check
node tests/editor.test.cjs
```

## Pull Requests

1. Einen fokussierten Branch von `main` erstellen.
2. Verhalten und Rückwärtskompatibilität erhalten.
3. Tests für Fehlerkorrekturen oder neue Logik ergänzen.
4. Changelog und Dokumentation bei sichtbaren Änderungen aktualisieren.
5. Die lokalen Prüfungen ausführen und einen kleinen, verständlichen PR eröffnen.

Bitte keine Zugangsdaten, Kalenderinhalte oder personenbezogenen Daten in Issues, Logs oder Tests veröffentlichen.
