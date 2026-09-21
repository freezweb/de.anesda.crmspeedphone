<?php

namespace Anesda\CRM\SpeedPhone;

final class EmailTemplateBrandService
{
    public function __construct(private readonly \DBManager $db)
    {
    }

    public function migrate(string $informationTemplateName): int
    {
        $result = $this->db->query(
            "SELECT id,name,subject,body,body_html
             FROM email_templates
             WHERE deleted=0 AND (
                name='" . $this->db->quote($informationTemplateName) . "'
                OR LOWER(CONCAT_WS(' ',name,subject,body,body_html)) LIKE '%anesda.de%'
                OR LOWER(CONCAT_WS(' ',name,subject,body,body_html)) LIKE '%anesda ug%'
                OR LOWER(CONCAT_WS(' ',name,subject,body,body_html)) LIKE '%memmingen%'
                OR CONCAT_WS(' ',name,subject,body,body_html) LIKE '%08331%'
             )"
        );
        $informationTemplate = self::informationTemplate();
        $updated = 0;
        while ($row = $this->db->fetchByAssoc($result)) {
            $values = (string) $row['name'] === $informationTemplateName
                ? $informationTemplate
                : [
                    'subject' => self::rewriteLegacyBranding((string) ($row['subject'] ?? '')),
                    'body' => self::rewriteLegacyBranding((string) ($row['body'] ?? '')),
                    'body_html' => self::rewriteLegacyBranding((string) ($row['body_html'] ?? '')),
                ];
            if (
                $values['subject'] === (string) ($row['subject'] ?? '')
                && $values['body'] === (string) ($row['body'] ?? '')
                && $values['body_html'] === (string) ($row['body_html'] ?? '')
            ) {
                continue;
            }
            $this->db->query(
                "UPDATE email_templates SET
                    subject='" . $this->db->quote($values['subject']) . "',
                    body='" . $this->db->quote($values['body']) . "',
                    body_html='" . $this->db->quote($values['body_html']) . "',
                    date_modified=UTC_TIMESTAMP()
                 WHERE id='" . $this->db->quote((string) $row['id']) . "' AND deleted=0"
            );
            $updated++;
        }

        return $updated;
    }

    public static function rewriteLegacyBranding(string $value): string
    {
        return str_replace(
            [
                'St.-Josefs-Kirchplatz 4',
                'St.-Josefs Kirchplatz 4',
                'D-87700 Memmingen',
                '87700 Memmingen',
                'Anesda UG',
                'https://www.anesda.de',
                'http://www.anesda.de',
                'https://anesda.de',
                'http://anesda.de',
                'www.anesda.de',
                '@anesda.de',
                'anesda.de',
                '+49 8331 / 756849-0',
                '+49 8331-7568490',
                '+49 8331 7568490',
                '08331 7568490',
                '08331-7568490',
                '+49 3878 0-679790',
                'Memmingen',
            ],
            [
                'Parkstr. 5',
                'Parkstr. 5',
                '19309 Lanz',
                '19309 Lanz',
                'Anesda Nord UG',
                'https://anesda-nord.de',
                'https://anesda-nord.de',
                'https://anesda-nord.de',
                'https://anesda-nord.de',
                'anesda-nord.de',
                '@anesda-nord.de',
                'anesda-nord.de',
                '+49 38780 579999',
                '+49 38780 579999',
                '+49 38780 579999',
                '+49 38780 579999',
                '+49 38780 579999',
                '+49 38780 579999',
                'Lanz',
            ],
            $value
        );
    }

    public static function informationTemplate(): array
    {
        $subject = 'Wie telefonisch besprochen';
        $body = <<<'TEXT'
Guten Tag,

vielen Dank für das freundliche Gespräch.

Wie telefonisch besprochen, sende ich Ihnen die gewünschten Informationen zu.

Wenn Sie dazu Fragen haben, antworten Sie gerne direkt auf diese E-Mail.

Viele Grüße
Ihr Team von Anesda Nord
TEXT;
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Wie telefonisch besprochen</title></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#17202a;">
<div style="max-width:640px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#17202a;">
<p style="margin:0 0 16px;">Guten Tag,</p>
<p style="margin:0 0 16px;">vielen Dank für das freundliche Gespräch.</p>
<p style="margin:0 0 16px;">Wie telefonisch besprochen, sende ich Ihnen die gewünschten Informationen zu.</p>
<div data-speedphone-requested-information="1"></div>
<p style="margin:0 0 16px;">Wenn Sie dazu Fragen haben, antworten Sie gerne direkt auf diese E-Mail.</p>
<p style="margin:0;">Viele Grüße<br>Ihr Team von Anesda Nord</p>
<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;">
<img data-speedphone-footer-logo="1" src="https://anesda-nord.de/anesda_logo.png" alt="Anesda Nord" width="140" style="display:block;max-width:140px;height:auto;background-color:#ffffff !important;border-radius:6px;padding:6px;">
</div>
</div>
</body></html>
HTML;

        return [
            'subject' => $subject,
            'body' => $body,
            'body_html' => htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }
}
