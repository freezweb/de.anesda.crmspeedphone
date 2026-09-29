<?php

/** Plattformunabhängiges SuiteCRM-Modulpaket für Jenkins und lokale PHP-Aufrufe. */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Der Paketbau darf nur über die Kommandozeile laufen.');
}
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Für den Paketbau wird die PHP-ZIP-Erweiterung benötigt.');
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
$archive = new ZipArchive();
if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Das Modulpaket konnte nicht angelegt werden.');
}
try {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || str_ends_with($file->getFilename(), '.local.php')) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleRoot) + 1));
        if (!$archive->addFile($file->getPathname(), $relative)) {
            throw new RuntimeException('Datei konnte nicht gepackt werden: ' . $relative);
        }
    }
} finally {
    if (!$archive->close()) {
        throw new RuntimeException('Das Modulpaket konnte nicht vollständig geschrieben werden.');
    }
}
$archive = new ZipArchive();
if ($archive->open($zipPath) !== true) {
    throw new RuntimeException('Das fertige Paket konnte nicht geprüft werden.');
}
try {
    if ($archive->locateName('manifest.php') === false || $archive->locateName('copy/custom/CRM/SpeedPhone/bootstrap.php') === false) {
        throw new RuntimeException('Manifest oder SpeedPhone-Dateien fehlen im Paket.');
    }
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);
        if (str_ends_with($name, '.local.php') || str_contains($name, '\\') || str_contains($name, '../')) {
            throw new RuntimeException('Unzulässige Datei im Modulpaket: ' . $name);
        }
    }
} finally {
    $archive->close();
}
echo 'Paket erstellt: ' . $zipPath . "\n";
