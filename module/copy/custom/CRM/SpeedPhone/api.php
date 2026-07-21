<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\ActionService;
use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\EmailService;
use Anesda\CRM\SpeedPhone\InputValidator;
use Anesda\CRM\SpeedPhone\LockService;
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
    $lockService = new LockService($config, $db, $current_user);
    $queue = new QueueService($config, $db, $current_user, $lockService);
    $queue->assertUserAllowed();

    if ((string) ($_POST['operation'] ?? '') === 'heartbeat') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        $lock = $lockService->heartbeat($prospectId, (string) ($_POST['lock_token'] ?? ''));
        echo json_encode(['success' => true, 'data' => ['expires_at' => $lock['expires_at']]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'next') {
        $candidate = $queue->getNextCandidate();
        $statistics = $queue->getStatistics();
        $userTimezone = (string) ($current_user->getPreference('timezone') ?: 'Europe/Berlin');
        echo json_encode([
            'success' => true,
            'data' => [
                'workspace_html' => speedPhoneRenderWorkspace(
                    $candidate,
                    $userTimezone,
                    (int) $config->get('default_callback_days', 7)
                ),
                'statistics' => $statistics,
                'prospect_id' => $candidate['id'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $emailService = new EmailService($config, $db, $current_user);
    $service = new ActionService(
        $config,
        $queue,
        $emailService,
        new BusinessDayCalculator(),
        $current_user,
        $lockService
    );
    $result = $service->execute($_POST);

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
