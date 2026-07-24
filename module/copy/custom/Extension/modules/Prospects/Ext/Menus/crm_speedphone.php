<?php

global $mod_strings, $current_user, $db;

$speedPhoneAllowed = false;
try {
    require_once 'custom/CRM/SpeedPhone/bootstrap.php';
    $speedPhoneAllowed = (new Anesda\CRM\SpeedPhone\UserAccessService($db, $current_user))
        ->currentProfile()['user_type'] !== 'disabled';
} catch (Throwable) {
    // Bei einer noch nicht abgeschlossenen Installation bleibt das Menü verborgen.
}

if ($speedPhoneAllowed) {
    $module_menu[] = [
        'index.php?module=Prospects&action=speedphone',
        $mod_strings['LBL_CRM_SPEEDPHONE'] ?? 'CRM SpeedPhone',
        'Phone',
        'Prospects',
    ];
}
