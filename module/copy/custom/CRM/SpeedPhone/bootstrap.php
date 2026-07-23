<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

$speedPhoneBase = __DIR__;
foreach ([
    'Config',
    'BusinessDayCalculator',
    'InputValidator',
    'AclRoleService',
    'UserAccessService',
    'AssignmentService',
    'LockService',
    'QueueService',
    'EmailService',
    'ActionService',
    'DialerService',
    'IncomingCallService',
] as $className) {
    require_once $speedPhoneBase . '/src/' . $className . '.php';
}
