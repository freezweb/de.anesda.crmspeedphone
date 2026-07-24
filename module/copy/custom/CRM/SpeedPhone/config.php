<?php

return [
    'source_list_name' => '',
    'email_template_name' => '',
    'email_sending_enabled' => false,
    'lock_minutes' => 20,
    'default_callback_days' => 7,
    'callback_escalation_days' => 2,
    'external_stale_days' => 14,
    'max_attempts' => 5,
    'retry_days' => [2, 4, 7, 14, 30],
    'candidate_batch_size' => 200,
    'candidate_scan_limit' => 5000,
    'local_postcode_patterns' => [],
    'positive_patterns' => [],
    'exclude_patterns' => [],
    'dialer_android_store_url' => 'https://play.google.com/store/apps/details?id=de.anesda.crmspeedphone.dialer',
    'dialer_ios_store_url' => '',
];
