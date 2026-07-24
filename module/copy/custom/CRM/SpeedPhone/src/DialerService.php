<?php

namespace Anesda\CRM\SpeedPhone;

use InvalidArgumentException;
use RuntimeException;

final class DialerService
{
    private const PAIRING_LIFETIME_SECONDS = 600;
    private const COMMAND_LIFETIME_SECONDS = 120;
    private const DEVICE_READY_SECONDS = 20;

    public function __construct(private \DBManager $db, private ?\User $currentUser = null)
    {
    }

    public function createPairing(string $endpoint, string $setupPage): array
    {
        $userId = $this->currentUserId();
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException('Die öffentliche Dialer-Adresse muss HTTPS verwenden.');
        }
        if (!filter_var($setupPage, FILTER_VALIDATE_URL) || !str_starts_with($setupPage, 'https://')) {
            throw new RuntimeException('Die öffentliche Dialer-Installationsseite muss HTTPS verwenden.');
        }

        $this->cleanup();
        $this->db->query("UPDATE crm_speedphone_dialer_pairings
            SET used_at=UTC_TIMESTAMP()
            WHERE user_id='" . $this->quote($userId) . "' AND used_at IS NULL");

        $token = self::base64Url(random_bytes(32));
        $pairingId = $this->guid();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::PAIRING_LIFETIME_SECONDS);
        $this->db->query("INSERT INTO crm_speedphone_dialer_pairings
            (id, user_id, token_hash, created_at, expires_at, used_at)
            VALUES ('" . $this->quote($pairingId) . "', '" . $this->quote($userId) . "',
                    '" . hash('sha256', $token) . "', UTC_TIMESTAMP(),
                    '" . $this->quote($expiresAt) . "', NULL)");

        $deepLink = 'speedphone://pair?v=1&server=' . rawurlencode($endpoint) . '&token=' . rawurlencode($token);
        // Das Fragment wird weder an den Webserver übertragen noch im Access-Log gespeichert.
        $payload = $setupPage . '#setup=' . self::base64Url($deepLink);

        return [
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'expires_in' => self::PAIRING_LIFETIME_SECONDS,
        ];
    }

    public function claimPairing(string $token, string $deviceName, string $platform, string $deviceTokenHash): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            throw new InvalidArgumentException('Der Kopplungscode ist ungültig.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $deviceTokenHash)) {
            throw new InvalidArgumentException('Das Gerätetoken ist ungültig.');
        }
        $deviceName = trim($deviceName);
        if ($deviceName === '' || mb_strlen($deviceName) > 120) {
            throw new InvalidArgumentException('Der Gerätename ist ungültig.');
        }
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new InvalidArgumentException('Die Geräteplattform ist ungültig.');
        }

        $this->db->query('START TRANSACTION');
        try {
            $result = $this->db->query("SELECT p.id, p.user_id, u.first_name, u.last_name, u.user_name
                FROM crm_speedphone_dialer_pairings p
                INNER JOIN users u ON u.id=p.user_id AND u.deleted=0 AND u.status='Active'
                WHERE p.token_hash='" . hash('sha256', $token) . "'
                  AND p.used_at IS NULL AND p.expires_at>UTC_TIMESTAMP()
                LIMIT 1 FOR UPDATE");
            $pairing = $this->db->fetchByAssoc($result);
            if (!$pairing) {
                throw new RuntimeException('Der QR-Code ist abgelaufen oder wurde bereits verwendet.');
            }

            $deviceId = $this->guid();
            $this->db->query("UPDATE crm_speedphone_dialer_devices SET active=0
                WHERE token_hash='" . $deviceTokenHash . "'");
            $this->db->query("INSERT INTO crm_speedphone_dialer_devices
                (id, user_id, device_name, platform, token_hash, active, paired_at, last_seen_at, last_error)
                VALUES ('" . $this->quote($deviceId) . "', '" . $this->quote((string) $pairing['user_id']) . "',
                        '" . $this->quote($deviceName) . "', '" . $this->quote($platform) . "',
                        '" . $deviceTokenHash . "', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)");
            $this->db->query("UPDATE crm_speedphone_dialer_pairings SET used_at=UTC_TIMESTAMP()
                WHERE id='" . $this->quote((string) $pairing['id']) . "'");
            $this->db->query('COMMIT');

            $userName = trim((string) $pairing['first_name'] . ' ' . (string) $pairing['last_name']);
            return ['device_id' => $deviceId, 'user_name' => $userName ?: (string) $pairing['user_name']];
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    public function poll(string $deviceId, string $deviceToken): array
    {
        $device = $this->authenticateDevice($deviceId, $deviceToken);
        $this->db->query("UPDATE crm_speedphone_dialer_devices
            SET last_seen_at=UTC_TIMESTAMP(), last_error=NULL WHERE id='" . $this->quote($deviceId) . "'");
        $this->db->query("UPDATE crm_speedphone_dialer_commands SET status='expired', completed_at=UTC_TIMESTAMP()
            WHERE device_id='" . $this->quote($deviceId) . "' AND status IN ('pending','received')
              AND expires_at<=UTC_TIMESTAMP()");

        $result = $this->db->query("SELECT id, prospect_id, phone, display_name, created_at, expires_at
            FROM crm_speedphone_dialer_commands
            WHERE device_id='" . $this->quote($deviceId) . "' AND status IN ('pending','received')
              AND expires_at>UTC_TIMESTAMP()
            ORDER BY created_at ASC LIMIT 1");
        $command = $this->db->fetchByAssoc($result);

        return [
            'device_name' => (string) $device['device_name'],
            'command' => $command ?: null,
            'poll_after_ms' => 2000,
        ];
    }

    public function acknowledge(string $deviceId, string $deviceToken, string $commandId, string $status, string $error = ''): void
    {
        $this->authenticateDevice($deviceId, $deviceToken);
        if (!preg_match('/^[a-f0-9-]{36}$/i', $commandId)) {
            throw new InvalidArgumentException('Die Anruf-ID ist ungültig.');
        }
        if (!in_array($status, ['received', 'dialed', 'failed'], true)) {
            throw new InvalidArgumentException('Der Anrufstatus ist ungültig.');
        }
        $completed = $status === 'received' ? 'NULL' : 'UTC_TIMESTAMP()';
        $delivered = $status === 'received' ? 'UTC_TIMESTAMP()' : 'COALESCE(delivered_at, UTC_TIMESTAMP())';
        $errorValue = $error === '' ? 'NULL' : "'" . $this->quote(mb_substr($error, 0, 255)) . "'";
        $this->db->query("UPDATE crm_speedphone_dialer_commands
            SET status='" . $status . "', delivered_at={$delivered}, completed_at={$completed}, error_message={$errorValue}
            WHERE id='" . $this->quote($commandId) . "' AND device_id='" . $this->quote($deviceId) . "'");
    }

    public function disconnect(string $deviceId, string $deviceToken): void
    {
        $this->authenticateDevice($deviceId, $deviceToken);
        $this->db->query("UPDATE crm_speedphone_dialer_devices SET active=0
            WHERE id='" . $this->quote($deviceId) . "'");
    }

    public function listDevices(): array
    {
        $userId = $this->currentUserId();
        $result = $this->db->query("SELECT id, device_name, platform, paired_at, last_seen_at,
                IF(last_seen_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::DEVICE_READY_SECONDS . " SECOND), 1, 0) AS is_ready
            FROM crm_speedphone_dialer_devices
            WHERE user_id='" . $this->quote($userId) . "' AND active=1 ORDER BY last_seen_at DESC");
        $devices = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $devices[] = $row;
        }
        return $devices;
    }

    public function queueCall(string $prospectId, string $phoneKind = 'work'): array
    {
        $userId = $this->currentUserId();
        $devices = $this->listDevices();
        $device = null;
        foreach ($devices as $candidate) {
            if ((int) $candidate['is_ready'] === 1) {
                $device = $candidate;
                break;
            }
        }
        if ($device === null) {
            throw new RuntimeException('Kein gekoppeltes Handy ist gerade empfangsbereit. Öffnen Sie die App und lassen Sie den Bildschirm aktiv.');
        }

        /** @var \Prospect $prospect */
        $prospect = \BeanFactory::getBean('Prospects', $prospectId);
        if (!$prospect || empty($prospect->id) || (int) $prospect->deleted === 1) {
            throw new RuntimeException('Der Zielkontakt wurde nicht gefunden.');
        }
        $rawPhone = $phoneKind === 'mobile' ? (string) $prospect->phone_mobile : (string) $prospect->phone_work;
        $phone = self::normalizePhone($rawPhone);
        $displayName = trim((string) ($prospect->account_name ?: $prospect->name));

        $this->db->query("UPDATE crm_speedphone_dialer_commands SET status='cancelled', completed_at=UTC_TIMESTAMP()
            WHERE device_id='" . $this->quote((string) $device['id']) . "' AND status IN ('pending','received')");
        $commandId = $this->guid();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::COMMAND_LIFETIME_SECONDS);
        $this->db->query("INSERT INTO crm_speedphone_dialer_commands
            (id, device_id, user_id, prospect_id, phone, display_name, status, created_at, expires_at,
             delivered_at, completed_at, error_message)
            VALUES ('" . $this->quote($commandId) . "', '" . $this->quote((string) $device['id']) . "',
                    '" . $this->quote($userId) . "', '" . $this->quote($prospectId) . "',
                    '" . $this->quote($phone) . "', '" . $this->quote($displayName) . "', 'pending',
                    UTC_TIMESTAMP(), '" . $this->quote($expiresAt) . "', NULL, NULL, NULL)");

        return ['command_id' => $commandId, 'device_name' => (string) $device['device_name'], 'platform' => (string) $device['platform'], 'status' => 'pending'];
    }

    public function commandStatus(string $commandId): array
    {
        $userId = $this->currentUserId();
        $result = $this->db->query("SELECT status, error_message FROM crm_speedphone_dialer_commands
            WHERE id='" . $this->quote($commandId) . "' AND user_id='" . $this->quote($userId) . "' LIMIT 1");
        $row = $this->db->fetchByAssoc($result);
        if (!$row) {
            throw new RuntimeException('Der Anrufauftrag wurde nicht gefunden.');
        }
        return ['status' => (string) $row['status'], 'error' => (string) ($row['error_message'] ?? '')];
    }

    public function revokeDevice(string $deviceId): void
    {
        $userId = $this->currentUserId();
        $this->db->query("UPDATE crm_speedphone_dialer_devices SET active=0
            WHERE id='" . $this->quote($deviceId) . "' AND user_id='" . $this->quote($userId) . "'");
    }

    public static function normalizePhone(string $phone): string
    {
        if (preg_match('/[*#;,]/', $phone)) {
            throw new InvalidArgumentException('Steuercodes sind als Telefonnummer nicht zulässig.');
        }
        $normalized = preg_replace('/[^+0-9]/', '', trim($phone)) ?? '';
        if (!preg_match('/^\+?[0-9]{5,20}$/', $normalized)) {
            throw new InvalidArgumentException('Für diesen Kontakt ist keine gültige Telefonnummer hinterlegt.');
        }
        return $normalized;
    }

    public function authenticateDevice(string $deviceId, string $deviceToken): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $deviceId) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $deviceToken)) {
            throw new RuntimeException('Geräteanmeldung ungültig.');
        }
        $result = $this->db->query("SELECT id, user_id, device_name, token_hash FROM crm_speedphone_dialer_devices
            WHERE id='" . $this->quote($deviceId) . "' AND active=1 LIMIT 1");
        $device = $this->db->fetchByAssoc($result);
        if (!$device || !hash_equals((string) $device['token_hash'], hash('sha256', $deviceToken))) {
            throw new RuntimeException('Dieses Gerät ist nicht mehr gekoppelt.');
        }
        return $device;
    }

    private function cleanup(): void
    {
        $this->db->query('DELETE FROM crm_speedphone_dialer_pairings WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        $this->db->query("UPDATE crm_speedphone_dialer_commands SET status='expired', completed_at=UTC_TIMESTAMP()
            WHERE status IN ('pending','received') AND expires_at<=UTC_TIMESTAMP()");
    }

    private function currentUserId(): string
    {
        if ($this->currentUser === null || empty($this->currentUser->id)) {
            throw new RuntimeException('Nicht angemeldet.');
        }
        return (string) $this->currentUser->id;
    }

    private function quote(string $value): string
    {
        return $this->db->quote($value);
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

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
