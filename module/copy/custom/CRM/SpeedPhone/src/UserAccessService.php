<?php

namespace Anesda\CRM\SpeedPhone;

final class UserAccessService
{
    private const TABLE = 'crm_speedphone_user_settings';
    private const OPTIONS_TABLE = 'crm_speedphone_options';
    private const ROLES = ['internal', 'external', 'disabled'];

    private ?array $currentProfile = null;

    public function __construct(
        private readonly \DBManager $db,
        private readonly \User $currentUser
    ) {
    }

    public function assertAllowed(): void
    {
        if ($this->currentProfile()['user_type'] === 'disabled') {
            throw new \RuntimeException('Dein Benutzer ist für CRM SpeedPhone nicht freigeschaltet.');
        }
    }

    /**
     * @return array{user_id:string,user_type:string,commission_percent:float,can_receive_unassigned:bool,can_manage:bool,pbx_extension:string}
     */
    public function currentProfile(): array
    {
        if ($this->currentProfile !== null) {
            return $this->currentProfile;
        }

        $userId = (string) $this->currentUser->id;
        $sql = "SELECT user_type, commission_percent, can_receive_unassigned, can_manage, pbx_extension
                FROM " . self::TABLE . "
                WHERE user_id='" . $this->db->quote($userId) . "' LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        if (!is_array($row) || !in_array((string) ($row['user_type'] ?? ''), self::ROLES, true)) {
            $isAdmin = !empty($this->currentUser->is_admin);
            $row = [
                'user_type' => $isAdmin ? 'internal' : 'disabled',
                'commission_percent' => 0,
                'can_receive_unassigned' => $isAdmin ? 1 : 0,
                'can_manage' => $isAdmin ? 1 : 0,
                'pbx_extension' => '',
            ];
        }

        $this->currentProfile = [
            'user_id' => $userId,
            'user_type' => (string) $row['user_type'],
            'commission_percent' => (float) $row['commission_percent'],
            'can_receive_unassigned' => (int) $row['can_receive_unassigned'] === 1,
            'can_manage' => (int) $row['can_manage'] === 1 && (string) $row['user_type'] === 'internal',
            'pbx_extension' => trim((string) ($row['pbx_extension'] ?? '')),
        ];

        return $this->currentProfile;
    }

    public function canManageTeam(): bool
    {
        return $this->currentProfile()['can_manage'];
    }

    public function assertCanManageTeam(): void
    {
        if (!$this->canManageTeam()) {
            throw new \RuntimeException('Du darfst die SpeedPhone-Teamrechte nicht verwalten.');
        }
    }

    public function optionInt(string $name, int $fallback, int $minimum, int $maximum): int
    {
        $sql = "SELECT option_value FROM " . self::OPTIONS_TABLE . "
                WHERE option_name='" . $this->db->quote($name) . "' LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        $value = is_array($row) ? (int) ($row['option_value'] ?? $fallback) : $fallback;

        return max($minimum, min($maximum, $value));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTeamUsers(): array
    {
        $this->assertCanManageTeam();
        $sql = "SELECT u.id, u.user_name, u.first_name, u.last_name, u.is_admin,
                       s.user_type, s.commission_percent, s.can_receive_unassigned, s.can_manage,
                       s.pbx_extension,
                       COALESCE(a.assigned_count, 0) assigned_count,
                       COALESCE(a.won_count, 0) won_count
                FROM users u
                LEFT JOIN " . self::TABLE . " s ON s.user_id=u.id
                LEFT JOIN (
                    SELECT owner_user_id,
                           COUNT(*) assigned_count,
                           SUM(won_by_user_id=owner_user_id) won_count
                    FROM crm_speedphone_assignments
                    GROUP BY owner_user_id
                ) a ON a.owner_user_id=u.id
                WHERE u.deleted=0 AND u.status='Active' AND u.employee_status='Active'
                ORDER BY u.first_name, u.last_name, u.user_name";
        $result = $this->db->query($sql);
        $users = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $hasStoredSettings = in_array((string) ($row['user_type'] ?? ''), self::ROLES, true);
            $isAdmin = (int) ($row['is_admin'] ?? 0) === 1;
            $userType = $hasStoredSettings ? (string) $row['user_type'] : ($isAdmin ? 'internal' : 'disabled');
            $users[] = [
                'id' => (string) $row['id'],
                'user_name' => (string) $row['user_name'],
                'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?: (string) $row['user_name'],
                'is_admin' => $isAdmin,
                'user_type' => $userType,
                'commission_percent' => $hasStoredSettings ? (float) $row['commission_percent'] : 0.0,
                'can_receive_unassigned' => $hasStoredSettings
                    ? (int) $row['can_receive_unassigned'] === 1
                    : $isAdmin,
                'can_manage' => $hasStoredSettings ? (int) $row['can_manage'] === 1 : $isAdmin,
                'pbx_extension' => $hasStoredSettings ? trim((string) ($row['pbx_extension'] ?? '')) : '',
                'assigned_count' => (int) $row['assigned_count'],
                'won_count' => (int) $row['won_count'],
            ];
        }

        return $users;
    }

    public function escalationOptions(Config $config): array
    {
        return [
            'callback_escalation_days' => $this->optionInt(
                'callback_escalation_days',
                (int) $config->get('callback_escalation_days', 2),
                0,
                30
            ),
            'external_stale_days' => $this->optionInt(
                'external_stale_days',
                (int) $config->get('external_stale_days', 14),
                1,
                180
            ),
        ];
    }

    public function saveTeamSettings(array $input, Config $config): void
    {
        $this->assertCanManageTeam();
        $roles = is_array($input['user_type'] ?? null) ? $input['user_type'] : [];
        $commissions = is_array($input['commission_percent'] ?? null) ? $input['commission_percent'] : [];
        $receivers = is_array($input['can_receive_unassigned'] ?? null) ? $input['can_receive_unassigned'] : [];
        $managers = is_array($input['can_manage'] ?? null) ? $input['can_manage'] : [];
        $pbxExtensions = is_array($input['pbx_extension'] ?? null) ? $input['pbx_extension'] : [];

        $activeUsers = [];
        $result = $this->db->query("SELECT id, is_admin FROM users
                                   WHERE deleted=0 AND status='Active' AND employee_status='Active'");
        while ($row = $this->db->fetchByAssoc($result)) {
            $activeUsers[(string) $row['id']] = (int) $row['is_admin'] === 1;
        }

        $normalized = [];
        $managerCount = 0;
        foreach ($activeUsers as $userId => $isAdmin) {
            $role = (string) ($roles[$userId] ?? 'disabled');
            if (!in_array($role, self::ROLES, true)) {
                throw new \InvalidArgumentException('Ungültige SpeedPhone-Rolle.');
            }
            $commission = str_replace(',', '.', trim((string) ($commissions[$userId] ?? '0')));
            if ($commission === '' || !is_numeric($commission)) {
                throw new \InvalidArgumentException('Der Provisionssatz ist ungültig.');
            }
            $commissionValue = max(0.0, min(100.0, (float) $commission));
            if ($role !== 'external') {
                $commissionValue = 0.0;
            }
            $canReceive = $role !== 'disabled' && isset($receivers[$userId]);
            $canManage = $role === 'internal' && isset($managers[$userId]);
            $pbxExtension = trim((string) ($pbxExtensions[$userId] ?? ''));
            if ($pbxExtension !== '' && !preg_match('/^[1-9][0-9]{2,7}$/', $pbxExtension)) {
                throw new \InvalidArgumentException('Die Festnetz-Durchwahl muss aus 3 bis 8 Ziffern bestehen.');
            }
            if ($canManage) {
                $managerCount++;
            }
            $normalized[$userId] = compact('role', 'commissionValue', 'canReceive', 'canManage', 'pbxExtension');
        }
        if ($managerCount < 1) {
            throw new \InvalidArgumentException('Mindestens ein interner Benutzer muss die Teamverwaltung behalten.');
        }

        $callbackDays = max(0, min(30, (int) ($input['callback_escalation_days'] ?? $config->get('callback_escalation_days', 2))));
        $staleDays = max(1, min(180, (int) ($input['external_stale_days'] ?? $config->get('external_stale_days', 14))));

        $this->db->query('START TRANSACTION');
        try {
            foreach ($normalized as $userId => $settings) {
                $role = $this->db->quote($settings['role']);
                $commission = number_format($settings['commissionValue'], 2, '.', '');
                $canReceive = $settings['canReceive'] ? 1 : 0;
                $canManage = $settings['canManage'] ? 1 : 0;
                $pbxExtension = $this->db->quote($settings['pbxExtension']);
                $quotedUserId = $this->db->quote($userId);
                $this->db->query("INSERT INTO " . self::TABLE . "
                    (user_id, user_type, commission_percent, can_receive_unassigned, can_manage, pbx_extension, date_modified)
                    VALUES ('{$quotedUserId}', '{$role}', {$commission}, {$canReceive}, {$canManage}, '{$pbxExtension}', UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE
                        user_type=VALUES(user_type),
                        commission_percent=VALUES(commission_percent),
                        can_receive_unassigned=VALUES(can_receive_unassigned),
                        can_manage=VALUES(can_manage),
                        pbx_extension=VALUES(pbx_extension),
                        date_modified=UTC_TIMESTAMP()");
            }
            $this->db->query("UPDATE crm_speedphone_assignments spa
                              INNER JOIN " . self::TABLE . " s ON s.user_id=spa.owner_user_id
                              SET spa.owner_type=s.user_type,
                                  spa.owner_commission_percent=s.commission_percent");
            $this->db->query("INSERT IGNORE INTO crm_speedphone_assignments
                (prospect_id, owner_user_id, owner_type, owner_commission_percent,
                 assigned_at, last_activity_at)
                SELECT p.id, p.created_by, 'external', s.commission_percent,
                       COALESCE(p.date_entered, UTC_TIMESTAMP()), COALESCE(p.date_entered, UTC_TIMESTAMP())
                FROM prospects p
                INNER JOIN " . self::TABLE . " s
                    ON s.user_id=p.created_by AND s.user_type='external'
                WHERE p.deleted=0 AND COALESCE(p.created_by, '')<>''");
            (new AclRoleService($this->db))->synchronize();
            foreach ([
                'callback_escalation_days' => $callbackDays,
                'external_stale_days' => $staleDays,
            ] as $name => $value) {
                $this->db->query("INSERT INTO " . self::OPTIONS_TABLE . "
                    (option_name, option_value, date_modified)
                    VALUES ('" . $this->db->quote($name) . "', '" . (int) $value . "', UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE option_value=VALUES(option_value), date_modified=UTC_TIMESTAMP()");
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }

        $this->currentProfile = null;
    }
}
