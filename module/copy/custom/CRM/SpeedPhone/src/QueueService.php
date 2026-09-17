<?php

namespace Anesda\CRM\SpeedPhone;

final class QueueService
{
    private readonly CandidatePriorityService $priorities;
    private readonly LinkedInContactService $linkedInContacts;

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser,
        private readonly LockService $locks,
        private readonly UserAccessService $access,
        private readonly AssignmentService $assignments
    ) {
        $this->priorities = new CandidatePriorityService($config);
        $this->linkedInContacts = new LinkedInContactService($config, $db);
    }

    public function assertUserAllowed(): void
    {
        $this->access->assertAllowed();
    }

    public function getNextCandidate(?string $excludedProspectId = null): ?array
    {
        $this->assertUserAllowed();
        if ($excludedProspectId !== null
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $excludedProspectId) !== 1) {
            throw new \InvalidArgumentException('Die auszunehmende Kontaktkennung ist ungültig.');
        }
        $listId = $this->getSourceListId();
        $batchSize = max(20, min(500, (int) $this->config->get('candidate_batch_size', 200)));
        $userCondition = $this->assignments->sqlAccessCondition();
        $baseSql = $this->candidateSelectSql($listId, $userCondition);

        $this->locks->cleanupExpired();
        $activeLock = $this->locks->getActiveForCurrentUser();
        if ($activeLock !== null) {
            $activeCandidate = $this->findCandidateById($baseSql, $activeLock['prospect_id']);
            if (($excludedProspectId === null || !hash_equals($excludedProspectId, $activeLock['prospect_id']))
                && $activeCandidate !== null
                && !$this->isExcluded($activeCandidate)) {
                return $this->enrichCandidate($activeCandidate, $activeLock);
            }
            $this->locks->releaseCurrentUserLock();
        }

        $excludedSql = $excludedProspectId === null
            ? ''
            : " AND p.id<>'" . $this->db->quote($excludedProspectId) . "'";
        $sql = $baseSql . ' AND ' . IndustryFilter::allowedSql(IndustryFilter::selected($this->currentUser), fn (string $value): string => $this->db->quote($value)) . $excludedSql . "
                  AND NOT EXISTS (
                      SELECT 1 FROM crm_speedphone_locks spl
                      WHERE spl.prospect_id=p.id AND spl.expires_at>UTC_TIMESTAMP()
                  )
                ORDER BY
                    CASE COALESCE(pc.speedphone_status_c, '')
                        WHEN 'callback' THEN 0
                        ELSE 1
                    END,
                    CASE
                        WHEN spa.owner_user_id='" . $this->db->quote((string) $this->currentUser->id) . "' THEN 0
                        WHEN spa.owner_type='external' THEN 1
                        ELSE 2
                    END,
                    speedphone_priority_tier,
                    CASE COALESCE(pc.speedphone_status_c, '')
                        WHEN 'retry' THEN 0
                        ELSE 1
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

                $lock = $this->locks->acquire((string) $row['id']);
                if ($lock === null) {
                    continue;
                }
                if (!hash_equals((string) $row['id'], $lock['prospect_id'])) {
                    $lockedCandidate = $this->findCandidateById($baseSql, $lock['prospect_id']);
                    if ($lockedCandidate !== null && !$this->isExcluded($lockedCandidate)) {
                        return $this->enrichCandidate($lockedCandidate, $lock);
                    }
                    $this->locks->releaseCurrentUserLock();
                    continue;
                }

                return $this->enrichCandidate($row, $lock);
            }

            if ($rowsRead < $batchSize) {
                break;
            }
        }

        return null;
    }

    /**
     * Gibt nur die aktuelle Reservierung frei und nimmt dieselbe UUID einmalig
     * aus dem unmittelbar folgenden Pick. Der Kontaktdatensatz und damit seine
     * Position in der Warteschlange bleiben vollständig unverändert.
     */
    public function skipCurrentCandidate(string $prospectId, string $lockToken): ?array
    {
        $this->assertUserAllowed();
        $this->locks->assertOwned($prospectId, $lockToken);
        $this->locks->release($prospectId, $lockToken);

        $nextCandidate = $this->getNextCandidate($prospectId);
        if ($nextCandidate !== null) {
            return $nextCandidate;
        }

        // Gibt es keine Alternative im aktuellen Filter, wird der unveränderte
        // Kontakt wieder reserviert, statt eine fälschlich leere Liste zu zeigen.
        return $this->getNextCandidate();
    }

    /**
     * Lädt ausschließlich den bereits reservierten Kontakt neu.
     *
     * Diese Methode erwirbt bewusst keine neue Reservierung. Dadurch kann die
     * Oberfläche den sichtbaren Datensatz regelmäßig aktualisieren, ohne dem
     * Telefonierer unbemerkt einen anderen Kontakt unterzuschieben.
     */
    public function getCurrentCandidate(string $prospectId, string $lockToken): array
    {
        $this->assertUserAllowed();
        $this->locks->assertOwned($prospectId, $lockToken);

        $listId = $this->getSourceListId();
        $userCondition = $this->assignments->sqlAccessCondition();
        $baseSql = $this->candidateSelectSql($listId, $userCondition, false);
        $candidate = $this->findCandidateById($baseSql, $prospectId);
        if ($candidate === null || $this->isExcluded($candidate)) {
            throw new \RuntimeException(
                'Der reservierte Zielkontakt ist nicht mehr für SpeedPhone freigegeben.'
            );
        }

        $lock = $this->locks->getActiveForCurrentUser();
        if ($lock === null || !hash_equals($prospectId, $lock['prospect_id'])) {
            throw new \RuntimeException('Die Kontaktreservierung ist abgelaufen.');
        }

        return $this->enrichCandidate($candidate, $lock);
    }

    /**
     * Öffnet einen bekannten Rückrufer aus der bestehenden Zielkontaktliste.
     * Ist der Datensatz bereits bei einem anderen Mitarbeiter reserviert,
     * bleibt dessen Sperre unangetastet und es wird null zurückgegeben.
     */
    public function openCandidateById(string $prospectId, bool $allowIncomingAccess = true): ?array
    {
        $this->assertUserAllowed();
        $listId = $this->getSourceListId();
        $userCondition = $allowIncomingAccess
            ? $this->assignments->sqlIncomingAccessCondition()
            : $this->assignments->sqlAccessCondition();
        $candidate = $this->findCandidateById(
            $this->candidateSelectSql($listId, $userCondition, false),
            $prospectId
        );
        if ($candidate === null || (!$allowIncomingAccess && $this->isExcluded($candidate))) {
            return null;
        }

        $lock = $this->locks->switchTo($prospectId);
        if ($lock === null) {
            return null;
        }

        return $this->enrichCandidate($candidate, $lock);
    }

    public function getStatistics(): array
    {
        $this->locks->cleanupExpired();
        $listId = $this->getSourceListId();
        $userCondition = $this->assignments->sqlAccessCondition();
        [$todayStartUtc, $tomorrowStartUtc] = $this->todayUtcRange();
        $common = " FROM prospects p
                    INNER JOIN prospect_lists_prospects plp
                        ON plp.related_id=p.id AND plp.related_type='Prospects'
                       AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                    LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                    LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
                    LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
                    WHERE p.deleted=0 AND p.do_not_call=0 AND {$userCondition}
                      AND " . $this->centralRetailAllowedSql() . " AND " . $this->travelAllowedSql();
        $processedCommon = " FROM calls c
                    LEFT JOIN calls_cstm cc ON cc.id_c=c.id
                    INNER JOIN prospects p ON p.id=c.parent_id AND p.deleted=0
                    INNER JOIN prospect_lists_prospects plp
                        ON plp.related_id=p.id AND plp.related_type='Prospects'
                       AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                    WHERE c.deleted=0
                      AND c.parent_type='Prospects'
                      AND c.status='Held'
                      AND c.direction='Outbound'
                      AND (
                          COALESCE(cc.speedphone_result_c, '')<>''
                          OR c.name LIKE 'SpeedPhone:%'
                      )
                      AND c.date_start>='" . $this->db->quote($todayStartUtc) . "'
                      AND c.date_start<'" . $this->db->quote($tomorrowStartUtc) . "'";
        $processedTodayMine = $this->scalar(
            "SELECT COUNT(DISTINCT c.id) n {$processedCommon}
             AND c.assigned_user_id='" . $this->db->quote((string) $this->currentUser->id) . "'"
        );
        $processedTodayAll = $this->scalar("SELECT COUNT(DISTINCT c.id) n {$processedCommon}");

        return [
            'open' => $this->scalar("SELECT COUNT(*) n {$common}
                AND (TRIM(COALESCE(p.phone_work, ''))<>'' OR TRIM(COALESCE(p.phone_mobile, ''))<>'')
                AND COALESCE(pc.speedphone_status_c, '') NOT IN ('interested','no_interest','invalid_phone','blocked','paused')"),
            'callbacks_due' => $this->scalar("SELECT COUNT(*) n {$common}
                AND pc.speedphone_status_c='callback'
                AND pc.speedphone_next_call_c<=UTC_TIMESTAMP()"),
            // Der bisherige Schlüssel bleibt für bestehende API-Nutzer erhalten.
            'processed_today' => $processedTodayMine,
            'processed_today_mine' => $processedTodayMine,
            'processed_today_all' => $processedTodayAll,
            'interested' => $this->scalar("SELECT COUNT(*) n {$common}
                AND pc.speedphone_status_c='interested'"),
            'locked' => $this->scalar("SELECT COUNT(*) n
                FROM crm_speedphone_locks spl
                INNER JOIN prospects p ON p.id=spl.prospect_id AND p.deleted=0
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                WHERE spl.expires_at>UTC_TIMESTAMP()"),
        ];
    }

    /**
     * Liefert den lokalen Tagesanfang des Benutzers als UTC-Zeitfenster.
     *
     * Dadurch zählen Anrufe kurz nach Mitternacht auch während der Sommerzeit
     * zuverlässig zum erwarteten Kalendertag.
     *
     * @return array{0: string, 1: string}
     */
    private function todayUtcRange(): array
    {
        $timezoneName = (string) ($this->currentUser->getPreference('timezone') ?: 'Europe/Berlin');
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Europe/Berlin');
        }

        $localStart = new \DateTimeImmutable('today', $timezone);
        $localEnd = $localStart->modify('+1 day');
        $utc = new \DateTimeZone('UTC');

        return [
            $localStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $localEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public function canEditProspect(string $id, bool $requireTravelAllowed = false): bool
    {
        $listId = $this->getSourceListId();
        $userCondition = $this->assignments->sqlAccessCondition();
        $sql = "SELECT p.first_name, p.last_name, p.account_name, p.description
                FROM prospects p
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote($listId) . "' AND plp.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
                LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
                WHERE p.id='" . $this->db->quote($id) . "'
                  AND p.deleted=0 AND p.do_not_call=0 AND {$userCondition}
                  AND " . ($requireTravelAllowed ? $this->travelAllowedSql() : '1=1') . "
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
        if ($this->priorities->isExcluded($candidate)) {
            return true;
        }

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

    private function enrichCandidate(array $candidate, array $lock): array
    {
        $prospect = \BeanFactory::getBean('Prospects', $candidate['id']);
        if (!$prospect || empty($prospect->id)) {
            throw new \RuntimeException('Der ausgewählte Zielkontakt konnte nicht geladen werden.');
        }

        $candidate['name'] = trim((string) ($prospect->account_name ?: trim($prospect->first_name . ' ' . $prospect->last_name)));
        $candidate['industry_stored'] = (string) ($prospect->speedphone_industry_c ?? '');
        $candidate['industry'] = IndustryFilter::classify($candidate['name'], $candidate['industry_stored']);
        $candidate['email'] = (string) ($prospect->emailAddress?->getPrimaryAddress($prospect) ?? '');
        $candidate['website'] = $this->extractWebsite((string) $prospect->description);
        $candidate['linkedin'] = $this->linkedInContacts->discover(
            (string) $candidate['id'],
            (string) $candidate['name'],
            (string) ($candidate['primary_address_city'] ?? ''),
            (string) $candidate['website']
        );
        $priority = $this->priorities->classify($candidate);
        $candidate['priority_tier'] = $priority['tier'];
        $candidate['priority_label'] = $priority['label'];
        $candidate['score'] = $priority['base_score'];
        $candidate['reasons'] = [$priority['label']];
        $canIncreasePriority = $priority['tier'] !== CandidatePriorityService::TIER_LATE;

        if ((int) $candidate['clicked'] === 1) {
            if ($canIncreasePriority) {
                $candidate['score'] += 100;
            }
            $candidate['reasons'][] = 'Link in einer Kampagnenmail angeklickt';
        }
        if ((int) $candidate['viewed'] === 1) {
            if ($canIncreasePriority) {
                $candidate['score'] += 30;
            }
            $candidate['reasons'][] = 'Kampagnenmail geöffnet';
        }

        foreach ((array) $this->config->get('local_postcode_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, (string) $candidate['primary_address_postalcode']) === 1) {
                if ($canIncreasePriority) {
                    $candidate['score'] += 20;
                }
                $candidate['reasons'][] = 'Regionaler Betrieb';
                break;
            }
        }

        $haystack = implode(' ', [$candidate['name'], $candidate['description'] ?? '']);
        if ($priority['tier'] !== CandidatePriorityService::TIER_LATE) {
            foreach ((array) $this->config->get('positive_patterns', []) as $pattern) {
                if (@preg_match((string) $pattern, $haystack) === 1) {
                    $candidate['score'] += 10;
                    $candidate['reasons'][] = 'Passende Unternehmensart';
                    break;
                }
            }
        }

        $candidate['recent_calls'] = $this->getRecentCalls($candidate['id']);
        $candidate['sent_emails'] = $this->getSentEmails($candidate['id']);
        $candidate['assignment'] = $this->assignments->getAssignment((string) $candidate['id']);
        $candidate['current_profile'] = $this->access->currentProfile();
        $candidate['lock_token'] = $lock['lock_token'];
        $candidate['lock_expires_at'] = $lock['expires_at'];

        return $candidate;
    }

    private function getRecentCalls(string $prospectId): array
    {
        $sql = "SELECT c.name, c.status, c.direction, c.date_start, c.description,
                       COALESCE(cc.speedphone_result_c, '') speedphone_result,
                       TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) caller_name,
                       u.user_name caller_username
                FROM calls c
                LEFT JOIN calls_cstm cc ON cc.id_c=c.id
                LEFT JOIN users u ON u.id=c.assigned_user_id AND u.deleted=0
                WHERE c.deleted=0 AND c.parent_type='Prospects'
                  AND c.parent_id='" . $this->db->quote($prospectId) . "'
                ORDER BY c.date_start DESC LIMIT 10";
        $result = $this->db->query($sql);
        $calls = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $calls[] = $row;
        }

        return $calls;
    }

    private function getSentEmails(string $prospectId): array
    {
        $quotedProspectId = $this->db->quote($prospectId);
        $directSql = "SELECT DISTINCT e.id,
                             COALESCE(NULLIF(e.name, ''), 'E-Mail ohne Betreff') subject,
                             COALESCE(e.date_sent_received, e.date_entered) sent_at,
                             COALESCE(et.to_addrs, '') recipient,
                             'CRM-E-Mail' source,
                             0 opened,
                             0 clicked
                      FROM emails e
                      INNER JOIN emails_text et ON et.email_id=e.id AND et.deleted=0
                      WHERE e.deleted=0
                        AND e.type='out'
                        AND e.status='sent'
                        AND (
                            (e.parent_type='Prospects' AND e.parent_id='{$quotedProspectId}')
                            OR EXISTS (
                                SELECT 1 FROM emails_beans eb
                                WHERE eb.email_id=e.id AND eb.deleted=0
                                  AND eb.bean_module='Prospects'
                                  AND eb.bean_id='{$quotedProspectId}'
                            )
                        )
                      ORDER BY sent_at DESC
                      LIMIT 20";

        $campaignSql = "SELECT cl.id,
                               COALESCE(NULLIF(et.subject, ''), NULLIF(em.name, ''), NULLIF(c.name, ''), 'Kampagnenmail') subject,
                               cl.activity_date sent_at,
                               COALESCE(cl.more_information, '') recipient,
                               COALESCE(NULLIF(c.name, ''), 'Kampagnenmail') source,
                               EXISTS (
                                   SELECT 1 FROM campaign_log opened_log
                                   WHERE opened_log.deleted=0
                                     AND opened_log.target_type='Prospects'
                                     AND opened_log.target_id=cl.target_id
                                     AND opened_log.campaign_id <=> cl.campaign_id
                                     AND opened_log.marketing_id <=> cl.marketing_id
                                     AND opened_log.activity_type='viewed'
                               ) opened,
                               EXISTS (
                                   SELECT 1 FROM campaign_log clicked_log
                                   WHERE clicked_log.deleted=0
                                     AND clicked_log.target_type='Prospects'
                                     AND clicked_log.target_id=cl.target_id
                                     AND clicked_log.campaign_id <=> cl.campaign_id
                                     AND clicked_log.marketing_id <=> cl.marketing_id
                                     AND clicked_log.activity_type='link'
                               ) clicked
                        FROM campaign_log cl
                        LEFT JOIN campaigns c ON c.id=cl.campaign_id AND c.deleted=0
                        LEFT JOIN email_marketing em ON em.id=cl.marketing_id AND em.deleted=0
                        LEFT JOIN email_templates et ON et.id=em.template_id AND et.deleted=0
                        WHERE cl.deleted=0
                          AND cl.target_type='Prospects'
                          AND cl.target_id='{$quotedProspectId}'
                          AND cl.activity_type='targeted'
                        ORDER BY cl.activity_date DESC
                        LIMIT 20";

        $emails = array_merge(
            $this->fetchRows($directSql, 'direct'),
            $this->fetchRows($campaignSql, 'campaign')
        );
        usort($emails, static fn (array $left, array $right): int => strcmp(
            (string) ($right['sent_at'] ?? ''),
            (string) ($left['sent_at'] ?? '')
        ));

        return array_slice($emails, 0, 10);
    }

    public function getEmailPreview(string $prospectId, string $emailId, string $kind): array
    {
        if (!$this->canEditProspect($prospectId)) {
            throw new \RuntimeException('Kein Zugriff auf diesen Zielkontakt.');
        }

        $prospect = $this->db->quote($prospectId);
        $email = $this->db->quote($emailId);
        if ($kind === 'direct') {
            $sql = "SELECT COALESCE(NULLIF(e.name,''),'E-Mail ohne Betreff') subject,
                           COALESCE(e.date_sent_received,e.date_entered) sent_at,
                           COALESCE(et.to_addrs,'') recipient,
                           COALESCE(NULLIF(et.description,''),et.description_html,'') body,
                           COALESCE(et.description,'') tracking_source
                    FROM emails e
                    INNER JOIN emails_text et ON et.email_id=e.id AND et.deleted=0
                    WHERE e.id='{$email}' AND e.deleted=0 AND e.type='out' AND e.status='sent'
                      AND ((e.parent_type='Prospects' AND e.parent_id='{$prospect}')
                           OR EXISTS (SELECT 1 FROM emails_beans eb
                                      WHERE eb.email_id=e.id AND eb.deleted=0
                                        AND eb.bean_module='Prospects' AND eb.bean_id='{$prospect}'))
                    LIMIT 1";
        } elseif ($kind === 'campaign') {
            $sql = "SELECT COALESCE(NULLIF(et.subject,''),NULLIF(em.name,''),NULLIF(c.name,''),'Kampagnenmail') subject,
                           cl.activity_date sent_at,
                           COALESCE(cl.more_information,'') recipient,
                           COALESCE(NULLIF(et.body,''),et.body_html,'') body,
                           cl.campaign_id,cl.marketing_id,cl.target_id
                    FROM campaign_log cl
                    LEFT JOIN campaigns c ON c.id=cl.campaign_id AND c.deleted=0
                    LEFT JOIN email_marketing em ON em.id=cl.marketing_id AND em.deleted=0
                    LEFT JOIN email_templates et ON et.id=em.template_id AND et.deleted=0
                    WHERE cl.id='{$email}' AND cl.deleted=0 AND cl.target_type='Prospects'
                      AND cl.target_id='{$prospect}' AND cl.activity_type='targeted'
                    LIMIT 1";
        } else {
            throw new \InvalidArgumentException('Unbekannte E-Mail-Quelle.');
        }

        $row = $this->db->fetchByAssoc($this->db->query($sql));
        if (!is_array($row)) {
            throw new \RuntimeException('Die E-Mail wurde für diesen Kontakt nicht gefunden.');
        }
        $interactions = $kind === 'direct'
            ? $this->directEmailInteractions((string) $row['recipient'], (string) $row['tracking_source'])
            : $this->campaignEmailInteractions($emailId, $row);
        $row['recipient'] = $this->normalizeRecipients((string) $row['recipient']);
        $row['body'] = self::emailPreviewText((string) $row['body']);
        $row['content_note'] = $kind === 'campaign'
            ? 'Bei Kampagnen wird der im CRM gespeicherte Vorlageninhalt angezeigt.'
            : '';
        $row['interactions'] = $interactions;
        $row['interaction_summary'] = $this->interactionSummary($interactions);
        unset($row['tracking_source'], $row['campaign_id'], $row['marketing_id'], $row['target_id']);

        return $row;
    }

    /** @return list<array{type: string, occurred_at: string, detail: string}> */
    private function directEmailInteractions(string $recipient, string $trackingSource): array
    {
        if (preg_match('/Anesda-Mail-ID:\s*([0-9a-f-]{36})/i', $trackingSource, $match) !== 1) {
            return [];
        }
        $messageId = strtolower($match[1]);
        $normalizedRecipient = $this->normalizeRecipients($recipient);
        $addresses = array_values(array_filter(array_map('trim', explode(',', $normalizedRecipient)), static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false));
        if ($addresses === []) {
            return [];
        }
        $quotedAddresses = implode(',', array_map(fn (string $address): string => "'" . $this->db->quote($address) . "'", $addresses));
        $result = $this->db->query("SELECT event_type,payload_json,created_at
            FROM crm_speedphone_mail_webhook_events
            WHERE state='processed' AND event_type IN ('opened','unique_opened','proxy_open','click')
              AND LOWER(email_address) IN ({$quotedAddresses})
            ORDER BY created_at DESC,event_id DESC");
        $interactions = [];
        while ($event = $this->db->fetchByAssoc($result)) {
            $payload = self::decodeWebhookPayload((string) ($event['payload_json'] ?? ''));
            $payloadMessageId = strtolower(trim((string) ($payload['message_id'] ?? $payload['message-id'] ?? '')));
            if ($payloadMessageId !== $messageId) {
                continue;
            }
            $type = (string) $event['event_type'] === 'click' ? 'clicked' : 'opened';
            $interactions[] = [
                'type' => $type,
                'occurred_at' => $this->normalizeInteractionDate((string) ($payload['date'] ?? ''), (string) $event['created_at']),
                'detail' => $type === 'clicked' ? self::safeInteractionUrl((string) ($payload['url'] ?? '')) : '',
            ];
        }

        return $this->sortInteractions($interactions);
    }

    /** @return list<array{type: string, occurred_at: string, detail: string}> */
    private function campaignEmailInteractions(string $targetedId, array $targeted): array
    {
        $campaign = $this->nullableSqlValue($targeted['campaign_id'] ?? null);
        $marketing = $this->nullableSqlValue($targeted['marketing_id'] ?? null);
        $targetId = $this->db->quote((string) ($targeted['target_id'] ?? ''));
        $sentAt = $this->db->quote((string) ($targeted['sent_at'] ?? ''));
        $targetedId = $this->db->quote($targetedId);
        $next = $this->db->fetchByAssoc($this->db->query("SELECT MIN(activity_date) next_sent_at
            FROM campaign_log
            WHERE deleted=0 AND activity_type='targeted' AND target_type='Prospects'
              AND target_id='{$targetId}' AND campaign_id <=> {$campaign} AND marketing_id <=> {$marketing}
              AND id<>'{$targetedId}' AND activity_date>'{$sentAt}'"));
        $until = !empty($next['next_sent_at'])
            ? " AND activity.activity_date<'" . $this->db->quote((string) $next['next_sent_at']) . "'"
            : '';
        $result = $this->db->query("SELECT activity.activity_type,activity.activity_date,activity.more_information
            FROM campaign_log activity
            LEFT JOIN crm_speedphone_mail_webhook_events webhook
              ON webhook.campaign_log_id=activity.id
            WHERE activity.deleted=0 AND activity.target_type='Prospects'
              AND activity.target_id='{$targetId}'
              AND activity.campaign_id <=> {$campaign} AND activity.marketing_id <=> {$marketing}
              AND activity.activity_type IN ('viewed','link')
              AND activity.activity_date>='{$sentAt}'{$until}
              AND webhook.event_id IS NULL
            ORDER BY activity.activity_date DESC,activity.id DESC");
        $interactions = [];
        while ($event = $this->db->fetchByAssoc($result)) {
            $type = (string) $event['activity_type'] === 'link' ? 'clicked' : 'opened';
            $interactions[] = [
                'type' => $type,
                'occurred_at' => (string) $event['activity_date'],
                'detail' => $type === 'clicked' ? self::safeInteractionUrl((string) ($event['more_information'] ?? '')) : '',
            ];
        }

        return $this->sortInteractions($interactions);
    }

    public static function decodeWebhookPayload(string $payload): array
    {
        $decoded = json_decode(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function safeInteractionUrl(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strlen($value) > 2000 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $value : '';
    }

    private function normalizeInteractionDate(string $payloadDate, string $fallback): string
    {
        try {
            if ($payloadDate !== '') {
                return (new \DateTimeImmutable($payloadDate))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        } catch (\Throwable) {
        }
        return $fallback;
    }

    /** @param list<array{type: string, occurred_at: string, detail: string}> $interactions */
    private function sortInteractions(array $interactions): array
    {
        usort($interactions, static fn (array $left, array $right): int => strcmp($right['occurred_at'], $left['occurred_at']));
        return $interactions;
    }

    private function interactionSummary(array $interactions): array
    {
        $summary = ['open_count' => 0, 'click_count' => 0, 'last_opened_at' => '', 'last_clicked_at' => ''];
        foreach ($interactions as $interaction) {
            $countKey = $interaction['type'] === 'clicked' ? 'click_count' : 'open_count';
            $lastKey = $interaction['type'] === 'clicked' ? 'last_clicked_at' : 'last_opened_at';
            $summary[$countKey]++;
            if ($summary[$lastKey] === '') {
                $summary[$lastKey] = $interaction['occurred_at'];
            }
        }
        return $summary;
    }

    private function nullableSqlValue(mixed $value): string
    {
        return $value === null || $value === '' ? 'NULL' : "'" . $this->db->quote((string) $value) . "'";
    }

    public static function emailPreviewText(string $value): string
    {
        $value = preg_replace('~<(?:br|hr|/p|/div|/li|/tr|/td|/h[1-6])\b[^>]*>~iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;

        return mb_substr(trim($value), 0, 100000);
    }

    private function fetchRows(string $sql, string $kind): array
    {
        $result = $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $row['kind'] = $kind;
            $row['recipient'] = $this->normalizeRecipients((string) ($row['recipient'] ?? ''));
            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeRecipients(string $value): string
    {
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $value, $matches) > 0) {
            return implode(', ', array_values(array_unique(array_map('strtolower', $matches[0]))));
        }

        return trim($value) !== '' ? trim($value) : 'Adresse nicht protokolliert';
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

    private function candidateSelectSql(string $listId, string $userCondition, bool $onlyDue = true): string
    {
        $escalatedExpression = $this->assignments->sqlEscalatedExpression();
        $nameExpression = $this->candidateNameSql();
        $textExpression = "LOWER(CONCAT_WS(' ', {$nameExpression}, COALESCE(p.description, '')))";
        $priorityExpression = $this->priorities->sqlTierExpression(
            $nameExpression,
            $textExpression,
            fn (string $value): string => $this->db->quote($value)
        );
        $travelCondition = $onlyDue ? ' AND ' . $this->travelAllowedSql() : '';
        $dueCondition = $onlyDue
            ? "AND COALESCE(pc.speedphone_status_c, '') NOT IN
                      ('interested', 'no_interest', 'invalid_phone', 'blocked', 'paused')
                  AND (pc.speedphone_next_call_c IS NULL OR pc.speedphone_next_call_c='' OR pc.speedphone_next_call_c<=UTC_TIMESTAMP())"
            : '';

        return "SELECT p.id, p.first_name, p.last_name, p.account_name, p.description,
                       p.phone_work, p.phone_mobile, p.primary_address_street,
                       p.primary_address_postalcode, p.primary_address_city,
                       COALESCE(pc.speedphone_status_c, '') speedphone_status,
                       COALESCE(pc.speedphone_attempts_c, 0) speedphone_attempts,
                       pc.speedphone_next_call_c speedphone_next_call,
                       pc.speedphone_travel_minutes_c travel_minutes,
                       pc.speedphone_travel_status_c travel_status,
                       pc.speedphone_travel_note_c travel_note,
                       spa.owner_user_id speedphone_owner_user_id,
                       spa.owner_type speedphone_owner_type,
                       CASE WHEN {$escalatedExpression} THEN 1 ELSE 0 END speedphone_is_escalated,
                       COALESCE(eng.clicked, 0) clicked,
                       COALESCE(eng.viewed, 0) viewed,
                       {$priorityExpression} speedphone_priority_tier
                FROM prospects p
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id
                   AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote($listId) . "'
                   AND plp.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
                LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
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
                  AND " . $this->centralRetailAllowedSql() . "
                  AND (TRIM(COALESCE(p.phone_work, ''))<>'' OR TRIM(COALESCE(p.phone_mobile, ''))<>'')
                  {$dueCondition} {$travelCondition}";
    }

    private function travelAllowedSql(): string
    {
        return TravelFilter::allowedSql($this->config, fn (string $value): string => $this->db->quote($value));
    }

    private function candidateNameSql(): string
    {
        return "LOWER(TRIM(CASE
                    WHEN TRIM(COALESCE(p.account_name, ''))<>'' THEN p.account_name
                    ELSE CONCAT_WS(' ', p.first_name, p.last_name)
                END))";
    }

    private function centralRetailAllowedSql(): string
    {
        return $this->priorities->sqlAllowedCondition(
            $this->candidateNameSql(),
            fn (string $value): string => $this->db->quote($value)
        );
    }

    private function findCandidateById(string $baseSql, string $prospectId): ?array
    {
        $sql = $baseSql . " AND p.id='" . $this->db->quote($prospectId) . "' LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));

        return is_array($row) && !empty($row['id']) ? $row : null;
    }
}
