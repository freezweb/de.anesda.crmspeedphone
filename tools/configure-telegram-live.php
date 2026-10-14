<?php

/** Einmalige lokale Einrichtung des Anesda-Bots; erwartet das Geheimnis ausschließlich über STDIN. */
if (PHP_SAPI !== 'cli' || count($argv) !== 1 || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Nur als root ohne Argumente auf dem CRM-Server ausführbar.\n");
    exit(2);
}

$base = '/srv/www/vhosts/crm.anesda.de/public/legacy/custom/CRM/SpeedPhone';
$target = $base . '/telegram.local.php';
$cronTarget = '/etc/cron.d/crm-speedphone-telegram';
if (!is_dir($base) || !is_file($base . '/telegram_worker.php') || is_file($target) || is_file($cronTarget)) {
    throw new RuntimeException('Zielpfad fehlt oder die Telegram-Einrichtung besteht bereits.');
}
$json = stream_get_contents(STDIN, 4097);
if (!is_string($json) || strlen($json) > 4096) {
    throw new RuntimeException('Die Eingabe ist ungültig.');
}
$input = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
$token = (string) ($input['bot_token'] ?? '');
$chatId = filter_var($input['chat_id'] ?? null, FILTER_VALIDATE_INT);
$threadId = filter_var($input['thread_id'] ?? null, FILTER_VALIDATE_INT);
if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token)
    || $chatId === false || $chatId >= 0 || $threadId === false || $threadId <= 0) {
    throw new RuntimeException('Bot-Token oder Telegram-Ziel ist ungültig.');
}

$config = [
    'telegram_call_enabled' => true,
    'telegram_call_bot_token' => $token,
    'telegram_call_chat_id' => $chatId,
    'telegram_call_thread_id' => $threadId,
];
$source = "<?php\n\nreturn " . var_export($config, true) . ";\n";
$cron = "MAILTO=\"\"\n* * * * * www-data /usr/bin/php {$base}/telegram_worker.php --run 2>&1 | /usr/bin/logger -t crm-speedphone-telegram\n";
$configTemp = tempnam($base, '.telegram-config-');
$cronTemp = tempnam('/etc/cron.d', '.crm-speedphone-');
if ($configTemp === false || $cronTemp === false) {
    throw new RuntimeException('Temporäre Konfigurationsdateien konnten nicht angelegt werden.');
}
try {
    if (file_put_contents($configTemp, $source, LOCK_EX) !== strlen($source)
        || !chgrp($configTemp, 'www-data') || !chmod($configTemp, 0640)
        || file_put_contents($cronTemp, $cron, LOCK_EX) !== strlen($cron)
        || !chmod($cronTemp, 0644)) {
        throw new RuntimeException('Telegram-Konfiguration konnte nicht sicher gespeichert werden.');
    }
    if (!rename($configTemp, $target)) {
        throw new RuntimeException('Telegram-Konfiguration konnte nicht aktiviert werden.');
    }
    if (!rename($cronTemp, $cronTarget)) {
        unlink($target);
        throw new RuntimeException('Telegram-Zeitplan konnte nicht aktiviert werden.');
    }
} finally {
    if (is_file($configTemp)) {
        unlink($configTemp);
    }
    if (is_file($cronTemp)) {
        unlink($cronTemp);
    }
}
echo "Telegram-Anrufmeldungen sind für den eigenen Thread eingerichtet.\n";
