<?php

require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/BusinessDayCalculator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/InputValidator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/render.php';

use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\InputValidator;

$failures = [];

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
    'recent_calls' => [],
    'lock_token' => str_repeat('a', 64),
], 'Europe/Berlin', 7);
check(str_contains($workspace, 'Gesendete E-Mails'), 'E-Mail-Historie fehlt im gerenderten Kontakt.');
check(str_contains($workspace, 'info@example.org'), 'Empfängeradresse fehlt in der E-Mail-Historie.');
check(str_contains($workspace, 'value="email_callback"'), 'Aktion „E-Mail jetzt senden + wieder anrufen“ fehlt.');
check(preg_match('/name="callback_date"[^>]*value="\d{4}-\d{2}-\d{2}"/', $workspace) === 1, 'Rückrufdatum ist nicht vorbelegt.');
check(preg_match('/name="callback_date"[^>]*min="\d{4}-\d{2}-\d{2}"/', $workspace) === 1, 'Rückrufdatum verhindert keine vergangenen Tage.');
check(str_contains($workspace, 'name="callback_time"'), 'Optionale Uhrzeit für einen festen Rückruftermin fehlt.');
check(str_contains($workspace, 'Ohne Uhrzeit:'), 'Unterschied zwischen Tagesliste und festem Termin wird nicht erklärt.');
check(str_contains($workspace, 'E-Mail jetzt senden + wieder anrufen'), 'E-Mail-Wiedervorlage ist nicht eindeutig beschriftet.');

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
