<?php

if (!defined('sugarEntry') || !sugarEntry) { die('Not A Valid Entry Point'); }

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
try {
    require_once __DIR__ . '/bootstrap.php';
    $config = Anesda\CRM\SpeedPhone\Config::load(__DIR__);
    $id = (string) ($_GET['m'] ?? '');
    $url = (string) ($_GET['u'] ?? '');
    if (!(bool) $config->get('email_logo_tracking_enabled', false)
        || !Anesda\CRM\SpeedPhone\EmailLogoTrackingService::verify(
            $config->requireString('mail_webhook_secret'), $id, $url, (string) ($_GET['s'] ?? '')
        )) { http_response_code(404); exit; }
    $db = $GLOBALS['db'];
    $row = $db->fetchByAssoc($db->query("SELECT e.parent_id,et.to_addrs FROM emails e
        INNER JOIN emails_text et ON et.email_id=e.id AND et.deleted=0
        WHERE e.id='" . $db->quote($id) . "' AND e.deleted=0 AND e.type='out' AND e.status='sent'
          AND e.parent_type='Prospects' LIMIT 1"));
    if (!$row) { http_response_code(404); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        try {
            (new Anesda\CRM\SpeedPhone\MailWebhookService($config, $db))->process([
                'id' => create_guid(), 'event' => 'opened', 'email' => (string) $row['to_addrs'],
                'message_id' => $id, 'date' => gmdate('c'),
                'metadata' => ['crm_target_id' => (string) $row['parent_id'], 'crm_email_id' => $id, 'source' => 'speedphone-footer-logo'],
            ]);
        } catch (Throwable $error) {
            // Die Bildanzeige darf bei einer vorübergehend gestörten Protokollierung nicht ausfallen.
            $GLOBALS['log']->warn('SpeedPhone-Logo-Abruf konnte nicht protokolliert werden: ' . $error->getMessage());
        }
    }
    header('Location: ' . $url, true, 302);
} catch (Throwable) { http_response_code(404); }
