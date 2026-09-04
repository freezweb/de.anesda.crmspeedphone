<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\DialerService;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\PbxService;
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
try {
    $accessService->assertAllowed();
} catch (Throwable) {
    ACLController::displayNoAccess(true);
    return;
}
$assignmentService = new AssignmentService($config, $db, $current_user, $accessService);
$lockService = new Anesda\CRM\SpeedPhone\LockService($config, $db, $current_user);
$queue = new QueueService($config, $db, $current_user, $lockService, $accessService, $assignmentService);
$error = '';
$candidate = null;
$statistics = [
    'open' => 0,
    'callbacks_due' => 0,
    'processed_today_mine' => 0,
    'processed_today_all' => 0,
    'interested' => 0,
    'locked' => 0,
];
$currentProfile = $accessService->currentProfile();
$canManageTeam = $accessService->canManageTeam();
$teamUsers = [];
$escalationOptions = [];
$ownedContacts = [];
$dialerDevices = [];
$pbxStatus = [];
try {
    $candidate = $queue->getNextCandidate();
    $statistics = $queue->getStatistics();
    $ownedContacts = $assignmentService->listOwnedByCurrentUser();
    $dialerDevices = (new DialerService($db, $current_user))->listDevices();
    $pbxStatus = (new PbxService($config, $db, $current_user, $accessService))->status();
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
<link rel="stylesheet" href="<?= speedPhoneEscape($assetBase) ?>/speedphone.css?v=1.12.0">
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
            <button type="button" class="button button--secondary" data-speedphone-dialer-toggle aria-expanded="false">
                Handy koppeln
            </button>
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
        <?php require __DIR__ . '/dialer_panel.php'; ?>
        <?php require __DIR__ . '/owned_contacts.php'; ?>
    <?php endif; ?>

    <?php if ($canManageTeam && $error === ''): ?>
        <?php require __DIR__ . '/team_settings.php'; ?>
    <?php endif; ?>

    <section class="stats" aria-label="Tagesübersicht">
        <article><strong data-stat="open"><?= (int) $statistics['open'] ?></strong><span>offen</span></article>
        <article><strong data-stat="callbacks_due"><?= (int) $statistics['callbacks_due'] ?></strong><span>Rückrufe fällig</span></article>
        <article><strong data-stat="processed_today_mine"><?= (int) $statistics['processed_today_mine'] ?></strong><span>heute · ich</span></article>
        <article><strong data-stat="processed_today_all"><?= (int) $statistics['processed_today_all'] ?></strong><span>heute · alle</span></article>
        <article><strong data-stat="interested"><?= (int) $statistics['interested'] ?></strong><span>Interessenten</span></article>
        <article><strong data-stat="locked"><?= (int) $statistics['locked'] ?></strong><span>gerade reserviert</span></article>
    </section>

    <div id="speedphone-message" class="message" role="status" aria-live="polite" tabindex="-1" hidden></div>

    <?php if ($config->get('travel_filter_enabled', false)): ?>
        <aside class="message" role="note">
            Regionalfilter: maximal <?= (int) $config->get('travel_max_minutes', 60) ?> Minuten einfache Anfahrt
            ab <?= speedPhoneEscape((string) $config->get('travel_origin_label', '')) ?>.
            <?php if ($config->get('travel_included_areas', [])): ?>
                Zusätzlich einbezogen: <?= speedPhoneEscape(implode(', ', (array) $config->get('travel_included_areas', []))) ?>.
            <?php endif; ?>
            Zu weit entfernte, ungeprüfte und Grenzbereich-Kontakte sind aus der Anrufliste ausgeblendet,
            bleiben aber im CRM erhalten. PLZ-/Ortsschätzungen sind keine adressgenauen Fahrzeiten.
            <?php if ($currentProfile['user_type'] === 'internal'): ?>
                <a href="index.php?module=Prospects&amp;action=index&amp;query=true&amp;searchFormTab=advanced_search&amp;speedphone_travel_status_c_advanced%5B%5D=too_far">Zu weit entfernte Kontakte im CRM anzeigen</a>
            <?php endif; ?>
            <small>Ortsdaten: <a href="https://www.geonames.org/" target="_blank" rel="noopener">GeoNames</a> ·
                Routen: <a href="https://routing.openstreetmap.de/about.html" target="_blank" rel="noopener">OSRM/FOSSGIS</a> ·
                © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap-Mitwirkende</a> ·
                <a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noopener">Kartendaten korrigieren</a>
            </small>
        </aside>
    <?php endif; ?>

    <div id="speedphone-workspace">
        <?php if ($error !== ''): ?>
            <section class="empty empty--error">
                <h2>SpeedPhone ist noch nicht einsatzbereit</h2>
                <p><?= speedPhoneEscape($error) ?></p>
                <p>Prüfe <code>custom/CRM/SpeedPhone/config.local.php</code> und führe anschließend „Quick Repair and Rebuild“ aus.</p>
            </section>
        <?php else: ?>
            <?= speedPhoneRenderWorkspace($candidate, $userTimezone, (int) $config->get('default_callback_days', 7), $dialerDevices, $pbxStatus) ?>
        <?php endif; ?>
    </div>

    <div class="speedphone__footer">CRM SpeedPhone © anesda</div>
</main>
<script src="<?= speedPhoneEscape($assetBase) ?>/vendor/qrcode-generator/qrcode.js?v=2.0.4"></script>
<script src="<?= speedPhoneEscape($assetBase) ?>/speedphone.js?v=1.12.0"></script>
