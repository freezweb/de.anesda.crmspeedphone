$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$moduleRoot = Join-Path $projectRoot 'module'
$distRoot = Join-Path $projectRoot 'dist'
$buildRoot = Join-Path $projectRoot 'build\package'
$manifestPath = Join-Path $moduleRoot 'manifest.php'
$version = (& php -r "include '$($manifestPath.Replace('\', '/'))'; echo `$manifest['version'];").Trim()
if ($version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Ungültige Modulversion im Manifest: $version"
}
$zipPath = Join-Path $distRoot "de.anesda.crmspeedphone-$version.zip"

if (Test-Path $buildRoot) {
    Remove-Item -LiteralPath $buildRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $buildRoot -Force | Out-Null
New-Item -ItemType Directory -Path $distRoot -Force | Out-Null

Copy-Item -Path (Join-Path $moduleRoot '*') -Destination $buildRoot -Recurse -Force
$localConfig = Join-Path $buildRoot 'copy\custom\CRM\SpeedPhone\config.local.php'
if (Test-Path $localConfig) {
    Remove-Item -LiteralPath $localConfig -Force
}

if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$outputArchive = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)
try {
    Get-ChildItem -LiteralPath $buildRoot -Recurse -File | ForEach-Object {
        $relativePath = $_.FullName.Substring($buildRoot.Length + 1).Replace('\', '/')
        $entry = $outputArchive.CreateEntry(
            $relativePath,
            [System.IO.Compression.CompressionLevel]::Optimal
        )
        $sourceStream = [System.IO.File]::OpenRead($_.FullName)
        $targetStream = $entry.Open()
        try {
            $sourceStream.CopyTo($targetStream)
        }
        finally {
            $targetStream.Dispose()
            $sourceStream.Dispose()
        }
    }
}
finally {
    $outputArchive.Dispose()
}

$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = $archive.Entries.FullName
    if ($names -notcontains 'manifest.php') {
        throw 'Paketprüfung fehlgeschlagen: manifest.php liegt nicht im ZIP-Stamm.'
    }
    if (-not ($names | Where-Object { $_ -like 'copy/custom/CRM/SpeedPhone/*' })) {
        throw 'Paketprüfung fehlgeschlagen: SpeedPhone-Dateien fehlen.'
    }
}
finally {
    $archive.Dispose()
}

Write-Host "Paket erstellt: $zipPath"
