<?php

// Isolierte Übergabeprüfung ohne CRM-Datenbank oder echte Telefonate.
define('sugarEntry', true);
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/bootstrap.php';

use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\UserAccessService;

class User
{
    public string $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    public int $is_admin = 0;
}

class DBManager
{
    public array $queries = [];
    public string $owner = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    public string $lockOwner = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private int $userIndex = 0;

    public function quote(string $value): string { return addslashes($value); }
    public function query(string $sql): string
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM users u')) { $this->userIndex = 0; }
        return $sql;
    }
    public function fetchByAssoc(string $sql): array|false
    {
        if (str_contains($sql, 'FROM users u')) {
            $users = [
                ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'user_name' => 'quelle', 'first_name' => 'Mara', 'last_name' => 'Quelle', 'is_admin' => 0, 'user_type' => 'internal', 'commission_percent' => 0],
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'user_name' => 'ziel', 'first_name' => 'Timo', 'last_name' => 'Ziel', 'is_admin' => 0, 'user_type' => 'external', 'commission_percent' => 20],
                ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'user_name' => 'gesperrt', 'first_name' => '', 'last_name' => '', 'is_admin' => 0, 'user_type' => 'disabled', 'commission_percent' => 0],
            ];
            return $users[$this->userIndex++] ?? false;
        }
        if (str_contains($sql, 'FROM prospects')) { return ['id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']; }
        if (str_contains($sql, 'FROM crm_speedphone_assignments')) { return ['owner_user_id' => $this->owner]; }
        if (str_contains($sql, 'FROM crm_speedphone_locks')) {
            return $this->lockOwner === '' ? false : ['user_id' => $this->lockOwner, 'lock_token' => str_repeat('a', 64)];
        }
        if (str_contains($sql, 'FROM crm_speedphone_user_settings')) {
            return ['user_type' => 'internal', 'commission_percent' => 0, 'can_receive_unassigned' => 1, 'can_manage' => 0, 'pbx_extension' => ''];
        }
        return false;
    }
}

function verifyTransfer(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$configClass = new ReflectionClass(Config::class);
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getProperty('values')->setValue($config, []);
$db = new DBManager();
$user = new User();
$access = new UserAccessService($db, $user);
$service = new AssignmentService($config, $db, $user, $access);
$prospectId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$targetId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$result = $service->transfer($prospectId, $targetId, str_repeat('a', 64));
verifyTransfer($result['target_name'] === 'Timo Ziel', 'Der Empfängername fehlt in der Rückmeldung.');
$sql = implode("\n", $db->queries);
verifyTransfer(str_contains($sql, "owner_user_id='" . $targetId . "'") && str_contains($sql, 'owner_commission_percent=20.00'), 'Zuständigkeit und aktueller Provisionssatz wurden nicht umgestellt.');
verifyTransfer(str_contains($sql, 'INSERT INTO crm_speedphone_assignment_transfers') && str_contains($sql, 'transferred_by_user_id'), 'Die Übergabe wurde nicht nachvollziehbar protokolliert.');
verifyTransfer(!str_contains($sql, 'won_by_user_id=') && !str_contains($sql, 'won_commission_percent=') && !str_contains($sql, "last_result='transferred'"), 'Eine Übergabe darf Gewinnprovision und letztes Anrufergebnis nicht überschreiben.');
verifyTransfer(str_contains($sql, 'DELETE FROM crm_speedphone_locks') && str_contains($sql, 'COMMIT'), 'Die eigene Reservierung muss nach erfolgreicher Übergabe freigegeben werden.');

$db->queries = [];
$db->lockOwner = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
try {
    $service->transfer($prospectId, $targetId, str_repeat('a', 64));
    throw new RuntimeException('Eine fremde aktive Reservierung wurde übergangen.');
} catch (RuntimeException $error) {
    verifyTransfer(str_contains($error->getMessage(), 'anderen Telefonisten'), 'Eine fremde Sperre muss verständlich abgewiesen werden.');
}
verifyTransfer(str_contains(implode("\n", $db->queries), 'ROLLBACK'), 'Eine abgelehnte Übergabe muss zurückgerollt werden.');

$db->lockOwner = '';
$db->owner = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
try {
    $service->transfer($prospectId, $targetId);
    throw new RuntimeException('Ein fremder Besitzer wurde ohne Leitungsrecht übergangen.');
} catch (RuntimeException $error) {
    verifyTransfer(str_contains($error->getMessage(), 'zuständige Person'), 'Fremde Zuständigkeit muss geschützt bleiben.');
}

try {
    $service->transfer($prospectId, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
    throw new RuntimeException('Ein deaktivierter Benutzer wurde als Ziel akzeptiert.');
} catch (InvalidArgumentException $error) {
    verifyTransfer(str_contains($error->getMessage(), 'nicht für SpeedPhone freigeschaltet'), 'Deaktivierte Empfänger müssen abgelehnt werden.');
}

echo "SpeedPhone-Übergaben erfolgreich geprüft.\n";
