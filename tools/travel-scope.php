<?php

/** CLI: exportiert Orte oder übernimmt geprüfte Anfahrtsgruppen ohne Kontaktkopien. */
if (PHP_SAPI !== 'cli') { exit(1); }
$options = getopt('', ['legacy:', 'workdir:', 'mode:', 'origin:']);
$legacy = realpath($options['legacy'] ?? '');
$work = realpath($options['workdir'] ?? '');
if (!$legacy || !$work || !is_file($legacy . '/config.php')) {
    throw new RuntimeException('CRM-Verzeichnis und vorhandenes Arbeitsverzeichnis angeben.');
}
define('sugarEntry', true);
require $legacy . '/config.php';
$c = $sugar_config['dbconfig'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($c['db_host_name'], $c['db_user_name'], $c['db_password'], $c['db_name']);
$db->set_charset('utf8mb4');
$config = require $legacy . '/custom/CRM/SpeedPhone/config.local.php';
$list = $db->real_escape_string($config['source_list_name']);
$base = "FROM prospects p
    JOIN prospect_lists_prospects plp ON plp.related_id=p.id AND plp.related_type='Prospects' AND plp.deleted=0
    JOIN prospect_lists pl ON pl.id=plp.prospect_list_id AND pl.deleted=0 AND pl.name='{$list}'
    WHERE p.deleted=0";
$placeKey = "MD5(CONCAT_WS('|',COALESCE(p.primary_address_postalcode,''),COALESCE(p.primary_address_city,''),COALESCE(p.primary_address_country,'')))";
$hash = "MD5(CONCAT_WS('|',COALESCE(p.primary_address_street,''),COALESCE(p.primary_address_postalcode,''),COALESCE(p.primary_address_city,''),COALESCE(p.primary_address_country,'')))";
if (($options['mode'] ?? '') === 'export') {
    $result = $db->query("SELECT DISTINCT {$placeKey} `key`, COALESCE(p.primary_address_postalcode,'') postcode,
        COALESCE(p.primary_address_city,'') city, COALESCE(p.primary_address_country,'') country {$base}");
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    file_put_contents($work . '/locations.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo 'EXPORTED_LOCATIONS=' . count($rows) . PHP_EOL;
    exit;
}
if (($options['mode'] ?? '') !== 'apply' || empty($options['origin'])) {
    throw new RuntimeException('Modus export/apply und Abfahrtsort für apply angeben.');
}
$assessments = json_decode(file_get_contents($work . '/assessments.json'), true, 512, JSON_THROW_ON_ERROR);
$result = $db->query("SELECT DISTINCT p.id, {$placeKey} place_key, {$hash} address_hash {$base}");
$rows = $result->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $row) {
    if (!isset($assessments[$row['place_key']])) {
        throw new RuntimeException('Seit dem Export ist ein neuer Ort hinzugekommen. Erneut exportieren.');
    }
}
$before = $db->query("SELECT pc.id_c,pc.speedphone_travel_status_c,pc.speedphone_travel_minutes_c,
    pc.speedphone_travel_origin_c,pc.speedphone_travel_hash_c,pc.speedphone_travel_note_c
    FROM prospects_cstm pc WHERE pc.id_c IN (SELECT p.id {$base})");
$backup = $work . '/travel-before-' . gmdate('Ymd-His') . '.json';
file_put_contents($backup, json_encode($before->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
chmod($backup, 0640);
$statement = $db->prepare('INSERT INTO prospects_cstm
    (id_c,speedphone_travel_status_c,speedphone_travel_minutes_c,speedphone_travel_origin_c,speedphone_travel_hash_c,speedphone_travel_note_c)
    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE speedphone_travel_status_c=VALUES(speedphone_travel_status_c),
    speedphone_travel_minutes_c=VALUES(speedphone_travel_minutes_c),speedphone_travel_origin_c=VALUES(speedphone_travel_origin_c),
    speedphone_travel_hash_c=VALUES(speedphone_travel_hash_c),speedphone_travel_note_c=VALUES(speedphone_travel_note_c)');
$counts = [];
$db->begin_transaction();
try {
    foreach ($rows as $row) {
        $assessment = $assessments[$row['place_key']];
        $status = $assessment['status'];
        $minutes = $assessment['minutes'];
        if (!in_array($status, ['within_range','too_far','borderline','unverified'], true)
            || ($minutes !== null && (!is_int($minutes) || $minutes < 0))
            || ($status === 'within_range' && ($minutes === null || $minutes > 60))) {
            throw new RuntimeException('Ungültiges Routenergebnis.');
        }
        $note = $assessment['note'] . ' Prüfung: ' . gmdate('d.m.Y') . '.';
        $statement->bind_param('ssisss', $row['id'], $status, $minutes, $options['origin'], $row['address_hash'], $note);
        $statement->execute();
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    $db->commit();
} catch (Throwable $error) { $db->rollback(); throw $error; }
$localFile = $legacy . '/custom/CRM/SpeedPhone/travel.local.php';
if (is_file($localFile)) { copy($localFile, $work . '/travel-local-before-' . gmdate('Ymd-His') . '.php'); }
$values = ['travel_filter_enabled'=>true, 'travel_origin_label'=>$options['origin'], 'travel_max_minutes'=>60];
file_put_contents($localFile . '.tmp', "<?php\nreturn " . var_export($values,true) . ";\n", LOCK_EX);
chmod($localFile . '.tmp',0640);
rename($localFile . '.tmp',$localFile);
echo json_encode(['updated'=>$counts,'backup'=>$backup],JSON_UNESCAPED_UNICODE) . PHP_EOL;
