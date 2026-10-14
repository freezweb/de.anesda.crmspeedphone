<?php

/** CLI-Arbeiter für die dauerhaft gespeicherten Telegram-Anrufmeldungen. */
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--check', '--run'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Aufruf: php telegram_worker.php --check|--run\n");
    exit(2);
}

$legacyRoot = dirname(__DIR__, 3);
chdir($legacyRoot);
$_GET = [];
$_POST = [];
$_REQUEST = [];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = 'index.php';
unset($_SERVER['argv'], $_SERVER['argc']);
define('sugarEntry', true);
require $legacyRoot . '/include/entryPoint.php';
require_once __DIR__ . '/bootstrap.php';

$config = \Anesda\CRM\SpeedPhone\Config::load(__DIR__);
$service = new \Anesda\CRM\SpeedPhone\TelegramCallService($config, $db,
    (string) ($sugar_config['site_url'] ?? ''));
if ($argv[1] === '--check') {
    if (!(bool) $config->get('telegram_call_enabled', false)
        || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', (string) $config->get('telegram_call_bot_token', ''))
        || (int) $config->get('telegram_call_chat_id', 0) >= 0
        || (int) $config->get('telegram_call_thread_id', 0) <= 0) {
        throw new RuntimeException('Die Telegram-Anrufkonfiguration ist nicht bereit.');
    }
    $exists = (int) $db->getOne("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='crm_speedphone_telegram_calls'");
    if ($exists !== 1) {
        throw new RuntimeException('Die Telegram-Anruftabelle fehlt.');
    }
    echo "Telegram-Anrufmeldungen bereit.\n";
    exit(0);
}

$result = $service->deliverPending();
echo sprintf("Telegram-Anrufmeldungen: %d gesendet, %d fehlgeschlagen.\n", $result['sent'], $result['failed']);
exit($result['failed'] === 0 ? 0 : 1);
