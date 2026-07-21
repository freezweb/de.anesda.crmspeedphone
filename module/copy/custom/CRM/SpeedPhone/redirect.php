<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

global $sugar_config;

$siteUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
$siteUrl = preg_replace('~/legacy$~', '', $siteUrl);
header('Location: ' . $siteUrl . '/#/prospects/speedphone');
exit;
