<?php
/**
 * Phase 5 Holistic Improvements Verification Suite:
 * 1) Item XLSX pure-PHP import & upload/confirm temp-key fix
 * 2) User onboarding auto_verify + manual verify_user + nested-form fix + login brute-force 429 lockout
 * 3) Dashboard upgraded KPIs (compact_idr), quick actions, pending tasks, 7/30d chart, multi-PT table & dark-mode fixes
 * 4) All 5 Report PDF & Excel (.xlsx) exports + unified dropdown UI
 * 5) Audit Log persistence, RBAC (403 Viewer/Staff vs 200 Admin), grid/export endpoints & config/env.php readiness
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI access only.\n");
}

if (!extension_loaded('curl') || !extension_loaded('mysqli') || !extension_loaded('zlib')) {
    fwrite(STDERR, "ERROR: Ekstensi curl, mysqli, dan zlib dibutuhkan.\n");
    exit(2);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/export.php';
require_once __DIR__ . '/../includes/item-import.php';

$pass = 0;
$fail = 0;

function p5_assert(bool $cond, string $label, string $detail = ''): void
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

function p5_http(string $url, $postData, string $cookieFile, bool $follow = false): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ];
    if ($postData !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_array($postData) && array_filter($postData, fn($v) => $v instanceof CURLFile)
            ? $postData
            : (is_array($postData) ? http_build_query($postData) : $postData);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'error' => ''];
}

function p5_login(string $baseUrl, string $username, string $password, string $cookieFile): array
{
    $lg = p5_http($baseUrl . '/auth/login.php', null, $cookieFile, false);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lg['body'], $m);
    return p5_http($baseUrl . '/auth/login.php', [
        'csrf_token' => $m[1] ?? '',
        'username'   => $username,
        'password'   => $password,
    ], $cookieFile, false);
}

function p5_build_import_xlsx(array $rows): string
{
    $rowsXml = '';
    foreach ($rows as $rIdx => $row) {
        $rNum = $rIdx + 1;
        $rowsXml .= '<row r="' . $rNum . '">';
        foreach ($row as $cIdx => $val) {
            $ref = export_xlsx_col_letter($cIdx) . $rNum;
            $rowsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                . export_xlsx_xml_text((string)$val) . '</t></is></c>';
        }
        $rowsXml .= '</row>';
    }
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . $rowsXml . '</sheetData></worksheet>';
    $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Items" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    return export_zip_build([
        '[Content_Types].xml'        => $ctXml,
        '_rels/.rels'                => $relsXml,
        'xl/workbook.xml'            => $wbXml,
        'xl/_rels/workbook.xml.rels' => $wbRelsXml,
        'xl/worksheets/sheet1.xml'   => $sheetXml,
    ]);
}

function p5_zip_has_valid_sheet(string $zip): bool
{
    if (!str_starts_with($zip, "PK\x03\x04")) return false;
    $pos = 0;
    $len = strlen($zip);
    while ($pos + 30 <= $len) {
        if (substr($zip, $pos, 4) !== "PK\x03\x04") break;
        $hdr = unpack('Vsig/vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($zip, $pos, 30));
        $nameStart = $pos + 30;
        $name = substr($zip, $nameStart, $hdr['nlen']);
        $dataStart = $nameStart + $hdr['nlen'] + $hdr['elen'];
        $comp = substr($zip, $dataStart, $hdr['csize']);
        if ($name === 'xl/worksheets/sheet1.xml') {
            $raw = $hdr['method'] === 8 ? @gzinflate($comp) : $comp;
            return is_string($raw) && @simplexml_load_string($raw) !== false;
        }
        $pos = $dataStart + $hdr['csize'];
    }
    return false;
}

echo "=== Phase 5 Holistic Improvements Verification Suite ===\n\n";

$db      = getDB();
$root    = dirname(__DIR__);
$baseUrl = rtrim(getenv('UAT_BASE_URL') ?: 'http://localhost:8080/masuk-sukses-pertama-warehouse', '/');

// ── 1. Production Config, Templates, Dark Mode & DB Schema Checks ─────────────
p5_assert(file_exists($root . '/config/env.php'), 'config/env.php loader tersedia');
p5_assert(file_exists($root . '/.env.example') && file_exists($root . '/config/config.local.example.php'), 'Template konfigurasi produksi .env.example & config.local.example.php tersedia');
p5_assert(function_exists('msp_detect_app_url') && str_starts_with(msp_detect_app_url(), 'http'), 'msp_detect_app_url() mendeteksi/menyusun URL HTTP valid');

$htaccess = file_get_contents($root . '/.htaccess');
p5_assert(str_contains($htaccess, '\\.env') && str_contains($htaccess, '\\.user\\.ini') && str_contains($htaccess, 'Permissions-Policy'), '.htaccess memblokir file sensitif & menyertakan header keamanan');

$styleCss = file_get_contents($root . '/assets/css/style.css');
p5_assert(str_contains($styleCss, 'html[data-bs-theme=dark] .btn-light'), 'assets/css/style.css memperbaiki kontras .btn-light pada dark mode');

// Verify unit pure-PHP XLSX reader
$unitCode = 'P5UNIT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$tmpXlsx  = sys_get_temp_dir() . '/p5_import_' . uniqid() . '.xlsx';
$xlsxBin  = p5_build_import_xlsx([
    ['code', 'name', 'category', 'unit', 'min_stock', 'buy_price', 'sell_price', 'description'],
    [$unitCode, 'Ban Test Phase 5 XLSX', 'KAT-TBR', 'pcs', '5', '1500000', '2100000', 'Uji pure-PHP XLSX'],
]);
file_put_contents($tmpXlsx, $xlsxBin);
$parsedXlsx = item_import_read($tmpXlsx, 'xlsx');
p5_assert(count($parsedXlsx) === 2 && ($parsedXlsx[1][0] ?? '') === $unitCode, 'item_import_read(.xlsx) mem-parsing OOXML tanpa perlu ext-zip');

// ── 2. Live HTTP Suite ────────────────────────────────────────────────────────
$saCookie = sys_get_temp_dir() . '/p5_sa_' . uniqid() . '.cookie';
$vwCookie = sys_get_temp_dir() . '/p5_vw_' . uniqid() . '.cookie';
$sfCookie = sys_get_temp_dir() . '/p5_sf_' . uniqid() . '.cookie';
$t1Cookie = sys_get_temp_dir() . '/p5_t1_' . uniqid() . '.cookie';
$t2Cookie = sys_get_temp_dir() . '/p5_t2_' . uniqid() . '.cookie';
$bfCookie = sys_get_temp_dir() . '/p5_bf_' . uniqid() . '.cookie';

$createdItemCodes = [];
$createdUsernames = [];
$auditRefTag      = 'P5-' . substr(bin2hex(random_bytes(3)), 0, 6);
$unameAuto        = 'p5auto_' . strtolower(substr($auditRefTag, 3));
$unameManual      = 'p5man_' . strtolower(substr($auditRefTag, 3));

try {
    // Guest check on audit-logs
    $guestAudit = p5_http($baseUrl . '/pages/audit-logs/index.php', null, sys_get_temp_dir() . '/p5_an_' . uniqid() . '.cookie', false);
    if ($guestAudit['code'] === 0) {
        p5_assert(false, 'Koneksi Apache live untuk uji Phase 5', $guestAudit['error']);
    } else {
        p5_assert($guestAudit['code'] === 302 && stripos($guestAudit['headers'], 'login.php') !== false, 'Guest mengakses audit-logs diredirect ke login.php');

        // Log in Super Admin, Viewer, Staff Gudang
        $lSa = p5_login($baseUrl, 'superadmin', 'P@ssw0rd', $saCookie);
        $lVw = p5_login($baseUrl, 'viewer', 'P@ssw0rd', $vwCookie);
        $lSf = p5_login($baseUrl, 'staffgudang', 'P@ssw0rd', $sfCookie);
        p5_assert($lSa['code'] === 302 && stripos($lSa['headers'], 'dashboard.php') !== false, 'Super Admin login sukses ke dashboard.php');

        // Dashboard checks
        $dashRes = p5_http($baseUrl . '/pages/dashboard.php', null, $saCookie, false);
        $dBody   = $dashRes['body'];
        p5_assert(
            $dashRes['code'] === 200
            && str_contains($dBody, 'Tugas Tertunda')
            && str_contains($dBody, '30 Hari')
            && str_contains($dBody, 'Qty Unit')
            && str_contains($dBody, 'Ringkasan Inventori Lintas Entitas')
            && str_contains($dBody, 'syncChartTheme')
            && str_contains($dBody, 'supplier-performance.php')
            && str_contains($dBody, 'audit-logs/index.php'),
            'Dashboard & Sidebar merender seluruh fitur baru (KPI, Quick Actions, Tugas Tertunda, Chart 7/30d, Multi-PT, Sidebar links)'
        );

        // Upload & Confirm XLSX Import roundtrip (verifies temp path hash fix + audit_log)
        $createdItemCodes[] = $unitCode;
        $impGet = p5_http($baseUrl . '/pages/items/import.php', null, $saCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $impGet['body'], $mcImp);
        $csrfImp = $mcImp[1] ?? '';

        $impPreview = p5_http($baseUrl . '/pages/items/import.php', [
            'csrf_token' => $csrfImp,
            'file'       => new CURLFile($tmpXlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'test_items.xlsx'),
        ], $saCookie, false);

        preg_match('/name="upload_key"\s+value="([a-f0-9]{32})"/i', $impPreview['body'], $mUk);
        $uploadKey = $mUk[1] ?? '';
        p5_assert(
            $impPreview['code'] === 200 && $uploadKey !== '' && str_contains($impPreview['body'], $unitCode),
            'Import XLSX upload & preview sukses dengan upload_key valid'
        );

        if ($uploadKey !== '') {
            preg_match('/name="csrf_token"\s+value="([^"]+)"/', $impPreview['body'], $mcImp2);
            $impConfirm = p5_http($baseUrl . '/pages/items/import.php', [
                'csrf_token' => $mcImp2[1] ?? $csrfImp,
                'action'     => 'confirm',
                'upload_key' => $uploadKey,
                'extension'  => 'xlsx',
            ], $saCookie, false);

            $chkSt = $db->prepare('SELECT id, name FROM items WHERE code = ?');
            $chkSt->bind_param('s', $unitCode);
            $chkSt->execute();
            $insRow = $chkSt->get_result()->fetch_assoc();
            $chkSt->close();

            p5_assert(
                $impConfirm['code'] === 302 && $insRow !== null && $insRow['name'] === 'Ban Test Phase 5 XLSX',
                'Konfirmasi Import XLSX berhasil menyimpan barang ke DB (bug temp path hash sudah tuntas)'
            );
        }

        // Users UI & Onboarding: auto_verify + manual verify_user + no nested form
        $uEditPage = p5_http($baseUrl . '/pages/users/index.php?edit=1', null, $saCookie, false);
        $editBody  = $uEditPage['body'];
        $hasNestedForm = preg_match('/<form\b[^>]*>(?:(?!(<\/form>)).)*<form\b/is', $editBody) === 1;
        p5_assert(
            $uEditPage['code'] === 200 && str_contains($editBody, 'name="auto_verify"') && !$hasNestedForm,
            'pages/users/index.php menyediakan checkbox auto_verify dan bebas nested <form> di modal edit'
        );

        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $editBody, $mcUser);
        $csrfUser = $mcUser[1] ?? '';

        // Create user with auto_verify=1 -> immediate login works
        $createdUsernames[] = $unameAuto;
        $crAuto = p5_http($baseUrl . '/pages/users/index.php', [
            'csrf_token'    => $csrfUser,
            'action'        => 'create',
            'name'          => 'User Auto ' . $auditRefTag,
            'username'      => $unameAuto,
            'email'         => $unameAuto . '@example.local',
            'role_id'       => 4,
            'password'      => 'TestPass#123',
            'company_ids'   => [1],
            'auto_verify'   => '1',
        ], $saCookie, false);
        $loginAuto = p5_login($baseUrl, $unameAuto, 'TestPass#123', $t1Cookie);
        p5_assert(
            $crAuto['code'] === 302 && $loginAuto['code'] === 302 && stripos($loginAuto['headers'], 'portal.php') !== false,
            'User dibuat dengan auto_verify=1 langsung aktif dan bisa login tanpa tunggu email'
        );

        // Create user with auto_verify=0 -> blocked on login -> 1-click verify_user -> login works
        $createdUsernames[] = $unameManual;
        $uList2 = p5_http($baseUrl . '/pages/users/index.php', null, $saCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $uList2['body'], $mcUser2);
        $csrfUser2 = $mcUser2[1] ?? $csrfUser;

        p5_http($baseUrl . '/pages/users/index.php', [
            'csrf_token'    => $csrfUser2,
            'action'        => 'create',
            'name'          => 'User Manual ' . $auditRefTag,
            'username'      => $unameManual,
            'email'         => $unameManual . '@example.local',
            'role_id'       => 4,
            'password'      => 'TestPass#123',
            'company_ids'   => [1],
        ], $saCookie, false);

        $loginUnver = p5_login($baseUrl, $unameManual, 'TestPass#123', $t2Cookie);
        $uManSt = $db->prepare('SELECT id, email_verified_at FROM users WHERE username = ?');
        $uManSt->bind_param('s', $unameManual);
        $uManSt->execute();
        $manRow = $uManSt->get_result()->fetch_assoc();
        $uManSt->close();
        $manId = (int)($manRow['id'] ?? 0);

        p5_assert(
            $manId > 0 && $manRow['email_verified_at'] === null && stripos($loginUnver['headers'], 'portal.php') === false,
            'User tanpa auto_verify tertahan verifikasi sebelum diaktifkan Admin'
        );

        $uList3 = p5_http($baseUrl . '/pages/users/index.php?q=' . urlencode($unameManual), null, $saCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $uList3['body'], $mcUser3);
        p5_http($baseUrl . '/pages/users/index.php', [
            'csrf_token' => $mcUser3[1] ?? $csrfUser2,
            'action'     => 'verify_user',
            'id'         => $manId,
        ], $saCookie, false);
        $loginAfterVer = p5_login($baseUrl, $unameManual, 'TestPass#123', $t2Cookie);
        p5_assert(
            $loginAfterVer['code'] === 302 && stripos($loginAfterVer['headers'], 'portal.php') !== false,
            'Tombol 1-klik verify_user mengaktifkan akun sehingga user langsung dapat login'
        );

        // Brute-force lockout test on isolated $unameAuto (5 failures -> 6th returns 429)
        for ($att = 1; $att <= 5; $att++) {
            p5_login($baseUrl, $unameAuto, 'WrongPass!' . $att, $bfCookie);
        }
        $lockedRes = p5_login($baseUrl, $unameAuto, 'TestPass#123', $bfCookie);
        p5_assert(
            $lockedRes['code'] === 429 && str_contains($lockedRes['body'], 'Terlalu banyak percobaan masuk gagal'),
            'Brute-force guard mengunci login (HTTP 429) setelah 5 percobaan sandi salah'
        );

        // Report PDF & Excel XLSX live export checks across all 5 reports
        $reports = [
            'stock'                => ['ui' => '/pages/reports/stock.php',                'exp' => '/pages/reports/stock_export.php'],
            'mutation'             => ['ui' => '/pages/reports/mutation.php',             'exp' => '/pages/reports/mutation_export.php'],
            'stock-in'             => ['ui' => '/pages/reports/stock-in.php',             'exp' => '/pages/reports/stock-in_export.php'],
            'stock-out'            => ['ui' => '/pages/reports/stock-out.php',            'exp' => '/pages/reports/stock-out_export.php'],
            'supplier-performance' => ['ui' => '/pages/reports/supplier-performance.php', 'exp' => '/pages/reports/supplier-performance_export.php'],
        ];
        foreach ($reports as $rName => $paths) {
            $uiRes = p5_http($baseUrl . $paths['ui'], null, $saCookie, false);
            p5_assert(
                $uiRes['code'] === 200
                && str_contains($uiRes['body'], 'Export PDF')
                && str_contains($uiRes['body'], 'Export Excel')
                && str_contains($uiRes['body'], 'format=pdf')
                && str_contains($uiRes['body'], 'format=excel'),
                "UI Laporan {$rName}: dropdown terpadu Export PDF/Excel terender"
            );

            $pdfRes = p5_http($baseUrl . $paths['exp'] . '?format=pdf', null, $saCookie, false);
            p5_assert(
                $pdfRes['code'] === 200
                && stripos($pdfRes['headers'], 'application/pdf') !== false
                && str_starts_with($pdfRes['body'], '%PDF-1.4')
                && str_contains($pdfRes['body'], '%%EOF'),
                "Laporan {$rName} ?format=pdf menghasilkan PDF 1.4 valid (" . strlen($pdfRes['body']) . ' B)'
            );

            $xlsxRes = p5_http($baseUrl . $paths['exp'] . '?format=excel', null, $saCookie, false);
            p5_assert(
                $xlsxRes['code'] === 200
                && stripos($xlsxRes['headers'], 'spreadsheetml.sheet') !== false
                && p5_zip_has_valid_sheet($xlsxRes['body']),
                "Laporan {$rName} ?format=excel menghasilkan Excel XLSX OOXML valid (" . strlen($xlsxRes['body']) . ' B)'
            );
        }

        // Audit Log UI, RBAC & Export checks
        $auditVw = p5_http($baseUrl . '/pages/audit-logs/index.php', null, $vwCookie, false);
        $auditSf = p5_http($baseUrl . '/pages/audit-logs/index.php', null, $sfCookie, false);
        p5_assert($auditVw['code'] === 403 && $auditSf['code'] === 403, 'RBAC: Viewer & Staff Gudang ditolak (HTTP 403) pada pages/audit-logs/index.php');

        $auditSa = p5_http($baseUrl . '/pages/audit-logs/index.php?q=' . urlencode($unameAuto), null, $saCookie, false);
        p5_assert(
            $auditSa['code'] === 200
            && str_contains($auditSa['body'], 'grid-toolbar')
            && str_contains($auditSa['body'], 'grid-filter-form')
            && str_contains($auditSa['body'], 'grid-footer')
            && str_contains($auditSa['body'], $unameAuto),
            'Audit Log mencatat & menampilkan jejak user & brute-force lockout di grid terstandarisasi'
        );

        $auditPdf  = p5_http($baseUrl . '/pages/audit-logs/export.php?format=pdf', null, $saCookie, false);
        $auditXlsx = p5_http($baseUrl . '/pages/audit-logs/export.php?format=excel', null, $saCookie, false);
        p5_assert(
            $auditPdf['code'] === 200 && str_starts_with($auditPdf['body'], '%PDF-1.4')
            && $auditXlsx['code'] === 200 && p5_zip_has_valid_sheet($auditXlsx['body']),
            'Export Audit Log ?format=pdf & ?format=excel valid'
        );
    }
} finally {
    // Cleanup temporary test rows & files
    foreach ($createdItemCodes as $ic) {
        $st = $db->prepare('DELETE FROM items WHERE code = ?');
        $st->bind_param('s', $ic);
        $st->execute();
        $st->close();
    }
    foreach ($createdUsernames as $un) {
        $st = $db->prepare('DELETE FROM users WHERE username = ?');
        $st->bind_param('s', $un);
        $st->execute();
        $st->close();
        $la = $db->prepare('DELETE FROM login_attempts WHERE username_key = ?');
        $la->bind_param('s', $un);
        $la->execute();
        $la->close();
        $al = $db->prepare('DELETE FROM audit_logs WHERE username = ? OR reference_no = ? OR description LIKE ?');
        $like = '%' . $un . '%';
        $al->bind_param('sss', $un, $un, $like);
        $al->execute();
        $al->close();
    }
    // Remove synthetic import / Phase 5 report export audit noise from this test run if needed
    $cleanAudit = $db->prepare("DELETE FROM audit_logs WHERE description LIKE ? OR description LIKE '%Ban Test Phase 5 XLSX%'");
    $unitLike = '%' . $unitCode . '%';
    $cleanAudit->bind_param('s', $unitLike);
    $cleanAudit->execute();
    $cleanAudit->close();

    foreach ([$tmpXlsx, $saCookie, $vwCookie, $sfCookie, $t1Cookie, $t2Cookie, $bfCookie] as $f) {
        if (is_string($f) && file_exists($f)) @unlink($f);
    }
}

echo "\n=== Summary ===\nPassed: {$pass}\nFailed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
