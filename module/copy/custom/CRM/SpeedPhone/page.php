<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\QueueService;
use Anesda\CRM\SpeedPhone\UserAccessService;

global $current_user, $db, $sugar_config;

if (empty($current_user->id)) {
    sugar_die('Nicht angemeldet.');
}

if (empty($_SESSION['crm_speedphone_csrf'])) {
    $_SESSION['crm_speedphone_csrf'] = bin2hex(random_bytes(32));
}

$config = Config::load(__DIR__);
$accessService = new UserAccessService($db, $current_user);
$assignmentService = new AssignmentService($config, $db, $current_user, $accessService);
$lockService = new Anesda\CRM\SpeedPhone\LockService($config, $db, $current_user);
$queue = new QueueService($config, $db, $current_user, $lockService, $accessService, $assignmentService);
$error = '';
$candidate = null;
$statistics = ['open' => 0, 'callbacks_due' => 0, 'processed_today' => 0, 'interested' => 0, 'locked' => 0];
$currentProfile = $accessService->currentProfile();
$canManageTeam = $accessService->canManageTeam();
$teamUsers = [];
$escalationOptions = [];
$ownedContacts = [];
try {
    $candidate = $queue->getNextCandidate();
    $statistics = $queue->getStatistics();
    $ownedContacts = $assignmentService->listOwnedByCurrentUser();
    if ($canManageTeam) {
        $teamUsers = $accessService->listTeamUsers();
        $escalationOptions = $accessService->escalationOptions($config);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$siteUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
$legacyBase = str_ends_with($siteUrl, '/legacy') ? $siteUrl : $siteUrl . '/legacy';
$assetBase = $legacyBase . '/custom/CRM/SpeedPhone/assets';
$userTimezone = (string) ($current_user->getPreference('timezone') ?: 'Europe/Berlin');

?>
<link rel="stylesheet" href="<?= speedPhoneEscape($assetBase) ?>/speedphone.css?v=1.3.3.1">
<main class="speedphone" data-api-url="index.php?entryPoint=crmSpeedPhoneApi" data-csrf="<?= speedPhoneEscape($_SESSION['crm_speedphone_csrf']) ?>">
    <header class="speedphone__header">
        <div>
            <p class="speedphone__eyebrow">SuiteCRM-Telefonwarteschlange</p>
            <h1>CRM SpeedPhone</h1>
            <p>
                Angemeldet als <?= speedPhoneEscape(trim($current_user->first_name . ' ' . $current_user->last_name) ?: $current_user->user_name) ?>
                · <?= $currentProfile['user_type'] === 'external' ? 'Extern' : 'Intern' ?>
                <?php if ($currentProfile['user_type'] === 'external'): ?>· <?= speedPhoneEscape(speedPhonePercent($currentProfile['commission_percent'])) ?> Provision<?php endif; ?>
            </p>
        </div>
        <div class="speedphone__header-actions">
            <button type="button" class="button button--secondary" data-speedphone-owned-toggle aria-expanded="false">
                Meine Kontakte (<?= count($ownedContacts) ?>)
            </button>
            <?php if ($canManageTeam): ?>
                <button type="button" class="button button--secondary" data-speedphone-team-toggle aria-expanded="false">Team &amp; Provision</button>
            <?php endif; ?>
            <?php if ($currentProfile['user_type'] === 'internal'): ?>
                <a class="button button--secondary" href="index.php?module=Prospects&amp;action=index">Alle Zielkontakte</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($error === ''): ?>
        <?php require __DIR__ . '/owned_contacts.php'; ?>
    <?php endif; ?>

    <?php if ($canManageTeam && $error === ''): ?>
        <?php require __DIR__ . '/team_settings.php'; ?>
    <?php endif; ?>

    <section class="stats" aria-label="Tagesübersicht">
        <article><strong data-stat="open"><?= (int) $statistics['open'] ?></strong><span>offen</span></article>
        <article><strong data-stat="callbacks_due"><?= (int) $statistics['callbacks_due'] ?></strong><span>Rückrufe fällig</span></article>
        <article><strong data-stat="processed_today"><?= (int) $statistics['processed_today'] ?></strong><span>heute bearbeitet</span></article>
        <article><strong data-stat="interested"><?= (int) $statistics['interested'] ?></strong><span>Interessenten</span></article>
        <article><strong data-stat="locked"><?= (int) $statistics['locked'] ?></strong><span>gerade reserviert</span></article>
    </section>

    <div id="speedphone-message" class="message" role="status" aria-live="polite" tabindex="-1" hidden></div>

    <div id="speedphone-workspace">
        <?php if ($error !== ''): ?>
            <section class="empty empty--error">
                <h2>SpeedPhone ist noch nicht einsatzbereit</h2>
                <p><?= speedPhoneEscape($error) ?></p>
                <p>Prüfe <code>custom/CRM/SpeedPhone/config.local.php</code> und führe anschließend „Quick Repair and Rebuild“ aus.</p>
            </section>
        <?php else: ?>
            <?= speedPhoneRenderWorkspace($candidate, $userTimezone, (int) $config->get('default_callback_days', 7)) ?>
        <?php endif; ?>
    </div>

    <div class="speedphone__footer">CRM SpeedPhone © anesda</div>
</main>
<script src="<?= speedPhoneEscape($assetBase) ?>/speedphone.js?v=1.3.3.1"></script>
