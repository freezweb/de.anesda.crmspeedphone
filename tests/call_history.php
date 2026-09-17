<?php

use Anesda\CRM\SpeedPhone\CallHistoryService;

$historyFixture=['call_id'=>'befc6200-da8e-47a5-9fc8-3b30e8451018','prospect_id'=>'bdfc6200-da8e-47a5-9fc8-3b30e8451018',
    'date_start'=>'2026-09-17 09:00:00','account_name'=>'Firma <script>Test</script>','first_name'=>'','last_name'=>'',
    'phone_work'=>'030 12345','phone_mobile'=>'','do_not_call'=>0,'current_status'=>'retry','caller_first_name'=>'Antonia',
    'caller_last_name'=>'Beispiel','result'=>null,'call_name'=>'SpeedPhone: Kein Interesse','note'=>'Notiz <img src=x onerror=alert(1)>',
    'lock_user_id'=>null];
$historyRow=CallHistoryService::normalizeRow($historyFixture,'a');
check($historyRow['can_open'] && $historyRow['result']==='no_interest' && $historyRow['current_status']==='retry','Historisches Ergebnis und aktueller wieder freigegebener Status müssen getrennt bleiben.');
$lockedHistoryRow=CallHistoryService::normalizeRow(array_replace($historyFixture,['lock_user_id'=>'b','lock_first_name'=>'Daniel','lock_last_name'=>'Beispiel']),'a');
check(!$lockedHistoryRow['can_open'] && str_contains($lockedHistoryRow['unavailable_reason'],'Daniel Beispiel'),'Fremde Reservierung muss die Öffnen-Schaltfläche sperren.');
check(CallHistoryService::normalizeRow(array_replace($historyFixture,['lock_user_id'=>'a']),'a')['can_open'],'Die eigene Reservierung darf das erneute Öffnen nicht verhindern.');
check(!CallHistoryService::normalizeRow(array_replace($historyFixture,['do_not_call'=>1]),'a')['can_open'],'Anrufverbot darf nicht durch die Historie aufgehoben werden.');
check(!CallHistoryService::normalizeRow(array_replace($historyFixture,['current_status'=>'blocked']),'a')['can_open'],'Gesperrter Warteschlangenstatus muss die Öffnung verhindern.');
check(!CallHistoryService::normalizeRow(array_replace($historyFixture,['phone_work'=>'']),'a')['can_open'],'Kontakte ohne Rufnummer dürfen nicht in die Anrufmaske geöffnet werden.');
check(CallHistoryService::normalizeRow(array_replace($historyFixture,['call_name'=>'SpeedPhone: Produktflyer versendet mit automatisch']),'a')['result']==='send_flyers','Gekürzte historische Flyer-Bezeichnungen müssen verständlich zugeordnet werden.');
$history=['rows'=>[$historyRow,$lockedHistoryRow],'total'=>2,'page'=>1,'pages'=>1,'page_size'=>50];
$userTimezone='Europe/Berlin';
ob_start();require __DIR__.'/../module/copy/custom/CRM/SpeedPhone/call_history_report.php';$historyHtml=ob_get_clean();
check(str_contains($historyHtml,'In SpeedPhone öffnen') && str_contains($historyHtml,'17.09.2026 11:00'),'Anrufliste muss Öffnen-Aktion und lokalen Anrufzeitpunkt zeigen.');
check(!str_contains($historyHtml,'action=DetailView') && !str_contains($historyHtml,'<script>Test</script>') && !str_contains($historyHtml,'<img src=x'),'Die Historie darf keine CRM-Detail-Links oder unmaskierte Kontaktnamen/Notizen ausgeben.');
check(str_contains($historyHtml,'Kein Interesse (historisch)') && str_contains($historyHtml,'Aktuell: Erneut anrufen'),'Fehlklick-Ergebnis und neuer Kontaktstatus dürfen nicht verwechselt werden.');
$history=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'page_size'=>50];
ob_start();require __DIR__.'/../module/copy/custom/CRM/SpeedPhone/call_history_report.php';$emptyHistoryHtml=ob_get_clean();
check(str_contains($emptyHistoryHtml,'Keine Anrufe'),'Leere Anrufliste muss verständlich dargestellt werden.');
$historySource=file_get_contents(__DIR__.'/../module/copy/custom/CRM/SpeedPhone/src/CallHistoryService.php');
check(str_contains($historySource,'sqlAccessCondition()') && !str_contains($historySource,'sqlIncomingAccessCondition()'),'Anrufhistorie muss normale Zuständigkeit prüfen, nicht interne Sonderrechte für eingehende Anrufe.');
check(str_contains($historySource,"c.status='Held'") && str_contains($historySource,"<>'later'") && str_contains($historySource,'AND EXISTS'),'Geplante Anrufe, Verschieben und doppelte Listeneinträge dürfen die Historie nicht verfälschen.');
check(!str_contains($historySource,'TravelFilter::allowedSql') && !str_contains($historySource,'IndustryFilter::allowedSql'),'Historische Anrufe dürfen nicht durch den aktuellen Branchen-/Regionalfilter verschwinden.');
check(str_contains($apiSource,'$queue->openCandidateById($prospectId, false)') && str_contains($apiSource,"'open_call_history'"),'Historienöffnung muss SpeedPhone mit normalen Zuständigkeitsrechten verwenden.');
check(str_contains($pageSource,'data-call-history-toggle') && str_contains($javascriptSource,"data.set('operation','open_call_history')") && str_contains($javascriptSource,'refreshCallHistory();'),'AJAX-Anrufliste ist nicht vollständig angeschlossen.');
