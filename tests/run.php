<?php

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/BusinessDayCalculator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/InputValidator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/AssignmentService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/DialerService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/IncomingCallService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/render.php';

use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\InputValidator;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\DialerService;
use Anesda\CRM\SpeedPhone\IncomingCallService;

$failures = [];

if (!class_exists('SugarBean')) {
    class SugarBean
    {
        public string $id = '';
        public string $created_by = '';
        public array $fetched_row = [];
    }
}

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$calculator = new BusinessDayCalculator();
$friday = new DateTimeImmutable('2026-07-17 09:00:00', new DateTimeZone('UTC'));
check($calculator->addBusinessDays($friday, 1)->format('Y-m-d') === '2026-07-20', 'Freitag + 1 Werktag muss Montag ergeben.');
check($calculator->addBusinessDays($friday, 2)->format('Y-m-d') === '2026-07-21', 'Freitag + 2 Werktage muss Dienstag ergeben.');
check($calculator->addBusinessDays($friday, 0)->format('Y-m-d') === '2026-07-17', '0 Werktage darf das Datum nicht verändern.');

$validator = new InputValidator();
check($validator->uuid('befc6200-da8e-47a5-9fc8-3b30e8451018') === 'befc6200-da8e-47a5-9fc8-3b30e8451018', 'Gültige UUID wurde abgelehnt.');
check($validator->action('interested') === 'interested', 'Gültige Aktion wurde abgelehnt.');
check($validator->action('email_callback') === 'email_callback', 'E-Mail mit Rückruf wurde als Aktion abgelehnt.');
check($validator->email('info@example.org') === 'info@example.org', 'Gültige E-Mail wurde abgelehnt.');
check($validator->email('') === '', 'Leere optionale E-Mail wurde abgelehnt.');
check(!AssignmentService::actionAssignsOwner('not_reached'), 'Ein erfolgloser Anruf darf keinen Besitzer erzeugen.');
check(!AssignmentService::actionAssignsOwner('wrong_number'), 'Eine falsche Nummer darf keinen Besitzer erzeugen.');
check(!AssignmentService::actionAssignsOwner('later'), 'Ein Verschieben ohne Anruf darf keinen Besitzer erzeugen.');
check(AssignmentService::actionAssignsOwner('callback'), 'Ein vereinbarter RÃ¼ckruf muss den Kontakt zuordnen.');
check(AssignmentService::actionAssignsOwner('email_callback'), 'Ein E-Mail-Wunsch muss den Kontakt zuordnen.');
check(AssignmentService::actionAssignsOwner('interested'), 'Ein Interessent muss dem erfolgreichen Mitarbeiter zugeordnet werden.');
check(
    str_contains(
        file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/AssignmentService.php'),
        'sqlIncomingAccessCondition'
    ),
    'Interne Benutzer können einen Rückrufer nicht unabhängig von der Provisionszuordnung öffnen.'
);
check(DialerService::normalizePhone('+49 (0) 431 265189') === '+490431265189', 'Telefonnummer wird nicht sicher normalisiert.');
check(
    IncomingCallService::phoneVariants('+49 (0) 331 2882214')
        === ['4903312882214', '493312882214', '03312882214'],
    'Internationale Rückrufnummer mit optionaler Null wird nicht zuverlässig zugeordnet.'
);
check(
    in_array('493312882214', IncomingCallService::phoneVariants('0331 2882214'), true),
    'Nationale Rückrufnummer erhält keine deutsche internationale Vergleichsform.'
);
try {
    DialerService::normalizePhone('*21*123#');
    check(false, 'MMI-Steuercodes dürfen nicht als Telefonnummer akzeptiert werden.');
} catch (InvalidArgumentException) {
}

require_once __DIR__ . '/../module/copy/custom/modules/Prospects/SpeedPhoneProspectHook.php';
$existingProspect = new SugarBean();
$existingProspect->id = 'befc6200-da8e-47a5-9fc8-3b30e8451018';
$existingProspect->created_by = '12fc6200-da8e-47a5-9fc8-3b30e8451000';
$existingProspect->fetched_row = ['id' => $existingProspect->id];
try {
    (new SpeedPhoneProspectHook())->assignExternalCreator($existingProspect, 'after_save', 'SpeedPhone-Rückruf');
    check(true, 'String-Argument des SuiteCRM-Hooks wurde akzeptiert.');
} catch (TypeError $error) {
    check(false, 'Der SuiteCRM-Hook muss String-Argumente beim Rückruf-Speichern akzeptieren: ' . $error->getMessage());
}

$exampleConfig = require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/config.local.php.example';
check(
    preg_match('/anwalt|anwält|rechtsanw|kanzlei|notar/iu', implode(' ', $exampleConfig['exclude_patterns'])) !== 1,
    'Die veröffentlichte Modulkonfiguration darf keine besondere Anwalts-Sperre enthalten.'
);

$workspace = speedPhoneRenderWorkspace([
    'id' => 'befc6200-da8e-47a5-9fc8-3b30e8451018',
    'score' => 10,
    'name' => 'Beispielbetrieb',
    'primary_address_postalcode' => '12345',
    'primary_address_city' => 'Musterstadt',
    'phone_work' => '+49 123 456',
    'phone_mobile' => '',
    'email' => 'info@example.org',
    'website' => 'https://example.org',
    'reasons' => ['Passende Unternehmensart'],
    'speedphone_attempts' => 1,
    'sent_emails' => [[
        'subject' => 'Informationsmail',
        'recipient' => 'info@example.org',
        'sent_at' => '2026-07-21 08:00:00',
        'source' => 'Testkampagne',
        'opened' => 1,
        'clicked' => 0,
    ]],
    'recent_calls' => [[
        'name' => 'SpeedPhone: Nicht erreicht',
        'status' => 'Held',
        'date_start' => '2026-07-21 09:00:00',
        'description' => 'Zentrale nicht besetzt',
        'speedphone_result' => 'not_reached',
        'caller_name' => 'Max Mustermann',
        'caller_username' => 'maxmustermann',
    ]],
    'assignment' => [
        'owner_user_id' => '12fc6200-da8e-47a5-9fc8-3b30e8451000',
        'owner_name' => 'Jessica Wendt',
        'owner_type' => 'external',
        'owner_commission_percent' => 20,
        'is_escalated' => true,
        'won_by_user_id' => null,
    ],
    'current_profile' => [
        'user_id' => 'befc6200-da8e-47a5-9fc8-3b30e8451018',
        'user_type' => 'internal',
        'commission_percent' => 0,
    ],
    'lock_token' => str_repeat('a', 64),
], 'Europe/Berlin', 7, [[
    'id' => '12fc6200-da8e-47a5-9fc8-3b30e8451000',
    'device_name' => 'Testhandy',
    'platform' => 'android',
    'is_ready' => 1,
]]);
check(str_contains($workspace, 'Gesendete E-Mails'), 'E-Mail-Historie fehlt im gerenderten Kontakt.');
check(str_contains($workspace, 'info@example.org'), 'Empfängeradresse fehlt in der E-Mail-Historie.');
check(str_contains($workspace, 'value="email_callback"'), 'Aktion „E-Mail jetzt senden + wieder anrufen“ fehlt.');
check(preg_match('/name="callback_date"[^>]*value="\d{4}-\d{2}-\d{2}"/', $workspace) === 1, 'Rückrufdatum ist nicht vorbelegt.');
check(preg_match('/name="callback_date"[^>]*min="\d{4}-\d{2}-\d{2}"/', $workspace) === 1, 'Rückrufdatum verhindert keine vergangenen Tage.');
check(str_contains($workspace, 'name="callback_time"'), 'Optionale Uhrzeit für einen festen Rückruftermin fehlt.');
check(str_contains($workspace, 'Ohne Uhrzeit:'), 'Unterschied zwischen Tagesliste und festem Termin wird nicht erklärt.');
check(str_contains($workspace, 'E-Mail jetzt senden + wieder anrufen'), 'E-Mail-Wiedervorlage ist nicht eindeutig beschriftet.');
check(str_contains($workspace, 'name="email_address_confirmed"'), 'Bestätigung für eine ausdrücklich angeforderte Einzelmail fehlt.');
check(str_contains($workspace, 'data-speedphone-email-retry'), 'Wiederholungsaktion für fehlgeschlagene E-Mails fehlt.');
check(str_contains($workspace, 'Jessica Wendt'), 'Zugeordneter externer Mitarbeiter fehlt am Kontakt.');
check(str_contains($workspace, '20,00 %'), 'Provisionssatz fehlt am Kontakt.');
check(str_contains($workspace, 'interne Team freigegeben'), 'Interne Eskalation wird nicht sichtbar erklärt.');
check(str_contains($workspace, 'Kontaktverlauf'), 'Nachvollziehbarer Kontaktverlauf fehlt.');
check(str_contains($workspace, 'Max Mustermann'), 'Anrufender Mitarbeiter fehlt im Kontaktverlauf.');
check(str_contains($workspace, 'data-speedphone-live-status'), 'Sichtbarer Status der Live-Reservierung fehlt.');
check(speedPhoneResultLabel('not_reached') === 'Nicht erreicht', 'Anrufergebnis wird nicht lesbar übersetzt.');

$emailServiceSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/EmailService.php');
check(str_contains($workspace, 'data-speedphone-dialer-call="work"'), 'Schaltfläche zum Anruf über das gekoppelte Handy fehlt.');
check(!str_contains($workspace, 'data-speedphone-dialer-call="work" disabled'), 'Handywahl bleibt trotz empfangsbereitem Gerät gesperrt.');
check(str_contains($emailServiceSource, 'explicitOneTimeRequest'), 'Einmalige ausdrückliche Versandfreigabe fehlt im E-Mail-Dienst.');
check(str_contains($emailServiceSource, 'die globale E-Mail-Sperre bleibt bestehen'), 'Fortbestand der globalen E-Mail-Sperre wird nicht bestätigt.');
check(!preg_match('/UPDATE\s+email_addresses/i', $emailServiceSource), 'Die einmalige Freigabe darf globale E-Mail-Sperrmerkmale nicht löschen.');

$apiSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/api.php');
check(str_contains($apiSource, "'dialer_pairing'"), 'API-Aktion zur QR-Kopplung fehlt.');
check(str_contains($apiSource, "'dialer_call'"), 'API-Aktion zur Handywahl fehlt.');
check(str_contains($apiSource, "'refresh_current'"), 'AJAX-Aktualisierung des reservierten Kontakts fehlt.');
check(str_contains($apiSource, 'openPendingForCurrentUser'), 'Eingehende Rückrufe werden im Portal nicht automatisch geöffnet.');

$queueSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/QueueService.php');
check(str_contains($queueSource, 'getCurrentCandidate'), 'Aktueller Kontakt kann nicht ohne Warteschlangenwechsel aktualisiert werden.');
check(
    str_contains($queueSource, 'erwirbt bewusst keine neue Reservierung'),
    'Live-Aktualisierung muss ausdrücklich ohne neue Kontaktreservierung arbeiten.'
);

$javascriptSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/assets/speedphone.js');
check(str_contains($javascriptSource, "data.set('operation', 'refresh_current')"), 'Browser fragt keine aktuellen Kontaktdaten per AJAX ab.');
check(str_contains($javascriptSource, 'LIVE_UPDATE_INTERVAL_MS = 10000'), 'Live-Aktualisierung läuft nicht im vorgesehenen Intervall.');
check(
    str_contains($javascriptSource, "root.dataset.speedphoneInitialized = 'true'"),
    'Die Browserinitialisierung ist nicht gegen eine doppelte Skriptausführung abgesichert.'
);
check(str_contains($javascriptSource, 'currentMain.replaceWith(incomingMain)'), 'Live-Aktualisierung schützt das laufend bearbeitete Eingabeformular nicht.');
check(str_contains($javascriptSource, 'payload.data.incoming_call'), 'Browser reagiert nicht auf eingehende Rückrufereignisse.');
check(str_contains($javascriptSource, 'storeCurrentDraft'), 'Ein Rückrufwechsel schützt laufende Formulareingaben nicht.');
check(
    preg_match('/finally\\s*\\{.*?dialButton\\.disabled\\s*=\\s*false;/s', $javascriptSource) === 1,
    'Handywahl wird nach einem Versuch nicht wieder freigegeben.'
);

$manifestSource = file_get_contents(__DIR__ . '/../module/manifest.php');
$pageSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/page.php');
$versionMatch = [];
check(
    preg_match("/'version'\\s*=>\\s*'([^']+)'/", $manifestSource, $versionMatch) === 1,
    'Die Modulversion konnte nicht aus dem Manifest gelesen werden.'
);
$assetVersion = $versionMatch[1] ?? '';
check(
    $assetVersion !== ''
        && str_contains($pageSource, '/speedphone.css?v=' . $assetVersion)
        && str_contains($pageSource, '/speedphone.js?v=' . $assetVersion),
    'CSS und JavaScript müssen mit der aktuellen Modulversion aus dem Browsercache geladen werden.'
);

$dialerEntryPointSource = file_get_contents(__DIR__ . '/../module/copy/custom/Extension/application/Ext/EntryPointRegistry/crm_speedphone.php');
check(str_contains($dialerEntryPointSource, "'auth' => false"), 'Die native App erreicht den token-geschützten Dialer-Endpunkt nicht ohne CRM-Sitzung.');
check(
    str_contains($dialerEntryPointSource, 'crmSpeedPhoneDialerSetup'),
    'Öffentliche Installations- und Kopplungsseite fehlt.'
);
$dialerApiSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/dialer_api.php');
check(str_contains($dialerApiSource, "'claim_pairing'"), 'Öffentliche API kann den QR-Einmalcode nicht einlösen.');
check(str_contains($dialerApiSource, "'incoming_call'"), 'Gekoppelte App kann keinen eingehenden Rückruf melden.');
check(!str_contains($dialerApiSource, 'crm_speedphone_csrf'), 'Native Geräteauthentifizierung darf nicht von einer Browser-CSRF-Sitzung abhängen.');
check(str_contains($apiSource, "'resend_email'"), 'API-Aktion zum Wiederholen ohne zweiten Anruf fehlt.');

$dialerServiceSource = file_get_contents(
    __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/DialerService.php'
);
check(
    str_contains($dialerServiceSource, "'#setup='"),
    'QR-Code führt nicht über die geräteabhängige Installationsseite.'
);
$dialerSetupSource = file_get_contents(
    __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/dialer_setup.php'
);
check(
    str_contains($dialerSetupSource, 'Android') && str_contains($dialerSetupSource, 'iPhone'),
    'Installationsseite unterscheidet Android und iPhone nicht.'
);
check(
    str_contains($dialerSetupSource, 'Referrer-Policy: no-referrer'),
    'Installationsseite schützt den kurzlebigen Kopplungscode nicht vor Referrer-Weitergabe.'
);

$installerSource = file_get_contents(__DIR__ . '/../module/scripts/post_install.php');
check(
    str_contains($installerSource, 'crm_speedphone_incoming_calls'),
    'Installer legt die UUID-basierte Tabelle für Rückrufereignisse nicht an.'
);
check(
    str_contains($installerSource, '$repair->clearDashlets()'),
    'Installer leert den Dashlet-Cache nicht, damit der Dashboard-Einstieg registriert wird.'
);
check(
    str_contains($installerSource, "'module' => 'Home'"),
    'Dashboard-Einstieg darf nicht vom Listenrecht der Zielkontakte abhängen.'
);

$speedPhoneViewSource = file_get_contents(
    __DIR__ . '/../module/copy/custom/modules/Prospects/views/view.speedphone.php'
);
check(
    !str_contains($speedPhoneViewSource, "checkAccess('Prospects', 'list'"),
    'SpeedPhone darf für externe Mitarbeiter nicht am absichtlich gesperrten Zielkontakt-Listenrecht scheitern.'
);
check(
    str_contains($speedPhoneViewSource, 'UserAccessService'),
    'SpeedPhone-Ansicht prüft nicht die eigene Modulfreigabe.'
);

$speedPhoneMenuSource = file_get_contents(
    __DIR__ . '/../module/copy/custom/Extension/modules/Prospects/Ext/Menus/crm_speedphone.php'
);
check(
    !str_contains($speedPhoneMenuSource, "checkAccess('Prospects', 'list'"),
    'SpeedPhone-Menü darf nicht vom allgemeinen Zielkontakt-Listenrecht abhängen.'
);

$teamUsers = [[
    'id' => 'befc6200-da8e-47a5-9fc8-3b30e8451018',
    'name' => 'Jessica Wendt',
    'user_name' => 'jessicawendt',
    'is_admin' => false,
    'user_type' => 'external',
    'commission_percent' => 20,
    'can_receive_unassigned' => true,
    'can_manage' => false,
    'assigned_count' => 4,
    'won_count' => 1,
]];
$escalationOptions = ['callback_escalation_days' => 2, 'external_stale_days' => 14];
ob_start();
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/team_settings.php';
$teamSettingsHtml = (string) ob_get_clean();
check(str_contains($teamSettingsHtml, 'Team, Rechte und Provision'), 'Teamverwaltung fehlt.');
check(str_contains($teamSettingsHtml, 'value="external" selected'), 'Externe Rolle wird nicht vorausgewählt.');
check(str_contains($teamSettingsHtml, 'value="20.00"'), 'Provisionssatz wird nicht bearbeitbar dargestellt.');
check(str_contains($teamSettingsHtml, 'external_stale_days'), 'Eskalationsfrist bei Untätigkeit fehlt.');

$aclRoleSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/AclRoleService.php');
check(str_contains($aclRoleSource, 'CRM SpeedPhone Extern'), 'Verwaltete externe SuiteCRM-Rolle fehlt.');
check(str_contains($aclRoleSource, 'CRM SpeedPhone Intern'), 'Verwaltete interne SuiteCRM-Rolle fehlt.');
check(
    str_contains($aclRoleSource, "'list' => \$internal ? \$all : \$none"),
    'Externe Rolle darf keinen Zugriff auf die allgemeine Zielkontaktliste erhalten.'
);
check(str_contains($aclRoleSource, "'delete' => \$none"), 'SpeedPhone-Rollen dürfen keine Löschberechtigung erteilen.');

$ownedContacts = [[
    'id' => 'befc6200-da8e-47a5-9fc8-3b30e8451018',
    'name' => 'Eigener Beispielbetrieb',
    'phone_work' => '+49 123 456',
    'phone_mobile' => '',
    'email' => 'info@example.org',
    'speedphone_status' => 'callback',
    'speedphone_next_call' => '2026-07-28 00:00:00',
    'last_contact_at' => '2026-07-21 09:00:00',
    'record_module' => 'Prospects',
    'ownership_source' => 'SpeedPhone-Zuordnung',
]];
$userTimezone = 'Europe/Berlin';
ob_start();
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/owned_contacts.php';
$ownedContactsHtml = (string) ob_get_clean();
check(str_contains($ownedContactsHtml, 'Meine Kontakte'), 'Eigene Kontaktliste fehlt.');
check(str_contains($ownedContactsHtml, 'Eigener Beispielbetrieb'), 'Zugeordneter Kontakt fehlt in der eigenen Liste.');
check(str_contains($ownedContactsHtml, 'record=befc6200-da8e-47a5-9fc8-3b30e8451018'), 'Eigene Liste referenziert nicht die vorhandene UUID.');
check(str_contains($ownedContactsHtml, 'data-speedphone-owned-email'), 'Einmalige Informationsmail kann aus „Meine Kontakte“ nicht versendet werden.');
check(str_contains($ownedContactsHtml, 'data-email="info@example.org"'), 'E-Mail-Aktion verwendet nicht die vorhandene Kontaktadresse.');

foreach ([
    fn () => $validator->uuid('keine-uuid'),
    fn () => $validator->action('delete'),
    fn () => $validator->email('ungültig'),
] as $index => $operation) {
    try {
        $operation();
        $failures[] = 'Ungültige Eingabe ' . ($index + 1) . ' wurde nicht abgelehnt.';
    } catch (InvalidArgumentException) {
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Alle CRM-SpeedPhone-Tests erfolgreich.' . PHP_EOL;
