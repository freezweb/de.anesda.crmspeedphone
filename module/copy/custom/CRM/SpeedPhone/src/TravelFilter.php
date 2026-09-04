<?php

namespace Anesda\CRM\SpeedPhone;

/** Reversibler Anfahrtsfilter, unabhängig von Anrufstatus und Kontaktverboten. */
final class TravelFilter
{
    public static function addressHashSql(): string
    {
        return "MD5(CONCAT_WS('|', COALESCE(p.primary_address_street,''), COALESCE(p.primary_address_postalcode,''), COALESCE(p.primary_address_city,''), COALESCE(p.primary_address_country,'')))";
    }

    public static function allowedSql(Config $config, callable $quote): string
    {
        if (!$config->get('travel_filter_enabled', false)) {
            return '1=1';
        }
        $minutes = max(1, (int) $config->get('travel_max_minutes', 60));
        $origin = $quote((string) $config->get('travel_origin_label', ''));
        return "(pc.speedphone_travel_status_c IN ('within_range','included_exception')
            AND (pc.speedphone_travel_status_c='included_exception' OR pc.speedphone_travel_minutes_c BETWEEN 0 AND {$minutes})
            AND pc.speedphone_travel_origin_c='{$origin}'
            AND pc.speedphone_travel_hash_c=" . self::addressHashSql() . ')';
    }
}
