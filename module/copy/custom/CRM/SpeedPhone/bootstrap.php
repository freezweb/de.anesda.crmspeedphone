<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

$speedPhoneBase = __DIR__;
foreach ([
    'Config',
    'BusinessDayCalculator',
    'InputValidator',
    'LockService',
    'QueueService',
    'EmailService',
    'ActionService',
] as $className) {
    require_once $speedPhoneBase . '/src/' . $className . '.php';
}
