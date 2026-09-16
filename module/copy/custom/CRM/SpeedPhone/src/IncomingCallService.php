<?php

namespace Anesda\CRM\SpeedPhone;

final class IncomingCallService
{
    private const TABLE = 'crm_speedphone_incoming_calls';
    private const PBX_EVENTS_TABLE = 'crm_speedphone_pbx_incoming_events';
    private const PBX_MATCHES_TABLE = 'crm_speedphone_pbx_incoming_matches';
    private const MAX_CLOCK_SKEW = 300;

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db
    ) {
    }

    public static function pbxSignature(string $secret, string $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function verifyPbxWebhook(string $body, string $timestamp, string $signature, ?int $now = null): void
    {
        $secret = $this->config->requireString('pbx_incoming_webhook_secret');
        $now ??= time();
        if (!preg_match('/^\d{10}$/', $timestamp) || abs($now - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            throw new \RuntimeException('Der Zeitstempel der Telefonanlage ist ungültig oder abgelaufen.');
        }
        if (!preg_match('/^v1=[a-f0-9]{64}$/', $signature)
            || !hash_equals(self::pbxSignature($secret, $timestamp, $body), strtolower($signature))) {
            throw new \RuntimeException('Die Signatur der Telefonanlage ist ungültig.');
        }
    }

    public function reportFromPbx(string $phone, string $sourceEventId, string $extension = ''): array
    {
        $phone = DialerService::normalizePhone($phone);
        $sourceEventId = trim($sourceEventId);
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $sourceEventId) !== 1) {
            throw new \InvalidArgumentException('Die Ereigniskennung der Telefonanlage ist ungültig.');
        }
        $extension = trim($extension);
        if ($extension !== '' && preg_match('/^[0-9]{3,8}$/', $extension) !== 1) {
            throw new \InvalidArgumentException('Die gemeldete Durchwahl ist ungültig.');
        }

        $this->cleanupPbx();
        $extensionCondition = $extension !== '' && $extension !== '6000'
            ? " AND settings.pbx_extension='" . $this->db->quote($extension) . "'"
            : '';
        $users = $this->db->query("SELECT users.id
            FROM users
            INNER JOIN crm_speedphone_user_settings settings ON settings.user_id=users.id
            WHERE users.deleted=0 AND users.status='Active'
              AND settings.user_type IN ('internal','external')
              AND COALESCE(settings.pbx_extension,'')<>''{$extensionCondition}");
        $eventCount = 0;
        $matchCount = 0;
        while ($userRow = $this->db->fetchByAssoc($users)) {
            $user = \BeanFactory::getBean('Users', (string) $userRow['id']);
            if (!$user || empty($user->id) || (int) $user->deleted === 1) {
                continue;
            }
            $access = new UserAccessService($this->db, $user);
            try {
                $access->assertAllowed();
            } catch (\Throwable) {
                continue;
            }
            $assignments = new AssignmentService($this->config, $this->db, $user, $access);
            $matches = $this->findProspects($phone, $assignments->sqlIncomingAccessCondition());
            if ($matches === []) {
                continue;
            }
            $existingEventId = $this->existingPbxEvent((string) $user->id, $sourceEventId);
            $eventId = $existingEventId ?: $this->guid();
            if ($existingEventId === null) {
                $this->db->query("INSERT INTO " . self::PBX_EVENTS_TABLE . "
                    (id,user_id,source_event_id,caller_phone,received_at,acknowledged_at)
                    VALUES ('" . $this->db->quote($eventId) . "','" . $this->db->quote((string) $user->id) . "','"
                    . $this->db->quote($sourceEventId) . "','" . $this->db->quote($phone) . "',UTC_TIMESTAMP(),NULL)");
                $eventCount++;
            }
            foreach ($matches as $match) {
                $this->db->query("INSERT INTO " . self::PBX_MATCHES_TABLE . "
                    (event_id,prospect_id,match_type,match_score)
                    VALUES ('" . $this->db->quote($eventId) . "','" . $this->db->quote((string) $match['id']) . "','"
                    . $this->db->quote((string) $match['match_type']) . "'," . (int) $match['match_score'] . ")
                    ON DUPLICATE KEY UPDATE match_type=VALUES(match_type),match_score=VALUES(match_score)");
                $matchCount++;
            }
        }

        return ['accepted' => true, 'events' => $eventCount, 'matches' => $matchCount];
    }

    public function pendingPbxForCurrentUser(\User $currentUser): ?array
    {
        $this->cleanupPbx();
        $event = $this->db->fetchByAssoc($this->db->query("SELECT id,caller_phone,received_at
            FROM " . self::PBX_EVENTS_TABLE . "
            WHERE user_id='" . $this->db->quote((string) $currentUser->id) . "'
              AND acknowledged_at IS NULL
              AND received_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)
            ORDER BY received_at DESC LIMIT 1"));
        if (empty($event['id'])) {
            return null;
        }
        $result = $this->db->query("SELECT matches.prospect_id,matches.match_type,matches.match_score,
                TRIM(COALESCE(NULLIF(prospects.account_name,''),CONCAT_WS(' ',prospects.first_name,prospects.last_name))) display_name,
                COALESCE(prospects.primary_address_city,'') city,
                COALESCE(prospects.phone_work,'') phone_work,
                COALESCE(prospects.phone_mobile,'') phone_mobile
            FROM " . self::PBX_MATCHES_TABLE . " matches
            INNER JOIN prospects ON prospects.id=matches.prospect_id AND prospects.deleted=0
            WHERE matches.event_id='" . $this->db->quote((string) $event['id']) . "'
            ORDER BY matches.match_score DESC,display_name");
        $matches = [];
        while ($match = $this->db->fetchByAssoc($result)) {
            $matches[] = [
                'prospect_id' => (string) $match['prospect_id'],
                'display_name' => (string) $match['display_name'],
                'city' => (string) $match['city'],
                'phone_work' => (string) $match['phone_work'],
                'phone_mobile' => (string) $match['phone_mobile'],
                'match_type' => (string) $match['match_type'],
                'match_label' => self::matchLabel((string) $match['match_type']),
            ];
        }
        if ($matches === []) {
            $this->acknowledgePbxEvent((string) $event['id'], (string) $currentUser->id);
            return null;
        }

        return [
            'event_id' => (string) $event['id'],
            'caller_phone' => (string) $event['caller_phone'],
            'received_at' => (string) $event['received_at'],
            'matches' => $matches,
        ];
    }

    public function openPbxMatch(\User $currentUser, QueueService $queue, string $eventId, string $prospectId): array
    {
        $match = $this->db->fetchByAssoc($this->db->query("SELECT events.id
            FROM " . self::PBX_EVENTS_TABLE . " events
            INNER JOIN " . self::PBX_MATCHES_TABLE . " matches ON matches.event_id=events.id
            WHERE events.id='" . $this->db->quote($eventId) . "'
              AND events.user_id='" . $this->db->quote((string) $currentUser->id) . "'
              AND events.acknowledged_at IS NULL
              AND matches.prospect_id='" . $this->db->quote($prospectId) . "'
            LIMIT 1"));
        if (empty($match['id'])) {
            throw new \RuntimeException('Diese Anrufmeldung ist nicht mehr verfügbar.');
        }
        $candidate = $queue->openCandidateById($prospectId);
        if ($candidate === null) {
            throw new \RuntimeException('Dieser Kontakt ist inzwischen bei einem anderen Mitarbeiter geöffnet. Bitte wähle einen anderen Treffer.');
        }
        $this->acknowledgePbxEvent($eventId, (string) $currentUser->id);

        return $candidate;
    }

    public function dismissPbxEvent(\User $currentUser, string $eventId): void
    {
        $this->acknowledgePbxEvent($eventId, (string) $currentUser->id);
    }

    private function acknowledgePbxEvent(string $eventId, string $userId): void
    {
        $this->db->query("UPDATE " . self::PBX_EVENTS_TABLE . " SET acknowledged_at=UTC_TIMESTAMP()
            WHERE id='" . $this->db->quote($eventId) . "'
              AND user_id='" . $this->db->quote($userId) . "' AND acknowledged_at IS NULL");
    }

    private function existingPbxEvent(string $userId, string $sourceEventId): ?string
    {
        $row = $this->db->fetchByAssoc($this->db->query("SELECT id FROM " . self::PBX_EVENTS_TABLE . "
            WHERE user_id='" . $this->db->quote($userId) . "'
              AND source_event_id='" . $this->db->quote($sourceEventId) . "' LIMIT 1"));
        return !empty($row['id']) ? (string) $row['id'] : null;
    }

    public function report(
        DialerService $dialer,
        string $deviceId,
        string $deviceToken,
        string $phone
    ): array {
        $device = $dialer->authenticateDevice($deviceId, $deviceToken);
        $user = \BeanFactory::getBean('Users', (string) $device['user_id']);
        if (!$user || empty($user->id) || (int) $user->deleted === 1) {
            throw new \RuntimeException('Der gekoppelte CRM-Benutzer ist nicht mehr aktiv.');
        }

        $normalizedPhone = DialerService::normalizePhone($phone);
        $access = new UserAccessService($this->db, $user);
        $access->assertAllowed();
        $assignments = new AssignmentService($this->config, $this->db, $user, $access);
        $prospect = $this->findProspect(
            $normalizedPhone,
            $assignments->sqlIncomingAccessCondition()
        );
        if ($prospect === null) {
            return [
                'matched' => false,
                'message' => 'Für diese eingehende Nummer wurde kein freigegebener CRM-Zielkontakt gefunden.',
            ];
        }

        $this->cleanup();
        $existingSql = "SELECT id
                        FROM " . self::TABLE . "
                        WHERE user_id='" . $this->db->quote((string) $user->id) . "'
                          AND prospect_id='" . $this->db->quote((string) $prospect['id']) . "'
                          AND received_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
                        ORDER BY received_at DESC
                        LIMIT 1";
        $existing = $this->db->fetchByAssoc($this->db->query($existingSql));
        $eventId = !empty($existing['id']) ? (string) $existing['id'] : $this->guid();
        if (empty($existing['id'])) {
            $this->db->query("INSERT INTO " . self::TABLE . "
                (id, device_id, user_id, prospect_id, received_at, opened_at)
                VALUES (
                    '" . $this->db->quote($eventId) . "',
                    '" . $this->db->quote($deviceId) . "',
                    '" . $this->db->quote((string) $user->id) . "',
                    '" . $this->db->quote((string) $prospect['id']) . "',
                    UTC_TIMESTAMP(),
                    NULL
                )");
        }

        return [
            'matched' => true,
            'event_id' => $eventId,
            'prospect_id' => (string) $prospect['id'],
            'display_name' => (string) $prospect['display_name'],
            'message' => 'Der Rückruf wurde dem vorhandenen CRM-Zielkontakt zugeordnet.',
        ];
    }

    /**
     * @return array{event_id: string, candidate: array}|null
     */
    public function openPendingForCurrentUser(
        \User $currentUser,
        QueueService $queue
    ): ?array {
        $this->cleanup();
        $sql = "SELECT id, prospect_id
                FROM " . self::TABLE . "
                WHERE user_id='" . $this->db->quote((string) $currentUser->id) . "'
                  AND opened_at IS NULL
                  AND received_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)
                ORDER BY received_at DESC
                LIMIT 1";
        $event = $this->db->fetchByAssoc($this->db->query($sql));
        if (empty($event['id']) || empty($event['prospect_id'])) {
            return null;
        }

        $candidate = $queue->openCandidateById((string) $event['prospect_id']);
        if ($candidate === null) {
            return null;
        }

        $this->db->query("UPDATE " . self::TABLE . "
                         SET opened_at=UTC_TIMESTAMP()
                         WHERE id='" . $this->db->quote((string) $event['id']) . "'
                           AND opened_at IS NULL");

        return [
            'event_id' => (string) $event['id'],
            'candidate' => $candidate,
        ];
    }

    private function findProspect(string $phone, string $userCondition): ?array
    {
        return $this->findProspects($phone, $userCondition)[0] ?? null;
    }

    private function findProspects(string $phone, string $userCondition): array
    {
        $listName = $this->config->requireString('source_list_name');
        $listSql = "SELECT id FROM prospect_lists
                    WHERE deleted=0 AND list_type='default'
                      AND name='" . $this->db->quote($listName) . "'
                    ORDER BY date_modified DESC LIMIT 1";
        $list = $this->db->fetchByAssoc($this->db->query($listSql));
        if (empty($list['id'])) {
            throw new \RuntimeException(sprintf(
                'Die Zielkontaktliste „%s“ wurde nicht gefunden.',
                $listName
            ));
        }

        $variants = self::phoneVariants($phone);
        $quotedVariants = implode(', ', array_map(
            fn (string $value): string => "'" . $this->db->quote($value) . "'",
            $variants
        ));
        $workPhone = $this->normalizedPhoneSql('p.phone_work');
        $mobilePhone = $this->normalizedPhoneSql('p.phone_mobile');
        $canonical = self::canonicalPhoneDigits($phone);
        $suffix = substr($canonical, -8);
        $prefixes = [];
        if (!self::isMobile($canonical)) {
            foreach ($variants as $variant) {
                $variantCanonical = self::canonicalPhoneDigits($variant);
                for ($cut = 1; $cut <= 4; $cut++) {
                    $prefix = substr($variantCanonical, 0, -$cut);
                    if (strlen($prefix) >= 7) {
                        $prefixes[] = $prefix;
                        if (str_starts_with($prefix, '49')) {
                            $prefixes[] = '0' . substr($prefix, 2);
                            $prefixes[] = '490' . substr($prefix, 2);
                        }
                    }
                }
            }
        }
        $prefixes = array_values(array_unique($prefixes));
        $prefixSql = $prefixes === [] ? '' : ' OR ' . implode(' OR ', array_map(
            fn (string $prefix): string => "{$workPhone} LIKE '" . $this->db->quote($prefix) . "%'",
            $prefixes
        ));
        $sql = "SELECT p.id,p.phone_work,p.phone_mobile,p.primary_address_city,
                       TRIM(COALESCE(NULLIF(p.account_name, ''),
                            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')))) display_name
                FROM prospects p
                INNER JOIN prospect_lists_prospects plp
                    ON plp.related_id=p.id
                   AND plp.related_type='Prospects'
                   AND plp.prospect_list_id='" . $this->db->quote((string) $list['id']) . "'
                   AND plp.deleted=0
                LEFT JOIN prospects_cstm pc ON pc.id_c=p.id
                LEFT JOIN crm_speedphone_assignments spa ON spa.prospect_id=p.id
                LEFT JOIN crm_speedphone_user_settings sp_creator ON sp_creator.user_id=p.created_by
                WHERE p.deleted=0
                  AND {$userCondition}
                  AND p.do_not_call=0
                  AND ({$workPhone} IN ({$quotedVariants}) OR {$mobilePhone} IN ({$quotedVariants})
                       OR RIGHT({$workPhone},8)='" . $this->db->quote($suffix) . "'
                       OR RIGHT({$mobilePhone},8)='" . $this->db->quote($suffix) . "'{$prefixSql})
                LIMIT 100";
        $result = $this->db->query($sql);
        $matches = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $best = null;
            foreach ([(string) $row['phone_work'], (string) $row['phone_mobile']] as $storedPhone) {
                $match = self::matchPhoneNumbers($phone, $storedPhone);
                if ($match !== null && ($best === null || $match['score'] > $best['score'])) {
                    $best = $match;
                }
            }
            if ($best === null) {
                continue;
            }
            $row['match_type'] = $best['type'];
            $row['match_score'] = $best['score'];
            $matches[] = $row;
        }
        usort($matches, static fn (array $left, array $right): int => ($right['match_score'] <=> $left['match_score']) ?: strcmp($left['display_name'], $right['display_name']));
        return $matches;
    }

    public static function canonicalPhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0049')) {
            $digits = '49' . substr($digits, 4);
        } elseif (str_starts_with($digits, '490')) {
            $digits = '49' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0') && strlen($digits) >= 7) {
            $digits = '49' . substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    public static function matchPhoneNumbers(string $incoming, string $stored): ?array
    {
        $incoming = self::canonicalPhoneDigits($incoming);
        $stored = self::canonicalPhoneDigits($stored);
        if (strlen($incoming) < 7 || strlen($stored) < 7) {
            return null;
        }
        if (hash_equals($incoming, $stored)) {
            return ['type' => 'exact', 'score' => 100];
        }
        if (abs(strlen($incoming) - strlen($stored)) <= 2
            && hash_equals(substr($incoming, -8), substr($stored, -8))) {
            return ['type' => 'suffix', 'score' => 90];
        }
        if (self::isMobile($incoming) || self::isMobile($stored)) {
            return null;
        }
        $common = 0;
        $max = min(strlen($incoming), strlen($stored));
        while ($common < $max && $incoming[$common] === $stored[$common]) {
            $common++;
        }
        $incomingTail = strlen($incoming) - $common;
        $storedTail = strlen($stored) - $common;
        if ($common >= 7 && $incomingTail <= 4 && $storedTail <= 4) {
            return ['type' => 'extension', 'score' => 70 + min(15, $common)];
        }
        return null;
    }

    private static function isMobile(string $canonical): bool
    {
        return preg_match('/^491[567][0-9]/', $canonical) === 1;
    }

    private static function matchLabel(string $type): string
    {
        return match ($type) {
            'exact' => 'Exakte Rufnummer',
            'suffix' => 'Gleiche Rufnummer trotz anderer Schreibweise',
            'extension' => 'Mögliche andere Durchwahl desselben Betriebs',
            default => 'Möglicher Rufnummerntreffer',
        };
    }

    /**
     * @return list<string>
     */
    public static function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        $variants = [$digits];
        if (str_starts_with($digits, '49') && strlen($digits) > 6) {
            $national = substr($digits, 2);
            if (str_starts_with($national, '0')) {
                $variants[] = '49' . substr($national, 1);
                $variants[] = $national;
            } else {
                $variants[] = '0' . $national;
                $variants[] = '490' . $national;
            }
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 5) {
            $variants[] = '49' . substr($digits, 1);
            $variants[] = '490' . substr($digits, 1);
        }

        return array_values(array_unique(array_filter(
            $variants,
            static fn (string $value): bool => strlen($value) >= 5
        )));
    }

    private function normalizedPhoneSql(string $field): string
    {
        $expression = "COALESCE({$field}, '')";
        foreach (['+', ' ', '(', ')', '-', '/', '.', "\t", "\r", "\n"] as $character) {
            $expression = "REPLACE({$expression}, '" . $this->db->quote($character) . "', '')";
        }

        return $expression;
    }

    private function cleanup(): void
    {
        $this->db->query(
            'DELETE FROM ' . self::TABLE . ' WHERE received_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );
    }

    private function cleanupPbx(): void
    {
        $this->db->query('DELETE matches FROM ' . self::PBX_MATCHES_TABLE . ' matches LEFT JOIN '
            . self::PBX_EVENTS_TABLE . ' events ON events.id=matches.event_id WHERE events.id IS NULL'
            . ' OR events.received_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        $this->db->query('DELETE FROM ' . self::PBX_EVENTS_TABLE
            . ' WHERE received_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
    }

    private function guid(): string
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
