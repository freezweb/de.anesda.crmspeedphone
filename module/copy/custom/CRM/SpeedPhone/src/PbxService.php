<?php

namespace Anesda\CRM\SpeedPhone;

use InvalidArgumentException;
use RuntimeException;

final class PbxService
{
    private readonly ?\Closure $runner;

    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser,
        private readonly UserAccessService $accessService,
        ?callable $runner = null
    ) {
        $this->runner = $runner === null ? null : \Closure::fromCallable($runner);
    }

    /**
     * @return array{enabled:bool,ready:bool,extension:string,message:string}
     */
    public function status(): array
    {
        $extension = (string) ($this->accessService->currentProfile()['pbx_extension'] ?? '');
        $enabled = (bool) $this->config->get('pbx_enabled', false)
            && ($this->gatewayCommand() !== [] || $this->hasAmiConfig());

        if (!$enabled) {
            return [
                'enabled' => false,
                'ready' => false,
                'extension' => $extension,
                'message' => 'Die Telefonanlage ist noch nicht mit SpeedPhone verbunden.',
            ];
        }
        if ($extension === '') {
            return [
                'enabled' => true,
                'ready' => false,
                'extension' => '',
                'message' => 'Für deinen Benutzer ist noch keine Festnetz-Durchwahl hinterlegt.',
            ];
        }

        return [
            'enabled' => true,
            'ready' => true,
            'extension' => $extension,
            'message' => 'Zuerst klingelt deine Durchwahl ' . $extension . '.',
        ];
    }

    /**
     * @return array{call_id:string,extension:string,display_name:string,status:string,message:string}
     */
    public function queueCall(string $prospectId, string $phoneKind = 'work'): array
    {
        $status = $this->status();
        if (!$status['ready']) {
            throw new RuntimeException($status['message']);
        }

        $extension = self::normalizeExtension($status['extension']);
        if (!in_array($phoneKind, ['work', 'mobile'], true)) {
            throw new InvalidArgumentException('Die gewählte Telefonnummer ist ungültig.');
        }

        /** @var \Prospect $prospect */
        $prospect = \BeanFactory::getBean('Prospects', $prospectId);
        if (!$prospect || empty($prospect->id) || (int) $prospect->deleted === 1) {
            throw new RuntimeException('Der Zielkontakt wurde nicht gefunden.');
        }

        $rawPhone = $phoneKind === 'mobile' ? (string) $prospect->phone_mobile : (string) $prospect->phone_work;
        $phone = self::toPbxDialNumber(DialerService::normalizePhone($rawPhone));
        $displayName = trim((string) ($prospect->account_name ?: $prospect->name));
        $callId = $this->guid();
        $userId = (string) $this->currentUser->id;

        $recentResult = $this->db->query("SELECT id FROM crm_speedphone_pbx_calls
            WHERE user_id='" . $this->quote($userId) . "'
              AND prospect_id='" . $this->quote($prospectId) . "'
              AND extension='" . $this->quote($extension) . "'
              AND phone='" . $this->quote($phone) . "'
              AND status IN ('queued','accepted')
              AND created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND)
            LIMIT 1");
        if ($this->db->fetchByAssoc($recentResult)) {
            throw new RuntimeException('Dieser Festnetz-Anruf wurde bereits gestartet. Bitte einen Moment warten.');
        }

        $this->db->query("INSERT INTO crm_speedphone_pbx_calls
            (id, user_id, prospect_id, extension, phone, display_name, status, gateway_job_id,
             error_message, created_at, updated_at)
            VALUES ('" . $this->quote($callId) . "', '" . $this->quote($userId) . "',
                    '" . $this->quote($prospectId) . "', '" . $this->quote($extension) . "',
                    '" . $this->quote($phone) . "', '" . $this->quote($displayName) . "',
                    'queued', NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        try {
            $gatewayResult = $this->runGateway([
                'action' => 'bridge',
                'request_id' => $callId,
                'extension' => $extension,
                'target' => $phone,
                'display_name' => $displayName,
            ]);
            $jobId = trim((string) ($gatewayResult['job_id'] ?? ''));
            $this->db->query("UPDATE crm_speedphone_pbx_calls
                SET status='accepted', gateway_job_id=" . ($jobId === '' ? 'NULL' : "'" . $this->quote($jobId) . "'") . ",
                    updated_at=UTC_TIMESTAMP()
                WHERE id='" . $this->quote($callId) . "'");
        } catch (\Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $this->db->query("UPDATE crm_speedphone_pbx_calls
                SET status='failed', error_message='" . $this->quote($message) . "', updated_at=UTC_TIMESTAMP()
                WHERE id='" . $this->quote($callId) . "'");
            throw $exception;
        }

        return [
            'call_id' => $callId,
            'extension' => $extension,
            'display_name' => $displayName,
            'status' => 'accepted',
            'message' => 'Durchwahl ' . $extension . ' klingelt. Nach dem Abheben wird ' . $displayName . ' gewählt.',
        ];
    }

    public static function normalizeExtension(string $extension): string
    {
        $normalized = trim($extension);
        if (!preg_match('/^[1-9][0-9]{2,7}$/', $normalized)) {
            throw new InvalidArgumentException('Die Festnetz-Durchwahl muss aus 3 bis 8 Ziffern bestehen.');
        }

        return $normalized;
    }

    public static function toPbxDialNumber(string $phone): string
    {
        if (str_starts_with($phone, '+490')) {
            $phone = '0' . substr($phone, 4);
        } elseif (str_starts_with($phone, '+49')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '+')) {
            $phone = '00' . substr($phone, 1);
        }
        if (!preg_match('/^(?:0|00)[0-9]{4,19}$/', $phone)) {
            throw new InvalidArgumentException('Für die Festnetz-Wahl ist eine vollständige externe Rufnummer erforderlich.');
        }

        return $phone;
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function runGateway(array $payload): array
    {
        if ($this->runner !== null) {
            $result = ($this->runner)($payload);
            if (!is_array($result)) {
                throw new RuntimeException('Die Telefonanlage hat keine gültige Antwort geliefert.');
            }
            return $result;
        }

        $command = $this->gatewayCommand();
        if ($command === []) {
            return $this->runAmi($payload);
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Das Telefonanlagen-Gateway ist auf dem CRM-Server nicht ausführbar.');
        }

        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Der Auftrag an die Telefonanlage konnte nicht gestartet werden.');
        }

        fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $decoded = json_decode(trim((string) $stdout), true);
        if ($exitCode !== 0 || !is_array($decoded) || empty($decoded['success'])) {
            $error = trim((string) ($decoded['error'] ?? $stderr ?? ''));
            throw new RuntimeException($error !== '' ? $error : 'Die Telefonanlage hat den Anrufauftrag abgelehnt.');
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function runAmi(array $payload): array
    {
        if (!$this->hasAmiConfig()) {
            throw new RuntimeException('Die AMI-Verbindung zur Telefonanlage ist nicht vollständig konfiguriert.');
        }

        $host = trim((string) $this->config->get('pbx_ami_host', ''));
        $port = max(1, min(65535, (int) $this->config->get('pbx_ami_port', 5038)));
        $username = trim((string) $this->config->get('pbx_ami_username', ''));
        $secret = (string) $this->config->get('pbx_ami_secret', '');
        $context = trim((string) $this->config->get('pbx_ami_context', 'from-internal'));
        $timeout = max(3, min(30, (int) $this->config->get('pbx_ami_timeout_seconds', 10)));
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)
            || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $username)
            || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $context)
            || $secret === ''
            || preg_match('/[\r\n]/', $secret)) {
            throw new RuntimeException('Die AMI-Konfiguration der Telefonanlage ist ungültig.');
        }

        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Die Telefonanlage ist momentan nicht erreichbar.');
        }

        stream_set_timeout($socket, $timeout);
        try {
            // AMI sendet direkt nach dem TCP-Aufbau eine einzelne Protokollzeile.
            fgets($socket);
            $this->writeAmiAction($socket, [
                'Action' => 'Login',
                'Username' => $username,
                'Secret' => $secret,
                'Events' => 'off',
            ]);
            $login = $this->readAmiResponse($socket);
            if (strcasecmp((string) ($login['Response'] ?? ''), 'Success') !== 0) {
                throw new RuntimeException('Die Telefonanlage hat die SpeedPhone-Anmeldung abgelehnt.');
            }

            $this->writeAmiAction($socket, [
                'Action' => 'Originate',
                'ActionID' => (string) $payload['request_id'],
                'Channel' => 'Local/' . $payload['extension'] . '@' . $context . '/n',
                'Context' => $context,
                'Exten' => (string) $payload['target'],
                'Priority' => '1',
                'CallerID' => 'CRM SpeedPhone <' . $payload['extension'] . '>',
                'Timeout' => '45000',
                'Async' => 'true',
                'Variable' => 'SPEEDPHONE_REQUEST_ID=' . $payload['request_id'],
            ]);
            $originated = $this->readAmiResponse($socket);
            if (strcasecmp((string) ($originated['Response'] ?? ''), 'Success') !== 0) {
                throw new RuntimeException('Die Telefonanlage konnte den Anruf nicht starten.');
            }

            $this->writeAmiAction($socket, ['Action' => 'Logoff']);
        } finally {
            fclose($socket);
        }

        return ['success' => true, 'job_id' => (string) $payload['request_id']];
    }

    /** @param array<string, string> $fields */
    private function writeAmiAction(mixed $socket, array $fields): void
    {
        $lines = [];
        foreach ($fields as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value)) {
                throw new RuntimeException('Der Telefonanlagen-Auftrag enthält ungültige Steuerzeichen.');
            }
            $lines[] = $name . ': ' . $value;
        }
        $written = fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");
        if ($written === false) {
            throw new RuntimeException('Der Auftrag konnte nicht an die Telefonanlage übertragen werden.');
        }
    }

    /** @return array<string, string> */
    private function readAmiResponse(mixed $socket): array
    {
        $response = [];
        while (($line = fgets($socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($response !== []) {
                    return $response;
                }
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $response[substr($line, 0, $separator)] = ltrim(substr($line, $separator + 1));
            }
        }

        throw new RuntimeException('Die Telefonanlage hat nicht rechtzeitig geantwortet.');
    }

    private function hasAmiConfig(): bool
    {
        return trim((string) $this->config->get('pbx_ami_host', '')) !== ''
            && trim((string) $this->config->get('pbx_ami_username', '')) !== ''
            && (string) $this->config->get('pbx_ami_secret', '') !== '';
    }

    /** @return list<string> */
    private function gatewayCommand(): array
    {
        $command = $this->config->get('pbx_gateway_command', []);
        if (!is_array($command) || $command === []) {
            return [];
        }
        foreach ($command as $part) {
            if (!is_string($part) || $part === '' || str_contains($part, "\0")) {
                return [];
            }
        }

        return array_values($command);
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
}
