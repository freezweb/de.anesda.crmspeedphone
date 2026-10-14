<?php

namespace Anesda\CRM\SpeedPhone;

/** Meldet ausschließlich technisch bestätigte SpeedPhone-Wahlaufträge und zugeordnete Rückrufe. */
final class TelegramCallService
{
    private const TABLE = 'crm_speedphone_telegram_calls';

    public function __construct(private readonly Config $config, private readonly \DBManager $db,
        private readonly string $crmBaseUrl = '')
    {
    }

    /** Ein Telegram-Ausfall darf niemals einen Telefonanruf verhindern. */
    public static function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            error_log('CRM SpeedPhone: Telegram-Anrufmeldung konnte nicht vorgemerkt werden: ' . $exception->getMessage());
        }
    }

    public function queueDialerCommand(string $commandId): void
    {
        if (!$this->enabled()) {
            return;
        }
        $row = $this->db->fetchByAssoc($this->db->query("SELECT user_id,prospect_id,display_name
            FROM crm_speedphone_dialer_commands
            WHERE id='" . $this->db->quote($commandId) . "' AND status='dialed' LIMIT 1"));
        if ($row) {
            $this->queue('app-out', $commandId, 'outgoing', 'app', (string) $row['user_id'],
                (string) $row['prospect_id'], (string) $row['display_name']);
        }
    }

    public function queuePbxCall(string $callId): void
    {
        if (!$this->enabled()) {
            return;
        }
        $row = $this->db->fetchByAssoc($this->db->query("SELECT user_id,prospect_id,display_name
            FROM crm_speedphone_pbx_calls
            WHERE id='" . $this->db->quote($callId) . "' AND status='accepted' LIMIT 1"));
        if ($row) {
            $this->queue('pbx-out', $callId, 'outgoing', 'pbx', (string) $row['user_id'],
                (string) $row['prospect_id'], (string) $row['display_name']);
        }
    }

    public function queueAppIncoming(string $eventId, string $userId, string $prospectId, string $displayName): void
    {
        if (!$this->enabled()) {
            return;
        }
        $row = $this->db->fetchByAssoc($this->db->query("SELECT id FROM crm_speedphone_incoming_calls
            WHERE id='" . $this->db->quote($eventId) . "' AND user_id='" . $this->db->quote($userId) . "'
              AND prospect_id='" . $this->db->quote($prospectId) . "' LIMIT 1"));
        if ($row) {
            $this->queue('app-in', $eventId, 'incoming', 'app', $userId, $prospectId, $displayName);
        }
    }

    public function queuePbxIncoming(string $sourceEventId, ?string $userId, string $prospectId, string $displayName): void
    {
        if (!$this->enabled()) {
            return;
        }
        // Die Ereignis-ID stammt ausschließlich aus dem signierten Asterisk-Webhook.
        $this->queue('pbx-in', $sourceEventId, 'incoming', 'pbx', $userId, $prospectId, $displayName);
    }

    private function queue(string $kind, string $sourceId, string $direction, string $channel,
        ?string $userId, string $prospectId, string $displayName): void
    {
        if ($sourceId === '' || !preg_match('/^[a-f0-9-]{36}$/i', $prospectId)) {
            throw new \InvalidArgumentException('Die Anrufzuordnung ist ungültig.');
        }
        $eventKey = hash('sha256', $kind . ':' . $sourceId);
        $userSql = $userId === null || $userId === '' ? 'NULL' : "'" . $this->db->quote($userId) . "'";
        $displayName = mb_substr(trim($displayName) ?: 'Zielkontakt', 0, 255);
        $this->db->query("INSERT INTO " . self::TABLE . "
            (event_key,direction,channel,user_id,prospect_id,display_name,created_at,next_attempt_at)
            VALUES ('{$eventKey}','{$direction}','{$channel}',{$userSql},'" . $this->db->quote($prospectId) . "','"
            . $this->db->quote($displayName) . "',UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE user_id=COALESCE(user_id,VALUES(user_id))");
    }

    /** @return array{sent:int,failed:int} */
    public function deliverPending(int $limit = 20): array
    {
        if (!$this->enabled()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $token = $this->config->requireString('telegram_call_bot_token');
        $chatId = (int) $this->config->get('telegram_call_chat_id', 0);
        $threadId = (int) $this->config->get('telegram_call_thread_id', 0);
        if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token) || $chatId >= 0 || $threadId <= 0) {
            throw new \RuntimeException('Die Telegram-Anrufkonfiguration ist unvollständig.');
        }
        if ((int) $this->db->getOne("SELECT GET_LOCK('crm_speedphone_telegram_calls',0)") !== 1) {
            return ['sent' => 0, 'failed' => 0];
        }
        $sent = 0;
        $failed = 0;
        try {
            $limit = max(1, min(100, $limit));
            $result = $this->db->query("SELECT events.id,events.direction,events.channel,events.prospect_id,
                    events.display_name,events.created_at,events.attempts,
                    TRIM(CONCAT_WS(' ',users.first_name,users.last_name)) user_name,
                    users.user_name user_login
                FROM " . self::TABLE . " events
                LEFT JOIN users ON users.id=events.user_id AND users.deleted=0
                WHERE events.sent_at IS NULL AND events.attempts<10
                  AND events.next_attempt_at<=UTC_TIMESTAMP()
                ORDER BY events.id ASC LIMIT {$limit}");
            while ($row = $this->db->fetchByAssoc($result)) {
                $id = (int) $row['id'];
                try {
                    $messageId = $this->sendMessage($token, $chatId, $threadId,
                        self::formatMessage($row, $this->crmBaseUrl));
                    $this->db->query("UPDATE " . self::TABLE . " SET sent_at=UTC_TIMESTAMP(),
                        telegram_message_id={$messageId},last_error=NULL WHERE id={$id} AND sent_at IS NULL");
                    $sent++;
                } catch (\Throwable $exception) {
                    $delay = min(3600, 60 * (2 ** min(6, (int) ($row['attempts'] ?? 0))));
                    $message = mb_substr($exception->getMessage(), 0, 255);
                    $this->db->query("UPDATE " . self::TABLE . " SET attempts=attempts+1,
                        last_error='" . $this->db->quote($message) . "',
                        next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$delay} SECOND)
                        WHERE id={$id} AND sent_at IS NULL");
                    $failed++;
                    error_log('CRM SpeedPhone: Telegram-Anrufmeldung ' . $id . ' fehlgeschlagen: ' . $message);
                }
            }
        } finally {
            $this->db->getOne("SELECT RELEASE_LOCK('crm_speedphone_telegram_calls')");
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @param array<string,mixed> $row */
    public static function formatMessage(array $row, string $crmBaseUrl = ''): string
    {
        $incoming = (string) ($row['direction'] ?? '') === 'incoming';
        $channel = (string) ($row['channel'] ?? '') === 'app' ? 'Handy-App' : 'Telefonanlage';
        $title = $incoming ? '📲 Eingehender Anruf im CRM zugeordnet' : '📞 CRM-Wahlauftrag gestartet';
        $name = htmlspecialchars((string) (($row['display_name'] ?? '') ?: 'Zielkontakt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $actor = trim((string) (($row['user_name'] ?? '') ?: ($row['user_login'] ?? '')));
        $actor = $actor === '' ? 'Noch keinem Mitarbeiter zugeordnet' : htmlspecialchars($actor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
        $date = $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
        $prospectId = (string) $row['prospect_id'];
        $baseUrl = rtrim($crmBaseUrl, '/');
        $link = '';
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) && str_starts_with($baseUrl, 'https://')) {
            $legacyBase = str_ends_with($baseUrl, '/legacy') ? $baseUrl : $baseUrl . '/legacy';
            $url = htmlspecialchars($legacyBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '/index.php?module=Prospects&amp;action=DetailView&amp;record=' . rawurlencode($prospectId);
            $link = "<a href=\"{$url}\">CRM-Datensatz öffnen</a>\n";
        }
        $status = $incoming ? 'Die Rufnummer wurde einem CRM-Kontakt zugeordnet; ein Gespräch ist damit noch nicht bestätigt.'
            : 'Der Wählauftrag wurde über das CRM gestartet; eine Gesprächsverbindung ist damit noch nicht bestätigt.';
        return "<b>{$title}</b>\nKontakt: <b>{$name}</b>\nKanal: {$channel}\nMitarbeiter: {$actor}\nZeit: {$date}\n"
            . $link . $status;
    }

    private function sendMessage(string $token, int $chatId, int $threadId, string $html): int
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP-cURL ist für Telegram nicht verfügbar.');
        }
        $handle = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        if ($handle === false) {
            throw new \RuntimeException('Telegram-Verbindung konnte nicht vorbereitet werden.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $chatId,
                    'message_thread_id' => $threadId,
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($handle);
        }
        if (!is_string($body) || $httpStatus !== 200) {
            throw new \RuntimeException('Telegram hat die Anrufmeldung nicht bestätigt (HTTP ' . $httpStatus . ').');
        }
        $response = json_decode($body, true);
        if (!is_array($response) || empty($response['ok'])
            || (int) ($response['result']['chat']['id'] ?? 0) !== $chatId
            || (int) ($response['result']['message_thread_id'] ?? 0) !== $threadId
            || (int) ($response['result']['message_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Telegram bestätigte nicht das konfigurierte Anruf-Thread-Ziel.');
        }
        return (int) $response['result']['message_id'];
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('telegram_call_enabled', false);
    }
}
