<?php

namespace Anesda\CRM\SpeedPhone;

/** Ordnet vorhandene PBX-Mitschnitte nur bei eindeutigem CRM-Bezug zu. */
final class RecordingCopyService
{
    private const TABLE = 'crm_speedphone_recording_copies';

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly string $crmBaseUrl = ''
    ) {
    }

    public function execute(array $input): array
    {
        if (!(bool) $this->config->get('telegram_call_enabled', false)) {
            throw new \RuntimeException('Die Telegram-Anrufanbindung ist nicht aktiviert.');
        }
        $action = (string) ($input['action'] ?? '');
        $key = (string) ($input['recording_key'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Die Mitschnittkennung ist ungültig.');
        }
        return match ($action) {
            'claim' => $this->claim($key, $input),
            'complete' => $this->complete($key, $input),
            'fail' => $this->fail($key, $input),
            default => throw new \InvalidArgumentException('Die Mitschnittaktion ist ungültig.'),
        };
    }

    private function claim(string $key, array $input): array
    {
        $direction = (string) ($input['direction'] ?? '');
        $linkedId = (string) ($input['linkedid'] ?? '');
        $startedAt = (string) ($input['started_at_utc'] ?? '');
        $extension = (string) ($input['extension'] ?? '');
        $phone = (string) ($input['phone'] ?? '');
        $channel = (string) ($input['channel'] ?? '');
        if (!in_array($direction, ['incoming', 'outgoing'], true)
            || preg_match('/^[0-9]{8,12}\.[0-9]{1,8}$/', $linkedId) !== 1
            || preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/', $startedAt) !== 1) {
            throw new \InvalidArgumentException('Die Gesprächsdaten sind ungültig.');
        }
        if ($direction === 'outgoing' && (
            preg_match('/^[1-9][0-9]{2,7}$/', $extension) !== 1
            || preg_match('/^(?:0|00)[0-9]{4,19}$/', $phone) !== 1
            || preg_match('~^Local/' . preg_quote($extension, '~') . '@from-internal(?:/|-)~', $channel) !== 1
        )) {
            return ['send' => false, 'reason' => 'Kein eindeutig aus SpeedPhone gestarteter PBX-Anruf.'];
        }
        $matches = $direction === 'incoming'
            ? $this->incomingMatches($linkedId)
            : $this->outgoingMatches($extension, $phone, $startedAt);
        if (count($matches) !== 1) {
            return ['send' => false, 'reason' => count($matches) === 0
                ? 'Kein CRM-Gespräch zugeordnet.' : 'Mehrere mögliche CRM-Gespräche; keine automatische Kopie.'];
        }
        $match = $matches[0];
        $chatId = (int) $this->config->get('telegram_call_chat_id', 0);
        $threadId = (int) $this->config->get('telegram_call_thread_id', 0);
        if ($chatId >= 0 || $threadId <= 0) {
            throw new \RuntimeException('Das Marketing-Thread-Ziel ist nicht konfiguriert.');
        }

        $quotedKey = $this->db->quote($key);
        $this->db->query('START TRANSACTION');
        try {
            $existing = $this->db->fetchByAssoc($this->db->query('SELECT status,attempts FROM ' . self::TABLE
                . " WHERE recording_key='{$quotedKey}' FOR UPDATE"));
            $status = (string) ($existing['status'] ?? '');
            if (in_array($status, ['sent', 'sending', 'uncertain'], true)
                || ($status === 'error' && (int) ($existing['attempts'] ?? 0) >= 5)) {
                $this->db->query('COMMIT');
                return ['send' => false, 'reason' => 'Mitschnitt bereits gesendet oder Versandstatus muss geprüft werden.'];
            }
            $prospectId = $this->db->quote((string) $match['prospect_id']);
            $sourceId = $this->db->quote((string) $match['source_id']);
            if ($status === '') {
                $this->db->query('INSERT INTO ' . self::TABLE . "
                    (recording_key,prospect_id,source_id,direction,status,attempts,created_at,updated_at)
                    VALUES ('{$quotedKey}','{$prospectId}','{$sourceId}','{$direction}','sending',1,
                            UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            } else {
                $this->db->query('UPDATE ' . self::TABLE . " SET status='sending',attempts=attempts+1,
                    updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE recording_key='{$quotedKey}'");
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }

        $baseUrl = rtrim($this->crmBaseUrl, '/');
        $legacyBase = str_ends_with($baseUrl, '/legacy') ? $baseUrl : $baseUrl . '/legacy';
        return [
            'send' => true,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'prospect_id' => (string) $match['prospect_id'],
            'contact_name' => (string) $match['display_name'],
            'record_url' => $legacyBase . '/index.php?module=Prospects&action=DetailView&record=' . rawurlencode((string) $match['prospect_id']),
        ];
    }

    private function incomingMatches(string $linkedId): array
    {
        $eventKey = hash('sha256', 'pbx-in:' . $linkedId);
        $result = $this->db->query("SELECT events.prospect_id,events.display_name,
                '" . $this->db->quote($linkedId) . "' source_id
            FROM crm_speedphone_telegram_calls events
            INNER JOIN prospects p ON p.id=events.prospect_id AND p.deleted=0
            WHERE events.event_key='{$eventKey}' AND events.channel='pbx'
              AND events.direction='incoming' LIMIT 2");
        return $this->collect($result);
    }

    private function outgoingMatches(string $extension, string $phone, string $startedAt): array
    {
        $quotedExtension = $this->db->quote($extension);
        $quotedPhone = $this->db->quote($phone);
        $quotedTime = $this->db->quote($startedAt);
        $result = $this->db->query("SELECT calls.prospect_id,calls.display_name,calls.id source_id
            FROM crm_speedphone_pbx_calls calls
            INNER JOIN prospects p ON p.id=calls.prospect_id AND p.deleted=0
            WHERE calls.status='accepted' AND calls.extension='{$quotedExtension}'
              AND calls.phone='{$quotedPhone}'
              AND calls.created_at BETWEEN DATE_SUB('{$quotedTime}',INTERVAL 90 SECOND)
                                       AND DATE_ADD('{$quotedTime}',INTERVAL 90 SECOND)
            LIMIT 2");
        return $this->collect($result);
    }

    private function complete(string $key, array $input): array
    {
        $messageId = (int) ($input['telegram_message_id'] ?? 0);
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('Die Telegram-Nachrichtenkennung ist ungültig.');
        }
        $quotedKey = $this->db->quote($key);
        $this->db->query('UPDATE ' . self::TABLE . " SET status='sent',telegram_message_id={$messageId},
            updated_at=UTC_TIMESTAMP(),last_error=NULL
            WHERE recording_key='{$quotedKey}' AND status='sending'");
        $row = $this->db->fetchByAssoc($this->db->query('SELECT status,telegram_message_id FROM '
            . self::TABLE . " WHERE recording_key='{$quotedKey}' LIMIT 1"));
        if ((string) ($row['status'] ?? '') !== 'sent' || (int) ($row['telegram_message_id'] ?? 0) !== $messageId) {
            throw new \RuntimeException('Der bestätigte Mitschnitt konnte nicht protokolliert werden.');
        }
        return ['saved' => true];
    }

    private function fail(string $key, array $input): array
    {
        $error = mb_substr(trim((string) ($input['error'] ?? 'Versand fehlgeschlagen')), 0, 500);
        $this->db->query('UPDATE ' . self::TABLE . " SET status='error',
            updated_at=UTC_TIMESTAMP(),last_error='" . $this->db->quote($error) . "'
            WHERE recording_key='" . $this->db->quote($key) . "' AND status='sending'");
        return ['saved' => true];
    }

    private function collect(mixed $result): array
    {
        $rows = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
}
