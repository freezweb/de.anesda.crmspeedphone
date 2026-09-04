<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

$speedPhoneBase = __DIR__;
foreach ([
    'Config',
    'TravelFilter',
    'BusinessDayCalculator',
    'InputValidator',
    'AclRoleService',
    'UserAccessService',
    'AssignmentService',
    'LockService',
    'CandidatePriorityService',
    'LinkedInContactService',
    'QueueService',
    'EmailService',
    'EmailTemplateBrandService',
    'ActionService',
    'DialerService',
    'PbxService',
    'IncomingCallService',
    'MailWebhookService',
] as $className) {
    require_once $speedPhoneBase . '/src/' . $className . '.php';
}
