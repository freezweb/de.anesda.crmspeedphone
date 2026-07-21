<?php

global $mod_strings;

if (ACLController::checkAccess('Prospects', 'list', true)) {
    $module_menu[] = [
        'index.php?module=Prospects&action=speedphone',
        $mod_strings['LBL_CRM_SPEEDPHONE'] ?? 'CRM SpeedPhone',
        'Phone',
        'Prospects',
    ];
}
