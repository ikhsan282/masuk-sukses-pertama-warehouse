<#
.SYNOPSIS
    Automated daily low-stock email alert runner for Windows / XAMPP.
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File cron/low_stock_alert.ps1 -AdminEmail admin@mspgrup.co.id
#>
[CmdletBinding()]
param(
    [string]$AdminEmail = "",
    [string]$PhpPath = "",
    [string]$LogFile = ""
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$alertScript = Join-Path $repoRoot "includes\low_stock_alerts.php"
if (-not (Test-Path $alertScript)) {
    Write-Error "Script low_stock_alerts.php tidak ditemukan: $alertScript"
    exit 1
}

if ([string]::IsNullOrWhiteSpace($AdminEmail)) {
    $envFile = Join-Path $repoRoot ".env"
    if (Test-Path $envFile) {
        Get-Content -Path $envFile -Encoding UTF8 | ForEach-Object {
            if ($_ -match '^\s*(?:LOW_STOCK_ALERT_EMAIL|ADMIN_EMAIL)\s*=\s*"?([^"#]+)"?\s*$') {
                if ([string]::IsNullOrWhiteSpace($AdminEmail)) {
                    $AdminEmail = $Matches[1].Trim()
                }
            }
        }
    }
    if ([string]::IsNullOrWhiteSpace($AdminEmail) -and -not [string]::IsNullOrWhiteSpace($env:LOW_STOCK_ALERT_EMAIL)) {
        $AdminEmail = $env:LOW_STOCK_ALERT_EMAIL.Trim()
    }
    if ([string]::IsNullOrWhiteSpace($AdminEmail)) {
        $AdminEmail = "admin@example.com"
    }
}

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $xamppPhp = "C:\xampp\php\php.exe"
    if (Test-Path $xamppPhp) {
        $PhpPath = $xamppPhp
    } else {
        $cmd = Get-Command php -ErrorAction SilentlyContinue
        if ($cmd) { $PhpPath = $cmd.Source }
    }
}

if ([string]::IsNullOrWhiteSpace($PhpPath) -or -not (Test-Path $PhpPath)) {
    Write-Error "php.exe tidak ditemukan. Pastikan PHP/XAMPP terpasang atau tentukan -PhpPath."
    exit 1
}

if ([string]::IsNullOrWhiteSpace($LogFile)) {
    $logDir = Join-Path $repoRoot "logs"
    if (-not (Test-Path $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    $LogFile = Join-Path $logDir "low_stock_alerts.log"
}

$output = & $PhpPath $alertScript $AdminEmail 2>&1
$exitCode = $LASTEXITCODE
$stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$lines = @("[$stamp] Email=$AdminEmail ExitCode=$exitCode")
foreach ($line in $output) {
    $lines += "[$stamp] $line"
    Write-Host $line
}
Add-Content -Path $LogFile -Value $lines -Encoding UTF8

exit $exitCode
