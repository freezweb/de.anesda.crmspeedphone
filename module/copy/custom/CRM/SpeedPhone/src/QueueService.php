<?php

namespace Anesda\CRM\SpeedPhone;

final class QueueService
{
    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser
    ) {
    }

    public function assertUserAllowed(): void
    {
        $allowed = array_filter(array_map('strval', (array) $this->config->get('allowed_usernames', [])));
        if ($allowed !== [] && !$this->currentUser->is_admin && !in_array($this->currentUser->user_name, $allowed, true)) {
            throw new \RuntimeException('Dein Benutzer ist für CRM SpeedPhone nicht freigeschaltet.');
        }
    }

    public function getNextCandidate(): ?array
    {
        $this->assertUserAllowed();
        $listId = $this->getSourceListId();
        $batchSize = max(20, min(500, (int) $this->config->get('candidate_batch_size', 200)));
        $userCondition = $this->userCondition();

        $sql = "SELECT p.id, p.first_name, p.last_name, p.account_name, p.description,
                       p.phone_work, p.phone_mobile, p.primary_address_street,
                       p.primary_address_postalcode, p.primary_address_city,
                       COALESCE(pc.speedphone_status_c, '') speedphone_status,
                       COALESCE(pc.speedphone_attempts_c, 0) speedphone_attempts,
                       pc.speedphone_next_call_c speedphone_next_call,
                       COALESCE(eng.clicked, 0) clicked,
                       COALESCE(eng.viewed, 0) viewed
                FROM prospects p
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id
                   AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote($listId) . "'
                   AND plp.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                LEFT JOIN (
                    SELECT target_id,
                           MAX(activity_type='link') clicked,
                           MAX(activity_type='viewed') viewed
                    FROM campaign_log
                    WHERE deleted=0 AND target_type='Prospects'
                    GROUP BY target_id
                ) eng ON eng.target_id=p.id
                WHERE p.deleted=0
                  AND p.do_not_call=0
                  AND {$userCondition}
                  AND (TRIM(COALESCE(p.phone_work, ''))<>'' OR TRIM(COALESCE(p.phone_mobile, ''))<>'')
                  AND COALESCE(pc.speedphone_status_c, '') NOT IN
                      ('interested', 'no_interest', 'invalid_phone', 'blocked', 'paused')
                  AND (pc.speedphone_next_call_c IS NULL OR pc.speedphone_next_call_c='' OR pc.speedphone_next_call_c<=UTC_TIMESTAMP())
                ORDER BY
                    CASE COALESCE(pc.speedphone_status_c, '')
                        WHEN 'callback' THEN 0
                        WHEN 'retry' THEN 1
                        ELSE 2
                    END,
                    COALESCE(pc.speedphone_next_call_c, '1970-01-01 00:00:00'),
                    COALESCE(eng.clicked, 0) DESC,
                    COALESCE(eng.viewed, 0) DESC,
                    COALESCE(pc.speedphone_attempts_c, 0),
                    p.date_entered
                LIMIT %d, {$batchSize}";

        // Ausschlussmuster werden absichtlich zusätzlich in PHP geprüft. Deshalb
        // mehrere Seiten durchsuchen, damit eine stark gesperrte erste Seite die
        // Warteschlange nicht fälschlich als leer erscheinen lässt.
        $maxCandidates = max($batchSize, (int) $this->config->get('candidate_scan_limit', 5000));
        for ($offset = 0; $offset < $maxCandidates; $offset += $batchSize) {
            $result = $this->db->query(sprintf($sql, $offset));
            $rowsRead = 0;
            while ($row = $this->db->fetchByAssoc($result)) {
                $rowsRead++;
                if ($this->isExcluded($row)) {
                    continue;
                }

                return $this->enrichCandidate($row);
            }

            if ($rowsRead < $batchSize) {
                break;
            }
        }

        return null;
    }

    public function getStatistics(): array
    {
        $listId = $this->getSourceListId();
        $userCondition = $this->userCondition();
        $common = " FROM prospects p
                    INNER JOIN prospect_lists_prospects plp
                        ON plp.related_id=p.id AND plp.related_type='Prospects'
                       AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                    LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                    WHERE p.deleted=0 AND p.do_not_call=0 AND {$userCondition}";

        return [
            'open' => $this->scalar("SELECT COUNT(*) n {$common}
                AND (TRIM(COALESCE(p.phone_work, ''))<>'' OR TRIM(COALESCE(p.phone_mobile, ''))<>'')
                AND COALESCE(pc.speedphone_status_c, '') NOT IN ('interested','no_interest','invalid_phone','blocked','paused')"),
            'callbacks_due' => $this->scalar("SELECT COUNT(*) n {$common}
                AND pc.speedphone_status_c='callback'
                AND pc.speedphone_next_call_c<=UTC_TIMESTAMP()"),
            'processed_today' => $this->scalar("SELECT COUNT(*) n {$common}
                AND DATE(pc.speedphone_last_call_c)=UTC_DATE()"),
            'interested' => $this->scalar("SELECT COUNT(*) n {$common}
                AND pc.speedphone_status_c='interested'"),
        ];
    }

    public function canEditProspect(string $id): bool
    {
        $listId = $this->getSourceListId();
        $userCondition = $this->userCondition();
        $sql = "SELECT p.first_name, p.last_name, p.account_name, p.description
                FROM prospects p
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                WHERE p.id='" . $this->db->quote($id) . "'
                  AND p.deleted=0 AND p.do_not_call=0 AND {$userCondition}
                LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));

        return is_array($row) && !$this->isExcluded($row);
    }

    private function getSourceListId(): string
    {
        $name = $this->config->requireString('source_list_name');
        $sql = "SELECT id FROM prospect_lists
                WHERE deleted=0 AND list_type='default'
                  AND name='" . $this->db->quote($name) . "'
                ORDER BY date_modified DESC LIMIT 1";
        $result = $this->db->query($sql);
        $row = $this->db->fetchByAssoc($result);
        if (empty($row['id'])) {
            throw new \RuntimeException(sprintf('Die Zielkontaktliste „%s“ wurde nicht gefunden.', $name));
        }

        return $row['id'];
    }

    private function isExcluded(array $candidate): bool
    {
        $haystack = implode(' ', [
            $candidate['first_name'] ?? '',
            $candidate['last_name'] ?? '',
            $candidate['account_name'] ?? '',
            $candidate['description'] ?? '',
        ]);

        foreach ((array) $this->config->get('exclude_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function enrichCandidate(array $candidate): array
    {
        $prospect = \BeanFactory::getBean('Prospects', $candidate['id']);
        if (!$prospect || empty($prospect->id)) {
            throw new \RuntimeException('Der ausgewählte Zielkontakt konnte nicht geladen werden.');
        }

        $candidate['name'] = trim((string) ($prospect->account_name ?: trim($prospect->first_name . ' ' . $prospect->last_name)));
        $candidate['email'] = (string) ($prospect->emailAddress?->getPrimaryAddress($prospect) ?? '');
        $candidate['website'] = $this->extractWebsite((string) $prospect->description);
        $candidate['score'] = 0;
        $candidate['reasons'] = [];

        if ((int) $candidate['clicked'] === 1) {
            $candidate['score'] += 100;
            $candidate['reasons'][] = 'Link in einer Kampagnenmail angeklickt';
        }
        if ((int) $candidate['viewed'] === 1) {
            $candidate['score'] += 30;
            $candidate['reasons'][] = 'Kampagnenmail geöffnet';
        }

        foreach ((array) $this->config->get('local_postcode_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, (string) $candidate['primary_address_postalcode']) === 1) {
                $candidate['score'] += 20;
                $candidate['reasons'][] = 'Regionaler Betrieb';
                break;
            }
        }

        $haystack = implode(' ', [$candidate['name'], $candidate['description'] ?? '']);
        foreach ((array) $this->config->get('positive_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $haystack) === 1) {
                $candidate['score'] += 10;
                $candidate['reasons'][] = 'Passende Unternehmensart';
                break;
            }
        }

        if ($candidate['reasons'] === []) {
            $candidate['reasons'][] = 'Telefonnummer und aktive Listenzuordnung vorhanden';
        }

        $candidate['recent_calls'] = $this->getRecentCalls($candidate['id']);

        return $candidate;
    }

    private function getRecentCalls(string $prospectId): array
    {
        $sql = "SELECT name, status, direction, date_start, description
                FROM calls
                WHERE deleted=0 AND parent_type='Prospects'
                  AND parent_id='" . $this->db->quote($prospectId) . "'
                ORDER BY date_start DESC LIMIT 5";
        $result = $this->db->query($sql);
        $calls = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $calls[] = $row;
        }

        return $calls;
    }

    private function extractWebsite(string $description): string
    {
        if (preg_match('~(?:Web:\\s*)?(https?://[^\\s<]+)~iu', $description, $matches) !== 1) {
            return '';
        }

        return rtrim($matches[1], ".,;)\\r\\n");
    }

    private function scalar(string $sql): int
    {
        $result = $this->db->query($sql);
        $row = $this->db->fetchByAssoc($result);

        return (int) ($row['n'] ?? 0);
    }

    private function userCondition(): string
    {
        if (!(bool) $this->config->get('restrict_to_assigned_user', true) && $this->currentUser->is_admin) {
            return '1=1';
        }

        return "p.assigned_user_id='" . $this->db->quote($this->currentUser->id) . "'";
    }
}
