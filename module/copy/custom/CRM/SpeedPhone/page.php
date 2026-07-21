<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';

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
    $statistics = $queue->getStatistics();
    $candidate = $queue->getNextCandidate();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$siteUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
$legacyBase = str_ends_with($siteUrl, '/legacy') ? $siteUrl : $siteUrl . '/legacy';
$assetBase = $legacyBase . '/custom/CRM/SpeedPhone/assets';

function speedPhoneEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<link rel="stylesheet" href="<?= speedPhoneEscape($assetBase) ?>/speedphone.css?v=1.1.0">
<main class="speedphone" data-api-url="index.php?entryPoint=crmSpeedPhoneApi" data-csrf="<?= speedPhoneEscape($_SESSION['crm_speedphone_csrf']) ?>">
    <header class="speedphone__header">
        <div>
            <p class="speedphone__eyebrow">SuiteCRM-Telefonwarteschlange</p>
            <h1>CRM SpeedPhone</h1>
            <p>Angemeldet als <?= speedPhoneEscape(trim($current_user->first_name . ' ' . $current_user->last_name) ?: $current_user->user_name) ?></p>
        </div>
        <a class="button button--secondary" href="index.php?module=Prospects&action=index">Alle Zielkontakte</a>
    </header>

    <section class="stats" aria-label="Tagesübersicht">
        <article><strong><?= (int) $statistics['open'] ?></strong><span>offen</span></article>
        <article><strong><?= (int) $statistics['callbacks_due'] ?></strong><span>Rückrufe fällig</span></article>
        <article><strong><?= (int) $statistics['processed_today'] ?></strong><span>heute bearbeitet</span></article>
        <article><strong><?= (int) $statistics['interested'] ?></strong><span>Interessenten</span></article>
        <article><strong><?= (int) $statistics['locked'] ?></strong><span>gerade reserviert</span></article>
    </section>

    <div id="speedphone-message" class="message" hidden></div>

    <?php if ($error !== ''): ?>
        <section class="empty empty--error">
            <h2>SpeedPhone ist noch nicht einsatzbereit</h2>
            <p><?= speedPhoneEscape($error) ?></p>
            <p>Prüfe <code>custom/CRM/SpeedPhone/config.local.php</code> und führe anschließend „Quick Repair and Rebuild“ aus.</p>
        </section>
    <?php elseif ($candidate === null): ?>
        <section class="empty">
            <h2>Die aktuelle Warteschlange ist abgearbeitet</h2>
            <p>Es gibt momentan keinen fälligen, freigegebenen Zielkontakt mit Telefonnummer.</p>
        </section>
    <?php else: ?>
        <section class="candidate" data-prospect-id="<?= speedPhoneEscape($candidate['id']) ?>">
            <div class="candidate__main">
                <div class="candidate__title">
                    <div>
                        <p class="speedphone__eyebrow">Nächster Kontakt · Priorität <?= (int) $candidate['score'] ?></p>
                        <h2><?= speedPhoneEscape($candidate['name']) ?></h2>
                        <p><?= speedPhoneEscape(trim(($candidate['primary_address_postalcode'] ?? '') . ' ' . ($candidate['primary_address_city'] ?? ''))) ?></p>
                    </div>
                    <a class="record-link" href="index.php?module=Prospects&action=DetailView&record=<?= speedPhoneEscape($candidate['id']) ?>" target="_blank" rel="noopener">CRM-Datensatz öffnen</a>
                </div>

                <div class="contact-grid">
                    <?php if (!empty($candidate['phone_work'])): ?>
                        <a class="contact-card contact-card--phone" href="tel:<?= speedPhoneEscape(preg_replace('/[^+0-9]/', '', $candidate['phone_work'])) ?>">
                            <span>Telefon</span><strong><?= speedPhoneEscape($candidate['phone_work']) ?></strong>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($candidate['phone_mobile'])): ?>
                        <a class="contact-card contact-card--phone" href="tel:<?= speedPhoneEscape(preg_replace('/[^+0-9]/', '', $candidate['phone_mobile'])) ?>">
                            <span>Mobil</span><strong><?= speedPhoneEscape($candidate['phone_mobile']) ?></strong>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($candidate['email'])): ?>
                        <a class="contact-card" href="mailto:<?= speedPhoneEscape($candidate['email']) ?>">
                            <span>E-Mail</span><strong><?= speedPhoneEscape($candidate['email']) ?></strong>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($candidate['website'])): ?>
                        <a class="contact-card" href="<?= speedPhoneEscape($candidate['website']) ?>" target="_blank" rel="noopener">
                            <span>Website</span><strong>Website öffnen</strong>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="reasons">
                    <?php foreach ($candidate['reasons'] as $reason): ?>
                        <span><?= speedPhoneEscape($reason) ?></span>
                    <?php endforeach; ?>
                    <span><?= (int) $candidate['speedphone_attempts'] ?> bisherige SpeedPhone-Versuche</span>
                </div>

                <?php if (!empty($candidate['recent_calls'])): ?>
                    <details class="history">
                        <summary>Letzte Anrufe anzeigen</summary>
                        <?php foreach ($candidate['recent_calls'] as $call): ?>
                            <article>
                                <strong><?= speedPhoneEscape($call['name']) ?></strong>
                                <span><?= speedPhoneEscape($call['date_start']) ?> · <?= speedPhoneEscape($call['status']) ?></span>
                                <?php if (!empty($call['description'])): ?><p><?= nl2br(speedPhoneEscape($call['description'])) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
            </div>

            <form id="speedphone-form" class="quick-form">
                <input type="hidden" name="prospect_id" value="<?= speedPhoneEscape($candidate['id']) ?>">
                <input type="hidden" name="lock_token" value="<?= speedPhoneEscape($candidate['lock_token']) ?>">
                <h3>Anruf schnell eintragen</h3>
                <p class="lock-note">Für dich reserviert · andere Telefonierer erhalten inzwischen einen anderen Kontakt.</p>

                <label for="speedphone-note">Kurze Notiz</label>
                <textarea id="speedphone-note" name="note" rows="4" placeholder="Gespräch, Ansprechpartner oder Grund der Wiedervorlage"></textarea>

                <div class="field-row">
                    <div>
                        <label for="speedphone-callback">Rückruf am</label>
                        <input id="speedphone-callback" type="datetime-local" name="callback_at">
                    </div>
                    <div>
                        <label for="speedphone-email">Neue/bestätigte E-Mail-Adresse</label>
                        <input id="speedphone-email" type="email" name="new_email" value="<?= speedPhoneEscape($candidate['email']) ?>">
                    </div>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="email_requested" value="1">
                    <span>Kontakt hat eine Informationsmail gewünscht</span>
                </label>

                <div class="actions">
                    <button type="submit" name="result" value="not_reached" class="button button--warning">Nicht erreicht</button>
                    <button type="submit" name="result" value="callback" class="button button--info">Rückruf vereinbart</button>
                    <button type="submit" name="result" value="interested" class="button button--success">Erreicht · Interesse</button>
                    <button type="submit" name="result" value="no_interest" class="button button--muted">Erreicht · kein Interesse</button>
                    <button type="submit" name="result" value="wrong_number" class="button button--danger">Falsche Nummer</button>
                    <button type="submit" name="result" value="blocked" class="button button--danger">Nicht mehr kontaktieren</button>
                    <button type="submit" name="result" value="later" class="button button--secondary">Ohne Anruf später</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div class="speedphone__footer">CRM SpeedPhone © anesda</div>
</main>
<script src="<?= speedPhoneEscape($assetBase) ?>/speedphone.js?v=1.1.0"></script>
