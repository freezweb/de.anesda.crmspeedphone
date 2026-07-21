<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';

use Anesda\CRM\SpeedPhone\ActionService;
use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\EmailService;
use Anesda\CRM\SpeedPhone\QueueService;

global $current_user, $db;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Nur POST-Anfragen sind erlaubt.');
    }
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (empty($_SESSION['crm_speedphone_csrf']) || !hash_equals($_SESSION['crm_speedphone_csrf'], $csrf)) {
        throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte SpeedPhone neu laden.');
    }
    if (empty($current_user->id)) {
        throw new RuntimeException('Nicht angemeldet.');
    }

    $config = Config::load(__DIR__);
    $queue = new QueueService($config, $db, $current_user);
    $queue->assertUserAllowed();
    $emailService = new EmailService($config, $db, $current_user);
    $service = new ActionService($config, $queue, $emailService, new BusinessDayCalculator(), $current_user);
    $result = $service->execute($_POST);

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

