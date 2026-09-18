<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

use Anesda\CRM\SpeedPhone\Config;
use Anesda\CRM\SpeedPhone\IndustryFilter;
use Anesda\CRM\SpeedPhone\DialerService;
use Anesda\CRM\SpeedPhone\AssignmentService;
use Anesda\CRM\SpeedPhone\PbxService;
use Anesda\CRM\SpeedPhone\ProductFlyerService;
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
$productFlyers = [];
try {
    $candidate = $queue->getNextCandidate();
    $statistics = $queue->getStatistics();
    $ownedContacts = $assignmentService->listOwnedByCurrentUser();
    $dialerDevices = (new DialerService($db, $current_user))->listDevices();
    $pbxStatus = (new PbxService($config, $db, $current_user, $accessService))->status();
    $productFlyers = (new ProductFlyerService(__DIR__ . '/assets/flyers'))->available();
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
<link rel="stylesheet" href="<?= speedPhoneEscape($assetBase) ?>/speedphone.css?v=1.22.1">
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
            <button type="button" class="button button--secondary" data-call-history-toggle aria-expanded="false" aria-controls="speedphone-call-history">Bisherige Anrufe</button>
            <button type="button" class="button button--secondary" data-team-statistics-toggle aria-expanded="false" aria-controls="speedphone-team-statistics">Teamstatistik</button>
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

    <section id="speedphone-call-history" class="team-statistics call-history" aria-labelledby="call-history-title" hidden>
        <h2 id="call-history-title">Bisherige Anrufe</h2>
        <p>Kontakt direkt wieder in der SpeedPhone-Anrufmaske öffnen. Es wird dabei noch kein neuer Anruf gestartet oder protokolliert.</p>
        <form id="speedphone-call-history-filter">
            <label>Suche<input type="search" name="search" maxlength="200" placeholder="Firma, Name, Telefon oder Notiz"></label>
            <label>Anrufe<select name="scope"><option value="all">Alle freigegebenen Kontakte</option><option value="mine">Nur meine Anrufe</option></select></label>
            <label>Zeitraum<select name="period"><option value="all">Gesamter Verlauf</option><option value="today">Heute</option><option value="7days">Letzte 7 Tage</option><option value="30days">Letzte 30 Tage</option><option value="month">Dieser Monat</option><option value="custom">Eigener Zeitraum</option></select></label>
            <label>Von<input type="date" name="start"></label><label>Bis<input type="date" name="end"></label>
            <button class="button button--secondary" type="submit">Suchen</button>
        </form>
        <p data-call-history-status role="status" aria-live="polite">Wird beim Öffnen geladen.</p>
        <div data-call-history-report></div>
        <p class="team-report__explanation">Es gelten deine SpeedPhone-Zuständigkeitsrechte. Geplante Rückrufe und Überspringen sind keine erledigten Anrufe. Der Verlauf bleibt auch für abgeschlossene oder weiter entfernte Kontakte sichtbar; Anrufverbote, fremde Reservierungen und der Regionalfilter für ausgehende Anrufe bleiben wirksam.</p>
    </section>
    <section id="speedphone-team-statistics" class="team-statistics" aria-labelledby="team-statistics-title" hidden>
        <h2 id="team-statistics-title">Teamstatistik: Wer hat wie viel bearbeitet?</h2>
        <form id="team-statistics-filter">
            <label>Zeitraum<select name="period"><option value="today">Heute</option><option value="7days" selected>Letzte 7 Tage</option><option value="30days">Letzte 30 Tage</option><option value="month">Dieser Monat</option><option value="custom">Eigener Zeitraum</option></select></label>
            <label>Von<input type="date" name="start" required></label><label>Bis<input type="date" name="end" required></label>
            <label>Mitarbeiter<select name="user_id"><option value="">Alle Mitarbeiter</option></select></label>
            <button class="button button--secondary" type="submit">Anzeigen</button>
        </form>
        <p data-team-statistics-status role="status" aria-live="polite">Wird beim Öffnen geladen · automatische Aktualisierung alle 30 Sekunden.</p>
        <div data-team-statistics-report></div>
    </section>
    <div id="speedphone-message" class="message" role="status" aria-live="polite" tabindex="-1" hidden></div>

    <form id="speedphone-industry-filter" class="industry-filter">
        <div class="industry-filter__heading">
            <div>
                <span>Persönliche Ansicht</span>
                <h2>Anrufliste nach Branche filtern</h2>
            </div>
            <div class="industry-filter__controls">
                <label class="sr-only" for="speedphone-industry">Branche für meine Anrufliste</label>
                <select id="speedphone-industry" name="industry" aria-label="Branche für meine Anrufliste">
                    <?php foreach (IndustryFilter::OPTIONS as $value => $label): ?>
                        <option value="<?= speedPhoneEscape($value) ?>" <?= IndustryFilter::selected($current_user) === $value ? 'selected' : '' ?>><?= speedPhoneEscape($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button--secondary">Filter anwenden</button>
            </div>
        </div>
        <p>Dieser Filter ändert keinen Kontakt. Ein bereits geöffneter Kontakt bleibt erhalten; die Auswahl gilt beim nächsten Kontakt.</p>
        <small>Die Branche eines Kontakts steht weiter unten direkt bei seinen Stammdaten und kann dort korrigiert werden. Die Tageskennzahlen bleiben branchenübergreifend.</small>
    </form>

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
            <?= speedPhoneRenderWorkspace(
                $candidate,
                $userTimezone,
                (int) $config->get('default_callback_days', 7),
                $dialerDevices,
                $pbxStatus,
                $productFlyers,
                (int) $config->get('flyer_followup_business_days', 3)
            ) ?>
        <?php endif; ?>
    </div>

    <dialog id="speedphone-incoming-dialog" class="incoming-call" aria-labelledby="speedphone-incoming-title">
        <div class="incoming-call__header">
            <div>
                <span>Eingehender Festnetzanruf</span>
                <h2 id="speedphone-incoming-title" data-incoming-title>Passenden Kontakt auswählen</h2>
                <p data-incoming-phone></p>
            </div>
            <button type="button" class="email-preview__close" data-incoming-dismiss aria-label="Meldung schließen">×</button>
        </div>
        <p class="incoming-call__hint" data-incoming-hint>Die Rufnummer passt zu folgenden Zielkontakten. Wähle den richtigen Betrieb, um ihn reserviert in SpeedPhone zu öffnen.</p>
        <div class="incoming-call__matches" data-incoming-matches></div>
    </dialog>

    <dialog id="speedphone-email-compose-dialog" class="email-compose" aria-labelledby="speedphone-email-compose-title">
        <div class="email-compose__header">
            <div>
                <span>Vor dem Versand prüfen</span>
                <h2 id="speedphone-email-compose-title">Informationsmail bearbeiten</h2>
            </div>
            <button type="button" class="email-preview__close" data-email-compose-cancel aria-label="Entwurf schließen">×</button>
        </div>
        <div class="email-compose__content">
            <label>Empfänger</label>
            <output data-email-compose-recipient>–</output>
            <label for="speedphone-email-compose-subject">Betreff</label>
            <input id="speedphone-email-compose-subject" type="text" maxlength="255" data-email-compose-subject>
            <label for="speedphone-email-compose-body">Nachricht</label>
            <div class="email-compose__toolbar" role="toolbar" aria-label="Nachricht formatieren">
                <button type="button" data-email-editor-command="bold" title="Fett"><strong>Fett</strong></button>
                <button type="button" data-email-editor-command="italic" title="Kursiv"><em>Kursiv</em></button>
                <button type="button" data-email-editor-command="underline">Unterstreichen</button>
                <button type="button" data-email-editor-command="insertUnorderedList">Aufzählung</button>
                <button type="button" data-email-editor-command="insertOrderedList">Nummerierung</button>
                <button type="button" data-email-editor-command="createLink">Link</button>
                <button type="button" data-email-editor-command="unlink">Link entfernen</button>
                <button type="button" data-email-editor-command="undo">Zurück</button>
                <button type="button" data-email-editor-command="redo">Wiederholen</button>
            </div>
            <div class="email-compose__link" data-email-editor-link hidden>
                <label for="speedphone-email-link-url">Linkadresse</label>
                <input id="speedphone-email-link-url" type="url" data-email-editor-link-url placeholder="https://…">
                <button type="button" data-email-editor-link-save>Link übernehmen</button>
                <button type="button" data-email-editor-link-cancel>Abbrechen</button>
            </div>
            <iframe id="speedphone-email-compose-body" class="email-compose__editor" title="E-Mail-Nachricht bearbeiten" sandbox="allow-same-origin" data-email-compose-body></iframe>
            <div class="email-compose__attachments" data-email-compose-attachments hidden></div>
            <p class="field-hint">Die hier bearbeitete Fassung wird genau so versendet und anschließend im CRM protokolliert.</p>
        </div>
        <div class="email-compose__actions">
            <button type="button" class="button button--secondary" data-email-compose-cancel>Abbrechen</button>
            <button type="button" class="button button--mail" data-email-compose-send>E-Mail jetzt versenden</button>
        </div>
    </dialog>

    <dialog id="speedphone-email-dialog" class="email-preview" aria-labelledby="speedphone-email-dialog-title">
        <form method="dialog" class="email-preview__header">
            <div>
                <span>E-Mail-Vorschau</span>
                <h2 id="speedphone-email-dialog-title">E-Mail wird geladen …</h2>
            </div>
            <button type="submit" class="email-preview__close" aria-label="Vorschau schließen">×</button>
        </form>
        <dl class="email-preview__meta">
            <div><dt>Empfänger</dt><dd data-email-preview-recipient>–</dd></div>
            <div><dt>Versendet</dt><dd data-email-preview-date>–</dd></div>
        </dl>
        <p class="email-preview__privacy">Externe Bilder und aktive Inhalte werden zum Schutz vor Tracking nicht geladen.</p>
        <p class="email-preview__note" data-email-preview-note hidden></p>
        <section class="email-preview__activity" aria-labelledby="speedphone-email-activity-title">
            <div class="email-preview__activity-heading">
                <h3 id="speedphone-email-activity-title">Interaktionsverlauf</h3>
                <span data-email-preview-activity-total>Wird geladen …</span>
            </div>
            <div class="email-preview__activity-summary">
                <article>
                    <strong data-email-preview-open-count>0</strong>
                    <span>Öffnungen</span>
                    <small data-email-preview-last-open>zuletzt: –</small>
                </article>
                <article>
                    <strong data-email-preview-click-count>0</strong>
                    <span>Klicks</span>
                    <small data-email-preview-last-click>zuletzt: –</small>
                </article>
            </div>
            <ol class="email-preview__timeline" data-email-preview-interactions></ol>
            <p class="email-preview__activity-empty" data-email-preview-activity-empty hidden>Keine Öffnung oder kein Klick protokolliert.</p>
        </section>
        <pre data-email-preview-body>Inhalt wird geladen …</pre>
    </dialog>

    <div class="speedphone__footer">CRM SpeedPhone © anesda</div>
</main>
<script src="<?= speedPhoneEscape($assetBase) ?>/vendor/qrcode-generator/qrcode.js?v=2.0.4"></script>
<script src="<?= speedPhoneEscape($assetBase) ?>/email-editor.js?v=1.22.1"></script>
<script src="<?= speedPhoneEscape($assetBase) ?>/speedphone.js?v=1.22.1"></script>
