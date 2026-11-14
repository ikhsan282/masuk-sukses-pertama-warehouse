<?php
/**
 * Multi-Role Live HTTP + DB UAT Workflow Suite
 *
 * Verifies:
 *   Part A: Multi-role RBAC & PT isolation across all 4 seeded roles
 *           (Viewer, Staff Gudang, Admin, Super Admin) over real Apache HTTP sessions.
 *   Part B: End-to-end operational workflow via live HTTP CSRF forms + MySQL verification:
 *           switch-company -> PO draft -> submit -> approve -> partial receive #1 & #2
 *           -> supplier return -> inter-PT transfer (MSP -> SLN) + search filter
 *           -> stock opname create -> count -> finalize -> CSV & Print export.
 *   Cleanup: Snapshots pre-test stock/IDs and restores DB identically in finally.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI access only.\n");
}

if (!extension_loaded('mysqli') || !extension_loaded('curl')) {
    fwrite(STDERR, "ERROR: Ekstensi PHP mysqli & curl dibutuhkan.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';

$baseUrl = rtrim(getenv('UAT_BASE_URL') ?: 'http://localhost:8080/masuk-sukses-pertama-warehouse', '/');
$defaultPass = 'P@ssw0rd';

$pass = 0;
$fail = 0;

function uat_assert(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

function uat_has_php_error(string $body): bool
{
    return (bool)preg_match('/(Fatal error|Parse error|Warning:|Notice:|Uncaught\s+\w*Exception|ValueError)/i', $body);
}

function uat_http(string $url, ?array $postData, string $cookieFile, bool $follow = false, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ];
    if ($extraHeaders) {
        $opts[CURLOPT_HTTPHEADER] = $extraHeaders;
    }
    if ($postData !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postData);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'location' => '', 'body' => '', 'error' => $err];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }
    return ['code' => $code, 'location' => $location, 'body' => $body, 'error' => ''];
}

function uat_extract_csrf(string $html): ?string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function uat_switch_company(string $baseUrl, int $companyId, string $cookieFile, bool $follow = false, string $seedPage = '/pages/dashboard.php', ?string $redirect = null): array
{
    $seed = uat_http($baseUrl . $seedPage, null, $cookieFile, false);
    $csrf = uat_extract_csrf($seed['body']) ?? '';
    $payload = [
        'csrf_token' => $csrf,
        'company_id' => $companyId,
    ];
    if ($redirect !== null) {
        $payload['redirect'] = $redirect;
    }
    return uat_http($baseUrl . '/auth/switch-company.php', $payload, $cookieFile, $follow);
}

function uat_login(string $baseUrl, string $username, string $password, string $cookieFile): array
{
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
    $get = uat_http($baseUrl . '/auth/login.php', null, $cookieFile, false);
    if ($get['code'] !== 200) {
        return ['ok' => false, 'code' => $get['code'], 'location' => '', 'reason' => "GET login HTTP {$get['code']} {$get['error']}"];
    }
    $csrf = uat_extract_csrf($get['body']);
    if (!$csrf) {
        return ['ok' => false, 'code' => 0, 'location' => '', 'reason' => 'CSRF token tidak ditemukan pada form login'];
    }
    $post = uat_http($baseUrl . '/auth/login.php', [
        'csrf_token'    => $csrf,
        'username' => $username,
        'password' => $password,
    ], $cookieFile, false);

    $ok = $post['code'] === 302 && $post['location'] !== '' && !str_contains($post['location'], '/auth/login.php');
    return ['ok' => $ok, 'code' => $post['code'], 'location' => $post['location'], 'reason' => $ok ? '' : "POST login HTTP {$post['code']} loc={$post['location']}"];
}

function uat_get_stock(mysqli $db, int $companyId, int $itemId, int $locationId): int
{
    $st = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock WHERE company_id = ? AND item_id = ? AND location_id = ?');
    $st->bind_param('iii', $companyId, $itemId, $locationId);
    $st->execute();
    $st->bind_result($qty);
    $st->fetch();
    $st->close();
    return (int)$qty;
}

echo "=== MSP Warehouse Multi-Role UAT & End-to-End Workflow Suite ===\n";
echo "Target URL : {$baseUrl}\n\n";

$probe = uat_http($baseUrl . '/auth/login.php', null, sys_get_temp_dir() . '/uat_probe_' . uniqid() . '.txt', false);
if ($probe['code'] === 0) {
    fwrite(STDERR, "ERROR: Apache/HTTP server tidak dapat diakses di {$baseUrl} ({$probe['error']}).\n");
    exit(1);
}

$db = getDB();
$cookies = [];
$newCookie = static function (string $name) use (&$cookies): string {
    $path = sys_get_temp_dir() . '/uat_' . $name . '_' . uniqid() . '.cookie';
    $cookies[] = $path;
    return $path;
};

$snapshotStock = [];
$maxIds = [];

try {
    // ── PART A: Multi-Role RBAC & PT Access Verification ──────────────────────
    echo "-- Part A: Multi-Role RBAC & PT Isolation --\n";

    // A1. Unauthenticated access must redirect to login
    $anonJar = $newCookie('anon');
    $anonDash = uat_http($baseUrl . '/pages/dashboard.php', null, $anonJar, false);
    uat_assert($anonDash['code'] === 302 && str_contains($anonDash['location'], 'login.php'), 'A1: Guest mengakses dashboard diredirect ke login.php');

    // A2. Viewer role (MSP only, read-only)
    $viewerJar = $newCookie('viewer');
    $vLogin = uat_login($baseUrl, 'viewer', $defaultPass, $viewerJar);
    uat_assert($vLogin['ok'] && str_contains($vLogin['location'], '/pages/portal.php'), 'A2.1: Login Viewer sukses & diarahkan ke /pages/portal.php', $vLogin['reason']);

    $vStock = uat_http($baseUrl . '/pages/stock/index.php', null, $viewerJar, false);
    $vReport = uat_http($baseUrl . '/pages/reports/stock.php', null, $viewerJar, false);
    $vExport = uat_http($baseUrl . '/pages/reports/stock_export.php', null, $viewerJar, false);
    uat_assert($vStock['code'] === 200 && $vReport['code'] === 200 && $vExport['code'] === 200 && !uat_has_php_error($vStock['body']),
        'A2.2: Viewer dapat melihat stok, laporan, & export stok (HTTP 200)');

    $vBlocked = [];
    foreach ([
        '/pages/companies/index.php',
        '/pages/users/index.php',
        '/pages/roles/index.php',
        '/pages/purchase-orders/form.php',
        '/pages/transfers/create.php',
        '/pages/supplier-returns/create.php',
        '/pages/stock-opname/create.php',
    ] as $forbidden) {
        $r = uat_http($baseUrl . $forbidden, null, $viewerJar, false);
        if ($r['code'] !== 403) {
            $vBlocked[] = "{$forbidden} (HTTP {$r['code']})";
        }
    }
    uat_assert(empty($vBlocked), 'A2.3: Viewer ditolak (HTTP 403) di 7 modul tulis/admin/master', implode(', ', $vBlocked));

    // Viewer cannot switch to SLN (company_id=2); extract CSRF from profile.php since single-PT portal hides switcher
    $vSwitchRes = uat_switch_company($baseUrl, 2, $viewerJar, true, '/pages/profile.php', '/pages/portal.php');
    $vPortalAfter = uat_http($baseUrl . '/pages/portal.php', null, $viewerJar, false);
    uat_assert(
        str_contains($vSwitchRes['body'], 'Gagal beralih perusahaan')
        && str_contains($vPortalAfter['body'], 'PT Masuk Sukses Pertama')
        && !str_contains($vPortalAfter['body'], 'PT Sukses Logistik Nusantara'),
        'A2.4: Viewer tidak dapat berpindah ke PT yang tidak diizinkan (SLN)'
    );

    // A3. Staff Gudang role (MSP & SLN only, operational input, no approve/finalize/admin)
    $staffJar = $newCookie('staff');
    $sLogin = uat_login($baseUrl, 'staffgudang', $defaultPass, $staffJar);
    uat_assert($sLogin['ok'] && str_contains($sLogin['location'], '/pages/portal.php'), 'A3.1: Login Staff Gudang sukses & diarahkan ke /pages/portal.php', $sLogin['reason']);

    $sTransfer = uat_http($baseUrl . '/pages/transfers/create.php', null, $staffJar, false);
    $sOpnameCreate = uat_http($baseUrl . '/pages/stock-opname/create.php', null, $staffJar, false);
    uat_assert($sTransfer['code'] === 200 && $sOpnameCreate['code'] === 200,
        'A3.2: Staff Gudang dapat membuka form transfer & buat sesi stock opname');

    $sBlocked = [];
    foreach (['/pages/companies/index.php', '/pages/users/index.php', '/pages/roles/index.php', '/pages/purchase-orders/approve.php'] as $forbidden) {
        $r = uat_http($baseUrl . $forbidden, null, $staffJar, false);
        if ($r['code'] !== 403) {
            $sBlocked[] = "{$forbidden} (HTTP {$r['code']})";
        }
    }
    // Staff Gudang POST finalize opname must fail 403 even with valid CSRF? Wait: report.php checks require_perm('stock_opname.finalize') after view
    $sFinCheck = uat_http($baseUrl . '/pages/stock-opname/report.php?id=1', ['csrf_token' => 'dummy'], $staffJar, false);
    if ($sFinCheck['code'] !== 403) {
        $sBlocked[] = "/pages/stock-opname/report.php POST finalize (HTTP {$sFinCheck['code']})";
    }
    uat_assert(empty($sBlocked), 'A3.3: Staff Gudang ditolak (HTTP 403) di companies, users, roles, po.approve, & opname.finalize', implode(', ', $sBlocked));

    // Staff Gudang switch to SLN succeeds, PDM rejected
    uat_switch_company($baseUrl, 2, $staffJar, false, '/pages/portal.php');
    $sTransferSln = uat_http($baseUrl . '/pages/transfers/create.php', null, $staffJar, false);
    uat_assert(str_contains($sTransferSln['body'], 'SLN'), 'A3.4: Staff Gudang dapat berpindah ke PT SLN yang diizinkan');
    $sSwitchPdm = uat_switch_company($baseUrl, 3, $staffJar, true, '/pages/portal.php');
    $sAfterPdm = uat_http($baseUrl . '/pages/portal.php', null, $staffJar, false);
    uat_assert(
        str_contains($sSwitchPdm['body'], 'Gagal beralih perusahaan')
        && str_contains($sAfterPdm['body'], 'PT Sukses Logistik Nusantara')
        && !str_contains($sAfterPdm['body'], 'PT Prima Distribusi Mandiri'),
        'A3.5: Staff Gudang ditolak saat mencoba beralih ke PT PDM'
    );

    // A4. Admin role (dashboard, master PT/users, roles view without roles.edit save)
    $adminJar = $newCookie('admin');
    $aLogin = uat_login($baseUrl, 'admin', $defaultPass, $adminJar);
    uat_assert($aLogin['ok'] && str_contains($aLogin['location'], '/pages/dashboard.php'), 'A4.1: Login Admin sukses & diarahkan ke /pages/dashboard.php', $aLogin['reason']);

    $aComp = uat_http($baseUrl . '/pages/companies/index.php', null, $adminJar, false);
    $aUsers = uat_http($baseUrl . '/pages/users/index.php', null, $adminJar, false);
    $aRoles = uat_http($baseUrl . '/pages/roles/index.php', null, $adminJar, false);
    uat_assert($aComp['code'] === 200 && $aUsers['code'] === 200 && $aRoles['code'] === 200,
        'A4.2: Admin dapat mengakses Master PT, Users, & Roles (HTTP 200)');

    // A5. Super Admin role (all access + roles.edit capability)
    $saJar = $newCookie('superadmin');
    $saLogin = uat_login($baseUrl, 'superadmin', $defaultPass, $saJar);
    uat_assert($saLogin['ok'] && str_contains($saLogin['location'], '/pages/dashboard.php'), 'A5.1: Login Super Admin sukses & diarahkan ke /pages/dashboard.php', $saLogin['reason']);

    $saRoles = uat_http($baseUrl . '/pages/roles/index.php', null, $saJar, false);
    uat_assert($saRoles['code'] === 200 && str_contains($saRoles['body'], 'name="permissions[]"'),
        'A5.2: Super Admin memiliki form simpan izin peran (roles.edit)');

    // ── PART B: End-to-End Operational Flow (Live HTTP + DB Math) ──────────────
    echo "\n-- Part B: End-to-End Operational Flow (PO -> Partial Receive -> Return -> Inter-PT Transfer -> Opname -> Export) --\n";

    // Snapshot baseline stock & max IDs
    $stockRes = $db->query('SELECT id, item_id, location_id, company_id, quantity FROM stock ORDER BY id');
    while ($sr = $stockRes->fetch_assoc()) {
        $snapshotStock[(int)$sr['id']] = (int)$sr['quantity'];
    }
    $idTables = [
        'stock'             => 'id',
        'purchase_orders'   => 'id',
        'stock_in'          => 'id',
        'supplier_returns'  => 'id',
        'transfers'         => 'id',
        'stock_opname'      => 'session_id',
        'stock_adjustments' => 'id',
        'mutations'         => 'id',
    ];
    foreach ($idTables as $tbl => $pk) {
        $maxIds[$tbl] = (int)$db->query("SELECT COALESCE(MAX(`{$pk}`), 0) FROM `{$tbl}`")->fetch_row()[0];
    }

    $itemRow = $db->query('SELECT id, code, buy_price FROM items WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch_assoc();
    $supRow = $db->query('SELECT id FROM suppliers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch_assoc();
    $locRows = $db->query('SELECT id FROM locations WHERE is_active = 1 ORDER BY id LIMIT 2')->fetch_all(MYSQLI_ASSOC);

    $itemId = (int)$itemRow['id'];
    $itemCode = (string)$itemRow['code'];
    $buyPrice = (float)$itemRow['buy_price'] > 0 ? (float)$itemRow['buy_price'] : 100000.0;
    $supId = (int)$supRow['id'];
    $loc1 = (int)$locRows[0]['id'];
    $loc2 = (int)($locRows[1]['id'] ?? $locRows[0]['id']);

    $origMspQty = uat_get_stock($db, 1, $itemId, $loc1);
    $origSlnQty = uat_get_stock($db, 2, $itemId, $loc2);

    // B1. Switch to MSP (company_id=1)
    uat_switch_company($baseUrl, 1, $saJar, false);
    $dashMsp = uat_http($baseUrl . '/pages/dashboard.php', null, $saJar, false);
    uat_assert($dashMsp['code'] === 200 && str_contains($dashMsp['body'], 'MSP'), 'B1: Switch company ke PT MSP (id=1) aktif di topbar');

    // B2. Create PO Draft (+10 pcs)
    $poTag = '__UAT_PO_' . uniqid() . '__';
    $poForm = uat_http($baseUrl . '/pages/purchase-orders/form.php', null, $saJar, false);
    $csrfPo = uat_extract_csrf($poForm['body']);
    uat_assert($csrfPo !== null, 'B2.1: CSRF token form PO berhasil diekstrak');

    $poPost = uat_http($baseUrl . '/pages/purchase-orders/form.php', [
        'csrf_token'         => $csrfPo,
        'supplier_id'   => $supId,
        'location_id'   => $loc1,
        'order_date'    => date('Y-m-d'),
        'expected_date' => date('Y-m-d', strtotime('+3 days')),
        'notes'         => $poTag,
        'item_id'       => [$itemId],
        'quantity'      => [10],
        'buy_price'     => [$buyPrice],
    ], $saJar, false);
    $stPo = $db->prepare('SELECT id, reference_no, status, approval_status, company_id FROM purchase_orders WHERE notes = ? ORDER BY id DESC LIMIT 1');
    $stPo->bind_param('s', $poTag);
    $stPo->execute();
    $poRow = $stPo->get_result()->fetch_assoc();
    $stPo->close();
    uat_assert($poPost['code'] === 302 && $poRow && $poRow['status'] === 'draft' && (int)$poRow['company_id'] === 1,
        'B2.2: Create PO draft (+10 pcs) tersimpan ke DB untuk PT MSP');
    $poId = (int)$poRow['id'];

    // B3. Submit PO -> Pending -> Approve (Approve Modal lives on index.php)
    $poList = uat_http($baseUrl . '/pages/purchase-orders/index.php', null, $saJar, false);
    $csrfList = uat_extract_csrf($poList['body']);
    $subPost = uat_http($baseUrl . '/pages/purchase-orders/index.php', [
        'csrf_token' => $csrfList ?? '',
        'id'         => $poId,
        'action'     => 'order',
    ], $saJar, false);
    $stPoChk1 = $db->query("SELECT status, approval_status FROM purchase_orders WHERE id = {$poId}")->fetch_assoc();
    uat_assert($subPost['code'] === 302 && ($stPoChk1['status'] ?? '') === 'pending' && ($stPoChk1['approval_status'] ?? '') === 'pending',
        'B3.1: Submit PO mengubah status draft -> pending untuk approval');

    $poList2 = uat_http($baseUrl . '/pages/purchase-orders/index.php', null, $saJar, false);
    $csrfApprove = uat_extract_csrf($poList2['body']);
    $appPost = uat_http($baseUrl . '/pages/purchase-orders/approve.php', [
        'csrf_token'     => $csrfApprove ?? '',
        'id'             => $poId,
        'action'         => 'approve',
        'approval_notes' => 'UAT Auto Approved',
    ], $saJar, false);
    $stPoChk2 = $db->query("SELECT status, approval_status FROM purchase_orders WHERE id = {$poId}")->fetch_assoc();
    uat_assert($appPost['code'] === 302 && ($stPoChk2['approval_status'] ?? '') === 'approved',
        'B3.2: Approve PO sukses mengubah approval_status = approved');

    // B4. Partial Receive #1 (+4 pcs) & Partial Receive #2 (+6 pcs)
    $rec1Form = uat_http($baseUrl . '/pages/purchase-orders/receive-form.php?id=' . $poId, null, $saJar, false);
    $csrfRec1 = uat_extract_csrf($rec1Form['body']);
    $rec1Post = uat_http($baseUrl . '/pages/purchase-orders/receive-form.php?id=' . $poId, [
        'csrf_token'     => $csrfRec1 ?? '',
        'qty_' . $itemId => 4,
    ], $saJar, false);
    $poAfterRec1 = $db->query("SELECT status FROM purchase_orders WHERE id = {$poId}")->fetch_assoc();
    $qtyAfterRec1 = uat_get_stock($db, 1, $itemId, $loc1);
    uat_assert($rec1Post['code'] === 302 && ($poAfterRec1['status'] ?? '') === 'partial' && $qtyAfterRec1 === ($origMspQty + 4),
        "B4.1: Partial receive #1 (+4 pcs) -> status PO=partial & stok MSP {$origMspQty} -> {$qtyAfterRec1}");

    $rec2Form = uat_http($baseUrl . '/pages/purchase-orders/receive-form.php?id=' . $poId, null, $saJar, false);
    $csrfRec2 = uat_extract_csrf($rec2Form['body']);
    $rec2Post = uat_http($baseUrl . '/pages/purchase-orders/receive-form.php?id=' . $poId, [
        'csrf_token'     => $csrfRec2 ?? '',
        'qty_' . $itemId => 6,
    ], $saJar, false);
    $poAfterRec2 = $db->query("SELECT status FROM purchase_orders WHERE id = {$poId}")->fetch_assoc();
    $qtyAfterRec2 = uat_get_stock($db, 1, $itemId, $loc1);
    uat_assert($rec2Post['code'] === 302 && ($poAfterRec2['status'] ?? '') === 'completed' && $qtyAfterRec2 === ($origMspQty + 10),
        "B4.2: Partial receive #2 (+6 pcs) -> status PO=completed & stok MSP={$qtyAfterRec2}");

    // B5. Supplier Return (-3 pcs)
    $srTag = '__UAT_SR_' . uniqid() . '__';
    $srForm = uat_http($baseUrl . '/pages/supplier-returns/create.php', null, $saJar, false);
    $csrfSr = uat_extract_csrf($srForm['body']);
    $srPost = uat_http($baseUrl . '/pages/supplier-returns/create.php', [
        'csrf_token'            => $csrfSr,
        'transaction_date' => date('Y-m-d'),
        'supplier_id'      => $supId,
        'location_id'      => $loc1,
        'reason'           => 'UAT Return Defect',
        'notes'            => $srTag,
        'item_id'          => [$itemId],
        'quantity'         => [3],
        'buy_price'        => [$buyPrice],
    ], $saJar, false);
    $qtyAfterSr = uat_get_stock($db, 1, $itemId, $loc1);
    uat_assert($srPost['code'] === 302 && $qtyAfterSr === ($origMspQty + 7),
        "B5: Retur supplier (-3 pcs) memotong stok MSP menjadi {$qtyAfterSr}");

    // B6. Inter-PT Transfer (MSP id=1 -> SLN id=2, 2 pcs) & Filter Toolbar Search
    $trTag = '__UAT_TRF_' . uniqid() . '__';
    $trForm = uat_http($baseUrl . '/pages/transfers/create.php', null, $saJar, false);
    $csrfTr = uat_extract_csrf($trForm['body']);
    $trPost = uat_http($baseUrl . '/pages/transfers/create.php', [
        'csrf_token'            => $csrfTr,
        'transaction_date' => date('Y-m-d'),
        'from_company_id'  => 1,
        'from_location_id' => $loc1,
        'to_company_id'    => 2,
        'to_location_id'   => $loc2,
        'notes'            => $trTag,
        'item_id'          => [$itemId],
        'quantity'         => [2],
    ], $saJar, false);
    $stTr = $db->prepare('SELECT reference_no FROM transfers WHERE notes = ? ORDER BY id DESC LIMIT 1');
    $stTr->bind_param('s', $trTag);
    $stTr->execute();
    $trRow = $stTr->get_result()->fetch_assoc();
    $stTr->close();
    $trRef = $trRow['reference_no'] ?? '';

    $qtyMspAfterTr = uat_get_stock($db, 1, $itemId, $loc1);
    $qtySlnAfterTr = uat_get_stock($db, 2, $itemId, $loc2);
    uat_assert($trPost['code'] === 302 && $trRef !== '' && $qtyMspAfterTr === ($origMspQty + 5) && $qtySlnAfterTr === ($origSlnQty + 2),
        "B6.1: Transfer antar PT (MSP -> SLN, 2 pcs): MSP={$qtyMspAfterTr}, SLN={$qtySlnAfterTr}");

    // Search transfer reference_no on MSP grid & SLN grid
    $trGridMsp = uat_http($baseUrl . '/pages/transfers/index.php?q=' . urlencode($trRef) . '&date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'), null, $saJar, false);
    uat_assert($trGridMsp['code'] === 200 && $trRef !== '' && str_contains($trGridMsp['body'], $trRef) && str_contains($trGridMsp['body'], '>SLN<'),
        'B6.2: Filter toolbar transfers/index.php pada PT MSP menemukan transfer keluar (' . $trRef . ')');

    uat_switch_company($baseUrl, 2, $saJar, false);
    $trGridSln = uat_http($baseUrl . '/pages/transfers/index.php?q=' . urlencode($trRef) . '&date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'), null, $saJar, false);
    uat_assert($trGridSln['code'] === 200 && $trRef !== '' && str_contains($trGridSln['body'], $trRef) && str_contains($trGridSln['body'], '>MSP<'),
        'B6.3: Filter toolbar transfers/index.php pada PT SLN menemukan transfer masuk yang sama (' . $trRef . ')');

    // Switch back to MSP for Opname
    uat_switch_company($baseUrl, 1, $saJar, false);

    // B7. Stock Opname: Create -> Count (+1 variance) -> Finalize
    $opTag = '__UAT_OPNAME_' . uniqid() . '__';
    $opCreate = uat_http($baseUrl . '/pages/stock-opname/create.php', null, $saJar, false);
    $csrfOp = uat_extract_csrf($opCreate['body']);
    $opPost = uat_http($baseUrl . '/pages/stock-opname/create.php', [
        'csrf_token'       => $csrfOp,
        'opname_date' => date('Y-m-d'),
        'notes'       => $opTag,
    ], $saJar, false);
    $stOp = $db->prepare('SELECT session_id, status FROM stock_opname WHERE notes = ? ORDER BY session_id DESC LIMIT 1');
    $stOp->bind_param('s', $opTag);
    $stOp->execute();
    $opRow = $stOp->get_result()->fetch_assoc();
    $stOp->close();
    uat_assert($opPost['code'] === 302 && $opRow && $opRow['status'] === 'draft', 'B7.1: Sesi stock opname baru dibuat untuk PT MSP');
    $opSessionId = (int)$opRow['session_id'];

    $opCountForm = uat_http($baseUrl . '/pages/stock-opname/count.php?id=' . $opSessionId, null, $saJar, false);
    $csrfCount = uat_extract_csrf($opCountForm['body']);
    $targetPhysical = $qtyMspAfterTr + 1;
    $opCountPost = uat_http($baseUrl . '/pages/stock-opname/count.php?id=' . $opSessionId, [
        'csrf_token'        => $csrfCount,
        'item_id'      => $itemId,
        'location_id'  => $loc1,
        'physical_qty' => $targetPhysical,
    ], $saJar, false);
    $oiRow = $db->query("SELECT system_qty, physical_qty, variance FROM stock_opname_items WHERE session_id = {$opSessionId} AND item_id = {$itemId} AND location_id = {$loc1}")->fetch_assoc();
    uat_assert($opCountPost['code'] === 302 && $oiRow && (int)$oiRow['variance'] === 1,
        'B7.2: Input hitung fisik opname mencatat selisih variance = +1');

    $opRepForm = uat_http($baseUrl . '/pages/stock-opname/report.php?id=' . $opSessionId, null, $saJar, false);
    $csrfFin = uat_extract_csrf($opRepForm['body']);
    $opFinPost = uat_http($baseUrl . '/pages/stock-opname/report.php?id=' . $opSessionId, [
        'csrf_token' => $csrfFin,
    ], $saJar, false);
    $opAfterFin = $db->query("SELECT status FROM stock_opname WHERE session_id = {$opSessionId}")->fetch_assoc();
    $qtyAfterOp = uat_get_stock($db, 1, $itemId, $loc1);
    uat_assert($opFinPost['code'] === 302 && $opAfterFin['status'] === 'finalized' && $qtyAfterOp === ($origMspQty + 6),
        "B7.3: Finalisasi stock opname memposting adjustment (+1) -> stok MSP akhir={$qtyAfterOp}");

    // B8. Export CSV & Print Reports
    $mutCsv = uat_http($baseUrl . '/pages/reports/mutation_export.php?item_id=' . $itemId, null, $saJar, false);
    $mutPrint = uat_http($baseUrl . '/pages/reports/mutation_export.php?format=print&item_id=' . $itemId, null, $saJar, false);
    uat_assert($mutCsv['code'] === 200 && str_contains($mutCsv['body'], $itemCode) && !uat_has_php_error($mutCsv['body']),
        'B8.1: Export CSV mutasi mengembalikan HTTP 200 & memuat kode item hasil transaksi');
    uat_assert($mutPrint['code'] === 200 && str_contains($mutPrint['body'], '<table>') && !uat_has_php_error($mutPrint['body']),
        'B8.2: Export Print mutasi merender tabel HTML valid bebas PHP error');
} finally {
    // Rollback all test transactions in FK child-first order and restore exact pre-test stock
    if (!empty($maxIds)) {
        $db->query("DELETE FROM mutations WHERE id > {$maxIds['mutations']}");
        $db->query("DELETE FROM purchase_orders WHERE id > {$maxIds['purchase_orders']}");
        $db->query("DELETE FROM stock_in WHERE id > {$maxIds['stock_in']}");
        $db->query("DELETE FROM supplier_returns WHERE id > {$maxIds['supplier_returns']}");
        $db->query("DELETE FROM transfers WHERE id > {$maxIds['transfers']}");
        $db->query("DELETE FROM stock_adjustments WHERE id > {$maxIds['stock_adjustments']}");
        $db->query("DELETE FROM stock_opname WHERE session_id > {$maxIds['stock_opname']}");
        $db->query("DELETE FROM stock WHERE id > {$maxIds['stock']}");

        if ($snapshotStock) {
            $upd = $db->prepare('UPDATE stock SET quantity = ? WHERE id = ?');
            foreach ($snapshotStock as $sid => $sqty) {
                $upd->bind_param('ii', $sqty, $sid);
                $upd->execute();
            }
            $upd->close();
        }
    }

    foreach ($cookies as $cf) {
        if (file_exists($cf)) {
            @unlink($cf);
        }
    }
}

// Verify cleanup left 0 residual rows
$cleanOk = true;
if (!empty($maxIds)) {
    foreach ($idTables as $tbl => $pk) {
        $nowMax = (int)$db->query("SELECT COALESCE(MAX(`{$pk}`), 0) FROM `{$tbl}`")->fetch_row()[0];
        if ($nowMax > $maxIds[$tbl]) {
            $cleanOk = false;
        }
    }
}
uat_assert($cleanOk, 'Teardown: Seluruh data simulasi UAT dibersihkan & stok dikembalikan persis ke kondisi awal');

echo "\n=== Summary ===\nPassed: {$pass}\nFailed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
