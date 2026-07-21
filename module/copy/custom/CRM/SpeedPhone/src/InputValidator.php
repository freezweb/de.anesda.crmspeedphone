<?php

namespace Anesda\CRM\SpeedPhone;

final class InputValidator
{
    private const ACTIONS = [
        'not_reached',
        'callback',
        'email_callback',
        'interested',
        'no_interest',
        'wrong_number',
        'blocked',
        'later',
    ];

    public function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
            throw new \InvalidArgumentException('Ungültige Zielkontakt-UUID.');
        }

        return $value;
    }

    public function action(string $value): string
    {
        if (!in_array($value, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Ungültiges Anrufergebnis.');
        }

        return $value;
    }

    public function email(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Die neue E-Mail-Adresse ist ungültig.');
        }

        return $value;
    }
}
