<?php

final class SpeedPhoneProspectHook
{
    public function assignExternalCreator(SugarBean $bean, string $event, $arguments = []): void
    {
        $isUpdate = is_array($arguments) && array_key_exists('isUpdate', $arguments)
            ? !empty($arguments['isUpdate'])
            : !empty($bean->fetched_row);

        if ($isUpdate || empty($bean->id) || empty($bean->created_by)) {
            return;
        }

        require_once dirname(__DIR__, 2) . '/CRM/SpeedPhone/bootstrap.php';
        global $db;

        $settings = $db->fetchByAssoc($db->query("SELECT commission_percent
            FROM crm_speedphone_user_settings
            WHERE user_id='" . $db->quote((string) $bean->created_by) . "'
              AND user_type='external'
            LIMIT 1"));
        if (!is_array($settings)) {
            return;
        }

        $commission = number_format((float) ($settings['commission_percent'] ?? 0), 2, '.', '');
        $createdAt = !empty($bean->date_entered) ? (string) $bean->date_entered : gmdate('Y-m-d H:i:s');
        $db->query("INSERT IGNORE INTO crm_speedphone_assignments
            (prospect_id, owner_user_id, owner_type, owner_commission_percent,
             assigned_at, last_activity_at)
            VALUES ('" . $db->quote((string) $bean->id) . "',
                    '" . $db->quote((string) $bean->created_by) . "',
                    'external', {$commission},
                    '" . $db->quote($createdAt) . "', '" . $db->quote($createdAt) . "')");
    }
}
