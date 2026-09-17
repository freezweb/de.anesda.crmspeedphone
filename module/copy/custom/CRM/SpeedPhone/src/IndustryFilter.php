<?php

namespace Anesda\CRM\SpeedPhone;

/** Persönlicher Branchenfokus; keine Änderung an Kontaktzuständigkeiten. */
final class IndustryFilter
{
    public const OPTIONS = [
        '' => 'Alle Branchen',
        'hospitality' => 'Gastronomie und Beherbergung',
        'retail' => 'Einzelhandel',
        'craft' => 'Handwerk und Bau',
        'manufacturing' => 'Industrie und Produktion',
        'health' => 'Gesundheit und Pflege',
        'automotive' => 'Auto und Mobilität',
        'logistics' => 'Transport und Logistik',
        'agriculture' => 'Landwirtschaft',
        'education' => 'Bildung und Betreuung',
        'services' => 'Dienstleistungen',
        'unknown' => 'Nicht zugeordnet',
    ];

    private const PATTERNS = [
        'hospitality' => 'restaurant|gasthof|gasthaus|gastronom|hotel|pension|camping|catering|pizzeria|bistro|café|cafe |cafeteria|imbiss',
        'health' => 'arzt|ärzt|zahnarzt|zahnmedizin|apotheke|klinik|pflege|physiotherap|ergotherap|logopädie|sanitätshaus|therapiepraxis',
        'automotive' => 'autohaus|autowerkstatt|kfz|fahrzeugtechnik|reifenservice|fahrschule|motorrad|tankstelle|zweirad',
        'logistics' => 'spedition|logistik|transporte|transportservice|kurier|umzug|busunternehmen',
        'agriculture' => 'agrar|landwirtschaft|bauernhof|landgut|landtechnik|forstbetrieb|forstwirtschaft',
        'education' => 'schule|kindergarten|kindertagesstätte|kita |hochschule|universität|bildungszentrum',
        'manufacturing' => 'maschinenbau|anlagenbau|metallverarbeitung|kunststoff|fertigung|produktion|werkzeugbau|industrie|gießerei',
        'craft' => 'handwerk|bauunternehmen|baugeschäft|bauunternehmung|tischlerei|schreinerei|zimmerei|dachdeck|elektroinstallation|elektrotechnik|sanitär|heizungsbau|maler|fliesen|gartenbau|landschaftsbau|bäckerei|baeckerei|fleischerei|metzgerei',
        'retail' => 'einzelhandel|supermarkt|baumarkt|fachmarkt|buchhandlung|blumenladen|modehaus|möbelhaus|drogerie|getränkemarkt|optiker|juwelier',
        'services' => 'steuerberat|rechtsanwalt|kanzlei|versicherung|immobilien|friseur|kosmetik|reinigung|werbeagentur|ingenieurbüro|architekt|reisebüro|fotograf|unternehmensberatung',
    ];

    public static function classify(string $name, string $stored = ''): string
    {
        if ($stored !== '' && isset(self::OPTIONS[$stored])) {
            return $stored;
        }
        foreach (self::PATTERNS as $industry => $pattern) {
            if (preg_match('~' . $pattern . '~iu', $name) === 1) {
                return $industry;
            }
        }
        return 'unknown';
    }

    public static function sqlExpression(callable $quote): string
    {
        $keys = implode(',', array_map(static fn (string $key): string => "'" . $quote($key) . "'", array_filter(array_keys(self::OPTIONS))));
        $sql = "CASE WHEN pc.speedphone_industry_c IN ({$keys}) THEN pc.speedphone_industry_c";
        $name = "LOWER(COALESCE(NULLIF(TRIM(p.account_name),''),CONCAT_WS(' ',p.first_name,p.last_name)))";
        foreach (self::PATTERNS as $industry => $pattern) {
            $sql .= " WHEN {$name} REGEXP '" . $quote($pattern) . "' THEN '" . $quote($industry) . "'";
        }
        return $sql . " ELSE 'unknown' END";
    }

    public static function allowedSql(string $selection, callable $quote): string
    {
        self::validate($selection);
        return $selection === '' ? '1=1' : '(' . self::sqlExpression($quote) . ")='" . $quote($selection) . "'";
    }

    public static function validate(string $value): string
    {
        if (!array_key_exists($value, self::OPTIONS)) {
            throw new \InvalidArgumentException('Bitte eine gültige Branche auswählen.');
        }
        return $value;
    }

    public static function selected(\User $user): string
    {
        $value = (string) ($user->getPreference('industry_filter', 'CRM_SpeedPhone') ?: '');
        return array_key_exists($value, self::OPTIONS) ? $value : '';
    }

    public static function save(\User $user, string $value): string
    {
        $value = self::validate($value);
        $user->setPreference('industry_filter', $value, 0, 'CRM_SpeedPhone');
        $user->savePreferencesToDB();
        return $value;
    }
}
