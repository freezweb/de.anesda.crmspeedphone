<?php

namespace Anesda\CRM\SpeedPhone;

final class IncomingCallService
{
    private const TABLE = 'crm_speedphone_incoming_calls';

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db
    ) {
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
        $sql = "SELECT p.id,
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
                  AND ({$workPhone} IN ({$quotedVariants}) OR {$mobilePhone} IN ({$quotedVariants}))
                ORDER BY p.date_modified DESC
                LIMIT 1";
        $row = $this->db->fetchByAssoc($this->db->query($sql));

        return is_array($row) && !empty($row['id']) ? $row : null;
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
