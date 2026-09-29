<?php

/** Plattformunabhängiges SuiteCRM-Modulpaket für Jenkins und lokale PHP-Aufrufe. */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Der Paketbau darf nur über die Kommandozeile laufen.');
}
// PharData kann ZIP-Dateien auch ohne die optionale ZIP-Erweiterung erzeugen.
$useZip = class_exists(ZipArchive::class) && !in_array('--phar', $argv, true);
if (!$useZip && !class_exists(PharData::class)) {
    throw new RuntimeException('Für den Paketbau wird ZipArchive oder PharData benötigt.');
}
$projectRoot = dirname(__DIR__);
$moduleRoot = $projectRoot . '/module';
require $moduleRoot . '/manifest.php';
$version = (string) ($manifest['version'] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    throw new RuntimeException('Ungültige Modulversion im Manifest.');
}
$distRoot = $projectRoot . '/dist';
if (!is_dir($distRoot) && !mkdir($distRoot, 0775, true) && !is_dir($distRoot)) {
    throw new RuntimeException('Das Paketverzeichnis konnte nicht angelegt werden.');
}
$zipPath = $distRoot . '/de.anesda.crmspeedphone-' . $version . '.zip';
if ($useZip) {
    $archive = new ZipArchive();
    if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Das Modulpaket konnte nicht angelegt werden.');
    }
} else {
    // Es wird ausschließlich das aus der geprüften Version abgeleitete Paket ersetzt.
    if (is_file($zipPath) && !unlink($zipPath)) {
        throw new RuntimeException('Das bisherige Modulpaket konnte nicht ersetzt werden.');
    }
    $archive = new PharData($zipPath, 0, null, Phar::ZIP);
}
try {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || str_ends_with($file->getFilename(), '.local.php')) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleRoot) + 1));
        $added = $archive->addFile($file->getPathname(), $relative);
        if ($useZip && !$added) {
            throw new RuntimeException('Datei konnte nicht gepackt werden: ' . $relative);
        }
    }
} finally {
    if ($useZip && !$archive->close()) {
        throw new RuntimeException('Das Modulpaket konnte nicht vollständig geschrieben werden.');
    }
    unset($archive);
}
$names = [];
if ($useZip) {
    $archive = new ZipArchive();
    if ($archive->open($zipPath) !== true) {
        throw new RuntimeException('Das fertige Paket konnte nicht geprüft werden.');
    }
    try {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $names[] = $archive->getNameIndex($index);
        }
    } finally {
        $archive->close();
    }
} else {
    $archive = new PharData($zipPath);
    $prefix = 'phar://' . str_replace('\\', '/', $zipPath) . '/';
    foreach (new RecursiveIteratorIterator($archive) as $file) {
        $names[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($prefix));
    }
    unset($archive);
}
if (!in_array('manifest.php', $names, true) || !in_array('copy/custom/CRM/SpeedPhone/bootstrap.php', $names, true)) {
    throw new RuntimeException('Manifest oder SpeedPhone-Dateien fehlen im Paket.');
}
foreach ($names as $name) {
    if (str_ends_with($name, '.local.php') || str_contains($name, '\\') || str_contains($name, '../')) {
        throw new RuntimeException('Unzulässige Datei im Modulpaket: ' . $name);
    }
}
echo 'Paket erstellt: ' . $zipPath . "\n";
