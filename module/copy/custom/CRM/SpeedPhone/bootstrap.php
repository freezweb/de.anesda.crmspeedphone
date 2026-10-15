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
    'CallHistoryService',
    'CallbackService',
    'LockService',
    'CandidatePriorityService',
    'LinkedInContactService',
    'QueueService',
    'ProductFlyerService',
    'EmailContentService',
    'EmailLogoTrackingService',
    'EmailService',
    'EmailTemplateBrandService',
    'ActionService',
    'DialerService',
    'PbxService',
    'IncomingCallService',
    'TelegramCallService',
    'RecordingCopyService',
    'MailWebhookService',
] as $className) {
    require_once $speedPhoneBase . '/src/' . $className . '.php';
}
