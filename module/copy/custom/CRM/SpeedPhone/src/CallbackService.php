<?php

namespace Anesda\CRM\SpeedPhone;

/** Rückrufübersicht auf bestehenden Kontakt-UUIDs, ohne Reservierungen zu verändern. */
final class CallbackService
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser,
        private readonly UserAccessService $access,
        private readonly AssignmentService $assignments
    ) {
    }

    public function counts(): array
    {
        $rows = $this->rows();
        return ['mine' => count(array_filter($rows, fn (array $row): bool => $row['is_mine'])), 'all' => count($rows)];
    }

    public function list(array $input): array
    {
        $scope = (string) ($input['scope'] ?? 'mine');
        if (!in_array($scope, ['mine', 'all'], true)) {
            throw new \InvalidArgumentException('Ungültige Rückrufauswahl.');
        }
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search, 'UTF-8') > 200) {
            throw new \InvalidArgumentException('Bitte höchstens 200 Zeichen als Suchbegriff eingeben.');
        }
        $rows = $this->rows();
        $counts = ['mine' => count(array_filter($rows, fn (array $row): bool => $row['is_mine'])), 'all' => count($rows)];
        $rows = array_values(array_filter($rows, static fn (array $row): bool =>
            ($scope === 'all' || $row['is_mine'])
            && ($search === '' || mb_stripos(implode(' ', [$row['name'], $row['phone'], $row['owner'], $row['note']]), $search, 0, 'UTF-8') !== false)
        ));
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) ($input['page'] ?? 1)));
        return ['rows' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
    }

    public function assertCanOpen(string $prospectId): void
    {
        $prospectId = (new InputValidator())->uuid($prospectId);
        foreach ($this->rows($prospectId) as $row) {
            if (!$row['can_open']) {
                throw new \RuntimeException($row['unavailable_reason']);
            }
            return;
        }
        throw new \RuntimeException('Dieser Rückruf ist nicht mehr fällig oder für dich verfügbar.');
    }

    private function rows(?string $prospectId = null): array
    {
        $this->access->assertAllowed();
        $list = $this->db->fetchByAssoc($this->db->query("SELECT id FROM prospect_lists WHERE deleted=0 AND list_type='default' AND name='"
            . $this->db->quote($this->config->requireString('source_list_name')) . "' ORDER BY date_modified DESC LIMIT 1"));
        if (empty($list['id'])) { throw new \RuntimeException('Die SpeedPhone-Zielkontaktliste wurde nicht gefunden.'); }
        $owner = "COALESCE(NULLIF(spa.owner_user_id,''),NULLIF(lc.assigned_user_id,''),NULLIF(lc.created_by,''),p.assigned_user_id)";
        $access = $this->assignments->sqlAccessCondition();
        $visibility = $this->assignments->sqlIncomingAccessCondition();
        $travel = TravelFilter::allowedSql($this->config, fn (string $value): string => $this->db->quote($value));
        $idCondition = $prospectId === null ? '' : " AND p.id='" . $this->db->quote($prospectId) . "'";
        $result = $this->db->query("SELECT p.id,p.account_name,p.first_name,p.last_name,p.description,p.phone_work,p.phone_mobile,
            pc.speedphone_next_call_c next_call,{$owner} owner_user_id,
            u.first_name owner_first_name,u.last_name owner_last_name,u.user_name owner_user_name,
            lc.description note,CASE WHEN {$access} THEN 1 ELSE 0 END can_access,
            spl.user_id lock_user_id,lu.first_name lock_first_name,lu.last_name lock_last_name,lu.user_name lock_user_name
            FROM prospects p
            INNER JOIN prospects_cstm pc ON pc.id_c=p.id
            LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
            LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
            LEFT JOIN calls lc ON lc.id=(SELECT c.id FROM calls c WHERE c.parent_id=p.id AND c.parent_type='Prospects'
                AND c.deleted=0 AND c.status='Held' AND c.direction='Outbound' AND c.name LIKE 'SpeedPhone:%'
                ORDER BY c.date_start DESC,c.date_entered DESC,c.id DESC LIMIT 1)
            LEFT JOIN users u ON u.id={$owner}
            LEFT JOIN crm_speedphone_locks spl ON spl.prospect_id=p.id AND spl.expires_at>UTC_TIMESTAMP()
            LEFT JOIN users lu ON lu.id=spl.user_id
            WHERE p.deleted=0 AND p.do_not_call=0 AND pc.speedphone_status_c='callback'
                AND pc.speedphone_next_call_c IS NOT NULL AND pc.speedphone_next_call_c<>''
                AND pc.speedphone_next_call_c<>'0000-00-00 00:00:00' AND pc.speedphone_next_call_c<=UTC_TIMESTAMP()
                AND {$visibility} AND {$travel}
                AND (TRIM(COALESCE(p.phone_work,''))<>'' OR TRIM(COALESCE(p.phone_mobile,''))<>'')
                AND EXISTS (SELECT 1 FROM prospect_lists_prospects plp WHERE plp.related_id=p.id AND plp.related_type='Prospects'
                    AND plp.deleted=0 AND plp.prospect_list_id='" . $this->db->quote((string) $list['id']) . "')
                {$idCondition}
            ORDER BY pc.speedphone_next_call_c,p.account_name,p.id");
        $priorities = new CandidatePriorityService($this->config);
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            if ($priorities->isExcluded($row)) { continue; }
            $text = implode(' ', [$row['first_name'] ?? '', $row['last_name'] ?? '', $row['account_name'] ?? '', $row['description'] ?? '']);
            foreach ((array) $this->config->get('exclude_patterns', []) as $pattern) {
                if (@preg_match((string) $pattern, $text) === 1) { continue 2; }
            }
            $rows[] = self::normalizeRow($row, (string) $this->currentUser->id);
        }
        return $rows;
    }

    public static function normalizeRow(array $row, string $userId): array
    {
        $person = static fn (string $prefix): string => trim(($row[$prefix . 'first_name'] ?? '') . ' ' . ($row[$prefix . 'last_name'] ?? ''))
            ?: (string) ($row[$prefix . 'user_name'] ?? '');
        $foreignLock = !empty($row['lock_user_id']) && (string) $row['lock_user_id'] !== $userId;
        $allowed = !empty($row['can_access']);
        return ['prospect_id' => (string) $row['id'],
            'name' => trim((string) ($row['account_name'] ?? '')) ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'phone' => trim((string) ($row['phone_work'] ?? '')) ?: trim((string) ($row['phone_mobile'] ?? '')),
            'next_call' => (string) $row['next_call'], 'owner' => $person('owner_') ?: 'Nicht zugeordnet',
            'is_mine' => (string) ($row['owner_user_id'] ?? '') === $userId,
            'note' => (string) ($row['note'] ?? ''), 'can_open' => $allowed && !$foreignLock,
            'unavailable_reason' => $foreignLock ? 'Gerade reserviert: ' . ($person('lock_') ?: 'anderer Mitarbeiter')
                : ($allowed ? '' : 'Exklusiv einem anderen Mitarbeiter zugeordnet')];
    }
}
