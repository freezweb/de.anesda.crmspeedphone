# CRM SpeedPhone

CRM SpeedPhone ist eine schnelle, abarbeitbare Telefonakquise-Warteschlange für SuiteCRM 8. Die Erweiterung verwendet ausschließlich vorhandene Zielkontakt-UUIDs (`Prospects.id`) und legt keine Kontaktkopien an.

## Funktionen

- priorisierte Telefonliste aus einer bestehenden SuiteCRM-Zielkontaktliste
- native Ansicht innerhalb der SuiteCRM-Kopfzeile und Modulnavigation
- je Benutzer nur die ihm zugewiesenen Zielkontakte
- vorhandene Kampagnensignale wie Link-Klick und E-Mail-Öffnung als nachvollziehbare Priorisierung
- Schnellaktionen für „nicht erreicht“, „Rückruf“, „kein Interesse“, „Interesse“, „falsche Nummer“ und „nicht mehr kontaktieren“
- jeder Kontaktversuch wird als regulärer SuiteCRM-Anruf protokolliert
- automatische Wiedervorlage mit zunehmenden Abständen
- Rückrufe werden zusätzlich als geplanter SuiteCRM-Anruf gespeichert
- optionale Aktualisierung der primären E-Mail-Adresse
- optionaler Versand einer konfigurierten SuiteCRM-E-Mail-Vorlage bei bekundetem Interesse
- konfigurierbare Ausschlussmuster, etwa für Branchen oder Organisationstypen
- harte serverseitige Sperre: ausgeschlossene Zielkontakte können auch nicht durch einen direkten API-Aufruf protokolliert oder angemailt werden
- native SuiteCRM-Felder für Auswertung und Berichte

## Voraussetzungen

- SuiteCRM 8.8 oder neuer mit Legacy-Core 7.14
- PHP 8.1 oder neuer
- MariaDB oder MySQL
- Zielkontakte im Modul `Prospects`

## Installation

1. `tools/build.ps1` ausführen.
2. Die erzeugte ZIP-Datei aus `dist/` in SuiteCRM unter **Administration → Module Loader** hochladen.
3. Paket installieren und anschließend einmal **Quick Repair and Rebuild** ausführen, falls der Installer dies nicht automatisch erledigt hat.
4. `custom/CRM/SpeedPhone/config.local.php.example` nach `config.local.php` kopieren und mindestens `source_list_name` konfigurieren.
5. Im Zielkontakte-Menü **CRM SpeedPhone** öffnen. Die Warteschlange erscheint innerhalb der normalen SuiteCRM-Oberfläche.

## Konfiguration

Die veröffentlichte Standardkonfiguration liegt in:

`custom/CRM/SpeedPhone/config.php`

Instanzbezogene Werte gehören ausschließlich nach:

`custom/CRM/SpeedPhone/config.local.php`

Diese Datei wird bei Updates nicht überschrieben und ist nicht Teil des veröffentlichten Pakets. Unterstützte Optionen sind unter anderem:

- `source_list_name`: Name der vorhandenen Zielkontaktliste
- `email_template_name`: Name der SuiteCRM-E-Mail-Vorlage
- `email_sending_enabled`: tatsächlichen Versand aktivieren
- `retry_days`: Abstände der erneuten Anrufversuche
- `max_attempts`: maximale Zahl automatischer Versuche
- `candidate_scan_limit`: maximale Zahl geprüfter Warteschlangeneinträge pro Abruf
- `local_postcode_patterns`: regionale Priorisierung
- `positive_patterns`: zusätzliche Prioritätssignale
- `exclude_patterns`: harte Ausschlussregeln
- `allowed_usernames`: optionale Benutzerfreigabe
- `restrict_to_assigned_user`: auch Administratoren sehen nur ihre eigenen Zielkontakte

## Datenmodell

CRM SpeedPhone erweitert `prospects_cstm` und `calls_cstm`. Die Primärschlüssel dieser Tabellen bleiben die bestehenden SuiteCRM-UUIDs. Ein separater Kontakt- oder Queue-Datensatz wird nicht angelegt.

Der Installer erzeugt eine bislang fehlende `*_cstm`-Tabelle idempotent. Dadurch ist die Installation auch möglich, wenn im Modul „Anrufe“ zuvor noch kein benutzerdefiniertes Feld existierte.

## Datenschutz und Akquise

Das Modul bewertet lediglich technische und vorhandene CRM-Signale. Es ersetzt keine rechtliche Prüfung, ob ein Anruf oder eine E-Mail im Einzelfall zulässig ist. Opt-out, `do_not_call` und konfigurierte Sperrmuster werden vor jeder Anzeige beziehungsweise jedem Versand geprüft.

## Entwicklung

```powershell
php tests/run.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
.\tools\build.ps1
```

## Lizenz

MIT-Lizenz. Copyright © 2026 Anesda UG (haftungsbeschränkt), Memmingen.
