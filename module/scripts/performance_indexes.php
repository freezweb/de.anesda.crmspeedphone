<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/** Beschleunigt Regionalfilter und Wiedervorlagen ohne Datensatzänderungen. */
function speedPhoneEnsurePerformanceIndexes(\DBManager $db): void
{
    $existing = [];
    $result = $db->query('SHOW INDEX FROM prospects_cstm');
    while ($row = $db->fetchByAssoc($result)) {
        $existing[(string) $row['Key_name']] = true;
    }

    $indexes = [
        'idx_speedphone_travel' => 'speedphone_travel_status_c, speedphone_travel_minutes_c',
        'idx_speedphone_status_due' => 'speedphone_status_c, speedphone_next_call_c',
    ];
    foreach ($indexes as $name => $columns) {
        if (!isset($existing[$name])) {
            $db->query("ALTER TABLE prospects_cstm ADD INDEX {$name} ({$columns}), ALGORITHM=INPLACE, LOCK=NONE");
        }
    }
}
