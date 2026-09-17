<?php

namespace Anesda\CRM\SpeedPhone;

/** Teamweite, rein lesende Auswertung tatsächlicher SpeedPhone-Anrufprotokolle. */
final class TeamStatisticsService
{
    public const REACHED_RESULTS = ['callback', 'email_callback', 'send_flyers', 'interested', 'no_interest'];
    public const METRICS = [
        'processed' => 'Anrufe', 'reached' => 'Erreicht', 'not_reached' => 'Nicht erreicht',
        'interested' => 'Interesse', 'no_interest' => 'Kein Interesse',
        'callbacks' => 'Rückruf / Wiedervorlage', 'email_requested' => 'Mail angefordert',
        'wrong_number' => 'Falsche Nummer', 'blocked' => 'Kontaktsperre', 'other' => 'Unklar / Altprotokoll',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly UserAccessService $access
    ) {
    }

    /** Einheitliche Berliner Kalendertage für alle Teammitglieder, inklusive Sommerzeit. */
    public static function dateRange(string $preset, string $start = '', string $end = '', ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $today = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
        [$start, $end] = match ($preset) {
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            '7days' => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            '30days' => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [$start, $end],
            default => throw new \InvalidArgumentException('Bitte einen gültigen Statistikzeitraum wählen.'),
        };
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $timezone);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end
            || $from > $to || $from->diff($to)->days > 365) {
            throw new \InvalidArgumentException('Bitte gültige Datumswerte angeben: maximal 366 Tage, Ende nicht vor Beginn.');
        }
        return ['start' => $start, 'end' => $end,
            'from_utc' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'until_utc' => $to->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    public function report(string $preset = '7days', string $start = '', string $end = '', string $userId = ''): array
    {
        // Jeder freigeschaltete SpeedPhone-Benutzer, ausdrücklich keine Adminpflicht.
        $this->access->assertAllowed();
        $range = self::dateRange($preset, $start, $end);
        if (strlen($userId) > 64 || ($userId !== '' && preg_match('/^[a-zA-Z0-9-]+$/', $userId) !== 1)) {
            throw new \InvalidArgumentException('Ungültige Mitarbeiterauswahl.');
        }
        $list = $this->db->fetchByAssoc($this->db->query("SELECT id FROM prospect_lists WHERE deleted=0 AND name='"
            . $this->db->quote($this->config->requireString('source_list_name')) . "' LIMIT 1"));
        if (!$list) {
            throw new \RuntimeException('Die SpeedPhone-Zielkontaktliste wurde nicht gefunden.');
        }
        $excluded = array_values(array_filter(array_map('strval', (array) $this->config->get('statistics_excluded_user_names', []))));
        $exclusionSql = $excluded === [] ? '' : " AND COALESCE(u.user_name,'') NOT IN ("
            . implode(',', array_map(fn (string $value): string => "'" . $this->db->quote($value) . "'", $excluded)) . ')';
        // Pro UTC-Stunde aggregieren, lokale Tage in PHP umrechnen: benötigt keine
        // MySQL-Zeitzonentabellen und bleibt an Sommerzeitwechseln korrekt.
        $sql = "SELECT COALESCE(NULLIF(c.assigned_user_id,''),c.created_by) user_id,
                    u.first_name,u.last_name,u.user_name,
                    DATE_FORMAT(c.date_start,'%Y-%m-%d %H:00:00') hour_utc,
                    COALESCE(NULLIF(cc.speedphone_result_c,''),CASE
                      WHEN c.name='SpeedPhone: Nicht erreicht' THEN 'not_reached'
                      WHEN c.name='SpeedPhone: Wiedervorlage oder Rückruf' THEN 'callback'
                      WHEN c.name='SpeedPhone: E-Mail gewünscht mit Wiedervorlage' THEN 'email_callback'
                      WHEN c.name LIKE 'SpeedPhone: Produktflyer versendet mit automatisch%' THEN 'send_flyers'
                      WHEN c.name='SpeedPhone: Interesse' THEN 'interested'
                      WHEN c.name='SpeedPhone: Kein Interesse' THEN 'no_interest'
                      WHEN c.name='SpeedPhone: Falsche Nummer' THEN 'wrong_number'
                      WHEN c.name='SpeedPhone: Nicht mehr kontaktieren' THEN 'blocked'
                      ELSE 'legacy' END) result,
                    COUNT(*) amount,
                    SUM(COALESCE(cc.speedphone_email_requested_c,0)<>0) email_requested
                FROM calls c LEFT JOIN calls_cstm cc ON cc.id_c=c.id
                LEFT JOIN users u ON u.id=COALESCE(NULLIF(c.assigned_user_id,''),c.created_by)
                WHERE c.deleted=0 AND c.status='Held' AND c.direction='Outbound'
                  AND c.parent_type='Prospects'
                  AND (COALESCE(cc.speedphone_result_c,'')<>'' OR c.name LIKE 'SpeedPhone:%')
                  AND COALESCE(cc.speedphone_result_c,'')<>'later'
                  AND c.date_start>='" . $this->db->quote($range['from_utc']) . "'
                  AND c.date_start<'" . $this->db->quote($range['until_utc']) . "'
                  AND EXISTS (SELECT 1 FROM prospect_lists_prospects plp
                    WHERE plp.related_id=c.parent_id AND plp.related_type='Prospects' AND plp.deleted=0
                      AND plp.prospect_list_id='" . $this->db->quote((string) $list['id']) . "') {$exclusionSql}
                GROUP BY user_id,u.first_name,u.last_name,u.user_name,hour_utc,result";
        $result = $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = $row;
        }
        // Aktive Teammitglieder ohne Anrufe gehören ebenfalls in die Auswahl.
        $result = $this->db->query("SELECT u.id user_id,u.first_name,u.last_name,u.user_name
            FROM users u LEFT JOIN crm_speedphone_user_settings s ON s.user_id=u.id
            WHERE u.deleted=0 AND u.status='Active'
              AND COALESCE(s.user_type,CASE WHEN u.is_admin=1 THEN 'internal' ELSE 'disabled' END) IN ('internal','external') {$exclusionSql}");
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = $row + ['hour_utc' => $range['from_utc'], 'result' => 'legacy', 'amount' => 0, 'email_requested' => 0];
        }
        return self::aggregate($rows, $range, $userId);
    }

    public static function aggregate(array $rows, array $range, string $selectedUserId = ''): array
    {
        $empty = array_fill_keys(array_keys(self::METRICS), 0);
        $users = [];
        $days = [];
        $timezone = new \DateTimeZone('Europe/Berlin');
        for ($day = new \DateTimeImmutable($range['start'], $timezone); $day->format('Y-m-d') <= $range['end']; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = $empty;
        }
        $totals = $empty;
        foreach ($rows as $row) {
            $id = (string) $row['user_id'];
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            $name = $name ?: ((string) ($row['user_name'] ?? '') ?: 'Ehemaliger / unbekannter Mitarbeiter');
            $users[$id] ??= ['id' => $id, 'name' => $name, 'metrics' => $empty, 'days' => []];
            $date = (new \DateTimeImmutable((string) $row['hour_utc'], new \DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
            if (!isset($days[$date])) {
                continue;
            }
            $count = max(0, (int) $row['amount']);
            $result = (string) $row['result'];
            $metrics = $empty;
            $metrics['processed'] = $count;
            $metrics['reached'] = in_array($result, self::REACHED_RESULTS, true) ? $count : 0;
            $metrics['callbacks'] = in_array($result, ['callback', 'email_callback', 'send_flyers'], true) ? $count : 0;
            $metrics['email_requested'] = max(0, min($count, (int) ($row['email_requested'] ?? 0)));
            // Diese Aktionen bedeuten bereits ausdrücklich eine Mailanforderung;
            // ältere CRM-Versionen speicherten das zusätzliche Häkchen teils nicht.
            if (in_array($result, ['email_callback', 'send_flyers'], true)) {
                $metrics['email_requested'] = $count;
            }
            if (array_key_exists($result, $metrics) && !in_array($result, ['processed', 'reached', 'callbacks', 'email_requested', 'other'], true)) {
                $metrics[$result] = $count;
            } elseif (!in_array($result, ['callback', 'email_callback', 'send_flyers'], true)) {
                $metrics['other'] = $count;
            }
            $users[$id]['days'][$date] ??= $empty;
            foreach ($metrics as $key => $value) {
                $users[$id]['metrics'][$key] += $value;
                $users[$id]['days'][$date][$key] += $value;
                if ($selectedUserId === '' || $selectedUserId === $id) {
                    $days[$date][$key] += $value;
                    $totals[$key] += $value;
                }
            }
        }
        uasort($users, static fn (array $a, array $b): int => ($b['metrics']['processed'] <=> $a['metrics']['processed']) ?: strcasecmp($a['name'], $b['name']));
        if ($selectedUserId !== '' && !isset($users[$selectedUserId])) {
            throw new \InvalidArgumentException('Dieser Mitarbeiter ist nicht in der aktuellen Statistik verfügbar. Bitte „Alle“ wählen.');
        }
        return ['range' => $range, 'users' => array_values($users), 'days' => $days, 'totals' => $totals,
            'selected_user_id' => $selectedUserId, 'generated_at' => (new \DateTimeImmutable('now', $timezone))->format('d.m.Y H:i:s')];
    }
}
