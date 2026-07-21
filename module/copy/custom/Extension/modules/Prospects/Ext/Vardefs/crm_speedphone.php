<?php

$dictionary['Prospect']['fields']['speedphone_status_c'] = [
    'name' => 'speedphone_status_c',
    'vname' => 'LBL_SPEEDPHONE_STATUS',
    'type' => 'enum',
    'options' => 'speedphone_status_list',
    'len' => 40,
    'default' => '',
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Prospect']['fields']['speedphone_attempts_c'] = [
    'name' => 'speedphone_attempts_c',
    'vname' => 'LBL_SPEEDPHONE_ATTEMPTS',
    'type' => 'int',
    'default' => 0,
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Prospect']['fields']['speedphone_next_call_c'] = [
    'name' => 'speedphone_next_call_c',
    'vname' => 'LBL_SPEEDPHONE_NEXT_CALL',
    'type' => 'datetimecombo',
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Prospect']['fields']['speedphone_last_call_c'] = [
    'name' => 'speedphone_last_call_c',
    'vname' => 'LBL_SPEEDPHONE_LAST_CALL',
    'type' => 'datetimecombo',
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Prospect']['fields']['speedphone_last_result_c'] = [
    'name' => 'speedphone_last_result_c',
    'vname' => 'LBL_SPEEDPHONE_LAST_RESULT',
    'type' => 'enum',
    'options' => 'speedphone_result_list',
    'len' => 40,
    'source' => 'custom_fields',
    'audited' => true,
];

$dictionary['Prospect']['fields']['speedphone_last_note_c'] = [
    'name' => 'speedphone_last_note_c',
    'vname' => 'LBL_SPEEDPHONE_LAST_NOTE',
    'type' => 'text',
    'source' => 'custom_fields',
    'audited' => true,
];

