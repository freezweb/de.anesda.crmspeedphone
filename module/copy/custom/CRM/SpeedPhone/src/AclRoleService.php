<?php

namespace Anesda\CRM\SpeedPhone;

final class AclRoleService
{
    private const EXTERNAL_ROLE = 'CRM SpeedPhone Extern';
    private const INTERNAL_ROLE = 'CRM SpeedPhone Intern';

    public function __construct(private readonly \DBManager $db)
    {
    }

    /**
     * Synchronisiert ausschließlich die beiden vom Modul verwalteten Rollen.
     * Andere SuiteCRM-Rollen und Rollenzuordnungen bleiben unangetastet.
     */
    public function synchronize(): void
    {
        $externalRoleId = $this->ensureRole(
            self::EXTERNAL_ROLE,
            'Technische Mindestberechtigungen für externe CRM-SpeedPhone-Telefonierer.'
        );
        $internalRoleId = $this->ensureRole(
            self::INTERNAL_ROLE,
            'Technische Mindestberechtigungen für interne CRM-SpeedPhone-Mitarbeiter.'
        );
        $this->configureRole($externalRoleId, false);
        $this->configureRole($internalRoleId, true);

        $result = $this->db->query("SELECT s.user_id, s.user_type
            FROM crm_speedphone_user_settings s
            INNER JOIN users u ON u.id=s.user_id AND u.deleted=0");
        while ($row = $this->db->fetchByAssoc($result)) {
            $userId = (string) $row['user_id'];
            $roleId = match ((string) $row['user_type']) {
                'external' => $externalRoleId,
                'internal' => $internalRoleId,
                default => null,
            };
            $this->setManagedMembership($userId, $roleId, [$externalRoleId, $internalRoleId]);
        }
    }

    private function ensureRole(string $name, string $description): string
    {
        $row = $this->db->fetchByAssoc($this->db->query("SELECT id FROM acl_roles
            WHERE deleted=0 AND name='" . $this->db->quote($name) . "' LIMIT 1"));
        if (is_array($row) && !empty($row['id'])) {
            return (string) $row['id'];
        }

        $id = $this->createGuid();
        $this->db->query("INSERT INTO acl_roles
            (id, date_entered, date_modified, modified_user_id, created_by, name, description, deleted)
            VALUES ('{$id}', UTC_TIMESTAMP(), UTC_TIMESTAMP(), '1', '1',
                    '" . $this->db->quote($name) . "', '" . $this->db->quote($description) . "', 0)");

        return $id;
    }

    private function configureRole(string $roleId, bool $internal): void
    {
        $all = 90;
        $owner = 75;
        $enabled = 89;
        $none = -99;
        $permissions = [
            'Prospects' => [
                'access' => $enabled,
                'view' => $all,
                'edit' => $all,
                'list' => $internal ? $all : $none,
                'delete' => $none,
                'export' => $none,
                'import' => $none,
                'massupdate' => $none,
            ],
            'Calls' => [
                'access' => $enabled,
                'view' => $internal ? $all : $owner,
                'edit' => $all,
                'list' => $internal ? $all : $owner,
                'delete' => $none,
                'export' => $none,
                'import' => $none,
                'massupdate' => $none,
            ],
            'Leads' => [
                'access' => $enabled,
                'view' => $internal ? $all : $owner,
                'edit' => $internal ? $all : $owner,
                'list' => $internal ? $all : $owner,
                'delete' => $none,
                'export' => $none,
                'import' => $none,
                'massupdate' => $none,
            ],
        ];

        $categories = "'Prospects','Calls','Leads'";
        $result = $this->db->query("SELECT id, category, name FROM acl_actions
            WHERE deleted=0 AND category IN ({$categories})");
        while ($action = $this->db->fetchByAssoc($result)) {
            $category = (string) $action['category'];
            $name = (string) $action['name'];
            if (!isset($permissions[$category][$name])) {
                continue;
            }
            $this->setActionOverride($roleId, (string) $action['id'], $permissions[$category][$name]);
        }
    }

    private function setActionOverride(string $roleId, string $actionId, int $access): void
    {
        $roleId = $this->db->quote($roleId);
        $actionId = $this->db->quote($actionId);
        $row = $this->db->fetchByAssoc($this->db->query("SELECT id FROM acl_roles_actions
            WHERE role_id='{$roleId}' AND action_id='{$actionId}' LIMIT 1"));
        if (is_array($row) && !empty($row['id'])) {
            $this->db->query("UPDATE acl_roles_actions
                SET access_override={$access}, deleted=0, date_modified=UTC_TIMESTAMP()
                WHERE id='" . $this->db->quote((string) $row['id']) . "'");
            return;
        }

        $this->db->query("INSERT INTO acl_roles_actions
            (id, role_id, action_id, access_override, date_modified, deleted)
            VALUES ('" . $this->createGuid() . "', '{$roleId}', '{$actionId}', {$access}, UTC_TIMESTAMP(), 0)");
    }

    private function setManagedMembership(string $userId, ?string $targetRoleId, array $managedRoleIds): void
    {
        $quotedUserId = $this->db->quote($userId);
        $quotedRoles = implode(',', array_map(
            fn (string $roleId): string => "'" . $this->db->quote($roleId) . "'",
            $managedRoleIds
        ));
        $this->db->query("UPDATE acl_roles_users
            SET deleted=1, date_modified=UTC_TIMESTAMP()
            WHERE user_id='{$quotedUserId}' AND role_id IN ({$quotedRoles})");
        if ($targetRoleId === null) {
            return;
        }

        $quotedRoleId = $this->db->quote($targetRoleId);
        $row = $this->db->fetchByAssoc($this->db->query("SELECT id FROM acl_roles_users
            WHERE user_id='{$quotedUserId}' AND role_id='{$quotedRoleId}' LIMIT 1"));
        if (is_array($row) && !empty($row['id'])) {
            $this->db->query("UPDATE acl_roles_users SET deleted=0, date_modified=UTC_TIMESTAMP()
                WHERE id='" . $this->db->quote((string) $row['id']) . "'");
            return;
        }

        $this->db->query("INSERT INTO acl_roles_users
            (id, role_id, user_id, date_modified, deleted)
            VALUES ('" . $this->createGuid() . "', '{$quotedRoleId}', '{$quotedUserId}', UTC_TIMESTAMP(), 0)");
    }

    private function createGuid(): string
    {
        if (function_exists('create_guid')) {
            return create_guid();
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
