<?php

return [
    'source_list_name' => '',
    'email_template_name' => '',
    'email_sending_enabled' => false,
    'allowed_usernames' => [],
    'restrict_to_assigned_user' => false,
    'lock_minutes' => 20,
    'max_attempts' => 5,
    'retry_days' => [2, 4, 7, 14, 30],
    'candidate_batch_size' => 200,
    'candidate_scan_limit' => 5000,
    'local_postcode_patterns' => [],
    'positive_patterns' => [],
    'exclude_patterns' => [],
];
