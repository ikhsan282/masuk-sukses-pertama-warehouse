<#
.SYNOPSIS
    Automated daily MySQL backup with ZIP compression and 14-day retention for Windows / XAMPP.
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File cron/backup_db.ps1
#>
[CmdletBinding()]
param(
    [int]$RetentionDays = 14,
    [string]$BackupDir = "",
    [string]$MysqldumpPath = ""
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$dbConfigFile = Join-Path $repoRoot "config\database.php"
if (-not (Test-Path $dbConfigFile)) {
    Write-Error "Config database tidak ditemukan: $dbConfigFile"
    exit 1
}

if ([string]::IsNullOrWhiteSpace($BackupDir)) {
    $BackupDir = Join-Path $repoRoot "backups"
}
if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
}

function Get-PhpDefine {
    param([string]$Content, [string]$Name, [string]$Default = "")
    $pattern = "define\(\s*'$([regex]::Escape($Name))'\s*,\s*(?:'([^']*)'|(\d+))\s*\)"
    $m = [regex]::Match($Content, $pattern)
    if ($m.Success) {
        if ($m.Groups[1].Success) { return $m.Groups[1].Value }
        if ($m.Groups[2].Success) { return $m.Groups[2].Value }
    }
    return $Default
}

$cfgText = Get-Content -Path $dbConfigFile -Raw -Encoding UTF8
$dbHost = Get-PhpDefine $cfgText "DB_HOST" "localhost"
$dbUser = Get-PhpDefine $cfgText "DB_USER" "root"
$dbPass = Get-PhpDefine $cfgText "DB_PASS" ""
$dbName = Get-PhpDefine $cfgText "DB_NAME" "db_msp_warehouse"
$dbPort = Get-PhpDefine $cfgText "DB_PORT" "3306"

if ([string]::IsNullOrWhiteSpace($MysqldumpPath)) {
    $xamppDump = "C:\xampp\mysql\bin\mysqldump.exe"
    if (Test-Path $xamppDump) {
        $MysqldumpPath = $xamppDump
    } else {
        $cmd = Get-Command mysqldump -ErrorAction SilentlyContinue
        if ($cmd) { $MysqldumpPath = $cmd.Source }
    }
}

if ([string]::IsNullOrWhiteSpace($MysqldumpPath) -or -not (Test-Path $MysqldumpPath)) {
    Write-Error "mysqldump tidak ditemukan. Pastikan MySQL/XAMPP terpasang atau tentukan -MysqldumpPath."
    exit 1
}

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$baseName = "${dbName}_${timestamp}"
$sqlFile = Join-Path $BackupDir "${baseName}.sql"
$zipFile = Join-Path $BackupDir "${baseName}.zip"

$prevPwd = $env:MYSQL_PWD
try {
    if (-not [string]::IsNullOrEmpty($dbPass)) {
        $env:MYSQL_PWD = $dbPass
    } else {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }

    $args = @(
        "--host=$dbHost",
        "--port=$dbPort",
        "--user=$dbUser",
        "--single-transaction",
        "--routines",
        "--triggers",
        "--hex-blob",
        "--default-character-set=utf8mb4",
        "--result-file=$sqlFile",
        $dbName
    )

    & $MysqldumpPath @args
    if ($LASTEXITCODE -ne 0) {
        if (Test-Path $sqlFile) { Remove-Item $sqlFile -Force -ErrorAction SilentlyContinue }
        Write-Error "mysqldump gagal dengan exit code $LASTEXITCODE."
        exit $LASTEXITCODE
    }

    if (-not (Test-Path $sqlFile) -or (Get-Item $sqlFile).Length -le 0) {
        if (Test-Path $sqlFile) { Remove-Item $sqlFile -Force -ErrorAction SilentlyContinue }
        Write-Error "File backup SQL kosong atau gagal dibuat."
        exit 1
    }

    Compress-Archive -Path $sqlFile -DestinationPath $zipFile -CompressionLevel Optimal -Force
    Remove-Item $sqlFile -Force

    if (-not (Test-Path $zipFile) -or (Get-Item $zipFile).Length -le 0) {
        Write-Error "File arsip ZIP backup kosong atau gagal dibuat."
        exit 1
    }

    $cutoff = (Get-Date).AddDays(-$RetentionDays)
    $pruned = 0
    Get-ChildItem -Path $BackupDir -File | Where-Object {
        ($_.Extension -in @('.zip', '.sql', '.gz')) -and ($_.LastWriteTime -lt $cutoff)
    } | ForEach-Object {
        Remove-Item $_.FullName -Force
        $pruned++
    }

    $zipSizeKB = [math]::Round((Get-Item $zipFile).Length / 1KB, 2)
    Write-Output "[OK] Backup berhasil: $zipFile ($zipSizeKB KB) | Retention: $RetentionDays hari | Pruned: $pruned file lama"
    exit 0
}
finally {
    if ($null -ne $prevPwd) {
        $env:MYSQL_PWD = $prevPwd
    } else {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}
