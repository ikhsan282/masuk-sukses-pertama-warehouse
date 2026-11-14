<?php
/**
 * Audit Log & Security Token Retention CLI
 *
 * Usage:
 *   php cron/cleanup_audit_logs.php --dry-run
 *   php cron/cleanup_audit_logs.php --days=365 --archive --batch=5000
 *   php cron/cleanup_audit_logs.php --days=180 --json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI access only.\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$args    = array_slice($argv ?? [], 1);
$dryRun  = in_array('--dry-run', $args, true);
$archive = in_array('--archive', $args, true);
$asJson  = in_array('--json', $args, true);

$days = 365;
$batch = 5000;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(30, min(3650, (int)substr($arg, 7)));
    } elseif (str_starts_with($arg, '--batch=')) {
        $batch = max(100, min(50000, (int)substr($arg, 8)));
    }
}

$db = getDB();
$cutoffAudit  = date('Y-m-d H:i:s', time() - ($days * 86400));
$cutoffAttempts = date('Y-m-d H:i:s', time() - (7 * 86400));

function cleanup_count_older(mysqli $db, string $table, string $col, string $cutoff): int {
    $st = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` < ?");
    if (!$st) return 0;
    $st->bind_param('s', $cutoff);
    $st->execute();
    $st->bind_result($cnt);
    $st->fetch();
    $st->close();
    return (int)$cnt;
}

function cleanup_count_now_expired(mysqli $db, string $table, string $col): int {
    $res = $db->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` < NOW()");
    return $res ? (int)$res->fetch_row()[0] : 0;
}

$eligibleAudit    = cleanup_count_older($db, 'audit_logs', 'created_at', $cutoffAudit);
$eligibleTokens   = cleanup_count_now_expired($db, 'user_remember_tokens', 'expires_at');
$eligibleAttempts = cleanup_count_older($db, 'login_attempts', 'attempted_at', $cutoffAttempts);

$deletedAudit    = 0;
$deletedTokens   = 0;
$deletedAttempts = 0;
$archiveFileRel  = null;

if (!$dryRun) {
    // 1. Archive & purge eligible audit_logs in bounded chunks
    if ($eligibleAudit > 0) {
        $gzHandle  = null;
        $csvHandle = null;
        $memCsv    = null;

        if ($archive) {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $stamp = date('Ymd_His');
            $useGz = function_exists('gzopen');
            $archiveFileRel = 'logs/audit_archive_' . $stamp . ($useGz ? '.csv.gz' : '.csv');
            $archivePath    = __DIR__ . '/../' . $archiveFileRel;
            $memCsv         = fopen('php://temp', 'r+');
            $writeArchiveRow = static function (array $row) use ($useGz, &$gzHandle, &$csvHandle, $archivePath, $memCsv): void {
                rewind($memCsv);
                ftruncate($memCsv, 0);
                fputcsv($memCsv, $row, ',', '"', '\\');
                rewind($memCsv);
                $line = stream_get_contents($memCsv);
                if ($useGz) {
                    if ($gzHandle === null) {
                        $gzHandle = gzopen($archivePath, 'wb6');
                    }
                    gzwrite($gzHandle, $line);
                } else {
                    if ($csvHandle === null) {
                        $csvHandle = fopen($archivePath, 'wb');
                    }
                    fwrite($csvHandle, $line);
                }
            };
            $writeArchiveRow(['id', 'company_id', 'user_id', 'username', 'action', 'entity_type', 'entity_id', 'reference_no', 'description', 'ip_address', 'created_at']);
        }

        $lastId = 0;
        while (true) {
            $sel = $db->prepare('SELECT id, company_id, user_id, username, action, entity_type, entity_id, reference_no, description, ip_address, created_at
                FROM audit_logs
                WHERE created_at < ? AND id > ?
                ORDER BY id ASC
                LIMIT ?');
            $sel->bind_param('sii', $cutoffAudit, $lastId, $batch);
            $sel->execute();
            $chunk = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
            $sel->close();

            if (empty($chunk)) {
                break;
            }

            $ids = [];
            foreach ($chunk as $r) {
                $idVal = (int)$r['id'];
                $lastId = $idVal;
                $ids[] = $idVal;
                if ($archive && isset($writeArchiveRow)) {
                    $writeArchiveRow(csv_safe_row([
                        $idVal,
                        $r['company_id'],
                        $r['user_id'],
                        $r['username'],
                        $r['action'],
                        $r['entity_type'],
                        $r['entity_id'],
                        $r['reference_no'],
                        $r['description'],
                        $r['ip_address'],
                        $r['created_at'],
                    ]));
                }
            }

            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $del = $db->prepare("DELETE FROM audit_logs WHERE id IN ($ph)");
            $del->bind_param(str_repeat('i', count($ids)), ...$ids);
            $del->execute();
            $deletedAudit += max(0, $del->affected_rows);
            $del->close();

            if (count($chunk) < $batch) {
                break;
            }
        }

        if ($gzHandle !== null) gzclose($gzHandle);
        if ($csvHandle !== null) fclose($csvHandle);
        if ($memCsv !== null) fclose($memCsv);
    }

    // 2. Purge expired remember-me tokens
    if ($eligibleTokens > 0) {
        while (true) {
            $delTok = $db->prepare('DELETE FROM user_remember_tokens WHERE expires_at < NOW() LIMIT ?');
            $delTok->bind_param('i', $batch);
            $delTok->execute();
            $aff = max(0, $delTok->affected_rows);
            $delTok->close();
            $deletedTokens += $aff;
            if ($aff < $batch) break;
        }
    }

    // 3. Purge stale login_attempts (> 7 days)
    if ($eligibleAttempts > 0) {
        while (true) {
            $delAtt = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ? LIMIT ?');
            $delAtt->bind_param('si', $cutoffAttempts, $batch);
            $delAtt->execute();
            $aff = max(0, $delAtt->affected_rows);
            $delAtt->close();
            $deletedAttempts += $aff;
            if ($aff < $batch) break;
        }
    }
}

$summary = [
    'dry_run'            => $dryRun,
    'retention_days'     => $days,
    'cutoff_audit'       => $cutoffAudit,
    'eligible_audit'     => $eligibleAudit,
    'deleted_audit'      => $deletedAudit,
    'eligible_tokens'    => $eligibleTokens,
    'deleted_tokens'     => $deletedTokens,
    'eligible_attempts'  => $eligibleAttempts,
    'deleted_attempts'   => $deletedAttempts,
    'archive_file'       => $archiveFileRel,
];

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "=== MSP Warehouse Log Retention ===\n";
    echo 'Mode      : ' . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . " (retensi >= {$days} hari, cutoff {$cutoffAudit})\n";
    echo "Audit Log : {$eligibleAudit} memenuhi syarat" . ($dryRun ? '' : ", {$deletedAudit} dihapus") . ($archiveFileRel ? " (arsip: {$archiveFileRel})" : '') . "\n";
    echo "Tokens    : {$eligibleTokens} kedaluwarsa" . ($dryRun ? '' : ", {$deletedTokens} dihapus") . "\n";
    echo "BruteLog  : {$eligibleAttempts} usang (>7 hari)" . ($dryRun ? '' : ", {$deletedAttempts} dihapus") . "\n";
}
exit(0);
