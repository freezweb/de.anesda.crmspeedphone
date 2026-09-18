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

$entry_point_registry['crmSpeedPhoneDialerSetup'] = [
    'file' => 'custom/CRM/SpeedPhone/dialer_setup.php',
    'auth' => false,
];

$entry_point_registry['anesdaMailWebhook'] = [
    'file' => 'custom/CRM/SpeedPhone/mail_webhook.php',
    'auth' => false,
];

$entry_point_registry['crmSpeedPhoneEmailLogo'] = [
    'file' => 'custom/CRM/SpeedPhone/email_logo.php',
    'auth' => false,
];

$entry_point_registry['crmSpeedPhonePbxIncoming'] = [
    'file' => 'custom/CRM/SpeedPhone/pbx_incoming.php',
    'auth' => false,
];
