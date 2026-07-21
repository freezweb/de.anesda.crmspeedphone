<?php

global $mod_strings;

if (ACLController::checkAccess('Prospects', 'list', true)) {
    $module_menu[] = [
        'index.php?entryPoint=crmSpeedPhone',
        $mod_strings['LBL_CRM_SPEEDPHONE'] ?? 'CRM SpeedPhone',
        'Phone',
        'Prospects',
    ];
}

