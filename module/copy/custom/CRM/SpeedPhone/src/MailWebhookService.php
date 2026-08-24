<?php

namespace Anesda\CRM\SpeedPhone;

final class MailWebhookService
{
    private const MAX_CLOCK_SKEW = 300;

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db
    ) {
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function verify(string $body, string $timestamp, string $signature, ?int $now = null): void
    {
        $secret = $this->config->requireString('mail_webhook_secret');
        $now ??= time();
        if (!preg_match('/^\d{10}$/', $timestamp) || abs($now - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            throw new \RuntimeException('Der Webhook-Zeitstempel ist ungültig oder abgelaufen.');
        }
        if (!preg_match('/^v1=[a-f0-9]{64}$/', $signature)
            || !hash_equals(self::signature($secret, $timestamp, $body), strtolower($signature))) {
            throw new \RuntimeException('Die Webhook-Signatur ist ungültig.');
        }
    }

    public function process(array $payload): array
    {
        $eventId = strtolower(trim((string) ($payload['id'] ?? '')));
        $eventType = strtolower(trim((string) ($payload['event'] ?? '')));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if (preg_match('/^[0-9a-f-]{36}$/', $eventId) !== 1
            || preg_match('/^[a-z_]{2,40}$/', $eventType) !== 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Webhook-ID, Ereignis oder Empfänger ist ungültig.');
        }

        $existing = $this->row("SELECT state FROM crm_speedphone_mail_webhook_events
                                WHERE event_id='" . $this->db->quote($eventId) . "' LIMIT 1");
        if (($existing['state'] ?? '') === 'processed') {
            return ['processed' => true, 'duplicate' => true, 'event_id' => $eventId];
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query("INSERT INTO crm_speedphone_mail_webhook_events
            (event_id,event_type,email_address,payload_json,payload_hash,state,attempts,created_at)
            VALUES ('" . $this->db->quote($eventId) . "','" . $this->db->quote($eventType) . "','"
                . $this->db->quote($email) . "','" . $this->db->quote($payloadJson) . "','"
                . hash('sha256', $payloadJson) . "','processing',1,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE state='processing',attempts=attempts+1,last_error=NULL");

        try {
            $context = $this->resolveContext($email, (array) ($payload['metadata'] ?? []));
            $this->applyAddressState($email, $eventType);
            $activityType = $this->activityType($eventType);
            if ($activityType !== null && $context !== null) {
                $this->insertCampaignActivity($context, $activityType, $email, $eventId);
            }
            $this->db->query("UPDATE crm_speedphone_mail_webhook_events
                              SET state='processed',processed_at=UTC_TIMESTAMP(),last_error=NULL
                              WHERE event_id='" . $this->db->quote($eventId) . "'");
        } catch (\Throwable $error) {
            $this->db->query("UPDATE crm_speedphone_mail_webhook_events SET state='failed',last_error='"
                . $this->db->quote(substr($error->getMessage(), 0, 1000)) . "' WHERE event_id='"
                . $this->db->quote($eventId) . "'");
            throw $error;
        }

        return ['processed' => true, 'duplicate' => false, 'event_id' => $eventId];
    }

    private function applyAddressState(string $email, string $eventType): void
    {
        $field = match ($eventType) {
            'hard_bounce', 'invalid_email', 'blocked' => 'invalid_email',
            'complaint', 'spam', 'unsubscribe', 'unsubscribed' => 'opt_out',
            default => null,
        };
        if ($field === null) {
            return;
        }
        $this->db->query("UPDATE email_addresses SET {$field}=1,date_modified=UTC_TIMESTAMP()
                          WHERE deleted=0 AND LOWER(email_address)='" . $this->db->quote($email) . "'");
    }

    private function activityType(string $eventType): ?string
    {
        return match ($eventType) {
            'opened', 'unique_opened', 'proxy_open' => 'viewed',
            'click' => 'link',
            'hard_bounce', 'invalid_email', 'blocked' => 'invalid email',
            'complaint', 'spam', 'unsubscribe', 'unsubscribed' => 'removed',
            default => null,
        };
    }

    private function resolveContext(string $email, array $metadata): ?array
    {
        $targetId = strtolower(trim((string) ($metadata['crm_target_id'] ?? '')));
        if ($targetId !== '' && preg_match('/^[0-9a-f-]{36}$/', $targetId) === 1) {
            $verified = $this->row("SELECT er.bean_id target_id,er.bean_module target_type
                FROM email_addr_bean_rel er
                INNER JOIN email_addresses ea ON ea.id=er.email_address_id AND ea.deleted=0
                WHERE er.deleted=0 AND er.bean_id='" . $this->db->quote($targetId) . "'
                  AND er.bean_module='Prospects' AND LOWER(ea.email_address)='" . $this->db->quote($email) . "'
                LIMIT 1");
            if ($verified) {
                $targeted = $this->latestTargeted($email, $targetId);
                return $targeted ?: [
                    'campaign_id' => null, 'marketing_id' => null, 'list_id' => null,
                    'target_id' => $targetId, 'target_type' => 'Prospects', 'target_tracker_key' => null,
                ];
            }
        }

        return $this->latestTargeted($email, null);
    }

    private function latestTargeted(string $email, ?string $targetId): ?array
    {
        $targetCondition = $targetId === null ? '' : " AND cl.target_id='" . $this->db->quote($targetId) . "'";
        return $this->row("SELECT cl.campaign_id,cl.marketing_id,cl.list_id,cl.target_id,cl.target_type,cl.target_tracker_key
            FROM campaign_log cl
            INNER JOIN email_addr_bean_rel er ON er.bean_id=cl.target_id AND er.deleted=0
            INNER JOIN email_addresses ea ON ea.id=er.email_address_id AND ea.deleted=0
            WHERE cl.deleted=0 AND cl.activity_type='targeted' AND cl.target_type='Prospects'
              AND LOWER(ea.email_address)='" . $this->db->quote($email) . "'{$targetCondition}
            ORDER BY cl.activity_date DESC LIMIT 1");
    }

    private function insertCampaignActivity(array $context, string $activityType, string $email, string $eventId): void
    {
        $event = $this->row("SELECT campaign_log_id FROM crm_speedphone_mail_webhook_events WHERE event_id='"
            . $this->db->quote($eventId) . "' LIMIT 1");
        if (!empty($event['campaign_log_id'])) {
            return;
        }
        $id = function_exists('create_guid') ? create_guid() : $this->guid();
        $value = fn (mixed $item): string => $item === null || $item === ''
            ? 'NULL'
            : "'" . $this->db->quote((string) $item) . "'";
        $this->db->query("INSERT INTO campaign_log
            (id,campaign_id,target_tracker_key,target_id,target_type,activity_type,activity_date,archived,hits,
             list_id,deleted,is_test_entry,marketing_id,more_information,date_modified)
            SELECT '" . $this->db->quote($id) . "'," . $value($context['campaign_id'] ?? null) . ","
                . $value($context['target_tracker_key'] ?? null) . ",'" . $this->db->quote((string) $context['target_id'])
                . "','" . $this->db->quote((string) $context['target_type']) . "','" . $this->db->quote($activityType)
                . "',UTC_TIMESTAMP(),0,0," . $value($context['list_id'] ?? null) . ",0,0,"
                . $value($context['marketing_id'] ?? null) . ",'" . $this->db->quote($email) . "',UTC_TIMESTAMP()
            FROM DUAL WHERE NOT EXISTS (
                SELECT 1 FROM crm_speedphone_mail_webhook_events WHERE event_id='" . $this->db->quote($eventId)
                . "' AND campaign_log_id IS NOT NULL
            )");
        $this->db->query("UPDATE crm_speedphone_mail_webhook_events SET campaign_log_id='"
            . $this->db->quote($id) . "' WHERE event_id='" . $this->db->quote($eventId) . "'");
    }

    private function row(string $sql): ?array
    {
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        return is_array($row) ? $row : null;
    }

    private function guid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
