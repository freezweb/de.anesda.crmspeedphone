<?php

chdir('/srv/www/vhosts/crm.anesda.de/public/legacy');
$_GET = [];
$_POST = [];
$_REQUEST = [];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = 'index.php';
unset($_SERVER['argv'], $_SERVER['argc']);
define('sugarEntry', true);
require 'include/entryPoint.php';

$adminRow = $db->fetchByAssoc($db->query(
    "SELECT id
     FROM users
     WHERE is_admin=1 AND deleted=0 AND status='Active'
     ORDER BY date_entered ASC
     LIMIT 1"
));
if (empty($adminRow['id'])) {
    throw new RuntimeException('Kein aktiver CRM-Administrator für den Reparaturlauf gefunden.');
}

$current_user = BeanFactory::getBean('Users', (string) $adminRow['id']);
$GLOBALS['current_user'] = $current_user;

require '/tmp/crm-speedphone-deploy/scripts/post_install.php';
