# CRM SpeedPhone

CRM SpeedPhone ist eine schnelle, abarbeitbare Telefonakquise-Warteschlange für SuiteCRM 8. Die Erweiterung verwendet ausschließlich vorhandene Zielkontakt-UUIDs (`Prospects.id`) und legt keine Kontaktkopien an.

## Funktionen

- priorisierte Telefonliste aus einer bestehenden SuiteCRM-Zielkontaktliste
- native Ansicht innerhalb der SuiteCRM-Kopfzeile und Modulnavigation
- automatisch eingeblendete Dashboard-Kachel „SpeedPhone starten“
- gemeinsamer Callcenter-Pool für mehrere gleichzeitig telefonierende Benutzer
- atomare UUID-Reservierung: ein Zielkontakt kann nie gleichzeitig bei zwei Mitarbeitern erscheinen
- administrierbare SpeedPhone-Rollen „Intern“, „Extern“ und „Kein Zugriff“
- automatisch verwaltete SuiteCRM-ACL-Rollen, damit Telefonierer keine globale Administratorrolle benötigen
- eine Reservierung allein erzeugt noch keine dauerhafte Zuordnung; „nicht erreicht“ bleibt im gemeinsamen Pool
- exklusive Betreuung ab dem ersten erreichten Gespräch: der Kontakt erscheint danach keinem anderen externen Mitarbeiter
- selbst angelegte Zielkontakte externer Mitarbeiter werden automatisch und ausschließlich ihrem Ersteller zugeordnet
- aufklappbare Liste „Meine Kontakte“ mit direkten UUID-Links auf zugeordnete Zielkontakte und selbst angelegte SuiteCRM-Interessenten (`Leads`)
- konfigurierbare interne Eskalation bei überfälligen Rückrufen oder zu langer Untätigkeit
- dauerhafte Provisionszuordnung an den externen Mitarbeiter, der das Interesse protokolliert
- sichtbarer Kontaktverlauf mit Mitarbeiter, Zeitpunkt, Ergebnis und Notiz
- Live-Aktualisierung von Kontakt-, Verlauf-, E-Mail-, Kennzahlen- und Dialerstatus alle zehn Sekunden per AJAX
- getrennte Tageskennzahlen für die eigenen und alle tatsächlich protokollierten SpeedPhone-Anrufe
- automatische Verlängerung während der gesamten geöffneten Bearbeitung und Freigabe nach dem Ergebnis
- automatische Freigabe abgelaufener Reservierungen nach konfigurierbarer Zeit
- vorhandene Kampagnensignale wie Link-Klick und E-Mail-Öffnung als nachvollziehbare Priorisierung
- signierte, idempotente Webhooks der eigenen Anesda-Mailplattform für Zustellung, Bounce, Beschwerde, Öffnung, Klick und Abmeldung
- automatische Kennzeichnung ungültiger oder abgemeldeter Adressen aus der eigenen Zustellkette
- chronologische Liste gesendeter Direkt- und Kampagnenmails mit Datum, Uhrzeit, Empfängeradresse und Betreff direkt am aktuellen Kontakt
- Kontaktwechsel und aktualisierte Kennzahlen per AJAX ohne vollständiges Neuladen der Seite
- beliebig wiederholbare Handywahl für denselben Kontakt, etwa wenn besetzt war oder ein Anruf neu gestartet werden muss
- Festnetz-Click-to-Call über Asterisk/FreePBX: zuerst klingelt die persönliche Mitarbeiter-Durchwahl, nach dem Abheben wird die Zielrufnummer aufgebaut
- pro Mitarbeiter administrierbare Festnetz-Durchwahl und nachvollziehbares Journal aller PBX-Wahlaufträge
- automatische Rückruferkennung über die gekoppelte Android-App: eingehende bekannte Nummern öffnen den vorhandenen Zielkontakt im laufenden Portal
- Rückrufereignisse bleiben benutzerbezogen und respektieren bestehende Mehrbenutzer-Reservierungen
- optionale SpeedPhone-Dialer-App für Android und iOS: ein Klick im CRM übergibt die vorhandene Telefonnummer sicher an das eigene Handy
- benutzerbezogene Geräte-Kopplung per kurzlebigem Einmal-QR-Code; die normale Handykamera öffnet die installierte App oder automatisch den passenden Google-/Apple-Store
- der Kopplungscode liegt ausschließlich im URL-Fragment und wird dadurch nicht an den Webserver oder dessen Access-Log übertragen
- Android startet den Anruf nach erteilter Telefonberechtigung direkt; iOS zeigt die systembedingt vorgeschriebene Anrufbestätigung
- Schnellaktionen für „nicht erreicht“, „Rückruf“, „kein Interesse“, „Interesse“, „falsche Nummer“ und „nicht mehr kontaktieren“
- eigene Aktion „E-Mail jetzt senden + wieder anrufen“, die den Kontakt offen lässt und keinen Interessentenstatus setzt
- jeder Kontaktversuch wird als regulärer SuiteCRM-Anruf protokolliert
- automatische Wiedervorlage mit zunehmenden Abständen
- Tageswiedervorlagen werden ohne Uhrzeit wieder in die Liste eingereiht; nur ausdrücklich vereinbarte Uhrzeiten erzeugen zusätzlich einen geplanten SuiteCRM-Anruf
- optionale Aktualisierung der primären E-Mail-Adresse
- optionaler Versand einer konfigurierten SuiteCRM-E-Mail-Vorlage bei bekundetem Interesse
- einmaliger, protokollierter Versand einer im aktuellen Telefonat ausdrücklich angeforderten Informationsmail; vorhandene globale Opt-out- oder Ungültig-Markierungen bleiben unverändert
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
5. Auf dem Dashboard **SpeedPhone starten** oder im Zielkontakte-Menü **CRM SpeedPhone** öffnen.
6. Als berechtigter interner Benutzer über **Team & Provision** die Mitarbeiterrollen, Provisionssätze, Festnetz-Durchwahlen und Eskalationsfristen einstellen.
7. Für die Handywahl **Handy koppeln** öffnen und den QR-Code mit der separaten App „SpeedPhone Dialer“ scannen.
8. Für die Festnetz-Wahl das eingeschränkte Gateway aus `infra/freepbx/` auf der Asterisk-/FreePBX-Anlage installieren und `pbx_gateway_command` instanzbezogen konfigurieren.

Beim Speichern erzeugt das Modul die Rollen `CRM SpeedPhone Extern` und `CRM SpeedPhone Intern` und weist sie den freigeschalteten Benutzern zu. Andere vorhandene SuiteCRM-Rollen werden nicht verändert. Externe erhalten keinen Zugriff auf die allgemeine Zielkontaktliste, können aber den in SpeedPhone angezeigten Datensatz bearbeiten und ihre eigenen Leads öffnen.

## Konfiguration

Die veröffentlichte Standardkonfiguration liegt in:

`custom/CRM/SpeedPhone/config.php`

Instanzbezogene Werte gehören ausschließlich nach:

`custom/CRM/SpeedPhone/config.local.php`

Mail-API- und Webhook-Geheimnisse werden getrennt mit Dateirecht `0640` in
`custom/CRM/SpeedPhone/mail.local.php` gehalten. Diese Datei ist ebenfalls kein
Paketbestandteil und wird bei Updates nicht überschrieben.

Telefonanlagen-Zugangsdaten werden entsprechend mit Dateirecht `0640` in
`custom/CRM/SpeedPhone/pbx.local.php` gehalten. Eine Vorlage liegt als
`pbx.local.php.example` bei; die echte Datei wird nicht paketiert oder überschrieben.

Diese Datei wird bei Updates nicht überschrieben und ist nicht Teil des veröffentlichten Pakets. Unterstützte Optionen sind unter anderem:

- `source_list_name`: Name der vorhandenen Zielkontaktliste
- `email_template_name`: Name der SuiteCRM-E-Mail-Vorlage
- `email_sending_enabled`: tatsächlichen Versand aktivieren
- `mail_webhook_secret`: einmalig vom Mailserver erzeugtes HMAC-Geheimnis; ausschließlich in `config.local.php` mit restriktiven Dateirechten speichern
- `mail_api_enabled`: SpeedPhone-Direktmails über die eigene empfängerbezogene Versand-API senden
- `mail_api_url`, `mail_api_key`, `mail_api_tenant_id`, `mail_api_account_id`: geschützte Zugangsdaten und vorhandene Mandanten-/Konto-IDs der Anesda-Mailplattform
- `retry_days`: Abstände der erneuten Anrufversuche
- `max_attempts`: maximale Zahl automatischer Versuche
- `candidate_scan_limit`: maximale Zahl geprüfter Warteschlangeneinträge pro Abruf
- `local_postcode_patterns`: regionale Priorisierung
- `positive_patterns`: zusätzliche Prioritätssignale
- `exclude_patterns`: harte Ausschlussregeln
- `central_retail_name_pattern`: dauerhaft ausgeschlossene, zentral versorgte Handelsketten
- `medium_business_pattern`: erkennbare mittelständische Betriebe mit höchster regulärer Grundpriorität
- `school_pattern`, `small_business_pattern`: Schulen und Kleinstformate, die erst nach dem Mittelstand erscheinen
- `linkedin_discovery_enabled`: öffentliche Suche nach passenden LinkedIn-Ansprechpartnern beim ersten Öffnen eines Firmenkontakts
- `linkedin_discovery_cache_days`, `linkedin_discovery_max_results`: Cache-Dauer und maximale Zahl angezeigter Profile
- `lock_minutes`: Laufzeit einer Reservierung ohne erfolgreiche Verlängerung
- `default_callback_days`: Vorbelegung des änderbaren Rückrufdatums ohne Uhrzeit, standardmäßig `7` Tage; eine optionale Uhrzeit erzeugt zusätzlich einen festen CRM-Termin
- `callback_escalation_days`: nach wie vielen Tagen ein nicht erledigter externer Rückruf intern sichtbar wird; über die Oberfläche änderbar
- `external_stale_days`: nach wie vielen Tagen ohne Kontaktversuch ein extern betreuter Kontakt intern sichtbar wird; über die Oberfläche änderbar
- `dialer_android_store_url`: öffentliche Google-Play-Adresse der Dialer-App
- `dialer_ios_store_url`: feste App-Store-Adresse der iOS-App; die Standardkonfiguration verweist auf Apple-App-ID `6794342212`
- `pbx_enabled`: Festnetz-Click-to-Call aktivieren
- `pbx_gateway_command`: shellfreie Argumentliste für den SSH-Aufruf des eingeschränkten Telefonanlagen-Gateways; Schlüssel und Known-Hosts-Datei bleiben außerhalb des Webroots
- `pbx_ami_host`, `pbx_ami_port`, `pbx_ami_username`, `pbx_ami_secret`: alternative direkte Anbindung an einen auf den CRM-Server beschränkten Asterisk-AMI-Benutzer
- `pbx_ami_context`, `pbx_ami_timeout_seconds`: Dialplan-Kontext und Verbindungszeitlimit der AMI-Anbindung

### Telefonanlagen-Gateway

Das Gateway unter `infra/freepbx/` nimmt ausschließlich JSON-Wahlaufträge über einen erzwungenen SSH-Befehl an. Es akzeptiert nur explizit freigegebene Mitarbeiter-Durchwahlen und normale Rufnummern, keine MMI-, Feature- oder Dialplan-Steuercodes. Der Asterisk-Callfile ruft zuerst die Mitarbeiter-Durchwahl im Kontext `from-internal` an. Erst wenn dort abgehoben wird, führt Asterisk die Zielrufnummer im selben Kontext aus. Alternativ unterstützt SpeedPhone denselben Ablauf nativ über Asterisk AMI; der AMI-Benutzer sollte dafür nur `call` und `originate` schreiben dürfen und per `permit` auf die Adresse des CRM-Servers begrenzt werden.

Auf der Telefonanlage wird `infra/freepbx/install.sh /pfad/zum/öffentlichen-schlüssel.pub` als `root` ausgeführt. Anschließend werden in `/etc/crm-speedphone-call/config.json` die zulässigen Durchwahlen eingetragen. Der zugehörige private Schlüssel und der feste Host-Key liegen auf dem CRM-Server beispielsweise unter `/etc/crm-speedphone-pbx/`; ein Muster für den vollständigen Befehl steht in `config.local.php.example`.

Die Warteschlange behandelt fällige Rückrufe weiterhin zuerst. Danach folgen
innerhalb der zulässigen Zuordnung erkennbarer Mittelstand, normale
Gewerbebetriebe und erst zuletzt Schulen sowie Kleinstpraxen. Linkklicks,
Mailöffnungen, Regionalität und Versuche sortieren nur noch innerhalb dieser
Geschäftsgrößen-Stufe. Zentral versorgte Handelsketten werden gar nicht als
Kandidaten angeboten.

Für jeden im SpeedPhone geöffneten Firmenkontakt wird außerdem automatisch
nach öffentlich indexierten LinkedIn-Personenprofilen gesucht. Angezeigt werden
Name, öffentlich sichtbare Funktion, direkter Profil-Link und eine
Zuordnungssicherheit. Die Ergebnisse werden je vorhandener Prospect-UUID
zwischengespeichert. Es werden weder LinkedIn-Zugangsdaten verwendet noch
Einladungen oder Nachrichten automatisch versendet.

## Datenmodell

CRM SpeedPhone erweitert `prospects_cstm` und `calls_cstm`. Die Primärschlüssel dieser Tabellen bleiben die bestehenden SuiteCRM-UUIDs. Ein separater Kontakt- oder Queue-Datensatz wird nicht angelegt.

Die Tabelle `crm_speedphone_locks` enthält ausschließlich kurzlebige Reservierungsdaten (`prospect_id`, `user_id`, Token und Zeitstempel). Sie referenziert damit die vorhandenen UUIDs und kopiert keine Kontaktinformationen. Eindeutige Datenbankindizes verhindern doppelte Reservierungen auch bei exakt gleichzeitigen Abrufen.

`crm_speedphone_user_settings` speichert pro vorhandener Benutzer-UUID die SpeedPhone-Rolle, den Provisionssatz, die Modulrechte und optional die persönliche Festnetz-Durchwahl. `crm_speedphone_assignments` referenziert ausschließlich Zielkontakt- und Benutzer-UUIDs und hält Betreuung, letzten Kontaktversuch sowie die bei einem Erfolg eingefrorene Provisionszuordnung fest. Ein Eintrag entsteht beim ersten erreichten Gespräch oder sofort für einen von einem externen Benutzer selbst angelegten Zielkontakt. `crm_speedphone_options` enthält die beiden Eskalationsfristen. Es werden weiterhin keine Kontaktkopien angelegt.

Die Dialer-Tabellen speichern Geräte, kurzlebige Kopplungen und Anrufaufträge. Jeder Auftrag referenziert den vorhandenen Zielkontakt ausschließlich über dessen UUID. Kopplungscodes und dauerhafte Gerätetoken werden serverseitig nur als SHA-256-Hash gespeichert; Telefonnummern in Anrufaufträgen verfallen nach zwei Minuten.

`crm_speedphone_incoming_calls` speichert bei einem zugeordneten Rückruf ausschließlich Ereignis-, Geräte-, Benutzer- und Zielkontakt-UUID sowie Eingangs- und Öffnungszeitpunkt. Die eingehende Telefonnummer wird nicht in einer zusätzlichen SpeedPhone-Tabelle gespeichert. Nicht zuordenbare Nummern erzeugen keinen Ereignisdatensatz.

`crm_speedphone_pbx_calls` protokolliert jeden Festnetz-Wahlauftrag mit vorhandener Benutzer- und Zielkontakt-UUID, verwendeter Durchwahl, Zielrufnummer, Zeitstempel sowie Annahme oder Fehlermeldung des Gateways. Das eigentliche Gesprächsergebnis wird weiterhin erst über die Schnellaktion als regulärer SuiteCRM-Anruf dokumentiert.

`crm_speedphone_mail_webhook_events` hält die eindeutige Ereignis-UUID, den signierten Nutzdaten-Hash, Verarbeitungszustand und die referenzierte Kampagnenaktivität. Dadurch bleiben Wiederholungsversuche nachvollziehbar, erzeugen aber keine doppelten Öffnungs-, Klick- oder Bounce-Aktivitäten. Das Webhook-Geheimnis wird weder in dieser Tabelle noch im Paket gespeichert.

Nach der Umstellung werden der frühere öffentliche Brevo-Webhook und das
Brevo-Backfill-Skript durch die Vorlagen unter `tools/brevo-*-retired.php`
ersetzt. Die Vorlagen enthalten bewusst weder Datenbankkennwörter noch Brevo-
API-Schlüssel; historische CRM-Aktivitäten bleiben unverändert erhalten.

Der Installer erzeugt eine bislang fehlende `*_cstm`-Tabelle idempotent. Dadurch ist die Installation auch möglich, wenn im Modul „Anrufe“ zuvor noch kein benutzerdefiniertes Feld existierte.
Beim Update werden außerdem ältere SpeedPhone-Anrufnamen einmalig in das strukturierte Ergebnisfeld übernommen. Nur tatsächlich erreichte Gespräche erzeugen daraus eine Betreuung; „nicht erreicht“, „falsche Nummer“ und reine Verschiebungen tun das nicht.

## Datenschutz und Akquise

Das Modul bewertet lediglich technische und vorhandene CRM-Signale. Es ersetzt keine rechtliche Prüfung, ob ein Anruf oder eine E-Mail im Einzelfall zulässig ist. Opt-out, `do_not_call` und konfigurierte Sperrmuster werden vor jeder Anzeige beziehungsweise jedem Versand geprüft. Eine im laufenden Telefonat ausdrücklich angeforderte einzelne Informationsmail kann nach gesonderter Bestätigung einmalig versendet werden. SpeedPhone protokolliert diese Ausnahme, ändert aber weder Opt-out noch die Kennzeichnung als ungültige Adresse; weitere E-Mails bleiben damit gesperrt.

## Entwicklung

```powershell
php tests/run.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
.\tools\build.ps1
```

## Lizenz

MIT-Lizenz. Copyright © 2026 Anesda UG (haftungsbeschränkt), Memmingen.
