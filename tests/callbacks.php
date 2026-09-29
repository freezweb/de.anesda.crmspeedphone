<?php

use Anesda\CRM\SpeedPhone\CallbackService;

$callbackFixture = ['id'=>'befc6200-da8e-47a5-9fc8-3b30e8451018','account_name'=>'Firma <script>Test</script>',
    'phone_work'=>'030 12345','next_call'=>'2026-09-28 22:00:00','owner_user_id'=>'me',
    'owner_first_name'=>'Antonia','owner_last_name'=>'Beispiel','can_access'=>1,'lock_user_id'=>null,
    'note'=>'Notiz <img src=x onerror=alert(1)>'];
$callbackRow = CallbackService::normalizeRow($callbackFixture, 'me');
check($callbackRow['is_mine'] && $callbackRow['can_open'], 'Eigene Rückrufe müssen als eigene Zuständigkeit erkennbar und zu öffnen sein.');
$foreignCallback = CallbackService::normalizeRow(array_replace($callbackFixture, ['owner_user_id'=>'other','can_access'=>0]), 'me');
check(!$foreignCallback['is_mine'] && !$foreignCallback['can_open'], 'Fremde exklusive Rückrufe dürfen nicht zum Öffnen freigegeben werden.');
$lockedCallback = CallbackService::normalizeRow(array_replace($callbackFixture, ['lock_user_id'=>'other','lock_first_name'=>'Daniel']), 'me');
check(!$lockedCallback['can_open'] && str_contains($lockedCallback['unavailable_reason'], 'Daniel'), 'Reservierte Rückrufe müssen den Mitarbeiter anzeigen und die Öffnung sperren.');
check(CallbackService::normalizeRow(array_replace($callbackFixture, ['lock_user_id'=>'me']), 'me')['can_open'], 'Die eigene Reservierung darf die Öffnung eines Rückrufs nicht sperren.');
$callbacks = ['rows'=>[$callbackRow,$foreignCallback,$lockedCallback],'total'=>3,'page'=>1,'pages'=>1];
$userTimezone = 'Europe/Berlin';
ob_start(); require __DIR__.'/../module/copy/custom/CRM/SpeedPhone/callback_report.php'; $callbackHtml=ob_get_clean();
check(str_contains($callbackHtml, '29.09.2026 · Tagesliste') && str_contains($callbackHtml, 'Antonia Beispiel · ich'), 'Tagesrückrufe müssen ohne erfundene Uhrzeit und mit Zuständigkeit angezeigt werden.');
check(str_contains($callbackHtml,'data-speedphone-callback-open') && !str_contains($callbackHtml,'action=DetailView'), 'Rückrufe müssen die SpeedPhone-Maske öffnen.');
check(!str_contains($callbackHtml,'<script>Test</script>') && !str_contains($callbackHtml,'<img src=x'), 'Kontaktnamen und Rückrufnotizen müssen HTML-sicher sein.');
$callbacks['rows']=[];
ob_start(); require __DIR__.'/../module/copy/custom/CRM/SpeedPhone/callback_report.php'; $callbackHtml=ob_get_clean();
check(str_contains($callbackHtml,'Keine fälligen Rückrufe'), 'Leere Rückruflisten benötigen eine verständliche Meldung.');
