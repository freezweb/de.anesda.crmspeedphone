<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once 'custom/CRM/SpeedPhone/bootstrap.php';
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 1048576) {
        throw new RuntimeException('Der Webhook-Inhalt fehlt oder ist zu groß.');
    }
    $service = new Anesda\CRM\SpeedPhone\MailWebhookService(
        Anesda\CRM\SpeedPhone\Config::load(__DIR__),
        $GLOBALS['db']
    );
    $service->verify(
        $body,
        (string) ($_SERVER['HTTP_X_ANESDA_WEBHOOK_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_ANESDA_WEBHOOK_SIGNATURE'] ?? '')
    );
    $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Webhook-JSON muss ein Objekt sein.');
    }
    http_response_code(200);
    echo json_encode($service->process($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $error) {
    http_response_code(str_contains(strtolower($error->getMessage()), 'signatur') ? 401 : 500);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $GLOBALS['log']->fatal('Anesda-Mail-Webhook fehlgeschlagen: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Interner Fehler bei der Webhook-Verarbeitung.']);
}
