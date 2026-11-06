#!/bin/bash
# Low Stock Alert Cron Job — jalankan harian 08:00
# crontab: 0 8 * * * /path/ke/masuk-sukses-pertama-warehouse/cron/low_stock_alert.sh
#
# PHP: pakai $MW_PHP bila di-set (dev), kalau tidak jatuh ke `php` dari PATH (cPanel).
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_BIN=${MW_PHP:-php}
RECIPIENT=${MW_ALERT_TO:-admin@localhost}

# shellcheck disable=SC2086
$PHP_BIN includes/low_stock_alerts.php "$RECIPIENT" >> logs/low_stock_alerts.log 2>&1
