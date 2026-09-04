<?php

/** Ergänzt nur eigene Felder; vorhandene kundenspezifische Layouts bleiben erhalten. */
function speedPhoneInstallTravelMetadata(): void
{
    $directory = 'custom/modules/Prospects/metadata';
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException('Das Verzeichnis für die Anfahrtsfilter-Ansichten konnte nicht angelegt werden.');
    }
    foreach (['detailviewdefs.php'=>'viewdefs', 'searchdefs.php'=>'searchdefs', 'SearchFields.php'=>'searchFields', 'listviewdefs.php'=>'listViewDefs'] as $file=>$variable) {
        ${$variable} = [];
        $target = $directory . '/' . $file;
        $source = is_file($target) ? $target : 'modules/Prospects/metadata/' . $file;
        if (!is_file($source)) { continue; }
        require $source;
        if (empty(${$variable}['Prospects'])) { continue; }
        $before = ${$variable};
        if ($variable === 'viewdefs') {
            $viewdefs['Prospects']['DetailView']['panels']['lbl_speedphone_travel_panel'] ??= [
                ['speedphone_travel_status_c', 'speedphone_travel_minutes_c'],
                ['speedphone_travel_origin_c'],
                ['speedphone_travel_note_c'],
            ];
        } elseif ($variable === 'searchdefs') {
            $searchdefs['Prospects']['layout']['advanced_search']['speedphone_travel_status_c'] ??= [
                'name'=>'speedphone_travel_status_c', 'label'=>'LBL_SPEEDPHONE_TRAVEL_STATUS', 'default'=>true,
            ];
        } elseif ($variable === 'searchFields') {
            $searchFields['Prospects']['speedphone_travel_status_c'] ??= ['query_type'=>'default'];
        } else {
            $listViewDefs['Prospects']['SPEEDPHONE_TRAVEL_STATUS_C'] ??= [
                'width'=>'10%', 'label'=>'LBL_SPEEDPHONE_TRAVEL_STATUS', 'default'=>false,
            ];
        }
        if ($before !== ${$variable}) {
            if (file_put_contents($target, "<?php\n// Layout-Ergänzung durch CRM SpeedPhone, Copyright anesda.\n$" . $variable . ' = ' . var_export(${$variable},true) . ";\n", LOCK_EX) === false) {
                throw new RuntimeException('Die Anfahrtsfilter-Ansicht konnte nicht gespeichert werden: ' . $file);
            }
        }
    }
}

speedPhoneInstallTravelMetadata();
