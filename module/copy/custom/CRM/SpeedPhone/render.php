<?php

function speedPhoneEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function speedPhoneDateTime(mixed $value, string $timezone): string
{
    try {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('d.m.Y H:i');
    } catch (Throwable) {
        return (string) $value;
    }
}

function speedPhoneResultLabel(mixed $value): string
{
    return [
        'not_reached' => 'Nicht erreicht',
        'callback' => 'Wiedervorlage / Rückruf',
        'email_callback' => 'E-Mail + Wiedervorlage',
        'interested' => 'Interesse',
        'no_interest' => 'Kein Interesse',
        'wrong_number' => 'Falsche Nummer',
        'blocked' => 'Nicht mehr kontaktieren',
        'later' => 'Ohne Anruf verschoben',
    ][(string) $value] ?? (string) $value;
}

function speedPhonePercent(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.') . ' %';
}

function speedPhoneStatusLabel(mixed $value): string
{
    return [
        '' => 'Offen',
        'retry' => 'Erneut anrufen',
        'callback' => 'Wiedervorlage / Rückruf',
        'interested' => 'Interesse',
        'no_interest' => 'Kein Interesse',
        'invalid_phone' => 'Ungültige Telefonnummer',
        'blocked' => 'Nicht mehr kontaktieren',
        'paused' => 'Pausiert',
    ][(string) $value] ?? speedPhoneResultLabel($value);
}

function speedPhoneRenderWorkspace(
    ?array $candidate,
    string $userTimezone,
    int $defaultCallbackDays = 7,
    array $dialerDevices = [],
    array $pbxStatus = []
): string
{
    $defaultCallbackDays = max(1, min(90, $defaultCallbackDays));
    try {
        $today = new DateTimeImmutable('today', new DateTimeZone($userTimezone));
        $todayDate = $today->format('Y-m-d');
        $defaultCallbackDate = $today
            ->modify('+' . $defaultCallbackDays . ' days')
            ->format('Y-m-d');
    } catch (Throwable) {
        $todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
        $defaultCallbackDate = (new DateTimeImmutable('+7 days'))->format('Y-m-d');
    }
    $dialerReady = false;
    foreach ($dialerDevices as $dialerDevice) {
        if ((int) ($dialerDevice['is_ready'] ?? 0) === 1) {
            $dialerReady = true;
            break;
        }
    }
    $pbxReady = !empty($pbxStatus['ready']);
    $pbxExtension = trim((string) ($pbxStatus['extension'] ?? ''));
    $pbxMessage = trim((string) ($pbxStatus['message'] ?? 'Die Telefonanlage ist noch nicht eingerichtet.'));
    ob_start();
    require __DIR__ . '/candidate.php';

    return (string) ob_get_clean();
}
