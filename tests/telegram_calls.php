<?php

// Isolierte Prüfung: keine Live-Anrufe, keine Telegram-Nachrichten und keine DB-Schreibzugriffe.
define('sugarEntry', true);
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/bootstrap.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\TelegramCallService;

class DBManager
{
    public array $queries = [];
    public array $rows = [];

    public function quote(string $value): string { return addslashes($value); }
    public function query(string $sql): string { $this->queries[] = $sql; return $sql; }
    public function fetchByAssoc(string $sql): array|false
    {
        if (str_contains($sql, 'FROM crm_speedphone_dialer_commands')) {
            return $this->rows['app_out'] ?? false;
        }
        if (str_contains($sql, 'FROM crm_speedphone_pbx_calls')) {
            return $this->rows['pbx_out'] ?? false;
        }
        if (str_contains($sql, 'FROM crm_speedphone_incoming_calls')) {
            return $this->rows['app_in'] ?? false;
        }
        return false;
    }
}

function verifyTelegram(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$configClass = new ReflectionClass(Config::class);
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getProperty('values')->setValue($config, ['telegram_call_enabled' => false]);
$db = new DBManager();
$service = new TelegramCallService($config, $db);
$service->queueDialerCommand('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
verifyTelegram($db->queries === [], 'Ohne Konfiguration dürfen weder Datenbank noch Telegram angesprochen werden.');
verifyTelegram($service->deliverPending() === ['sent' => 0, 'failed' => 0], 'Deaktivierter Versand muss leer bleiben.');

$configClass->getProperty('values')->setValue($config, ['telegram_call_enabled' => true]);
$userId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$prospectId = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
$db->rows = [
    'app_out' => ['user_id' => $userId, 'prospect_id' => $prospectId, 'display_name' => 'Betrieb & Sohn'],
    'pbx_out' => ['user_id' => $userId, 'prospect_id' => $prospectId, 'display_name' => 'Betrieb & Sohn'],
    'app_in' => ['id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd'],
];
$service->queueDialerCommand('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
$service->queuePbxCall('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee');
$service->queueAppIncoming('dddddddd-dddd-dddd-dddd-dddddddddddd', $userId, $prospectId, 'Betrieb & Sohn');
$service->queuePbxIncoming('1728901234.42', null, $prospectId, 'Betrieb & Sohn');
$inserts = array_values(array_filter($db->queries, static fn (string $sql): bool => str_contains($sql, 'INSERT INTO crm_speedphone_telegram_calls')));
verifyTelegram(count($inserts) === 4, 'Alle vier CRM-Anrufwege müssen ein Ereignis vormerken.');
verifyTelegram(count(array_unique(array_map(static fn (string $sql): string =>
    preg_match("/VALUES \\('([a-f0-9]{64})'/", $sql, $match) ? $match[1] : '', $inserts))) === 4,
    'App-/Festnetz- und Ein-/Ausgangsereignisse brauchen getrennte Deduplizierungsschlüssel.');
verifyTelegram(str_contains($inserts[3], 'ON DUPLICATE KEY UPDATE'), 'Wiederholte PBX-Webhooks dürfen keine zweite Nachricht erzeugen.');
verifyTelegram(str_contains($db->queries[0], "status='dialed'") && str_contains($db->queries[2], "status='accepted'"),
    'Fehlgeschlagene oder nur zugestellte Wählaufträge dürfen nicht gemeldet werden.');

$message = TelegramCallService::formatMessage([
    'direction' => 'incoming', 'channel' => 'pbx', 'display_name' => 'Betrieb & <Test>',
    'user_name' => 'Antonia & Co', 'user_login' => '', 'created_at' => '2026-10-14 08:00:00',
    'prospect_id' => $prospectId,
], 'https://crm.example.org');
verifyTelegram(str_contains($message, 'Betrieb &amp; &lt;Test&gt;') && str_contains($message, 'Antonia &amp; Co'),
    'Kontakt- und Mitarbeiternamen müssen HTML-sicher formatiert werden.');
verifyTelegram(str_contains($message, '14.10.2026 10:00') && str_contains($message, 'https://crm.example.org/legacy/'),
    'Zeit und CRM-Link müssen zur Installation passen.');
verifyTelegram(!str_contains($message, 'crm.anesda.de') && !str_contains($message, '<Test>'),
    'Das öffentliche Modul darf keine feste Anesda-CRM-Adresse oder unescaped Inhalte erzwingen.');
$appMessage = TelegramCallService::formatMessage([
    'direction' => 'outgoing', 'channel' => 'app', 'display_name' => 'Betrieb',
    'user_name' => 'Antonia', 'created_at' => '2026-10-28 08:00:00',
    'prospect_id' => $prospectId,
]);
verifyTelegram(str_contains($appMessage, 'SIP-Nebenstelle im CRM zugeordnet'),
    'App-Meldungen müssen die Bedingung für eine spätere Anlagenaufnahme erklären.');

echo "Telegram-Anrufprüfungen erfolgreich.\n";
