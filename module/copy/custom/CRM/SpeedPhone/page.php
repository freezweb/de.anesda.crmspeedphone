<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\QueueService;

global $current_user, $db, $sugar_config;

if (empty($current_user->id)) {
    sugar_die('Nicht angemeldet.');
}

if (empty($_SESSION['crm_speedphone_csrf'])) {
    $_SESSION['crm_speedphone_csrf'] = bin2hex(random_bytes(32));
}

$config = Config::load(__DIR__);
$lockService = new Anesda\CRM\SpeedPhone\LockService($config, $db, $current_user);
$queue = new QueueService($config, $db, $current_user, $lockService);
$error = '';
$candidate = null;
$statistics = ['open' => 0, 'callbacks_due' => 0, 'processed_today' => 0, 'interested' => 0, 'locked' => 0];
try {
    $candidate = $queue->getNextCandidate();
    $statistics = $queue->getStatistics();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$siteUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
$legacyBase = str_ends_with($siteUrl, '/legacy') ? $siteUrl : $siteUrl . '/legacy';
$assetBase = $legacyBase . '/custom/CRM/SpeedPhone/assets';
$userTimezone = (string) ($current_user->getPreference('timezone') ?: 'Europe/Berlin');

?>
<link rel="stylesheet" href="<?= speedPhoneEscape($assetBase) ?>/speedphone.css?v=1.2.1">
<main class="speedphone" data-api-url="index.php?entryPoint=crmSpeedPhoneApi" data-csrf="<?= speedPhoneEscape($_SESSION['crm_speedphone_csrf']) ?>">
    <header class="speedphone__header">
        <div>
            <p class="speedphone__eyebrow">SuiteCRM-Telefonwarteschlange</p>
            <h1>CRM SpeedPhone</h1>
            <p>Angemeldet als <?= speedPhoneEscape(trim($current_user->first_name . ' ' . $current_user->last_name) ?: $current_user->user_name) ?></p>
        </div>
        <a class="button button--secondary" href="index.php?module=Prospects&amp;action=index">Alle Zielkontakte</a>
    </header>

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
<script src="<?= speedPhoneEscape($assetBase) ?>/speedphone.js?v=1.2.1"></script>
