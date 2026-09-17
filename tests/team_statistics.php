<?php

use Anesda\CRM\SpeedPhone\TeamStatisticsService;

$statsNow = new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC'));
$statsRange = TeamStatisticsService::dateRange('7days', '', '', $statsNow);
check($statsRange['start'] === '2026-09-11' && $statsRange['end'] === '2026-09-17', 'Die Statistik muss sieben vollständige Kalendertage inklusive heute wählen.');
$dstRange = TeamStatisticsService::dateRange('custom', '2026-03-29', '2026-03-29');
check($dstRange['from_utc'] === '2026-03-28 23:00:00' && $dstRange['until_utc'] === '2026-03-29 22:00:00', 'Sommerzeitwechsel muss für alle Mitarbeiter dasselbe korrekte Zeitfenster ergeben.');
foreach ([['custom','2026-02-30','2026-03-01'], ['custom','2026-09-18','2026-09-17'], ['custom','2025-01-01','2026-09-17'], ['injection','','']] as $rangeInput) {
    try { TeamStatisticsService::dateRange(...$rangeInput); check(false, 'Ungültiger Statistikzeitraum wurde zugelassen.'); } catch (InvalidArgumentException) {}
}
$statsRows = [];
foreach (['not_reached','callback','email_callback','send_flyers','interested','no_interest','wrong_number','blocked','legacy'] as $outcome) {
    $statsRows[] = ['user_id'=>'a','first_name'=>'Antonia','last_name'=>'Beispiel','hour_utc'=>'2026-09-16 23:00:00','result'=>$outcome,'amount'=>1,'email_requested'=>in_array($outcome,['email_callback','send_flyers'],true)?1:0];
}
$statsRows[] = ['user_id'=>'b','first_name'=>'<script>Test</script>','last_name'=>'','hour_utc'=>'2026-09-15 08:00:00','result'=>'not_reached','amount'=>4,'email_requested'=>0];
$report = TeamStatisticsService::aggregate($statsRows, $statsRange);
check($report['totals']['processed'] === 13 && $report['totals']['reached'] === 5, 'Erreichte Anrufe und alle Versuche werden falsch klassifiziert.');
check($report['totals']['callbacks'] === 3 && $report['totals']['interested'] === 1 && $report['totals']['email_requested'] === 2, 'Rückrufe, Mailanforderungen und Interesse müssen getrennt gezählt werden.');
check($report['totals']['other'] === 1 && $report['totals']['blocked'] === 1, 'Altprotokolle und Kontaktsperren dürfen nicht automatisch als erreicht gelten.');
check($report['days']['2026-09-17']['processed'] === 9 && $report['days']['2026-09-16']['processed'] === 0, 'UTC-Anrufe kurz vor Mitternacht werden dem falschen lokalen Tag zugeordnet.');
check(count($report['days']) === 7 && count($report['users']) === 2, 'Kalendertage ohne Aktivität oder Mitarbeiter fehlen in der Statistik.');
$personalReport = TeamStatisticsService::aggregate($statsRows, $statsRange, 'b');
check($personalReport['totals']['processed'] === 4 && $personalReport['totals']['reached'] === 0 && count($personalReport['users']) === 2, 'Personenfilter darf nur die Auswertung, nicht die Mitarbeiterauswahl einschränken.');
$legacyMailReport = TeamStatisticsService::aggregate([['user_id'=>'c','first_name'=>'Ohne Häkchen','hour_utc'=>'2026-09-15 08:00:00','result'=>'send_flyers','amount'=>3,'email_requested'=>0]],$statsRange);
check($legacyMailReport['totals']['email_requested'] === 3, 'Die Flyeraktion selbst muss auch ohne historisch gespeichertes Häkchen als Mailanforderung zählen.');
$zeroMemberReport = TeamStatisticsService::aggregate([['user_id'=>'c','first_name'=>'Ohne Anrufe','hour_utc'=>$statsRange['from_utc'],'result'=>'legacy','amount'=>0,'email_requested'=>0]],$statsRange,'c');
check(count($zeroMemberReport['users']) === 1 && $zeroMemberReport['totals']['processed'] === 0, 'Aktive Teammitglieder ohne Anrufe müssen auswählbar bleiben.');
try { TeamStatisticsService::aggregate($statsRows,$statsRange,'x'); check(false,'Unbekannter Statistikmitarbeiter wurde akzeptiert.'); } catch (InvalidArgumentException) {}
ob_start();
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/team_statistics_report.php';
$statisticsHtml = ob_get_clean();
check(str_contains($statisticsHtml,'&lt;script&gt;Test&lt;/script&gt;') && !str_contains($statisticsHtml,'<script>Test</script>'), 'Mitarbeiternamen müssen in Diagrammen und Tabellen sicher maskiert werden.');
check(str_contains($statisticsHtml,'Tagesdetails') && str_contains($statisticsHtml,'team-report__timeline') && str_contains($statisticsHtml,'38,5 %'), 'Tagesdetails, Grafik oder korrekte Erreichungsquote fehlen.');
$report = TeamStatisticsService::aggregate([], $statsRange);
ob_start(); require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/team_statistics_report.php'; $emptyStatisticsHtml=ob_get_clean();
check(str_contains($emptyStatisticsHtml,'keine SpeedPhone-Anrufe'), 'Leere Statistik muss ohne Division durch null gerendert werden.');
$teamStatisticsSource=file_get_contents(__DIR__.'/../module/copy/custom/CRM/SpeedPhone/src/TeamStatisticsService.php');
check(str_contains($teamStatisticsSource,'$this->access->assertAllowed()') && !str_contains($teamStatisticsSource,'assertCanManageTeam'), 'Teamstatistik muss für alle freigeschalteten Benutzer, nicht nur Admins, zugänglich sein.');
check(str_contains($teamStatisticsSource,"c.status='Held'") && str_contains($teamStatisticsSource,"<>'later'") && str_contains($teamStatisticsSource,'AND EXISTS'), 'Geplante Rückrufe und Überspringen müssen ausgeschlossen und doppelte Listenverknüpfungen dürfen nicht doppelt gezählt werden.');
check(str_contains($teamStatisticsSource,"c.name='SpeedPhone: Nicht erreicht'") && str_contains($teamStatisticsSource,"c.name LIKE 'SpeedPhone: Produktflyer versendet mit automatisch%'"), 'Eindeutige historische Ergebnisbezeichnungen müssen auch bei gekürztem Flyer-Betreff ausgewertet werden.');
check(!str_contains($teamStatisticsSource,'sqlAccessCondition') && !str_contains($teamStatisticsSource,'IndustryFilter'), 'Teamstatistik darf nicht durch die persönliche Kontaktzuweisung oder Branche eingeschränkt werden.');
check(str_contains($apiSource,"'team_statistics'") && str_contains($pageSource,'data-team-statistics-toggle') && str_contains($javascriptSource,"data.set('operation', 'team_statistics')"), 'AJAX-Teamstatistik ist nicht vollständig in SpeedPhone angeschlossen.');
