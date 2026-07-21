<?php

require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/BusinessDayCalculator.php';
require_once __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/src/InputValidator.php';

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
check($validator->email('info@example.org') === 'info@example.org', 'Gültige E-Mail wurde abgelehnt.');
check($validator->email('') === '', 'Leere optionale E-Mail wurde abgelehnt.');

$exampleConfig = require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/config.local.php.example';
foreach (['Rechtsanwälte Müller', 'Kanzlei Schulz', 'Notarin Mayer'] as $lawyerName) {
    $excluded = false;
    foreach ($exampleConfig['exclude_patterns'] as $pattern) {
        if (preg_match($pattern, $lawyerName) === 1) {
            $excluded = true;
            break;
        }
    }
    check($excluded, 'Anwalts-Ausschluss hat nicht gegriffen: ' . $lawyerName);
}

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
