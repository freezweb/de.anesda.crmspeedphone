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
        $subject = 'Ihr IT-Partner vor Ort – Anesda Nord in Lanz';
        $body = <<<'TEXT'
Sehr geehrte Damen und Herren,

wir sind als Anesda Nord UG (haftungsbeschränkt) in Lanz Ihr regionaler Ansprechpartner für zuverlässige IT- und Entwicklungslösungen.

Unsere Leistungen:
- Software-, App- und Webentwicklung
- Automatisierung, Microcontroller, CODESYS und Siemens TIA
- Glasfaser- und Netzwerkinfrastruktur
- Hardwareentwicklung, Prototyping und 3D-Druck
- Telefonanlagen
- Hosting und Serverbetrieb

IT von A bis Z – von der Beratung über die Umsetzung bis zum laufenden Support.

Anesda Nord UG (haftungsbeschränkt)
Parkstr. 5
19309 Lanz
Telefon: +49 38780 579999
E-Mail: info@anesda-nord.de
Web: https://anesda-nord.de

Wir freuen uns auf ein unverbindliches Gespräch mit Ihnen.
TEXT;
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Anesda Nord – Ihr IT-Partner in Lanz</title></head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;color:#263442;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0d1b2a;padding:32px 40px;text-align:center;">
<img src="https://anesda-nord.de/anesda_logo.png" alt="Anesda Nord" width="190" style="display:block;margin:0 auto 14px;max-width:190px;height:auto;background:#fff;border-radius:8px;padding:8px;">
<p style="color:#7fc4ef;margin:0;font-size:14px;letter-spacing:2px;text-transform:uppercase;">Wir fangen an, wo andere aufhören</p>
</td></tr>
<tr><td style="background:#1a5276;padding:24px 40px;text-align:center;color:#fff;">
<p style="margin:0 0 8px;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#cde8f7;">Ihr regionaler IT-Partner</p>
<h1 style="margin:0;font-size:26px;">Anesda Nord in Lanz</h1>
</td></tr>
<tr><td style="padding:32px 40px 14px;font-size:16px;line-height:1.7;">
<p style="margin:0 0 16px;">Sehr geehrte Damen und Herren,</p>
<p style="margin:0;">wir sind als <strong>Anesda Nord UG (haftungsbeschränkt)</strong> in Lanz Ihr regionaler Ansprechpartner für zuverlässige IT- und Entwicklungslösungen.</p>
</td></tr>
<tr><td style="padding:14px 40px 24px;">
<h2 style="color:#1a5276;font-size:20px;margin:0 0 14px;border-bottom:2px solid #2980b9;padding-bottom:9px;">Unsere Leistungen</h2>
<ul style="margin:0;padding-left:22px;line-height:1.8;font-size:15px;">
<li>Software-, App- und Webentwicklung</li><li>Automatisierung, Microcontroller, CODESYS und Siemens TIA</li>
<li>Glasfaser- und Netzwerkinfrastruktur</li><li>Hardwareentwicklung, Prototyping und 3D-Druck</li>
<li>Telefonanlagen</li><li>Hosting und Serverbetrieb</li>
</ul>
</td></tr>
<tr><td style="padding:4px 40px 28px;text-align:center;">
<p style="font-size:16px;line-height:1.6;margin:0 0 20px;">IT von A bis Z – von der Beratung über die Umsetzung bis zum laufenden Support.</p>
<a href="https://anesda-nord.de/kontakt" style="display:inline-block;background:#2980b9;color:#fff;padding:14px 32px;text-decoration:none;border-radius:6px;font-weight:bold;">Unverbindlich Kontakt aufnehmen</a>
</td></tr>
<tr><td style="background:#eaf2f8;padding:24px 40px;text-align:center;font-size:14px;line-height:1.7;">
<strong>Anesda Nord UG (haftungsbeschränkt)</strong><br>Parkstr. 5 · 19309 Lanz<br>
<a href="tel:+4938780579999" style="color:#1a5276;">+49 38780 579999</a> · <a href="mailto:info@anesda-nord.de" style="color:#1a5276;">info@anesda-nord.de</a>
</td></tr>
<tr><td style="background:#0d1b2a;padding:22px 40px;text-align:center;color:#bdc3c7;font-size:13px;">
© 2026 Anesda Nord UG (haftungsbeschränkt) · <a href="https://anesda-nord.de" style="color:#7fc4ef;text-decoration:none;">anesda-nord.de</a> · <a href="https://anesda-nord.de/leistungen" style="color:#7fc4ef;text-decoration:none;">Leistungen</a>
</td></tr>
</table></td></tr></table>
</body></html>
HTML;

        return [
            'subject' => $subject,
            'body' => $body,
            'body_html' => htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }
}
