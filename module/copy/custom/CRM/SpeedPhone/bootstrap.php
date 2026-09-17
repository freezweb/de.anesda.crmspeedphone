<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

$speedPhoneBase = __DIR__;
foreach ([
    'Config',
    'TravelFilter',
    'IndustryFilter',
    'BusinessDayCalculator',
    'InputValidator',
    'AclRoleService',
    'UserAccessService',
    'TeamStatisticsService',
    'AssignmentService',
    'LockService',
    'CandidatePriorityService',
    'LinkedInContactService',
    'QueueService',
    'ProductFlyerService',
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
