<?php

/** CLI: Fehlklick-Abschlüsse gesichert wieder einreihen, niemals automatisch beim Modulupdate. */
if (PHP_SAPI !== 'cli') { exit(1); }
$options = getopt('', ['legacy:', 'backup-dir:', 'apply', 'expect-count:']);
$legacy = realpath((string) ($options['legacy'] ?? ''));
if (!$legacy || !is_file($legacy . '/config.php')) {
    throw new RuntimeException('Bitte das vorhandene CRM-Legacy-Verzeichnis angeben.');
}
$apply = array_key_exists('apply', $options);
$backupDirectory = realpath((string) ($options['backup-dir'] ?? ''));
$expected = filter_var($options['expect-count'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($apply && (empty($options['backup-dir']) || !array_key_exists('expect-count', $options)
    || !$backupDirectory || !is_writable($backupDirectory) || $backupDirectory === DIRECTORY_SEPARATOR
    || str_starts_with($backupDirectory . DIRECTORY_SEPARATOR, $legacy . DIRECTORY_SEPARATOR) || $expected === false)) {
    throw new RuntimeException('Für die Übernahme sind ein beschreibbares Sicherungsverzeichnis außerhalb des CRM und die erwartete Anzahl erforderlich.');
}
define('sugarEntry', true);
require $legacy . '/config.php';
require $legacy . '/custom/CRM/SpeedPhone/src/Config.php';
require $legacy . '/custom/CRM/SpeedPhone/src/TravelFilter.php';
$config = Anesda\CRM\SpeedPhone\Config::load($legacy . '/custom/CRM/SpeedPhone');
$c = $sugar_config['dbconfig'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($c['db_host_name'], $c['db_user_name'], $c['db_password'], $c['db_name']);
$db->set_charset('utf8mb4');
$travel = Anesda\CRM\SpeedPhone\TravelFilter::allowedSql($config, fn($value)=>$db->real_escape_string($value));
$listName = $db->real_escape_string($config->requireString('source_list_name'));
$listExists = "EXISTS (SELECT 1 FROM prospect_lists_prospects plp JOIN prospect_lists pl ON pl.id=plp.prospect_list_id WHERE plp.related_id=p.id AND plp.related_type='Prospects' AND plp.deleted=0 AND pl.deleted=0 AND pl.name='{$listName}')";
$targetWhere = "p.deleted=0 AND p.do_not_call=0 AND pc.speedphone_status_c='no_interest'";
$preview = $db->query("SELECT COUNT(*) contacts,SUM({$listExists}) in_source_list,SUM({$listExists} AND {$travel}) in_current_region FROM prospects p JOIN prospects_cstm pc ON pc.id_c=p.id WHERE {$targetWhere}")->fetch_assoc();
if (!$apply) {
    echo json_encode(['mode'=>'preview','counts'=>$preview], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
$db->begin_transaction();
try {
    $before = $db->query("SELECT p.id,p.do_not_call,pc.* FROM prospects p JOIN prospects_cstm pc ON pc.id_c=p.id WHERE {$targetWhere} ORDER BY p.id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    if (count($before) !== $expected) {
        throw new RuntimeException('Die Zielmenge hat sich geändert. Vorschau erneut prüfen.');
    }
    if ($before === []) { $db->rollback(); echo "Keine Kontakte zu korrigieren.\n"; exit; }
    $ids = implode(',', array_map(fn($row)=>"'".$db->real_escape_string($row['id'])."'",$before));
    $assignmentsSql = "SELECT * FROM crm_speedphone_assignments WHERE prospect_id IN ({$ids}) ORDER BY prospect_id";
    $callsSql = "SELECT c.*,cc.* FROM calls c LEFT JOIN calls_cstm cc ON cc.id_c=c.id WHERE c.parent_type='Prospects' AND c.parent_id IN ({$ids}) ORDER BY c.id";
    $assignments = $db->query($assignmentsSql . ' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $calls = $db->query($callsSql)->fetch_all(MYSQLI_ASSOC);
    $when = gmdate('Y-m-d H:i:s');
    $backup = $backupDirectory . '/no-interest-before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $contents = json_encode(['reason'=>'Fehlklicks auf „Erreicht · kein Interesse“, auf Nutzeranweisung wieder einreihen',
        'created_at_utc'=>$when,'after'=>['speedphone_status_c'=>'retry','speedphone_next_call_c'=>$when,'speedphone_last_result_c'=>'later'],
        'contacts'=>$before,'assignments'=>$assignments,'calls'=>$calls], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $handle = fopen($backup, 'xb');
    if (!$handle || !chmod($backup, 0600)) throw new RuntimeException('Sicherung konnte nicht sicher angelegt werden.');
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('Sicherung wurde nicht vollständig geschrieben.');
        }
    } finally { fclose($handle); }
    $statement = $db->prepare("UPDATE prospects_cstm SET speedphone_status_c='retry',speedphone_next_call_c=?,speedphone_last_result_c='later' WHERE id_c=? AND speedphone_status_c='no_interest'");
    foreach ($before as $row) {
        $id = $row['id'];
        $statement->bind_param('ss',$when,$id); $statement->execute();
        if ($statement->affected_rows !== 1) throw new RuntimeException('Zielkontakt konnte nicht eindeutig korrigiert werden.');
    }
    $after = $db->query("SELECT p.id,p.do_not_call,pc.* FROM prospects p JOIN prospects_cstm pc ON pc.id_c=p.id WHERE p.id IN ({$ids}) ORDER BY p.id")->fetch_all(MYSQLI_ASSOC);
    foreach ($after as $index=>$row) {
        if ($row['speedphone_status_c'] !== 'retry' || $row['speedphone_last_result_c'] !== 'later' || $row['speedphone_next_call_c'] !== $when) {
            throw new RuntimeException('Der neue Warteschlangenstatus stimmt nicht.');
        }
        $original = $before[$index];
        foreach (['speedphone_status_c','speedphone_next_call_c','speedphone_last_result_c'] as $field) { unset($row[$field], $original[$field]); }
        if ($row !== $original) throw new RuntimeException('Andere Kontaktdaten wurden unerwartet verändert.');
    }
    if (count($after) !== $expected || $assignments !== $db->query($assignmentsSql)->fetch_all(MYSQLI_ASSOC)
        || $calls !== $db->query($callsSql)->fetch_all(MYSQLI_ASSOC)) {
        throw new RuntimeException('Kontaktmenge, Zuständigkeit oder Anrufhistorie wurde unerwartet verändert.');
    }
    $db->commit();
    echo json_encode(['mode'=>'applied','restored'=>count($after),'due_at_utc'=>$when,'backup'=>$backup,
        'assignments_and_calls_unchanged'=>true,'counts'=>$preview],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) { $db->rollback(); throw $error; }
