<?php

// Isolierte Prüfung: keine Audiodateien und keine Telegram-Uploads.
define('sugarEntry', true);
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/bootstrap.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\RecordingCopyService;

class DBManager
{
    public array $queries = [];
    public array $matches = [];
    public array $appMatches = [];
    public string $state = '';
    public int $messageId = 0;
    private int $matchIndex = 0;
    private int $appMatchIndex = 0;

    public function quote(string $value): string { return addslashes($value); }
    public function query(string $sql): string
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM crm_speedphone_pbx_calls calls')
            || str_contains($sql, 'FROM crm_speedphone_telegram_calls events')) {
            $this->matchIndex = 0;
        }
        if (str_contains($sql, 'FROM crm_speedphone_dialer_commands commands')) {
            $this->appMatchIndex = 0;
        }
        if (str_contains($sql, 'INSERT INTO crm_speedphone_recording_copies')) { $this->state = 'sending'; }
        if (str_contains($sql, "status='sent'")) {
            $this->state = 'sent';
            preg_match('/telegram_message_id=([0-9]+)/', $sql, $match);
            $this->messageId = (int) ($match[1] ?? 0);
        }
        return $sql;
    }
    public function fetchByAssoc(string $sql): array|false
    {
        if (str_contains($sql, 'FROM crm_speedphone_pbx_calls calls')
            || str_contains($sql, 'FROM crm_speedphone_telegram_calls events')) {
            return $this->matches[$this->matchIndex++] ?? false;
        }
        if (str_contains($sql, 'FROM crm_speedphone_dialer_commands commands')) {
            return $this->appMatches[$this->appMatchIndex++] ?? false;
        }
        if (str_contains($sql, 'FROM crm_speedphone_recording_copies')) {
            return $this->state === '' ? false : ['status' => $this->state, 'attempts' => 1,
                'telegram_message_id' => $this->messageId];
        }
        return false;
    }
}

function verifyRecording(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$configClass = new ReflectionClass(Config::class);
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getProperty('values')->setValue($config, [
    'telegram_call_enabled' => true,
    'telegram_call_chat_id' => -1001234567890,
    'telegram_call_thread_id' => 838,
]);
$db = new DBManager();
$service = new RecordingCopyService($config, $db, 'https://crm.example.org');
$key = str_repeat('a', 64);
$prospectId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$outgoing = [
    'action' => 'claim', 'recording_key' => $key, 'direction' => 'outgoing',
    'linkedid' => '1792047958.3117', 'started_at_utc' => '2026-10-15 07:05:58',
    'extension' => '6006', 'phone' => '038780579999',
    'channel' => 'Local/6006@from-internal-0001;2',
];
$db->matches = [['prospect_id' => $prospectId, 'display_name' => 'Beispielbetrieb',
    'source_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']];
verifyRecording(!$service->execute([...$outgoing, 'channel' => 'PJSIP/6006-0001'])['send'],
    'Ein normaler Festnetzanruf darf nicht als CRM-Anruf kopiert werden.');
$claim = $service->execute($outgoing);
verifyRecording($claim['send'] && $claim['prospect_id'] === $prospectId
    && $claim['thread_id'] === 838, 'Eindeutiger CRM-Wählauftrag muss den Marketing-Thread liefern.');
$sql = implode("\n", $db->queries);
verifyRecording(str_contains($sql, "calls.status='accepted'")
    && str_contains($sql, "calls.extension='6006'")
    && str_contains($sql, "calls.phone='038780579999'")
    && str_contains($sql, 'INTERVAL 90 SECOND'),
    'Rufnummer, Durchwahl, Status und enger Zeitraum müssen zusammenpassen.');
verifyRecording(str_contains($sql, 'INSERT INTO crm_speedphone_recording_copies'),
    'Kopierauftrag muss zentral in MariaDB reserviert werden.');
verifyRecording(!$service->execute($outgoing)['send'], 'Eine laufende Kopie darf kein zweites Audio senden.');
verifyRecording($service->execute(['action' => 'complete', 'recording_key' => $key,
    'telegram_message_id' => 123])['saved'], 'Telegram-Bestätigung wurde nicht gespeichert.');
verifyRecording(!$service->execute($outgoing)['send'], 'Ein bereits kopierter Mitschnitt darf nicht erneut gesendet werden.');

$db->state = '';
$appOutgoing = [...$outgoing, 'recording_key' => str_repeat('d', 64),
    'extension' => '3002', 'phone' => '+4940547070', 'channel' => 'PJSIP/3002-00000915'];
verifyRecording(!$service->execute($appOutgoing)['send'],
    'Ein gewöhnlicher Zoiper-Anruf ohne CRM-Auftrag darf nicht kopiert werden.');
$db->appMatches = [['prospect_id' => $prospectId, 'display_name' => 'Zoiper-Kunde',
    'source_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']];
$appClaim = $service->execute($appOutgoing);
verifyRecording($appClaim['send'] && $appClaim['prospect_id'] === $prospectId,
    'Ein passender SpeedPhone-App-Auftrag mit zugeordnetem Zoiper-Endpunkt muss kopierbar sein.');
$appSql = implode("\n", $db->queries);
verifyRecording(str_contains($appSql, "settings.recording_sip_endpoint='3002'")
    && str_contains($appSql, "commands.status='dialed'")
    && str_contains($appSql, "'+4940547070'")
    && str_contains($appSql, "'040547070'")
    && str_contains($appSql, 'INTERVAL 45 SECOND'),
    'Zoiper-Zuordnung braucht Endpunkt, bestätigten Auftrag, Nummernvarianten und enges Zeitfenster.');
$db->state = '';
$db->appMatches = [
    ['prospect_id' => $prospectId, 'display_name' => 'A', 'source_id' => 'id-a'],
    ['prospect_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'display_name' => 'B', 'source_id' => 'id-b'],
];
verifyRecording(!$service->execute([...$appOutgoing, 'recording_key' => str_repeat('e', 64)])['send'],
    'Mehrdeutige Zoiper-Aufträge dürfen nicht kopiert werden.');

$db->state = '';
$db->matches = [
    ['prospect_id' => $prospectId, 'display_name' => 'A', 'source_id' => 'id-a'],
    ['prospect_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'display_name' => 'B', 'source_id' => 'id-b'],
];
verifyRecording(!$service->execute([...$outgoing, 'recording_key' => str_repeat('b', 64)])['send'],
    'Mehrdeutige CRM-Treffer dürfen nicht weitergeleitet werden.');

$db->matches = [['prospect_id' => $prospectId, 'display_name' => 'Rückrufer',
    'source_id' => '1792047958.3117']];
$incoming = [...$outgoing, 'recording_key' => str_repeat('c', 64), 'direction' => 'incoming',
    'extension' => '', 'phone' => '', 'channel' => 'PJSIP/WEMAG-0001'];
verifyRecording($service->execute($incoming)['send'],
    'Ein eindeutig im CRM zugeordneter eingehender PBX-Anruf muss kopierbar sein.');
verifyRecording(str_contains(implode("\n", $db->queries), hash('sha256', 'pbx-in:1792047958.3117')),
    'Eingehende Mitschnitte müssen über die exakte Asterisk-Ereigniskennung zugeordnet werden.');

echo "CRM-Mitschnittzuordnung erfolgreich geprüft.\n";
