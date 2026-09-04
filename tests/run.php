<?php

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/BusinessDayCalculator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/Config.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/TravelFilter.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/CandidatePriorityService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/LinkedInContactService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/InputValidator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/AssignmentService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/DialerService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/PbxService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/IncomingCallService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/MailWebhookService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/EmailTemplateBrandService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/EmailService.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/render.php';

use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\CandidatePriorityService;
use Anesda\CRM\SpeedPhone\LinkedInContactService;
use Anesda\CRM\SpeedPhone\InputValidator;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\DialerService;
use Anesda\CRM\SpeedPhone\PbxService;
use Anesda\CRM\SpeedPhone\IncomingCallService;
use Anesda\CRM\SpeedPhone\MailWebhookService;
use Anesda\CRM\SpeedPhone\EmailTemplateBrandService;
use Anesda\CRM\SpeedPhone\EmailService;

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
$travelConfigClass = new ReflectionClass(Config::class);
$travelConfig = $travelConfigClass->newInstanceWithoutConstructor();
$travelValues = $travelConfigClass->getProperty('values');
$travelValues->setValue($travelConfig, []);
check(Anesda\CRM\SpeedPhone\TravelFilter::allowedSql($travelConfig, 'addslashes') === '1=1', 'Anfahrtsfilter muss standardmäßig ausgeschaltet bleiben.');
$travelValues->setValue($travelConfig, ['travel_filter_enabled'=>true,'travel_max_minutes'=>60,'travel_origin_label'=>'19309 Lanz']);
$travelSql = Anesda\CRM\SpeedPhone\TravelFilter::allowedSql($travelConfig, 'addslashes');
check(str_contains($travelSql, "='within_range'"), 'Ungeprüfte und entfernte Kontakte dürfen nicht durch den Regionalfilter.');
check(str_contains($travelSql, 'BETWEEN 0 AND 60'), 'Der Filter muss bei maximal 60 Minuten begrenzen.');
check(str_contains($travelSql, 'MD5(CONCAT_WS'), 'Geänderte Anschriften müssen eine neue Prüfung erfordern.');
check(str_contains($travelSql, "origin_c='19309 Lanz'"), 'Ein anderer Abfahrtsort darf alte Bewertungen nicht übernehmen.');
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
check(PbxService::normalizeExtension('6010') === '6010', 'Gültige Festnetz-Durchwahl wurde abgelehnt.');
check(PbxService::toPbxDialNumber('+490431265189') === '0431265189', 'Optionale deutsche Ortsnull wird für die Telefonanlage nicht normalisiert.');
check(PbxService::toPbxDialNumber('+49431265189') === '0431265189', 'Deutsche E.164-Rufnummer wird für die Telefonanlage nicht normalisiert.');
check(PbxService::toPbxDialNumber('+31201234567') === '0031201234567', 'Internationale Rufnummer wird für die Telefonanlage nicht normalisiert.');
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
try {
    PbxService::normalizeExtension('60#10');
    check(false, 'Steuercodes dürfen nicht als Festnetz-Durchwahl akzeptiert werden.');
} catch (InvalidArgumentException) {
}
try {
    PbxService::toPbxDialNumber('6010');
    check(false, 'Eine Kontakttelefonnummer darf nicht als interne Durchwahl ausgeführt werden.');
} catch (InvalidArgumentException) {
}

$priorityConfig = Config::load(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone');
$priorityService = new CandidatePriorityService($priorityConfig);
$edeka = $priorityService->classify(['account_name' => 'EDEKA Böge', 'description' => 'Supermarkt']);
check($edeka['excluded'], 'EDEKA-Märkte müssen als zentral versorgte Handelskette ausgeschlossen werden.');
check(
    !$priorityService->isExcluded(['account_name' => 'Kolls im Rewe Markt', 'description' => 'Eigenständiger Betrieb']),
    'Ein eigenständiger Betrieb mit bloßer Standortangabe im Rewe-Markt darf nicht als Handelskette gelten.'
);
$school = $priorityService->classify(['account_name' => 'Grundschule Lanz', 'description' => '']);
check(
    $school['tier'] === CandidatePriorityService::TIER_LATE,
    'Schulen müssen nach dem Mittelstand eingeordnet werden.'
);
$ergo = $priorityService->classify(['account_name' => 'Praxis für Ergotherapie Muster', 'description' => '']);
check(
    $ergo['tier'] === CandidatePriorityService::TIER_LATE && $ergo['base_score'] === 0,
    'Kleine Ergotherapiepraxen müssen am Ende der regulären Akquise stehen.'
);
$mediumPractice = $priorityService->classify([
    'account_name' => 'Therapieverbund Nord GmbH',
    'description' => 'Physiotherapie an mehreren Standorten',
]);
check(
    $mediumPractice['tier'] === CandidatePriorityService::TIER_MEDIUM,
    'Eine als GmbH erkennbare größere Praxis darf nicht pauschal als Kleinstformat gelten.'
);
$medium = $priorityService->classify([
    'account_name' => 'Muster Maschinenbau GmbH',
    'description' => '',
]);
check(
    $medium['tier'] === CandidatePriorityService::TIER_MEDIUM && $medium['base_score'] === 100,
    'Erkennbarer Mittelstand muss die höchste reguläre Grundpriorität erhalten.'
);
$standard = $priorityService->classify(['account_name' => 'Blumenhaus Muster', 'description' => '']);
check(
    $standard['tier'] === CandidatePriorityService::TIER_STANDARD && $standard['base_score'] === 50,
    'Normale Gewerbebetriebe benötigen eine Priorität zwischen Mittelstand und Kleinstformat.'
);
$tierSql = $priorityService->sqlTierExpression(
    'company_name',
    'company_text',
    static fn (string $value): string => addslashes($value)
);
check(
    str_contains($tierSql, 'company_name REGEXP') && str_contains($tierSql, 'company_text REGEXP'),
    'Die Geschäftsgrößen-Priorität muss direkt in der Datenbankabfrage sortierbar sein.'
);

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
    'linkedin' => [
        'contacts' => [[
            'person_name' => 'Erika Beispiel',
            'role' => 'Geschäftsführerin',
            'profile_url' => 'https://www.linkedin.com/in/erika-beispiel/',
            'confidence' => 90,
        ]],
        'search_url' => 'https://www.linkedin.com/search/results/people/?keywords=Beispielbetrieb',
        'status' => 'found',
        'searched_at' => '2026-09-01 08:00:00',
    ],
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
]], [
    'enabled' => true,
    'ready' => true,
    'extension' => '6010',
    'message' => 'Zuerst klingelt deine Durchwahl 6010.',
]);
check(str_contains($workspace, 'Gesendete E-Mails'), 'E-Mail-Historie fehlt im gerenderten Kontakt.');
check(str_contains($workspace, 'LinkedIn-Ansprechpartner'), 'LinkedIn-Ansprechpartner fehlen im SpeedPhone-Kontakt.');
check(str_contains($workspace, 'Erika Beispiel'), 'Ein gefundener LinkedIn-Ansprechpartner wird nicht angezeigt.');
check(str_contains($workspace, '90 % Treffer'), 'Die Zuordnungssicherheit eines LinkedIn-Profils fehlt.');
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
check(str_contains($workspace, 'data-speedphone-pbx-call="work"'), 'Schaltfläche zum Anruf über die Telefonanlage fehlt.');
check(!str_contains($workspace, 'data-speedphone-pbx-call="work" disabled'), 'Festnetz-Wahl bleibt trotz konfigurierter Durchwahl gesperrt.');
check(str_contains($workspace, 'Festnetz · Durchwahl 6010'), 'Die verwendete Mitarbeiter-Durchwahl wird nicht angezeigt.');
check(str_contains($emailServiceSource, 'explicitOneTimeRequest'), 'Einmalige ausdrückliche Versandfreigabe fehlt im E-Mail-Dienst.');
check(str_contains($emailServiceSource, 'die globale E-Mail-Sperre bleibt bestehen'), 'Fortbestand der globalen E-Mail-Sperre wird nicht bestätigt.');
check(!preg_match('/UPDATE\s+email_addresses/i', $emailServiceSource), 'Die einmalige Freigabe darf globale E-Mail-Sperrmerkmale nicht löschen.');
check(str_contains($emailServiceSource, 'sendThroughMailApi'), 'SpeedPhone kann Direktmails nicht über die eigene Mail-API senden.');
check(str_contains($emailServiceSource, "'crm_target_id'"), 'Mail-API-Sendungen enthalten keine vorhandene CRM-Zielkontakt-UUID.');
check(str_contains($emailServiceSource, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS'), 'Mail-API-Zugang ist nicht auf HTTPS beschränkt.');

$legacyTemplate = 'Anesda UG, St.-Josefs-Kirchplatz 4, 87700 Memmingen, 08331 7568490, https://anesda.de, info@anesda.de';
$migratedTemplate = EmailTemplateBrandService::rewriteLegacyBranding($legacyTemplate);
check(str_contains($migratedTemplate, 'Anesda Nord UG'), 'Alte Gesellschaftsbezeichnung wird in Vorlagen nicht ersetzt.');
check(str_contains($migratedTemplate, 'Parkstr. 5, 19309 Lanz'), 'Alte Firmenanschrift wird in Vorlagen nicht ersetzt.');
check(str_contains($migratedTemplate, '+49 38780 579999'), 'Alte Telefonnummer wird in Vorlagen nicht ersetzt.');
check(str_contains($migratedTemplate, 'https://anesda-nord.de'), 'Alte Webdomain wird in Vorlagen nicht ersetzt.');
check(str_contains($migratedTemplate, 'info@anesda-nord.de'), 'Alte E-Mail-Domain wird in Vorlagen nicht ersetzt.');
$informationTemplate = EmailTemplateBrandService::informationTemplate();
$informationTemplateContent = implode("\n", $informationTemplate);
check(str_contains($informationTemplate['subject'], 'Anesda Nord'), 'SpeedPhone-Infomail hat noch den alten Betreff.');
check(str_contains($informationTemplateContent, 'Anesda Nord UG (haftungsbeschränkt)'), 'SpeedPhone-Infomail nennt nicht die neue Gesellschaft.');
check(str_contains($informationTemplateContent, 'Parkstr. 5'), 'SpeedPhone-Infomail enthält nicht die neue Anschrift.');
check(str_contains($informationTemplateContent, 'info@anesda-nord.de'), 'SpeedPhone-Infomail enthält nicht die neue E-Mail-Adresse.');
check(str_contains($informationTemplateContent, 'https://anesda-nord.de/kontakt'), 'SpeedPhone-Infomail verlinkt nicht auf den neuen Kontaktweg.');
check(!str_contains($informationTemplateContent, 'anesda.de'), 'SpeedPhone-Infomail enthält noch die alte Domain.');
check(!str_contains($informationTemplateContent, 'Anesda UG'), 'SpeedPhone-Infomail enthält noch die alte Gesellschaftsbezeichnung.');
check(!str_contains($informationTemplateContent, 'Memmingen'), 'SpeedPhone-Infomail enthält noch den alten Ort.');
check(
    str_starts_with(EmailService::decodeStoredHtml($informationTemplate['body_html']), '<!DOCTYPE html>'),
    'SuiteCRM-kodiertes HTML der SpeedPhone-Infomail wird vor dem Versand nicht dekodiert.'
);
check(
    preg_match('/praxis/iu', implode(' ', $exampleConfig['positive_patterns'])) !== 1,
    'Das pauschale Signal „Praxis“ darf Kleinstpraxen nicht mehr aufwerten.'
);
$queueSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/QueueService.php');
check(
    strpos($queueSource, 'speedphone_priority_tier,')
        < strpos($queueSource, 'COALESCE(eng.clicked, 0) DESC'),
    'Die Geschäftsgrößen-Priorität muss vor allgemeinen Mailreaktionen sortiert werden.'
);
check(
    str_contains($queueSource, '$canIncreasePriority'),
    'Mailöffnung und Regionalität dürfen Schulen oder Kleinstpraxen nicht wieder hochstufen.'
);
check(
    str_contains($queueSource, 'centralRetailAllowedSql'),
    'Zentral versorgte Handelsketten müssen bereits in der SQL-Auswahl ausgeschlossen werden.'
);

$linkedInFixture = <<<'HTML'
<!doctype html>
<html><body>
<div class="result">
  <h2>
    <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fde.linkedin.com%2Fin%2Fmanuel-mang-43a70498%2F&amp;rut=test">
      Manuel Mang - Geschäftsführer bei INTERAUTOMATION Deutschland GmbH | LinkedIn
    </a>
  </h2>
  <div class="result__snippet">Manuel Mang verantwortet die INTERAUTOMATION Deutschland GmbH in Berlin.</div>
</div>
<div class="result">
  <h2>
    <a class="result__a" href="https://www.linkedin.com/in/fremdes-profil/">
      Fremde Person - Geschäftsführung bei Andere GmbH | LinkedIn
    </a>
  </h2>
</div>
</body></html>
HTML;
$linkedInContacts = LinkedInContactService::parseSearchHtml(
    $linkedInFixture,
    'INTERAUTOMATION Deutschland GmbH',
    'Berlin',
    5
);
check(count($linkedInContacts) === 1, 'Die öffentliche LinkedIn-Suche muss Fremdfirmen herausfiltern.');
check(
    ($linkedInContacts[0]['person_name'] ?? '') === 'Manuel Mang',
    'Der Name eines LinkedIn-Ansprechpartners wird nicht zuverlässig ausgelesen.'
);
check(
    ($linkedInContacts[0]['profile_url'] ?? '') === 'https://de.linkedin.com/in/manuel-mang-43a70498/',
    'Der direkte LinkedIn-Profil-Link wird nicht sicher normalisiert.'
);
check(
    ($linkedInContacts[0]['confidence'] ?? 0) === 100,
    'Firmen-, Rollen- und Ortsübereinstimmung müssen die höchste Zuordnungssicherheit ergeben.'
);
$googleLinkedInFixture = <<<'HTML'
<!doctype html>
<html><body>
<div>
  <a href="/url?q=https%3A%2F%2Fwww.linkedin.com%2Fin%2Fcarolalilienthal%2F&amp;sa=U">
    <h3>Dr. Carola Lilienthal - Geschäftsführerin WPS – Workplace Solutions GmbH | LinkedIn</h3>
  </a>
</div>
</body></html>
HTML;
$googleLinkedInContacts = LinkedInContactService::parseSearchHtml(
    $googleLinkedInFixture,
    'WPS – Workplace Solutions GmbH',
    '',
    5
);
check(
    count($googleLinkedInContacts) === 1
        && ($googleLinkedInContacts[0]['profile_url'] ?? '') === 'https://www.linkedin.com/in/carolalilienthal/',
    'Direkte LinkedIn-Profile aus der öffentlichen Google-Ergebnisansicht werden nicht erkannt.'
);
$companyWebsiteFixture = <<<'HTML'
<!doctype html>
<html><body>
  <main>
    <p>Vertreten durch die Geschäftsführerin: Dr. Carola Lilienthal</p>
    <a href="https://www.linkedin.com/in/carolalilienthal/">Dr. Carola Lilienthal</a>
  </main>
</body></html>
HTML;
$companyWebsiteContacts = LinkedInContactService::parseCompanyWebsiteHtml(
    $companyWebsiteFixture,
    'WPS – Workplace Solutions GmbH',
    'https://www.wps.de/impressum',
    5
);
check(
    count($companyWebsiteContacts) === 1
        && ($companyWebsiteContacts[0]['person_name'] ?? '') === 'Dr. Carola Lilienthal'
        && ($companyWebsiteContacts[0]['confidence'] ?? 0) >= 80,
    'Direkte LinkedIn-Ansprechpartner müssen auch auf der Firmenwebsite erkannt werden.'
);
$imprintOnlyFixture = <<<'HTML'
<!doctype html>
<html><body><address>Geschäftsführer: Max Mustermann<br>Amtsgericht Hamburg HRB 12345</address></body></html>
HTML;
$imprintContacts = LinkedInContactService::parseCompanyWebsiteHtml(
    $imprintOnlyFixture,
    'Muster Mittelstand GmbH',
    'https://muster.example/impressum',
    5
);
check(
    count($imprintContacts) === 1
        && ($imprintContacts[0]['person_name'] ?? '') === 'Max Mustermann'
        && str_contains((string) ($imprintContacts[0]['profile_url'] ?? ''), 'linkedin.com/search/results/people/'),
    'Geschäftsführer aus dem Impressum müssen mit einer LinkedIn-Personensuche aufgelistet werden.'
);
check(
    str_contains(
        LinkedInContactService::buildLinkedInPeopleSearchUrl('Muster GmbH', 'Lanz'),
        'linkedin.com/search/results/people/'
    ),
    'Der manuelle LinkedIn-Fallback fehlt.'
);
$postInstallSource = file_get_contents(__DIR__ . '/../module/scripts/post_install.php');
check(
    str_contains($postInstallSource, 'crm_speedphone_linkedin_searches')
        && str_contains($postInstallSource, 'crm_speedphone_linkedin_contacts'),
    'Die LinkedIn-Fundstellen haben keine idempotent installierten Cache-Tabellen.'
);
check(
    EmailService::decodeStoredHtml('<p>Bereits echtes HTML</p>') === '<p>Bereits echtes HTML</p>',
    'Bereits dekodiertes Vorlagen-HTML darf nicht verändert werden.'
);

check(
    MailWebhookService::signature('secret', '1724500000', '{"event":"delivered"}')
        === 'v1=4e8bc89b80543fda19bb3735cdab3aef5785920a641ed77f99fcd8874ad6867f',
    'Die CRM-Signaturprüfung ist nicht mit dem Mailserver-Protokoll kompatibel.'
);
$configSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/Config.php');
check(str_contains($configSource, "'/mail.local.php'"), 'Mail-Geheimnisse besitzen keine getrennte lokale Konfigurationsdatei.');
check(str_contains($configSource, "'/pbx.local.php'"), 'Telefonanlagen-Geheimnisse besitzen keine getrennte lokale Konfigurationsdatei.');
$mailWebhookSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/MailWebhookService.php');
check(str_contains($mailWebhookSource, 'hash_equals'), 'Webhook-Signaturen werden nicht timing-sicher geprüft.');
check(str_contains($mailWebhookSource, 'MAX_CLOCK_SKEW'), 'Webhook-Wiederholungen besitzen kein begrenztes Zeitfenster.');
check(str_contains($mailWebhookSource, 'ON DUPLICATE KEY UPDATE'), 'Webhook-Verarbeitung ist nicht idempotent wiederholbar.');
check(str_contains($mailWebhookSource, "'unique_opened'"), 'Eindeutige Öffnungen werden nicht als CRM-Aktivität übernommen.');

$apiSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/api.php');
check(str_contains($apiSource, "'dialer_pairing'"), 'API-Aktion zur QR-Kopplung fehlt.');
check(str_contains($apiSource, "'dialer_call'"), 'API-Aktion zur Handywahl fehlt.');
check(str_contains($apiSource, "'pbx_call'"), 'API-Aktion zur Festnetz-Wahl fehlt.');
check(str_contains($apiSource, "'refresh_current'"), 'AJAX-Aktualisierung des reservierten Kontakts fehlt.');
check(str_contains($apiSource, 'openPendingForCurrentUser'), 'Eingehende Rückrufe werden im Portal nicht automatisch geöffnet.');

$queueSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/QueueService.php');
check(str_contains($queueSource, 'getCurrentCandidate'), 'Aktueller Kontakt kann nicht ohne Warteschlangenwechsel aktualisiert werden.');
check(
    str_contains($queueSource, 'erwirbt bewusst keine neue Reservierung'),
    'Live-Aktualisierung muss ausdrücklich ohne neue Kontaktreservierung arbeiten.'
);
check(
    str_contains($queueSource, "'processed_today_mine' => \$processedTodayMine")
        && str_contains($queueSource, "'processed_today_all' => \$processedTodayAll"),
    'Tagesstatistik wird nicht getrennt für den aktuellen Mitarbeiter und das gesamte Team geliefert.'
);
check(
    str_contains($queueSource, "c.status='Held'")
        && str_contains($queueSource, "c.name LIKE 'SpeedPhone:%'")
        && str_contains($queueSource, 'COUNT(DISTINCT c.id)'),
    'Tagesstatistik zählt nicht ausschließlich tatsächlich protokollierte SpeedPhone-Anrufe.'
);
check(
    str_contains($queueSource, "c.assigned_user_id='")
        && str_contains($queueSource, 'todayUtcRange'),
    'Persönliche Tagesstatistik berücksichtigt weder Mitarbeiter noch lokalen Kalendertag.'
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
check(str_contains($javascriptSource, "data.set('operation', 'pbx_call')"), 'Browser startet die Festnetz-Wahl nicht per AJAX.');
check(str_contains($javascriptSource, 'data-speedphone-pbx-call'), 'Festnetz-Schaltflächen besitzen keinen AJAX-Handler.');
$pbxServiceSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/PbxService.php');
check(str_contains($pbxServiceSource, "'Action' => 'Originate'"), 'Asterisk-AMI-Auftrag startet keinen nativen Originate-Ablauf.');
check(str_contains($pbxServiceSource, "'Channel' => 'Local/'"), 'Festnetz-Wahl klingelt nicht zuerst die Mitarbeiter-Durchwahl an.');
check(str_contains($pbxServiceSource, 'crm_speedphone_pbx_calls'), 'Festnetz-Wahlaufträge werden nicht nachvollziehbar protokolliert.');

$manifestSource = file_get_contents(__DIR__ . '/../module/manifest.php');
$pageSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/page.php');
check(
    str_contains($pageSource, 'data-stat="processed_today_mine"')
        && str_contains($pageSource, 'data-stat="processed_today_all"')
        && str_contains($pageSource, 'heute · ich')
        && str_contains($pageSource, 'heute · alle'),
    'Oberfläche zeigt die Tageszahlen für den Mitarbeiter und das gesamte Team nicht getrennt an.'
);
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
    str_contains($installerSource, 'crm_speedphone_pbx_calls')
        && str_contains($installerSource, "'pbx_extension' =>"),
    'Installer legt Durchwahl und nachvollziehbare Festnetz-Wahlaufträge nicht idempotent an.'
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
    'pbx_extension' => '6010',
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
check(str_contains($teamSettingsHtml, 'name="pbx_extension['), 'Mitarbeiter-Durchwahl kann nicht verwaltet werden.');
check(str_contains($teamSettingsHtml, 'value="6010"'), 'Gespeicherte Mitarbeiter-Durchwahl wird nicht angezeigt.');

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
