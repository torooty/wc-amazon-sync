# Universal install ZIP for wc-amazon-sync (Windows PowerShell).
# Paths use forward slashes (/) so Linux servers unpack directories correctly.
#
# Output (in wp-content/plugins/):
#   1) wc-amazon-sync-wp.zip  — WordPress: Plugins -> Add New -> Upload Plugin
#   2) wc-amazon-sync-ftp.zip — FTP/SFTP: create folder wc-amazon-sync, unpack inside
#
# Linux/macOS/Git Bash: ./create-install-archive.sh

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$pluginDir = $PSScriptRoot
$parentDir = Split-Path $pluginDir -Parent
$folderName = "wc-amazon-sync"

$excludeNames = @(
    "create-install-archive.ps1",
    "create-install-archive.sh",
    "wc-amazon-sync-wp.zip",
    "wc-amazon-sync-ftp.zip",
    "wc-amazon-sync.zip",
    "test22.txt"
)

function Test-ExcludedRelativePath {
    param([string]$RelativePath)
    $parts = $RelativePath -split '[\\/]'
    if ($parts[0] -in $excludeNames) { return $true }
    if ($parts -contains ".git") { return $true }
    if ($parts -contains ".idea") { return $true }
    if ($parts -contains ".vscode") { return $true }
    if ($parts -contains "node_modules") { return $true }
    if ($parts -contains "vendor") { return $true }
    $leaf = $parts[-1]
    if ($leaf -in @(".DS_Store", "Thumbs.db", "desktop.ini", ".env")) { return $true }
    if ($leaf -like ".env.*") { return $true }
    if ($leaf -like "*.log") { return $true }
    if ($leaf -like "*.zip") { return $true }
    if ($leaf -like "*.pem" -or $leaf -like "*.key") { return $true }
    return $false
}

$pluginFiles = Get-ChildItem -Path $pluginDir -Recurse -Force -File | Where-Object {
    $rel = $_.FullName.Substring($pluginDir.Length + 1)
    -not (Test-ExcludedRelativePath $rel)
}

$pluginDirs = Get-ChildItem -Path $pluginDir -Recurse -Force -Directory | Where-Object {
    $rel = $_.FullName.Substring($pluginDir.Length + 1)
    -not (Test-ExcludedRelativePath $rel)
}

$tempZipDir = Join-Path $env:TEMP "wc-amazon-sync-zip-out"
if (-not (Test-Path $tempZipDir)) {
    New-Item -ItemType Directory -Path $tempZipDir -Force | Out-Null
}

# --- 1) WordPress ZIP: single root folder wc-amazon-sync/
$zipPath1 = Join-Path $tempZipDir "$folderName-wp.zip"
if (Test-Path $zipPath1) { Remove-Item $zipPath1 -Force -ErrorAction SilentlyContinue }
$zip1 = [System.IO.Compression.ZipFile]::Open($zipPath1, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    [void]$zip1.CreateEntry("$folderName/")
    $seenDirs = @{}
    foreach ($d in $pluginDirs) {
        $rel = $d.FullName.Substring($pluginDir.Length + 1)
        $entryName = "$folderName/" + $rel.Replace("\", "/") + "/"
        if (-not $seenDirs[$entryName]) {
            $seenDirs[$entryName] = $true
            [void]$zip1.CreateEntry($entryName)
        }
    }
    foreach ($f in $pluginFiles) {
        $rel = $f.FullName.Substring($pluginDir.Length + 1)
        $entryName = "$folderName/" + $rel.Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip1, $f.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $zip1.Dispose()
}

# --- 2) FTP ZIP: plugin contents without outer folder
$zipPath2 = Join-Path $tempZipDir "$folderName-ftp.zip"
if (Test-Path $zipPath2) { Remove-Item $zipPath2 -Force -ErrorAction SilentlyContinue }
$zip2 = [System.IO.Compression.ZipFile]::Open($zipPath2, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $seenDirs = @{}
    foreach ($d in $pluginDirs) {
        $rel = $d.FullName.Substring($pluginDir.Length + 1)
        $entryName = $rel.Replace("\", "/") + "/"
        if (-not $seenDirs[$entryName]) {
            $seenDirs[$entryName] = $true
            [void]$zip2.CreateEntry($entryName)
        }
    }
    foreach ($f in $pluginFiles) {
        $rel = $f.FullName.Substring($pluginDir.Length + 1)
        $entryName = $rel.Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip2, $f.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $zip2.Dispose()
}

$dest1 = Join-Path $parentDir "$folderName-wp.zip"
$dest2 = Join-Path $parentDir "$folderName-ftp.zip"
Copy-Item $zipPath1 $dest1 -Force -ErrorAction SilentlyContinue
Copy-Item $zipPath2 $dest2 -Force -ErrorAction SilentlyContinue

if (Test-Path $dest1) {
    Write-Host "  1) $dest1" -ForegroundColor Green
} else {
    Write-Host "  1) $zipPath1 (TEMP)" -ForegroundColor Yellow
}
if (Test-Path $dest2) {
    Write-Host "  2) $dest2" -ForegroundColor Green
} else {
    Write-Host "  2) $zipPath2 (TEMP)" -ForegroundColor Yellow
}

Write-Host "ZIP with Linux paths (/) ready. WP upload or FTP unpack into wc-amazon-sync/." -ForegroundColor Cyan
