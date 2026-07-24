<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once __DIR__ . '/bootstrap.php';

use Anesda\CRM\SpeedPhone\Config;

$config = Config::load(__DIR__);
$androidStoreUrl = trim((string) $config->get('dialer_android_store_url', ''));
$iosStoreUrl = trim((string) $config->get('dialer_ios_store_url', ''));
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; "
    . "img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
);

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>SpeedPhone Dialer installieren und koppeln</title>
    <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; color: #12233f; background: linear-gradient(145deg, #eef8fb, #f5f7fb 45%, #e9f0fb); }
        main { width: min(100%, 560px); padding: 30px; border: 1px solid #d7e2ee; border-radius: 26px; background: rgba(255,255,255,.96); box-shadow: 0 24px 70px rgba(5,47,104,.14); }
        .logo { width: 76px; height: 76px; display: grid; place-items: center; margin-bottom: 22px; border-radius: 22px; color: white; background: #052f68; font-size: 36px; box-shadow: inset 0 -10px 20px rgba(5,187,221,.25); }
        h1 { margin: 0; font-size: clamp(28px, 7vw, 40px); line-height: 1.05; }
        .lead { margin: 14px 0 22px; color: #52647d; font-size: 17px; line-height: 1.55; }
        .status { padding: 16px; border-radius: 16px; background: #eaf7f9; color: #174a58; line-height: 1.45; }
        .actions { display: grid; gap: 12px; margin-top: 22px; }
        a, button { width: 100%; min-height: 52px; display: inline-flex; align-items: center; justify-content: center; padding: 12px 18px; border: 0; border-radius: 14px; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
        .primary { color: white; background: #052f68; }
        .secondary { color: #052f68; background: #e9eff8; }
        [hidden] { display: none !important; }
        .hint { margin: 18px 0 0; color: #6d7b8f; font-size: 14px; line-height: 1.5; }
        .error { background: #fff0f0; color: #8b1f2c; }
    </style>
</head>
<body>
<main>
    <div class="logo" aria-hidden="true">☎</div>
    <h1>SpeedPhone Dialer</h1>
    <p class="lead">Diese Seite erkennt Ihr Gerät, öffnet die installierte App oder führt Sie automatisch zum passenden Store.</p>
    <p class="status" id="status" role="status">Kopplungsdaten werden geprüft …</p>
    <div class="actions">
        <button class="primary" id="open-app" type="button" hidden>App öffnen und koppeln</button>
        <a class="secondary" id="open-store" href="#" rel="noreferrer" hidden>App installieren</a>
    </div>
    <p class="hint" id="hint">Der Einmalcode ist zehn Minuten gültig. CRM-Passwort und Kontaktdaten sind nicht im QR-Code enthalten.</p>
</main>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
(() => {
    'use strict';

    const androidStore = <?= json_encode($androidStoreUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const iosStore = <?= json_encode($iosStoreUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const status = document.getElementById('status');
    const openApp = document.getElementById('open-app');
    const openStore = document.getElementById('open-store');
    const hint = document.getElementById('hint');
    const userAgent = navigator.userAgent || '';
    const isAndroid = /Android/i.test(userAgent);
    const isIOS = /iPhone|iPad|iPod/i.test(userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let redirectTimer = null;

    const fail = (message) => {
        status.textContent = message;
        status.classList.add('error');
        openApp.hidden = true;
        openStore.hidden = true;
    };

    const decodeSetup = () => {
        const parameters = new URLSearchParams(location.hash.replace(/^#/, ''));
        const encoded = parameters.get('setup') || '';
        if (!/^[A-Za-z0-9_-]{40,2048}$/.test(encoded)) {
            throw new Error('Der Kopplungscode fehlt oder ist unvollständig.');
        }
        const padded = encoded.replace(/-/g, '+').replace(/_/g, '/')
            + '='.repeat((4 - encoded.length % 4) % 4);
        const bytes = Uint8Array.from(atob(padded), character => character.charCodeAt(0));
        const deepLink = new TextDecoder().decode(bytes);
        const parsed = new URL(deepLink);
        if (parsed.protocol !== 'speedphone:' || parsed.hostname !== 'pair') {
            throw new Error('Dieser QR-Code gehört nicht zu CRM SpeedPhone.');
        }
        return deepLink;
    };

    const openInstalledApp = (deepLink) => {
        if (redirectTimer !== null) {
            clearTimeout(redirectTimer);
        }
        location.href = deepLink;
    };

    try {
        const deepLink = decodeSetup();
        const storeUrl = isAndroid ? androidStore : (isIOS ? iosStore : '');
        openApp.hidden = false;
        openApp.addEventListener('click', () => openInstalledApp(deepLink));

        if (storeUrl) {
            openStore.href = storeUrl;
            openStore.hidden = false;
            openStore.textContent = isIOS ? 'Im App Store installieren' : 'Bei Google Play installieren';
        }

        if (isAndroid || isIOS) {
            status.textContent = 'SpeedPhone wird geöffnet. Falls die App noch fehlt, öffnet sich gleich der Store.';
            openInstalledApp(deepLink);
            if (storeUrl) {
                redirectTimer = window.setTimeout(() => location.replace(storeUrl), 1100);
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden && redirectTimer !== null) {
                        clearTimeout(redirectTimer);
                        redirectTimer = null;
                    }
                }, {once: true});
            } else {
                status.textContent = 'Die App ist für dieses Gerät noch nicht im Store veröffentlicht. Eine bereits installierte App kann direkt gekoppelt werden.';
                hint.textContent = 'Für iOS muss zusätzlich die App-Store-Adresse in der SpeedPhone-Konfiguration hinterlegt werden.';
            }
        } else {
            status.textContent = 'Scannen Sie diesen QR-Code mit einem Android-Smartphone oder iPhone.';
        }
    } catch (error) {
        fail(error instanceof Error ? error.message : String(error));
    }
})();
</script>
</body>
</html>
