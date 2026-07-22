<?php

$entry_point_registry['crmSpeedPhone'] = [
    'file' => 'custom/CRM/SpeedPhone/redirect.php',
    'auth' => true,
];

$entry_point_registry['crmSpeedPhoneApi'] = [
    'file' => 'custom/CRM/SpeedPhone/api.php',
    'auth' => true,
];

$entry_point_registry['crmSpeedPhoneDialerApi'] = [
    'file' => 'custom/CRM/SpeedPhone/dialer_api.php',
    'auth' => false,
];
