<?php

namespace Anesda\CRM\SpeedPhone;

final class LockService
{
    private const TABLE = 'crm_speedphone_locks';

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser
    ) {
    }

    public function cleanupExpired(): void
    {
        $this->db->query('DELETE FROM ' . self::TABLE . ' WHERE expires_at<=UTC_TIMESTAMP()');
    }

    public function getActiveForCurrentUser(): ?array
    {
        $sql = "SELECT prospect_id, lock_token, expires_at
                FROM " . self::TABLE . "
                WHERE user_id='" . $this->db->quote($this->currentUser->id) . "'
                  AND expires_at>UTC_TIMESTAMP()
                LIMIT 1";

        return $this->fetchLock($sql);
    }

    /**
     * @return array{prospect_id: string, lock_token: string, expires_at: string}|null
     */
    public function acquire(string $prospectId): ?array
    {
        $this->cleanupExpired();
        $active = $this->getActiveForCurrentUser();
        if ($active !== null) {
            return $active;
        }

        $token = bin2hex(random_bytes(32));
        $minutes = $this->lockMinutes();
        $sql = "INSERT IGNORE INTO " . self::TABLE . "
                    (prospect_id, user_id, lock_token, locked_at, expires_at)
                VALUES (
                    '" . $this->db->quote($prospectId) . "',
                    '" . $this->db->quote($this->currentUser->id) . "',
                    '" . $this->db->quote($token) . "',
                    UTC_TIMESTAMP(),
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$minutes} MINUTE)
                )";
        $this->db->query($sql);

        $active = $this->getActiveForCurrentUser();
        if ($active === null || !hash_equals($prospectId, $active['prospect_id'])) {
            return $active;
        }

        return $active;
    }

    public function assertOwned(string $prospectId, string $token): void
    {
        $token = $this->validateToken($token);
        $sql = "SELECT COUNT(*) n FROM " . self::TABLE . "
                WHERE prospect_id='" . $this->db->quote($prospectId) . "'
                  AND user_id='" . $this->db->quote($this->currentUser->id) . "'
                  AND lock_token='" . $this->db->quote($token) . "'
                  AND expires_at>UTC_TIMESTAMP()";
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        if ((int) ($row['n'] ?? 0) !== 1) {
            throw new \RuntimeException('Dieser Zielkontakt ist nicht mehr für dich reserviert. Bitte lade SpeedPhone neu.');
        }
    }

    public function heartbeat(string $prospectId, string $token): array
    {
        $this->assertOwned($prospectId, $token);
        $minutes = $this->lockMinutes();
        $sql = "UPDATE " . self::TABLE . "
                SET expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$minutes} MINUTE)
                WHERE prospect_id='" . $this->db->quote($prospectId) . "'
                  AND user_id='" . $this->db->quote($this->currentUser->id) . "'
                  AND lock_token='" . $this->db->quote($token) . "'";
        $this->db->query($sql);

        $active = $this->getActiveForCurrentUser();
        if ($active === null) {
            throw new \RuntimeException('Die Reservierung konnte nicht verlängert werden.');
        }

        return $active;
    }

    public function release(string $prospectId, string $token): void
    {
        $token = $this->validateToken($token);
        $sql = "DELETE FROM " . self::TABLE . "
                WHERE prospect_id='" . $this->db->quote($prospectId) . "'
                  AND user_id='" . $this->db->quote($this->currentUser->id) . "'
                  AND lock_token='" . $this->db->quote($token) . "'";
        $this->db->query($sql);
    }

    public function releaseCurrentUserLock(): void
    {
        $sql = "DELETE FROM " . self::TABLE . "
                WHERE user_id='" . $this->db->quote($this->currentUser->id) . "'";
        $this->db->query($sql);
    }

    private function lockMinutes(): int
    {
        return max(5, min(120, (int) $this->config->get('lock_minutes', 20)));
    }

    private function validateToken(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new \InvalidArgumentException('Die Reservierungskennung ist ungültig.');
        }

        return $token;
    }

    private function fetchLock(string $sql): ?array
    {
        $row = $this->db->fetchByAssoc($this->db->query($sql));
        if (empty($row['prospect_id']) || empty($row['lock_token'])) {
            return null;
        }

        return [
            'prospect_id' => (string) $row['prospect_id'],
            'lock_token' => (string) $row['lock_token'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }
}
