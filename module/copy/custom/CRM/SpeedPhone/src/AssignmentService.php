<?php

namespace Anesda\CRM\SpeedPhone;

final class AssignmentService
{
    private const TABLE = 'crm_speedphone_assignments';
    private const REACHED_ACTIONS = ['callback', 'email_callback', 'interested', 'no_interest', 'blocked'];

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser,
        private readonly UserAccessService $access
    ) {
    }

    public function sqlAccessCondition(
        string $assignmentAlias = 'spa',
        string $customAlias = 'pc',
        string $prospectAlias = 'p',
        string $creatorSettingsAlias = 'sp_creator'
    ): string {
        $profile = $this->access->currentProfile();
        $userId = $this->db->quote((string) $this->currentUser->id);
        $shared = $profile['can_receive_unassigned']
            ? "({$assignmentAlias}.prospect_id IS NULL
                AND (COALESCE({$creatorSettingsAlias}.user_type, '')<>'external'
                     OR {$prospectAlias}.created_by='{$userId}'))"
            : '0=1';
        $own = "{$assignmentAlias}.owner_user_id='{$userId}'";

        if ($profile['user_type'] === 'external') {
            return "({$own} OR {$shared})";
        }
        if ($profile['user_type'] !== 'internal') {
            return '0=1';
        }

        return "({$own} OR {$shared} OR " . $this->sqlEscalatedExpression($assignmentAlias, $customAlias) . ')';
    }

    /**
     * Eingehende Rückrufe müssen intern auch dann zugeordnet werden können,
     * wenn der Kontakt einem externen Provisionsmitarbeiter gehört. Der
     * Besitzer wird dadurch nicht verändert. Externe Benutzer behalten ihre
     * normale, eingeschränkte Sicht.
     */
    public function sqlIncomingAccessCondition(
        string $assignmentAlias = 'spa',
        string $customAlias = 'pc',
        string $prospectAlias = 'p',
        string $creatorSettingsAlias = 'sp_creator'
    ): string {
        if ($this->access->currentProfile()['user_type'] === 'internal') {
            return '1=1';
        }

        return $this->sqlAccessCondition(
            $assignmentAlias,
            $customAlias,
            $prospectAlias,
            $creatorSettingsAlias
        );
    }

    public function sqlEscalatedExpression(string $assignmentAlias = 'spa', string $customAlias = 'pc'): string
    {
        $options = $this->access->escalationOptions($this->config);
        $callbackDays = (int) $options['callback_escalation_days'];
        $staleDays = (int) $options['external_stale_days'];
        $userId = $this->db->quote((string) $this->currentUser->id);

        return "({$assignmentAlias}.owner_user_id<>'{$userId}'
            AND (
                {$assignmentAlias}.owner_type='disabled'
                OR ({$assignmentAlias}.owner_type='external' AND (
                ({$customAlias}.speedphone_status_c='callback'
                    AND {$customAlias}.speedphone_next_call_c<=DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$callbackDays} DAY))
                OR COALESCE({$assignmentAlias}.last_contact_at, {$assignmentAlias}.assigned_at)
                    <=DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$staleDays} DAY)
                ))
            ))";
    }

    public function recordAction(string $prospectId, string $action, \DateTimeImmutable $when): array
    {
        $profile = $this->access->currentProfile();
        $currentUserId = (string) $this->currentUser->id;
        $isReached = self::actionAssignsOwner($action);
        $assignment = $this->getAssignment($prospectId);

        // Eine Anzeige, Reservierung oder ein erfolgloser Versuch begründet kein Eigentum.
        // Erst ein tatsächlich erreichter Kontakt wird exklusiv dem Telefonierer zugeordnet.
        if ($assignment === null && $isReached) {
            $commission = number_format((float) $profile['commission_percent'], 2, '.', '');
            $this->db->query("INSERT IGNORE INTO " . self::TABLE . "
                (prospect_id, owner_user_id, owner_type, owner_commission_percent,
                 assigned_at, last_activity_at, last_contact_at, last_action_user_id, last_result)
                VALUES ('" . $this->db->quote($prospectId) . "',
                        '" . $this->db->quote($currentUserId) . "',
                        '" . $this->db->quote((string) $profile['user_type']) . "',
                        {$commission},
                        '" . $this->db->quote($when->format('Y-m-d H:i:s')) . "',
                        '" . $this->db->quote($when->format('Y-m-d H:i:s')) . "',
                        '" . $this->db->quote($when->format('Y-m-d H:i:s')) . "',
                        '" . $this->db->quote($currentUserId) . "',
                        '" . $this->db->quote($action) . "')");
            $assignment = $this->getAssignment($prospectId);
        }

        if ($assignment === null) {
            return [];
        }

        if ($assignment['owner_user_id'] !== $currentUserId) {
            if ($profile['user_type'] !== 'internal' || !$assignment['is_escalated']) {
                throw new \RuntimeException('Dieser Kontakt wird exklusiv von einem anderen Mitarbeiter betreut.');
            }

            // Ein interner erfolgloser Versuch bleibt in der Historie, nimmt dem Externen
            // den Kontakt aber noch nicht weg. Die Übernahme erfolgt erst beim Erreichen.
            if ($isReached) {
                $this->db->query("UPDATE " . self::TABLE . " SET
                    owner_user_id='" . $this->db->quote($currentUserId) . "',
                    owner_type='internal',
                    owner_commission_percent=0.00,
                    assigned_at='" . $this->db->quote($when->format('Y-m-d H:i:s')) . "'
                    WHERE prospect_id='" . $this->db->quote($prospectId) . "'");
            }
        }

        $updates = [
            "last_activity_at='" . $this->db->quote($when->format('Y-m-d H:i:s')) . "'",
            "last_action_user_id='" . $this->db->quote($currentUserId) . "'",
            "last_result='" . $this->db->quote($action) . "'",
        ];
        if ($isReached) {
            $updates[] = "last_contact_at='" . $this->db->quote($when->format('Y-m-d H:i:s')) . "'";
        }
        if ($action === 'interested') {
            $commission = $profile['user_type'] === 'external' ? (float) $profile['commission_percent'] : 0.0;
            $updates[] = "won_by_user_id='" . $this->db->quote($currentUserId) . "'";
            $updates[] = "won_at='" . $this->db->quote($when->format('Y-m-d H:i:s')) . "'";
            $updates[] = 'won_commission_percent=' . number_format($commission, 2, '.', '');
        }
        $this->db->query("UPDATE " . self::TABLE . ' SET ' . implode(', ', $updates)
            . " WHERE prospect_id='" . $this->db->quote($prospectId) . "'");

        return $this->getAssignment($prospectId) ?? [];
    }

    public static function actionAssignsOwner(string $action): bool
    {
        return in_array($action, self::REACHED_ACTIONS, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwnedByCurrentUser(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $currentUserId = $this->db->quote((string) $this->currentUser->id);
        $sql = "SELECT p.id,
                       TRIM(COALESCE(NULLIF(p.account_name, ''),
                           CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')))) name,
                       p.phone_work, p.phone_mobile,
                       COALESCE((
                           SELECT ea.email_address
                           FROM email_addr_bean_rel er
                           INNER JOIN email_addresses ea ON ea.id=er.email_address_id AND ea.deleted=0
                           WHERE er.bean_module='Prospects' AND er.bean_id=p.id AND er.deleted=0
                           ORDER BY er.primary_address DESC
                           LIMIT 1
                       ), '') email,
                       COALESCE(pc.speedphone_status_c, '') speedphone_status,
                       pc.speedphone_next_call_c speedphone_next_call,
                       spa.assigned_at, spa.last_contact_at, spa.last_result,
                       spa.won_at, spa.won_commission_percent,
                       'Prospects' record_module,
                       IF(p.created_by='{$currentUserId}', 'Selbst angelegt', 'SpeedPhone-Zuordnung') ownership_source
                FROM " . self::TABLE . " spa
                INNER JOIN prospects p ON p.id=spa.prospect_id AND p.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                WHERE spa.owner_user_id='{$currentUserId}'
                ORDER BY
                    CASE WHEN pc.speedphone_status_c='callback'
                              AND pc.speedphone_next_call_c<=UTC_TIMESTAMP() THEN 0 ELSE 1 END,
                    COALESCE(pc.speedphone_next_call_c, '9999-12-31 23:59:59'),
                    COALESCE(spa.last_contact_at, spa.assigned_at) DESC
                LIMIT {$limit}";
        $result = $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = $row;
        }

        // Viele SuiteCRM-Installationen verwenden für manuell angelegte Kontakte
        // das Modul „Interessenten“ (Leads). Auch diese bestehenden UUIDs erscheinen
        // in der persönlichen Liste, ohne sie in Prospects zu kopieren.
        $leadSql = "SELECT l.id,
                           TRIM(COALESCE(NULLIF(l.account_name, ''),
                               CONCAT(COALESCE(l.first_name, ''), ' ', COALESCE(l.last_name, '')))) name,
                           l.phone_work, l.phone_mobile,
                           '' email,
                           COALESCE(l.status, '') speedphone_status,
                           NULL speedphone_next_call,
                           l.date_entered assigned_at,
                           NULL last_contact_at,
                           NULL last_result,
                           NULL won_at,
                           0.00 won_commission_percent,
                           'Leads' record_module,
                           'Selbst angelegt' ownership_source
                    FROM leads l
                    WHERE l.deleted=0 AND l.created_by='{$currentUserId}'
                    ORDER BY l.date_modified DESC
                    LIMIT {$limit}";
        $result = $this->db->query($leadSql);
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = $row;
        }

        return array_slice($rows, 0, $limit);
    }

    public function getAssignment(string $prospectId): ?array
    {
        $escalated = $this->sqlEscalatedExpression('spa', 'pc');
        $sql = "SELECT spa.*,
                       TRIM(CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,''))) owner_name,
                       owner.user_name owner_username,
                       TRIM(CONCAT(COALESCE(winner.first_name,''), ' ', COALESCE(winner.last_name,''))) won_by_name,
                       CASE WHEN {$escalated} THEN 1 ELSE 0 END is_escalated
                FROM " . self::TABLE . " spa
                INNER JOIN prospects p ON p.id=spa.prospect_id AND p.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                LEFT JOIN users owner ON owner.id=spa.owner_user_id AND owner.deleted=0
                LEFT JOIN users winner ON winner.id=spa.won_by_user_id AND winner.deleted=0
                WHERE spa.prospect_id='" . $this->db->quote($prospectId) . "' LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        if (!is_array($row) || empty($row['prospect_id'])) {
            return null;
        }
        $row['owner_name'] = trim((string) $row['owner_name']) ?: (string) $row['owner_username'];
        $row['is_escalated'] = (int) $row['is_escalated'] === 1;

        return $row;
    }
}
