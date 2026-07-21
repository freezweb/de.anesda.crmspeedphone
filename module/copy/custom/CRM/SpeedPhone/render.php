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

function speedPhoneRenderWorkspace(?array $candidate, string $userTimezone, int $defaultCallbackDays = 7): string
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
    ob_start();
    require __DIR__ . '/candidate.php';

    return (string) ob_get_clean();
}
