# Architektur

Das Plugin hält WordPress-Integration, CalDAV-Kommunikation und Darstellung bewusst getrennt.

## Laufzeit

- `TT5_CalDAV_Plugin` verdrahtet die Komponenten.
- `TT5_CalDAV_Repository` validiert und speichert Kalenderkonfigurationen.
- `TT5_CalDAV_Client` koordiniert Cache, Abfragebereich und Event-Sortierung.
- `TT5_CalDAV_HTTP_Client` kapselt HTTP, Redirect- und Origin-Sicherheit.
- `TT5_CalDAV_WebDAV_Parser` liest Multi-Status- und Calendar-Data-Antworten.
- `TT5_CalDAV_Discovery` ermittelt Principal, Calendar Home und Collections.
- `TT5_CalDAV_ICal_Parser` liest VEVENT-Komponenten.
- `TT5_CalDAV_Recurrence` expandiert unterstützte RRULE-Wiederholungen.

## WordPress-Oberfläche

- `TT5_CalDAV_Admin` verarbeitet Aktionen; die View liegt unter `includes/admin/views`.
- `TT5_CalDAV_Blocks` registriert Assets und Block-Metadaten.
- `TT5_CalDAV_Block_Renderer` rendert dynamische Blöcke.
- `TT5_CalDAV_Block_Patterns` registriert Vorlagen.
- Editor-Code liegt nach Verantwortung getrennt unter `assets/editor`.

## Sicherheitsgrenzen

CalDAV-Zugangsdaten verlassen den Server nicht. Redirects und automatisch ermittelte Endpunkte müssen denselben Origin verwenden. Antwortgrößen sind begrenzt; Admin-Aktionen benötigen Capability-Prüfungen und Nonces.
