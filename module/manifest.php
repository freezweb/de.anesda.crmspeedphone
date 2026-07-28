<?php

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$manifest = [
    'acceptable_sugar_versions' => [
        'regex_matches' => ['7\\.14\\..*'],
    ],
    'acceptable_sugar_flavors' => ['CE'],
    'author' => 'Anesda UG (haftungsbeschränkt)',
    'description' => 'UUID-basierte Telefonakquise-Warteschlange für SuiteCRM 8',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => 'CRM SpeedPhone',
    'published_date' => '28.07.2026',
    'type' => 'module',
    'version' => '1.6.4',
];

$installdefs = [
    'id' => 'de_anesda_crmspeedphone',
    'copy' => [
        [
            'from' => '<basepath>/copy/custom',
            'to' => 'custom',
        ],
    ],
    'post_execute' => [
        '<basepath>/scripts/post_install.php',
    ],
    'post_uninstall' => [
        '<basepath>/scripts/post_uninstall.php',
    ],
];
