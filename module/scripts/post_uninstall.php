<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/Administration/QuickRepairAndRebuild.php';

global $db;

$result = $db->query("SELECT id, contents FROM user_preferences WHERE deleted=0 AND category='Home'");
while ($row = $db->fetchByAssoc($result)) {
    $decoded = base64_decode((string) ($row['contents'] ?? ''), true);
    $preferences = $decoded === false ? false : @unserialize($decoded, ['allowed_classes' => false]);
    if (!is_array($preferences) || empty($preferences['dashlets'])) {
        continue;
    }

    $removedIds = [];
    foreach ($preferences['dashlets'] as $dashletId => $dashlet) {
        if (($dashlet['className'] ?? '') === 'CRMSpeedPhoneDashlet') {
            $removedIds[] = $dashletId;
            unset($preferences['dashlets'][$dashletId]);
        }
    }
    if ($removedIds === []) {
        continue;
    }

    if (!isset($preferences['pages']) || !is_array($preferences['pages'])) {
        $preferences['pages'] = [];
    }
    foreach ($preferences['pages'] as &$page) {
        if (!isset($page['columns']) || !is_array($page['columns'])) {
            $page['columns'] = [];
        }
        foreach ($page['columns'] as &$column) {
            $column['dashlets'] = array_values(array_diff((array) ($column['dashlets'] ?? []), $removedIds));
        }
    }
    unset($page, $column);

    $contents = base64_encode(serialize($preferences));
    $db->query("UPDATE user_preferences
                SET contents='" . $db->quote($contents) . "', date_modified=UTC_TIMESTAMP()
                WHERE id='" . $db->quote((string) $row['id']) . "'");
}

$repair = new RepairAndClear();
$repair->repairAndClearAll(['rebuildExtensions'], ['Prospect', 'Call', 'Home'], true, false);
