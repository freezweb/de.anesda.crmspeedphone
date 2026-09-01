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

        $dashletId = null;
        foreach ((array) ($preferences['dashlets'] ?? []) as $existingDashletId => $dashlet) {
            if (($dashlet['className'] ?? '') === 'CRMSpeedPhoneDashlet') {
                $dashletId = (string) $existingDashletId;
                break;
            }
        }
        if ($dashletId === null) {
            $dashletId = function_exists('create_guid') ? create_guid() : speedPhoneCreateGuid();
        }

        $preferences['dashlets'][$dashletId] = [
            'className' => 'CRMSpeedPhoneDashlet',
            'module' => 'Home',
            'forceColumn' => 0,
            'fileLocation' => 'custom/modules/Home/Dashlets/CRMSpeedPhoneDashlet/CRMSpeedPhoneDashlet.php',
            'options' => [],
        ];

        $isPlaced = false;
        foreach ($preferences['pages'] as $page) {
            foreach ((array) ($page['columns'] ?? []) as $column) {
                if (in_array($dashletId, (array) ($column['dashlets'] ?? []), true)) {
                    $isPlaced = true;
                    break 2;
                }
            }
        }
        if (!$isPlaced) {
            $columnDashlets = &$preferences['pages'][0]['columns'][0]['dashlets'];
            if (!is_array($columnDashlets)) {
                $columnDashlets = [];
            }
            array_unshift($columnDashlets, $dashletId);
        }

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

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_user_settings` (
    `user_id` char(36) NOT NULL,
    `user_type` varchar(20) NOT NULL DEFAULT 'disabled',
    `commission_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
    `can_receive_unassigned` tinyint(1) NOT NULL DEFAULT 0,
    `can_manage` tinyint(1) NOT NULL DEFAULT 0,
    `date_modified` datetime NOT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_speedphone_user_type` (`user_type`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_options` (
    `option_name` varchar(64) NOT NULL,
    `option_value` varchar(255) NOT NULL,
    `date_modified` datetime NOT NULL,
    PRIMARY KEY (`option_name`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_assignments` (
    `prospect_id` char(36) NOT NULL,
    `owner_user_id` char(36) NOT NULL,
    `owner_type` varchar(20) NOT NULL,
    `owner_commission_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
    `assigned_at` datetime NOT NULL,
    `last_activity_at` datetime NULL,
    `last_contact_at` datetime NULL,
    `last_action_user_id` char(36) NULL,
    `last_result` varchar(40) NULL,
    `won_by_user_id` char(36) NULL,
    `won_at` datetime NULL,
    `won_commission_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`prospect_id`),
    KEY `idx_speedphone_assignment_owner` (`owner_user_id`),
    KEY `idx_speedphone_assignment_contact` (`last_contact_at`),
    KEY `idx_speedphone_assignment_winner` (`won_by_user_id`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_dialer_pairings` (
    `id` char(36) NOT NULL,
    `user_id` char(36) NOT NULL,
    `token_hash` char(64) NOT NULL,
    `created_at` datetime NOT NULL,
    `expires_at` datetime NOT NULL,
    `used_at` datetime NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_speedphone_pairing_token` (`token_hash`),
    KEY `idx_speedphone_pairing_user` (`user_id`),
    KEY `idx_speedphone_pairing_expiry` (`expires_at`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_dialer_devices` (
    `id` char(36) NOT NULL,
    `user_id` char(36) NOT NULL,
    `device_name` varchar(120) NOT NULL,
    `platform` varchar(20) NOT NULL,
    `token_hash` char(64) NOT NULL,
    `active` tinyint(1) NOT NULL DEFAULT 1,
    `paired_at` datetime NOT NULL,
    `last_seen_at` datetime NULL,
    `last_error` varchar(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_speedphone_device_token` (`token_hash`),
    KEY `idx_speedphone_device_user` (`user_id`, `active`),
    KEY `idx_speedphone_device_seen` (`last_seen_at`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_dialer_commands` (
    `id` char(36) NOT NULL,
    `device_id` char(36) NOT NULL,
    `user_id` char(36) NOT NULL,
    `prospect_id` char(36) NOT NULL,
    `phone` varchar(40) NOT NULL,
    `display_name` varchar(255) NOT NULL,
    `status` varchar(20) NOT NULL,
    `created_at` datetime NOT NULL,
    `expires_at` datetime NOT NULL,
    `delivered_at` datetime NULL,
    `completed_at` datetime NULL,
    `error_message` varchar(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_speedphone_command_device` (`device_id`, `status`, `expires_at`),
    KEY `idx_speedphone_command_user` (`user_id`, `created_at`),
    KEY `idx_speedphone_command_prospect` (`prospect_id`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_incoming_calls` (
    `id` char(36) NOT NULL,
    `device_id` char(36) NOT NULL,
    `user_id` char(36) NOT NULL,
    `prospect_id` char(36) NOT NULL,
    `received_at` datetime NOT NULL,
    `opened_at` datetime NULL,
    PRIMARY KEY (`id`),
    KEY `idx_speedphone_incoming_user` (`user_id`, `opened_at`, `received_at`),
    KEY `idx_speedphone_incoming_prospect` (`prospect_id`, `received_at`)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS `crm_speedphone_mail_webhook_events` (
    `event_id` char(36) NOT NULL,
    `event_type` varchar(40) NOT NULL,
    `email_address` varchar(255) NOT NULL,
    `payload_json` longtext NOT NULL,
    `payload_hash` char(64) NOT NULL,
    `state` varchar(20) NOT NULL,
    `attempts` int NOT NULL DEFAULT 1,
    `campaign_log_id` char(36) NULL,
    `last_error` varchar(1000) NULL,
    `created_at` datetime NOT NULL,
    `processed_at` datetime NULL,
    PRIMARY KEY (`event_id`),
    KEY `idx_speedphone_mail_event_time` (`created_at`),
    KEY `idx_speedphone_mail_event_email` (`email_address`, `created_at`),
    KEY `idx_speedphone_mail_event_state` (`state`, `created_at`)
) ENGINE=InnoDB");

$db->query("INSERT IGNORE INTO crm_speedphone_user_settings
    (user_id, user_type, commission_percent, can_receive_unassigned, can_manage, date_modified)
    SELECT id,
           IF(is_admin=1, 'internal', 'disabled'),
           0.00,
           IF(is_admin=1, 1, 0),
           IF(is_admin=1, 1, 0),
           UTC_TIMESTAMP()
    FROM users
    WHERE deleted=0 AND status='Active' AND employee_status='Active'");

$db->query("CREATE TABLE IF NOT EXISTS crm_speedphone_linkedin_searches (
    prospect_id char(36) NOT NULL,
    status varchar(20) NOT NULL,
    search_query varchar(1000) NOT NULL,
    result_count int NOT NULL DEFAULT 0,
    searched_at datetime NOT NULL,
    last_error varchar(1000) NULL,
    PRIMARY KEY (prospect_id),
    KEY idx_speedphone_linkedin_search_time (searched_at),
    KEY idx_speedphone_linkedin_search_status (status, searched_at)
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS crm_speedphone_linkedin_contacts (
    id char(36) NOT NULL,
    prospect_id char(36) NOT NULL,
    person_name varchar(255) NOT NULL,
    role_name varchar(500) NOT NULL,
    company_name varchar(255) NOT NULL,
    profile_url varchar(1000) NOT NULL,
    confidence int NOT NULL DEFAULT 0,
    found_at datetime NOT NULL,
    last_verified_at datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY idx_speedphone_linkedin_profile (prospect_id, profile_url(191)),
    KEY idx_speedphone_linkedin_prospect (prospect_id, confidence)
) ENGINE=InnoDB");

// Frühere Modulversionen haben das Ergebnis bei einzelnen SuiteCRM-Setups nur
// im lesbaren Anrufnamen gespeichert. Diese regulären CRM-Anrufe werden einmalig
// normalisiert, damit Historie und Zuordnung beim Update erhalten bleiben.
$db->query("INSERT IGNORE INTO calls_cstm (id_c)
            SELECT c.id FROM calls c
            WHERE c.deleted=0 AND c.parent_type='Prospects' AND c.name LIKE 'SpeedPhone:%'");
$db->query("UPDATE calls c
            INNER JOIN calls_cstm cc ON cc.id_c=c.id
            SET cc.speedphone_result_c=CASE
                WHEN c.name LIKE 'SpeedPhone: Nicht erreicht%' THEN 'not_reached'
                WHEN c.name LIKE 'SpeedPhone: Wiedervorlage oder Rückruf%' THEN 'callback'
                WHEN c.name LIKE 'SpeedPhone: Rückruf %' THEN 'callback'
                WHEN c.name LIKE 'SpeedPhone: E-Mail gewünscht mit Wiedervorlage%' THEN 'email_callback'
                WHEN c.name='SpeedPhone: Interesse' THEN 'interested'
                WHEN c.name='SpeedPhone: Kein Interesse' THEN 'no_interest'
                WHEN c.name='SpeedPhone: Falsche Nummer' THEN 'wrong_number'
                WHEN c.name='SpeedPhone: Nicht mehr kontaktieren' THEN 'blocked'
                ELSE cc.speedphone_result_c
            END
            WHERE c.deleted=0
              AND c.parent_type='Prospects'
              AND c.name LIKE 'SpeedPhone:%'
              AND COALESCE(cc.speedphone_result_c, '')=''");

$db->query("INSERT IGNORE INTO crm_speedphone_assignments
    (prospect_id, owner_user_id, owner_type, owner_commission_percent,
     assigned_at, last_activity_at)
    SELECT p.id,
           p.created_by,
           'external',
           s.commission_percent,
           COALESCE(p.date_entered, UTC_TIMESTAMP()),
           COALESCE(p.date_entered, UTC_TIMESTAMP())
    FROM prospects p
    INNER JOIN crm_speedphone_user_settings s
        ON s.user_id=p.created_by AND s.user_type='external'
    WHERE p.deleted=0 AND COALESCE(p.created_by, '')<>''");

$db->query("INSERT IGNORE INTO crm_speedphone_assignments
    (prospect_id, owner_user_id, owner_type, owner_commission_percent,
     assigned_at, last_activity_at, last_contact_at, last_action_user_id, last_result)
    SELECT c.parent_id,
           c.assigned_user_id,
           COALESCE(s.user_type, 'internal'),
           COALESCE(s.commission_percent, 0.00),
           c.date_start,
           c.date_start,
           c.date_start,
           c.assigned_user_id,
           cc.speedphone_result_c
    FROM calls c
    INNER JOIN calls_cstm cc ON cc.id_c=c.id
        AND cc.speedphone_result_c IN ('callback', 'email_callback', 'interested', 'no_interest', 'blocked')
    LEFT JOIN crm_speedphone_user_settings s ON s.user_id=c.assigned_user_id
    WHERE c.deleted=0
      AND c.parent_type='Prospects'
      AND c.assigned_user_id<>''
      AND NOT EXISTS (
          SELECT 1 FROM calls newer
          INNER JOIN calls_cstm newer_cstm
              ON newer_cstm.id_c=newer.id
             AND newer_cstm.speedphone_result_c IN
                 ('callback', 'email_callback', 'interested', 'no_interest', 'blocked')
          WHERE newer.deleted=0
            AND newer.parent_type='Prospects'
            AND newer.parent_id=c.parent_id
            AND (newer.date_start>c.date_start OR (newer.date_start=c.date_start AND newer.id>c.id))
      )");

$db->query("UPDATE crm_speedphone_assignments
            SET won_by_user_id=owner_user_id,
                won_at=last_contact_at,
                won_commission_percent=IF(owner_type='external', owner_commission_percent, 0.00)
            WHERE last_result='interested' AND won_by_user_id IS NULL");

require_once 'custom/CRM/SpeedPhone/bootstrap.php';
(new Anesda\CRM\SpeedPhone\EmailTemplateBrandService($db))->migrate(
    Anesda\CRM\SpeedPhone\Config::load('custom/CRM/SpeedPhone')->requireString('email_template_name')
);
(new Anesda\CRM\SpeedPhone\AclRoleService($db))->synchronize();

speedPhoneInstallDashboardDashlets($db);

$repair = new RepairAndClear();
$repair->repairAndClearAll(['rebuildExtensions'], ['Prospect', 'Call', 'Home'], true, false);
$repair->clearDashlets();
