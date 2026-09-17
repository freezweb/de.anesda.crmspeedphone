<?php

namespace Anesda\CRM\SpeedPhone;

/** Lesende Anrufliste unter denselben Zuständigkeitsrechten wie die Anrufmaske. */
final class CallHistoryService
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

    public function list(array $input): array
    {
        $this->access->assertAllowed();
        $period = (string) ($input['period'] ?? 'all');
        $scope = (string) ($input['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'mine'], true)) {
            throw new \InvalidArgumentException('Ungültige Auswahl für die Anrufliste.');
        }
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search, 'UTF-8') > 200) {
            throw new \InvalidArgumentException('Bitte höchstens 200 Zeichen als Suchbegriff eingeben.');
        }
        $where = '';
        if ($period !== 'all') {
            $range = TeamStatisticsService::dateRange($period, (string) ($input['start'] ?? ''), (string) ($input['end'] ?? ''));
            $where .= " AND c.date_start>='" . $this->db->quote($range['from_utc']) . "' AND c.date_start<'" . $this->db->quote($range['until_utc']) . "'";
        }
        if ($scope === 'mine') {
            $where .= " AND COALESCE(NULLIF(c.assigned_user_id,''),c.created_by)='" . $this->db->quote((string) $this->currentUser->id) . "'";
        }
        if ($search !== '') {
            // LOCATE behandelt Prozentzeichen und Unterstriche als echten Suchtext.
            $where .= " AND LOCATE('" . $this->db->quote($search) . "',CONCAT_WS(' ',p.account_name,p.first_name,p.last_name,p.phone_work,p.phone_mobile,c.description))>0";
        }
        $base = $this->baseSql() . $where;
        $count = $this->db->fetchByAssoc($this->db->query('SELECT COUNT(*) total' . $base));
        $total = (int) ($count['total'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) ($input['page'] ?? 1)));
        $result = $this->db->query("SELECT c.id call_id,c.parent_id prospect_id,c.date_start,c.description note,
            c.name call_name,cc.speedphone_result_c result,p.account_name,p.first_name,p.last_name,
            p.phone_work,p.phone_mobile,p.do_not_call,pc.speedphone_status_c current_status,
            u.first_name caller_first_name,u.last_name caller_last_name,u.user_name caller_user_name,
            spl.user_id lock_user_id,lu.first_name lock_first_name,lu.last_name lock_last_name,lu.user_name lock_user_name"
            . $base . ' ORDER BY c.date_start DESC,c.id DESC LIMIT ' . (($page - 1) * self::PAGE_SIZE) . ',' . self::PAGE_SIZE);
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = self::normalizeRow($row, (string) $this->currentUser->id);
        }
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>self::PAGE_SIZE];
    }

    /** Die Call-UUID wird serverseitig auf einen tatsächlich freigegebenen Kontakt aufgelöst. */
    public function prospectIdForCall(string $callId): string
    {
        $this->access->assertAllowed();
        $callId = (new InputValidator())->uuid($callId);
        $row = $this->db->fetchByAssoc($this->db->query('SELECT c.parent_id prospect_id,p.do_not_call,pc.speedphone_status_c current_status' . $this->baseSql()
            . " AND c.id='" . $this->db->quote($callId) . "' LIMIT 1"));
        if (!$row) {
            throw new \RuntimeException('Dieser Anruf ist nicht mehr für dich in SpeedPhone verfügbar.');
        }
        if ((int) $row['do_not_call'] === 1 || $row['current_status'] === 'blocked') {
            throw new \RuntimeException('Für diesen Kontakt besteht eine Anrufsperre. Sie wird durch die Anrufliste nicht aufgehoben.');
        }
        return (new InputValidator())->uuid((string) $row['prospect_id']);
    }

    private function baseSql(): string
    {
        $list = $this->db->fetchByAssoc($this->db->query("SELECT id FROM prospect_lists WHERE deleted=0 AND list_type='default' AND name='"
            . $this->db->quote($this->config->requireString('source_list_name')) . "' ORDER BY date_modified DESC LIMIT 1"));
        if (!$list) { throw new \RuntimeException('Die SpeedPhone-Zielkontaktliste wurde nicht gefunden.'); }
        return " FROM calls c JOIN prospects p ON p.id=c.parent_id
            LEFT JOIN calls_cstm cc ON cc.id_c=c.id
            LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
            LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
            LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
            LEFT JOIN users u ON u.id=COALESCE(NULLIF(c.assigned_user_id,''),c.created_by)
            LEFT JOIN crm_speedphone_locks spl ON spl.prospect_id=p.id AND spl.expires_at>UTC_TIMESTAMP()
            LEFT JOIN users lu ON lu.id=spl.user_id
            WHERE c.deleted=0 AND p.deleted=0 AND c.parent_type='Prospects'
              AND c.status='Held' AND c.direction='Outbound'
              AND (COALESCE(cc.speedphone_result_c,'')<>'' OR c.name LIKE 'SpeedPhone:%')
              AND COALESCE(cc.speedphone_result_c,'')<>'later'
              AND " . $this->assignments->sqlAccessCondition() . "
              AND EXISTS (SELECT 1 FROM prospect_lists_prospects plp
                WHERE plp.related_id=p.id AND plp.related_type='Prospects' AND plp.deleted=0
                  AND plp.prospect_list_id='" . $this->db->quote((string) $list['id']) . "')";
    }

    public static function normalizeRow(array $row, string $currentUserId): array
    {
        $name = trim((string) ($row['account_name'] ?? '')) ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $person = static fn (string $prefix): string => trim(($row[$prefix . 'first_name'] ?? '') . ' ' . ($row[$prefix . 'last_name'] ?? ''))
            ?: ((string) ($row[$prefix . 'user_name'] ?? '') ?: 'Unbekannter Mitarbeiter');
        $result = (string) ($row['result'] ?? '');
        if ($result === '') {
            $result = ['SpeedPhone: Nicht erreicht'=>'not_reached','SpeedPhone: Wiedervorlage oder Rückruf'=>'callback',
                'SpeedPhone: E-Mail gewünscht mit Wiedervorlage'=>'email_callback','SpeedPhone: Interesse'=>'interested',
                'SpeedPhone: Kein Interesse'=>'no_interest','SpeedPhone: Falsche Nummer'=>'wrong_number',
                'SpeedPhone: Nicht mehr kontaktieren'=>'blocked'][(string) ($row['call_name'] ?? '')] ?? 'legacy';
            if (str_starts_with((string) ($row['call_name'] ?? ''), 'SpeedPhone: Produktflyer versendet mit automatisch')) { $result = 'send_flyers'; }
        }
        $unavailable = (int) ($row['do_not_call'] ?? 0) === 1 || ($row['current_status'] ?? '') === 'blocked';
        $foreignLock = !empty($row['lock_user_id']) && (string) $row['lock_user_id'] !== $currentUserId;
        $phone = trim((string) ($row['phone_work'] ?? '')) ?: trim((string) ($row['phone_mobile'] ?? ''));
        return ['call_id'=>(string) $row['call_id'],'prospect_id'=>(string) $row['prospect_id'],
            'name'=>$name ?: 'Unbenannter Zielkontakt','phone'=>$phone,'date_start'=>(string) $row['date_start'],
            'caller'=>$person('caller_'),'result'=>$result,'note'=>(string) ($row['note'] ?? ''),
            'current_status'=>(string) ($row['current_status'] ?? ''),'can_open'=>!$unavailable && !$foreignLock && $phone !== '',
            'unavailable_reason'=>$unavailable ? 'Anrufsperre bleibt bestehen' : ($foreignLock ? 'Gerade reserviert: ' . $person('lock_') : ($phone === '' ? 'Keine Telefonnummer hinterlegt' : ''))];
    }
}
