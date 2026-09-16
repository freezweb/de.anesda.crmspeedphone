<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\IncomingCallService;

global $db;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Nur POST-Anfragen sind erlaubt.');
    }
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 10000) {
        throw new RuntimeException('Die Anrufmeldung ist ungültig.');
    }
    $config = Config::load(__DIR__);
    $service = new IncomingCallService($config, $db);
    $service->verifyPbxWebhook(
        $body,
        (string) ($_SERVER['HTTP_X_SPEEDPHONE_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_SPEEDPHONE_SIGNATURE'] ?? '')
    );
    $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    $result = $service->reportFromPbx(
        (string) ($payload['phone'] ?? ''),
        (string) ($payload['event_id'] ?? ''),
        (string) ($payload['extension'] ?? '')
    );
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
