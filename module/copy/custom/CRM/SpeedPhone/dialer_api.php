<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';

use Anesda\CRM\SpeedPhone\DialerService;
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

    $service = new DialerService($db);
    $operation = (string) ($_POST['operation'] ?? '');
    if ($operation === 'claim_pairing') {
        $result = $service->claimPairing(
            (string) ($_POST['pairing_token'] ?? ''),
            (string) ($_POST['device_name'] ?? ''),
            (string) ($_POST['platform'] ?? ''),
            strtolower((string) ($_POST['device_token_hash'] ?? ''))
        );
    } elseif ($operation === 'poll') {
        $result = $service->poll((string) ($_POST['device_id'] ?? ''), (string) ($_POST['device_token'] ?? ''));
    } elseif ($operation === 'ack') {
        $service->acknowledge(
            (string) ($_POST['device_id'] ?? ''),
            (string) ($_POST['device_token'] ?? ''),
            (string) ($_POST['command_id'] ?? ''),
            (string) ($_POST['status'] ?? ''),
            (string) ($_POST['error'] ?? '')
        );
        $result = ['acknowledged' => true];
    } elseif ($operation === 'disconnect') {
        $service->disconnect((string) ($_POST['device_id'] ?? ''), (string) ($_POST['device_token'] ?? ''));
        $result = ['disconnected' => true];
    } elseif ($operation === 'incoming_call') {
        $incomingCalls = new IncomingCallService(Config::load(__DIR__), $db);
        $result = $incomingCalls->report(
            $service,
            (string) ($_POST['device_id'] ?? ''),
            (string) ($_POST['device_token'] ?? ''),
            (string) ($_POST['phone'] ?? '')
        );
    } else {
        throw new InvalidArgumentException('Unbekannte Dialer-Aktion.');
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
