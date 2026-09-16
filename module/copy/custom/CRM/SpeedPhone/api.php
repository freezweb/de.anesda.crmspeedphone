<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\ActionService;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\BusinessDayCalculator;
use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\DialerService;
use Anesda\CRM\SpeedPhone\EmailService;
use Anesda\CRM\SpeedPhone\InputValidator;
use Anesda\CRM\SpeedPhone\IncomingCallService;
use Anesda\CRM\SpeedPhone\LockService;
use Anesda\CRM\SpeedPhone\PbxService;
use Anesda\CRM\SpeedPhone\ProductFlyerService;
use Anesda\CRM\SpeedPhone\QueueService;
use Anesda\CRM\SpeedPhone\UserAccessService;

global $current_user, $db, $sugar_config;

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
    $accessService = new UserAccessService($db, $current_user);
    $assignmentService = new AssignmentService($config, $db, $current_user, $accessService);
    $lockService = new LockService($config, $db, $current_user);
    $queue = new QueueService($config, $db, $current_user, $lockService, $accessService, $assignmentService);
    $queue->assertUserAllowed();
    $dialerService = new DialerService($db, $current_user);
    $pbxService = new PbxService($config, $db, $current_user, $accessService);
    $productFlyerService = new ProductFlyerService(__DIR__ . '/assets/flyers');

    if ((string) ($_POST['operation'] ?? '') === 'dialer_pairing') {
        $siteUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
        $legacyBase = str_ends_with($siteUrl, '/legacy') ? $siteUrl : $siteUrl . '/legacy';
        $result = $dialerService->createPairing(
            $legacyBase . '/index.php?entryPoint=crmSpeedPhoneDialerApi',
            $legacyBase . '/index.php?entryPoint=crmSpeedPhoneDialerSetup'
        );
        $result['devices'] = $dialerService->listDevices();
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'dialer_call') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        if (!$queue->canEditProspect($prospectId) || !ACLController::checkAccess('Prospects', 'edit', true)) {
            throw new RuntimeException('Kein Zugriff auf diesen Zielkontakt.');
        }
        $lockService->assertOwned($prospectId, (string) ($_POST['lock_token'] ?? ''));
        if (!$queue->canEditProspect($prospectId, true)) {
            throw new RuntimeException('Dieser Kontakt ist durch den Anfahrtsfilter von ausgehenden Anrufen ausgeschlossen.');
        }
        $result = $dialerService->queueCall($prospectId, (string) ($_POST['phone_kind'] ?? 'work'));
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'pbx_call') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        if (!$queue->canEditProspect($prospectId) || !ACLController::checkAccess('Prospects', 'edit', true)) {
            throw new RuntimeException('Kein Zugriff auf diesen Zielkontakt.');
        }
        $lockService->assertOwned($prospectId, (string) ($_POST['lock_token'] ?? ''));
        if (!$queue->canEditProspect($prospectId, true)) {
            throw new RuntimeException('Dieser Kontakt ist durch den Anfahrtsfilter von ausgehenden Anrufen ausgeschlossen.');
        }
        $result = $pbxService->queueCall($prospectId, (string) ($_POST['phone_kind'] ?? 'work'));
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'dialer_command_status') {
        $validator = new InputValidator();
        $commandId = $validator->uuid((string) ($_POST['command_id'] ?? ''));
        echo json_encode(['success' => true, 'data' => $dialerService->commandStatus($commandId)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'dialer_revoke') {
        $validator = new InputValidator();
        $dialerService->revokeDevice($validator->uuid((string) ($_POST['device_id'] ?? '')));
        echo json_encode(['success' => true, 'data' => ['message' => 'Die Kopplung wurde aufgehoben.']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'save_team_settings') {
        $accessService->saveTeamSettings($_POST, $config);
        echo json_encode([
            'success' => true,
            'data' => ['message' => 'Teamrechte, Provisionen und Eskalationsfristen wurden gespeichert.'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'heartbeat') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        $lock = $lockService->heartbeat($prospectId, (string) ($_POST['lock_token'] ?? ''));
        echo json_encode(['success' => true, 'data' => ['expires_at' => $lock['expires_at']]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'open_incoming_pbx') {
        $validator = new InputValidator();
        $eventId = $validator->uuid((string) ($_POST['event_id'] ?? ''));
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        $candidate = (new IncomingCallService($config, $db))->openPbxMatch(
            $current_user,
            $queue,
            $eventId,
            $prospectId
        );
        $userTimezone = (string) ($current_user->getPreference('timezone') ?: 'Europe/Berlin');
        echo json_encode(['success' => true, 'data' => [
            'workspace_html' => speedPhoneRenderWorkspace(
                $candidate,
                $userTimezone,
                (int) $config->get('default_callback_days', 7),
                $dialerService->listDevices(),
                $pbxService->status(),
                $productFlyerService->available(),
                (int) $config->get('flyer_followup_business_days', 3)
            ),
            'statistics' => $queue->getStatistics(),
            'expires_at' => $candidate['lock_expires_at'] ?? null,
            'display_name' => $candidate['name'] ?? '',
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'dismiss_incoming_pbx') {
        $validator = new InputValidator();
        (new IncomingCallService($config, $db))->dismissPbxEvent(
            $current_user,
            $validator->uuid((string) ($_POST['event_id'] ?? ''))
        );
        echo json_encode(['success' => true, 'data' => ['dismissed' => true]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'incoming_pbx_status') {
        $incoming = (new IncomingCallService($config, $db))->pendingPbxForCurrentUser($current_user);
        echo json_encode(['success' => true, 'data' => ['incoming_call' => $incoming]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'refresh_current') {
        $prospectIdInput = (string) ($_POST['prospect_id'] ?? '');
        $lockToken = (string) ($_POST['lock_token'] ?? '');
        $incomingService = new IncomingCallService($config, $db);
        $pbxIncomingCall = $incomingService->pendingPbxForCurrentUser($current_user);
        $incomingCall = $pbxIncomingCall === null
            ? $incomingService->openPendingForCurrentUser($current_user, $queue)
            : null;
        $candidate = null;
        $lock = null;
        if ($incomingCall !== null) {
            $candidate = $incomingCall['candidate'];
            $lock = [
                'expires_at' => $candidate['lock_expires_at'],
            ];
        } elseif ($prospectIdInput !== '') {
            $validator = new InputValidator();
            $prospectId = $validator->uuid($prospectIdInput);
            $lock = $lockService->heartbeat($prospectId, $lockToken);
            $candidate = $queue->getCurrentCandidate($prospectId, $lockToken);
        }
        $devices = $dialerService->listDevices();
        $userTimezone = (string) ($current_user->getPreference('timezone') ?: 'Europe/Berlin');

        echo json_encode([
            'success' => true,
            'data' => [
                'workspace_html' => speedPhoneRenderWorkspace(
                    $candidate,
                    $userTimezone,
                    (int) $config->get('default_callback_days', 7),
                    $devices,
                    $pbxService->status(),
                    $productFlyerService->available(),
                    (int) $config->get('flyer_followup_business_days', 3)
                ),
                'statistics' => $queue->getStatistics(),
                'devices' => $devices,
                'prospect_id' => $candidate['id'] ?? null,
                'expires_at' => $lock['expires_at'] ?? null,
                'incoming_call' => $pbxIncomingCall !== null
                    ? [
                        'source' => 'pbx',
                        'event_id' => $pbxIncomingCall['event_id'],
                        'caller_phone' => $pbxIncomingCall['caller_phone'],
                        'received_at' => $pbxIncomingCall['received_at'],
                        'matches' => $pbxIncomingCall['matches'],
                    ]
                    : ($incomingCall === null ? null : [
                        'source' => 'mobile',
                        'event_id' => $incomingCall['event_id'],
                        'display_name' => $candidate['name'],
                    ]),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                    (int) $config->get('default_callback_days', 7),
                    $dialerService->listDevices(),
                    $pbxService->status(),
                    $productFlyerService->available(),
                    (int) $config->get('flyer_followup_business_days', 3)
                ),
                'statistics' => $statistics,
                'prospect_id' => $candidate['id'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((string) ($_POST['operation'] ?? '') === 'email_preview') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        $emailId = $validator->uuid((string) ($_POST['email_id'] ?? ''));
        $lockService->assertOwned($prospectId, (string) ($_POST['lock_token'] ?? ''));
        echo json_encode([
            'success' => true,
            'data' => $queue->getEmailPreview($prospectId, $emailId, (string) ($_POST['email_kind'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $emailService = new EmailService($config, $db, $current_user, $productFlyerService);
    if ((string) ($_POST['operation'] ?? '') === 'resend_email') {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($_POST['prospect_id'] ?? ''));
        $newEmail = $validator->email((string) ($_POST['new_email'] ?? ''));
        if (empty($_POST['email_address_confirmed'])) {
            throw new InvalidArgumentException(
                'Bitte bestätigen Sie die ausdrückliche Anforderung dieser einmaligen Informationsmail.'
            );
        }
        if (!$queue->canEditProspect($prospectId) || !ACLController::checkAccess('Prospects', 'edit', true)) {
            throw new RuntimeException('Kein Zugriff auf diesen Zielkontakt.');
        }

        /** @var Prospect $prospect */
        $prospect = BeanFactory::getBean('Prospects', $prospectId);
        if (!$prospect || empty($prospect->id) || (int) $prospect->deleted === 1) {
            throw new RuntimeException('Der Zielkontakt wurde nicht gefunden.');
        }
        if ($newEmail !== '') {
            $prospect->email1 = $newEmail;
            $prospect->save(false);
        }

        $emailResult = $emailService->sendRequestedInformation(
            $prospect,
            true,
            $emailService->validateFlyerSelection($_POST['flyers'] ?? [])
        );
        echo json_encode([
            'success' => true,
            'data' => ['message' => $emailResult['message'], 'email' => $emailResult],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $service = new ActionService(
        $config,
        $queue,
        $emailService,
        new BusinessDayCalculator(),
        $current_user,
        $lockService,
        $assignmentService
    );
    $result = $service->execute($_POST);

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
