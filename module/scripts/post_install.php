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

function speedPhoneInstallDashboardDashlets(\DBManager $db): void
{
    $result = $db->query("SELECT up.id, up.contents
                          FROM user_preferences up
                          INNER JOIN users u ON u.id=up.assigned_user_id AND u.deleted=0 AND u.status='Active'
                          WHERE up.deleted=0 AND up.category='Home'");
    while ($row = $db->fetchByAssoc($result)) {
        $decoded = base64_decode((string) ($row['contents'] ?? ''), true);
        $preferences = $decoded === false ? false : @unserialize($decoded, ['allowed_classes' => false]);
        if (!is_array($preferences)) {
            continue;
        }

        if (!isset($preferences['dashlets']) || !is_array($preferences['dashlets'])) {
            $preferences['dashlets'] = [];
        }
        if (!isset($preferences['pages'][0]) || !is_array($preferences['pages'][0])) {
            $preferences['pages'][0] = ['columns' => []];
        }
        if (!isset($preferences['pages'][0]['columns']) || !is_array($preferences['pages'][0]['columns'])) {
            $preferences['pages'][0]['columns'] = [];
        }
        if (!isset($preferences['pages'][0]['columns'][0]) || !is_array($preferences['pages'][0]['columns'][0])) {
            $preferences['pages'][0]['columns'][0] = ['width' => '100%', 'dashlets' => []];
        }

        $alreadyInstalled = false;
        foreach ((array) ($preferences['dashlets'] ?? []) as $dashlet) {
            if (($dashlet['className'] ?? '') === 'CRMSpeedPhoneDashlet') {
                $alreadyInstalled = true;
                break;
            }
        }
        if ($alreadyInstalled) {
            continue;
        }

        $dashletId = function_exists('create_guid') ? create_guid() : speedPhoneCreateGuid();
        $preferences['dashlets'][$dashletId] = [
            'className' => 'CRMSpeedPhoneDashlet',
            'module' => 'Prospects',
            'forceColumn' => 0,
            'fileLocation' => 'custom/modules/Home/Dashlets/CRMSpeedPhoneDashlet/CRMSpeedPhoneDashlet.php',
            'options' => [],
        ];
        $columnDashlets = &$preferences['pages'][0]['columns'][0]['dashlets'];
        if (!is_array($columnDashlets)) {
            $columnDashlets = [];
        }
        array_unshift($columnDashlets, $dashletId);

        $contents = base64_encode(serialize($preferences));
        $db->query("UPDATE user_preferences
                    SET contents='" . $db->quote($contents) . "', date_modified=UTC_TIMESTAMP()
                    WHERE id='" . $db->quote((string) $row['id']) . "'");
    }
}

function speedPhoneCreateGuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
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

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_locks` (
    `prospect_id` char(36) NOT NULL,
    `user_id` char(36) NOT NULL,
    `lock_token` char(64) NOT NULL,
    `locked_at` datetime NOT NULL,
    `expires_at` datetime NOT NULL,
    PRIMARY KEY (`prospect_id`),
    UNIQUE KEY `idx_speedphone_lock_user` (`user_id`),
    KEY `idx_speedphone_lock_expires` (`expires_at`)
) ENGINE=InnoDB");

speedPhoneInstallDashboardDashlets($db);

$repair = new RepairAndClear();
$repair->repairAndClearAll(['rebuildExtensions'], ['Prospect', 'Call', 'Home'], true, false);
