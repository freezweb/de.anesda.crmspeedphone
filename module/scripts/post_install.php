<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/Administration/QuickRepairAndRebuild.php';

global $db;

/**
 * SuiteCRM legt eine *_cstm-Tabelle beim Repair nicht in jeder Version neu an.
 * Die Migration ist daher bewusst idempotent und auf die unterstützten
 * MariaDB-/MySQL-Installationen begrenzt.
 */
function speedPhoneEnsureCustomTableAndColumns(
    \DBManager $db,
    string $table,
    array $columns
): void {
    $db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id_c` char(36) NOT NULL,
        PRIMARY KEY (`id_c`)
    ) ENGINE=InnoDB");

    $existing = [];
    $result = $db->query("SHOW COLUMNS FROM `{$table}`");
    while ($row = $db->fetchByAssoc($result)) {
        $existing[(string) $row['Field']] = true;
    }

    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        }
    }
}

speedPhoneEnsureCustomTableAndColumns($db, 'prospects_cstm', [
    'speedphone_status_c' => 'varchar(40) NULL',
    'speedphone_attempts_c' => 'int(255) DEFAULT 0 NULL',
    'speedphone_next_call_c' => 'datetime NULL',
    'speedphone_last_call_c' => 'datetime NULL',
    'speedphone_last_result_c' => 'varchar(40) NULL',
    'speedphone_last_note_c' => 'text NULL',
]);
speedPhoneEnsureCustomTableAndColumns($db, 'calls_cstm', [
    'speedphone_result_c' => 'varchar(40) NULL',
    'speedphone_email_requested_c' => 'tinyint(1) DEFAULT 0 NULL',
]);

$repair = new RepairAndClear();
$repair->repairAndClearAll(['rebuildExtensions'], ['Prospect', 'Call'], true, false);
