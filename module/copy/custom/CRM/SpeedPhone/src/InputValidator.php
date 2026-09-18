<?php

namespace Anesda\CRM\SpeedPhone;

final class InputValidator
{
    private const ACTIONS = [
        'not_reached',
        'callback',
        'email_callback',
        'send_flyers',
        'interested',
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
        if ($value === 'no_interest') {
            throw new \InvalidArgumentException('„Kein Interesse“ wurde entfernt. Bitte „Am Datum wieder anrufen“ oder „Ohne Anruf später“ verwenden.');
        }
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

    public function emailSubject(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException('Der E-Mail-Betreff darf nicht leer sein oder Zeilenumbrüche enthalten.');
        }
        if (mb_strlen($value, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Der E-Mail-Betreff darf höchstens 255 Zeichen lang sein.');
        }

        return $value;
    }

    public function emailBody(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($value === '') {
            throw new \InvalidArgumentException('Der E-Mail-Text darf nicht leer sein.');
        }
        if (mb_strlen($value, 'UTF-8') > 20000) {
            throw new \InvalidArgumentException('Der E-Mail-Text darf höchstens 20.000 Zeichen lang sein.');
        }

        return $value;
    }

    public function emailBodyHtml(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') > 100000) {
            throw new \InvalidArgumentException('Die formatierte E-Mail darf höchstens 100.000 Zeichen lang sein.');
        }
        $html = EmailContentService::sanitizeHtml($value);
        if (EmailContentService::htmlToPlain($html) === '') {
            throw new \InvalidArgumentException('Die E-Mail-Nachricht darf nicht leer sein.');
        }
        return $html;
    }
}
