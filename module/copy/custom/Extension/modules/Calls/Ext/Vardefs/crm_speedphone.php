<?php

$dictionary['Call']['fields']['speedphone_result_c'] = [
    'name' => 'speedphone_result_c',
    'vname' => 'LBL_SPEEDPHONE_RESULT',
    'type' => 'enum',
    'options' => 'speedphone_result_list',
    'len' => 40,
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Call']['fields']['speedphone_email_requested_c'] = [
    'name' => 'speedphone_email_requested_c',
    'vname' => 'LBL_SPEEDPHONE_EMAIL_REQUESTED',
    'type' => 'bool',
    'default' => 0,
    'source' => 'custom_fields',
    'audited' => true,
];

