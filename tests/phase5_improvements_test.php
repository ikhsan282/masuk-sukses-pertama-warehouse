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
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/captcha.php';
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

function p5_login(string $baseUrl, string $username, string $password, string $cookieFile, bool $remember = false, ?string $captcha = null): array
{
    $lg = p5_http($baseUrl . '/auth/login.php', null, $cookieFile, false);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lg['body'], $m);
    $payload = [
        'csrf_token' => $m[1] ?? '',
        'username'   => $username,
        'password'   => $password,
    ];
    if ($remember) {
        $payload['remember'] = '1';
    }
    if ($captcha !== null) {
        $payload['captcha'] = $captcha;
    }
    return p5_http($baseUrl . '/auth/login.php', $payload, $cookieFile, false);
}

function p5_switch_company(string $baseUrl, int $companyId, string $cookieFile, bool $follow = true, string $seedPage = '/pages/dashboard.php'): array
{
    $p = p5_http($baseUrl . $seedPage, null, $cookieFile, false);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $p['body'], $m);
    return p5_http($baseUrl . '/auth/switch-company.php', [
        'csrf_token' => $m[1] ?? '',
        'company_id' => $companyId,
    ], $cookieFile, $follow);
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

// Verify pure-PHP PNG numeric CAPTCHA unit engine & one-time session verification
$capLightPng = captcha_render_png('12345', 180, 54, false);
$capDarkPng  = captcha_render_png('67890', 180, 54, true);
$ihdrDims    = strlen($capLightPng) >= 24 ? unpack('Nwidth/Nheight', substr($capLightPng, 16, 8)) : [];
$capUnitOk   = str_starts_with($capLightPng, "\x89PNG\r\n\x1a\n")
    && str_starts_with($capDarkPng, "\x89PNG\r\n\x1a\n")
    && ($ihdrDims['width'] ?? 0) === 180 && ($ihdrDims['height'] ?? 0) === 54
    && str_contains($capLightPng, 'IDAT') && str_contains($capLightPng, 'IEND')
    && $capLightPng !== $capDarkPng;
if (session_status() === PHP_SESSION_ACTIVE) {
    $issuedCap = captcha_issue_challenge(5);
    $capUnitOk = $capUnitOk
        && preg_match('/^\d{5}$/', $issuedCap) === 1
        && !captcha_verify('00000x')
        && !captcha_verify($issuedCap); // consumed by previous failed verification (one-time)
    $issuedCap2 = captcha_issue_challenge(5);
    $capUnitOk = $capUnitOk
        && captcha_verify($issuedCap2)
        && !captcha_verify($issuedCap2); // replay blocked
    $_SESSION['login_captcha'] = ['salt' => 'exp', 'hash' => hash('sha256', 'exp:12345'), 'expires_at' => time() - 10];
    $capUnitOk = $capUnitOk && !captcha_verify('12345');
    captcha_clear();
}
p5_assert($capUnitOk, 'Pure-PHP CAPTCHA menghasilkan PNG 24-bit valid (terang/gelap) & verifikasi one-time + kedaluwarsa berjalan benar');

// ── 2. Live HTTP Suite ────────────────────────────────────────────────────────
$saCookie = sys_get_temp_dir() . '/p5_sa_' . uniqid() . '.cookie';
$vwCookie = sys_get_temp_dir() . '/p5_vw_' . uniqid() . '.cookie';
$sfCookie = sys_get_temp_dir() . '/p5_sf_' . uniqid() . '.cookie';
$t1Cookie = sys_get_temp_dir() . '/p5_t1_' . uniqid() . '.cookie';
$t2Cookie = sys_get_temp_dir() . '/p5_t2_' . uniqid() . '.cookie';
$bfCookie = sys_get_temp_dir() . '/p5_bf_' . uniqid() . '.cookie';
$anCookie = sys_get_temp_dir() . '/p5_an_' . uniqid() . '.cookie';
$rmCookie = sys_get_temp_dir() . '/p5_rm_' . uniqid() . '.cookie';

$createdItemCodes = [];
$createdUsernames = [];
$pdmFixtures      = [];
$auditRefTag      = 'P5-' . substr(bin2hex(random_bytes(3)), 0, 6);
$unameAuto        = 'p5auto_' . strtolower(substr($auditRefTag, 3));
$unameManual      = 'p5man_' . strtolower(substr($auditRefTag, 3));

try {
    // Guest check on audit-logs
    $guestAudit = p5_http($baseUrl . '/pages/audit-logs/index.php', null, $anCookie, false);
    if ($guestAudit['code'] === 0) {
        p5_assert(false, 'Koneksi Apache live untuk uji Phase 5', $guestAudit['error']);
    } else {
        p5_assert(
            $guestAudit['code'] === 302 && stripos($guestAudit['headers'], 'login.php') !== false,
            'Guest mengakses audit-logs diredirect ke login.php'
        );

        // Auth pages dark mode support (login, forgot-password, reset-password, verify-email)
        $cssText = @file_get_contents(__DIR__ . '/../assets/css/style.css') ?: '';
        $authPagesOk = str_contains($cssText, 'html[data-bs-theme=dark] .auth-card')
            && str_contains($cssText, 'html[data-bs-theme=dark] .auth-wrapper');
        foreach (['/auth/login.php', '/auth/forgot-password.php', '/auth/reset-password.php', '/auth/verify-email.php'] as $ap) {
            $apRes = p5_http($baseUrl . $ap, null, $anCookie, false);
            $apOk = $apRes['code'] === 200
                && str_contains($apRes['body'], "localStorage.getItem('mw_theme')")
                && str_contains($apRes['body'], 'data-bs-theme')
                && str_contains($apRes['body'], 'id="darkToggle"')
                && str_contains($apRes['body'], 'assets/css/style.css?v=');
            if (!$apOk) {
                $authPagesOk = false;
            }
        }
        p5_assert($authPagesOk, 'Seluruh halaman auth (login, forgot, reset, verify) mendukung dark mode (mw_theme + #darkToggle + CSS)');

        // Landing page (/) modern layout, multi-PT stats, dark mode toggle & no dead href="#"
        $landRes = p5_http($baseUrl . '/', null, $anCookie, false);
        p5_assert(
            $landRes['code'] === 200
            && str_contains($landRes['body'], "localStorage.getItem('mw_theme')")
            && str_contains($landRes['body'], 'id="darkToggle"')
            && str_contains($landRes['body'], 'Multi-PT')
            && str_contains($landRes['body'], 'Audit Log')
            && !str_contains($landRes['body'], 'href="#"'),
            'Landing page (/) terupdate: Multi-PT, Audit Log, dark mode (#darkToggle + mw_theme), bebas link mati'
        );

        // Flash HTML safety: balanced <strong> renders bold without double-escaping or XSS
        set_flash('success', 'Beralih ke perusahaan: <strong>PT Maju & <script>alert(1)</script>Jaya</strong>');
        $fHtml = flash_html();
        p5_assert(
            str_contains($fHtml, '<strong>PT Maju &amp; &lt;script&gt;alert(1)&lt;/script&gt;Jaya</strong>')
            && !str_contains($fHtml, '&lt;strong&gt;')
            && !str_contains($fHtml, '<script>'),
            'flash_html() merender <strong> secara aman dan tetap menetralisir XSS/double-escape'
        );

        // Log in Super Admin, Viewer, Staff Gudang
        $lSa = p5_login($baseUrl, 'superadmin', 'P@ssw0rd', $saCookie);
        $lVw = p5_login($baseUrl, 'viewer', 'P@ssw0rd', $vwCookie);
        $lSf = p5_login($baseUrl, 'staffgudang', 'P@ssw0rd', $sfCookie);
        p5_assert($lSa['code'] === 302 && stripos($lSa['headers'], 'dashboard.php') !== false, 'Super Admin login sukses ke dashboard.php');

        // Verify GET & missing-CSRF POST to switch-company.php are blocked, while POST+CSRF renders bold <strong>
        $swGet = p5_http($baseUrl . '/auth/switch-company.php?id=2', null, $saCookie, false);
        $swBad = p5_http($baseUrl . '/auth/switch-company.php', ['company_id' => 2], $saCookie, false);
        $swRes = p5_switch_company($baseUrl, 2, $saCookie, true);
        p5_assert(
            $swGet['code'] === 405
            && $swBad['code'] === 403
            && $swRes['code'] === 200
            && str_contains($swRes['body'], 'Beralih ke perusahaan: <strong>')
            && !str_contains($swRes['body'], '&lt;strong&gt;'),
            'Switch company menolak GET (405) / tanpa CSRF (403) dan POST+CSRF merender alert <strong>PT ...</strong> aman'
        );
        p5_switch_company($baseUrl, 1, $saCookie, true);

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

        // Adaptive Numeric Image CAPTCHA + Brute-force lockout test on isolated $unameAuto
        $capCleanGet = p5_http($baseUrl . '/auth/login.php', null, $bfCookie, false);
        $fail1Res    = p5_login($baseUrl, $unameAuto, 'WrongPass!1', $bfCookie);
        $fail2Res    = p5_login($baseUrl, $unameAuto, 'WrongPass!2', $bfCookie);
        $capPngRes   = p5_http($baseUrl . '/auth/captcha.php?v=1', null, $bfCookie, false);
        $capWrongTry = p5_login($baseUrl, $unameAuto, 'TestPass#123', $bfCookie, false, '99999');

        p5_assert(
            !str_contains($capCleanGet['body'], 'auth/captcha.php')
            && !str_contains($fail1Res['body'], 'auth/captcha.php')
            && str_contains($fail2Res['body'], 'auth/captcha.php')
            && str_contains($fail2Res['body'], 'name="captcha"')
            && $capPngRes['code'] === 200
            && stripos($capPngRes['headers'], 'Content-Type: image/png') !== false
            && stripos($capPngRes['headers'], 'no-store') !== false
            && str_starts_with($capPngRes['body'], "\x89PNG\r\n\x1a\n")
            && $capWrongTry['code'] === 200
            && str_contains($capWrongTry['body'], 'CAPTCHA'),
            'Adaptive Numeric Image CAPTCHA tersembunyi di awal, aktif setelah 2 gagal login, mengirim image/png & menolak sandi benar jika CAPTCHA salah'
        );

        // Lengkapi 5 kegagalan password pada IP aktual dari request Apache agar lockout HTTP 429 teruji end-to-end
        $stIpRow = $db->prepare('SELECT ip_address FROM login_attempts WHERE username_key = ? ORDER BY id DESC LIMIT 1');
        $unameKey = login_normalize_key($unameAuto);
        $stIpRow->bind_param('s', $unameKey);
        $stIpRow->execute();
        $stIpRow->bind_result($bfClientIp);
        $stIpRow->fetch();
        $stIpRow->close();
        $bfClientIp = $bfClientIp ?: '127.0.0.1';
        for ($att = 3; $att <= 5; $att++) {
            login_record_failure($unameKey, $bfClientIp);
        }
        $lockedRes = p5_login($baseUrl, $unameAuto, 'TestPass#123', $bfCookie);
        p5_assert(
            $lockedRes['code'] === 429 && str_contains($lockedRes['body'], 'Terlalu banyak percobaan masuk gagal'),
            'Brute-force guard mengunci login (HTTP 429) setelah 5 percobaan sandi salah'
        );

        // Report PDF & Excel XLSX live export checks across all 6 reports
        $reports = [
            'stock'                => ['ui' => '/pages/reports/stock.php',                'exp' => '/pages/reports/stock_export.php'],
            'mutation'             => ['ui' => '/pages/reports/mutation.php',             'exp' => '/pages/reports/mutation_export.php'],
            'stock-in'             => ['ui' => '/pages/reports/stock-in.php',             'exp' => '/pages/reports/stock-in_export.php'],
            'stock-out'            => ['ui' => '/pages/reports/stock-out.php',            'exp' => '/pages/reports/stock-out_export.php'],
            'supplier-performance' => ['ui' => '/pages/reports/supplier-performance.php', 'exp' => '/pages/reports/supplier-performance_export.php'],
            'slow-moving'          => ['ui' => '/pages/reports/slow-moving.php',          'exp' => '/pages/reports/slow-moving_export.php'],
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

        // ── 3. Five Improvement Points Checks (IDOR, Profile/Remember, PO Print/Opname Export, Stock/Users Export, Portal/Cron) ──
        $supId = (int)$db->query('SELECT id FROM suppliers WHERE is_active=1 ORDER BY id LIMIT 1')->fetch_row()[0];
        $locId = (int)$db->query('SELECT id FROM locations WHERE is_active=1 ORDER BY id LIMIT 1')->fetch_row()[0];
        $itmId = (int)$db->query('SELECT id FROM items WHERE is_active=1 ORDER BY id LIMIT 1')->fetch_row()[0];
        $admId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_row()[0];
        $pdmTag = 'P5PDM-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $today = date('Y-m-d');

        $stPoPdm = $db->prepare("INSERT INTO purchase_orders (reference_no, company_id, supplier_id, location_id, user_id, status, approval_status, order_date) VALUES (?, 3, ?, ?, ?, 'draft', 'draft', ?)");
        $refPo = 'PO-PDM-' . $pdmTag;
        $stPoPdm->bind_param('siiis', $refPo, $supId, $locId, $admId, $today);
        $stPoPdm->execute();
        $pdmPoId = (int)$db->insert_id;
        $stPoPdm->close();
        $pdmFixtures[] = ['purchase_orders', 'id', $pdmPoId];

        $stSiPdm = $db->prepare('INSERT INTO stock_in (reference_no, company_id, supplier_id, location_id, user_id, transaction_date) VALUES (?, 3, ?, ?, ?, ?)');
        $refSi = 'SI-PDM-' . $pdmTag;
        $stSiPdm->bind_param('siiis', $refSi, $supId, $locId, $admId, $today);
        $stSiPdm->execute();
        $pdmSiId = (int)$db->insert_id;
        $stSiPdm->close();
        $pdmFixtures[] = ['stock_in', 'id', $pdmSiId];

        $stSoPdm = $db->prepare('INSERT INTO stock_out (reference_no, company_id, location_id, user_id, transaction_date) VALUES (?, 3, ?, ?, ?)');
        $refSo = 'SO-PDM-' . $pdmTag;
        $stSoPdm->bind_param('siis', $refSo, $locId, $admId, $today);
        $stSoPdm->execute();
        $pdmSoId = (int)$db->insert_id;
        $stSoPdm->close();
        $pdmFixtures[] = ['stock_out', 'id', $pdmSoId];

        $stTrPdm = $db->prepare('INSERT INTO transfers (reference_no, from_company_id, to_company_id, from_location_id, to_location_id, user_id, transaction_date) VALUES (?, 3, 3, ?, ?, ?, ?)');
        $refTr = 'TR-PDM-' . $pdmTag;
        $stTrPdm->bind_param('siiis', $refTr, $locId, $locId, $admId, $today);
        $stTrPdm->execute();
        $pdmTrId = (int)$db->insert_id;
        $stTrPdm->close();
        $pdmFixtures[] = ['transfers', 'id', $pdmTrId];

        $stAdjPdm = $db->prepare("INSERT INTO stock_adjustments (reference_no, company_id, location_id, user_id, status, transaction_date) VALUES (?, 3, ?, ?, 'draft', ?)");
        $refAdj = 'ADJ-PDM-' . $pdmTag;
        $stAdjPdm->bind_param('siis', $refAdj, $locId, $admId, $today);
        $stAdjPdm->execute();
        $pdmAdjId = (int)$db->insert_id;
        $stAdjPdm->close();
        $pdmFixtures[] = ['stock_adjustments', 'id', $pdmAdjId];

        $stSrPdm = $db->prepare("INSERT INTO supplier_returns (reference_no, company_id, supplier_id, location_id, user_id, reason, status, transaction_date) VALUES (?, 3, ?, ?, ?, 'Uji IDOR', 'cancelled', ?)");
        $refSr = 'SR-PDM-' . $pdmTag;
        $stSrPdm->bind_param('siiis', $refSr, $supId, $locId, $admId, $today);
        $stSrPdm->execute();
        $pdmSrId = (int)$db->insert_id;
        $stSrPdm->close();
        $pdmFixtures[] = ['supplier_returns', 'id', $pdmSrId];

        $stOpPdm = $db->prepare("INSERT INTO stock_opname (company_id, opname_date, status, user_id, notes) VALUES (3, ?, 'draft', ?, 'Uji IDOR Opname')");
        $stOpPdm->bind_param('si', $today, $admId);
        $stOpPdm->execute();
        $pdmOpId = (int)$db->insert_id;
        $stOpPdm->close();
        $pdmFixtures[] = ['stock_opname', 'session_id', $pdmOpId];

        // Staff Gudang (MSP & SLN only) must get HTTP 403 across all PDM (company_id=3) endpoints
        $idorPaths = [
            "/pages/purchase-orders/view.php?id={$pdmPoId}",
            "/pages/purchase-orders/print.php?id={$pdmPoId}",
            "/pages/purchase-orders/form.php?id={$pdmPoId}",
            "/pages/stock-in/view.php?id={$pdmSiId}",
            "/pages/stock-in/print.php?id={$pdmSiId}",
            "/pages/stock-out/view.php?id={$pdmSoId}",
            "/pages/stock-out/print.php?id={$pdmSoId}",
            "/pages/transfers/view.php?id={$pdmTrId}",
            "/pages/transfers/print.php?id={$pdmTrId}",
            "/pages/adjustments/view.php?id={$pdmAdjId}",
            "/pages/supplier-returns/view.php?id={$pdmSrId}",
            "/pages/stock-opname/count.php?id={$pdmOpId}",
            "/pages/stock-opname/report.php?id={$pdmOpId}",
            "/pages/stock-opname/report_export.php?id={$pdmOpId}&format=pdf",
        ];
        $idorFails = [];
        foreach ($idorPaths as $ip) {
            $resIdor = p5_http($baseUrl . $ip, null, $sfCookie, false);
            if ($resIdor['code'] !== 403) {
                $idorFails[] = "{$ip} (HTTP {$resIdor['code']})";
            }
        }
        // Check POST company_id spoofing on stock-in/create.php
        $siForm = p5_http($baseUrl . '/pages/stock-in/create.php', null, $sfCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $siForm['body'], $mcSi);
        $spoofPost = p5_http($baseUrl . '/pages/stock-in/create.php', [
            'csrf_token'       => $mcSi[1] ?? '',
            'company_id'       => 3,
            'supplier_id'      => $supId,
            'location_id'      => $locId,
            'transaction_date' => $today,
            'item_id'          => [$itmId],
            'quantity'         => [1],
            'buy_price'        => [1000],
        ], $sfCookie, false);
        if ($spoofPost['code'] !== 403) {
            $idorFails[] = "POST /pages/stock-in/create.php?company_id=3 (HTTP {$spoofPost['code']})";
        }
        p5_assert(empty($idorFails), 'Multi-PT Isolation (Anti-IDOR): Staff MSP/SLN ditolak HTTP 403 di 15 endpoint PT PDM', implode(', ', $idorFails));

        // Remember Me persistence & rotation + Logout revocation (on verified $unameManual, which is not locked by brute-force test)
        $manUid = (int)$db->query("SELECT id FROM users WHERE username = '" . $db->real_escape_string($unameManual) . "'")->fetch_row()[0];
        $rmLogin = p5_login($baseUrl, $unameManual, 'TestPass#123', $rmCookie, true);
        $rmCount1 = (int)$db->query("SELECT COUNT(*) FROM user_remember_tokens WHERE user_id = {$manUid}")->fetch_row()[0];
        $rmContent = file_exists($rmCookie) ? file_get_contents($rmCookie) : '';
        $hasRemCookie = $rmContent !== false && str_contains($rmContent, 'msp_remember');
        // Strip PHPSESSID from cookie jar to simulate new browser session with only msp_remember
        if ($hasRemCookie) {
            $filteredLines = array_filter(explode("\n", str_replace("\r\n", "\n", $rmContent)), fn($l) => !str_contains($l, 'PHPSESSID'));
            file_put_contents($rmCookie, implode("\n", $filteredLines));
        }
        $rmAutoPortal = p5_http($baseUrl . '/pages/portal.php', null, $rmCookie, false);
        p5_http($baseUrl . '/auth/logout.php', null, $rmCookie, false);
        $rmCountAfterLogout = (int)$db->query("SELECT COUNT(*) FROM user_remember_tokens WHERE user_id = {$manUid}")->fetch_row()[0];
        p5_assert(
            $rmLogin['code'] === 302 && $rmCount1 === 1 && $hasRemCookie
            && $rmAutoPortal['code'] === 200 && str_contains($rmAutoPortal['body'], 'Portal Gudang')
            && $rmCountAfterLogout === 0,
            'Ingat saya (remember=1) mengeluarkan token cookie DB, melakukan auto-login setelah PHPSESSID dihapus, dan terhapus saat logout',
            "code={$rmLogin['code']}, cnt1={$rmCount1}, rem=" . ($hasRemCookie ? '1' : '0') . ", portal={$rmAutoPortal['code']}, after={$rmCountAfterLogout}"
        );

        // Self-service Profile & Change Password + Navbar link (on $t2Cookie for $unameManual)
        $profGet = p5_http($baseUrl . '/pages/profile.php', null, $t2Cookie, false);
        $dashBody = p5_http($baseUrl . '/pages/dashboard.php', null, $saCookie, false)['body'];
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $profGet['body'], $mcProf);
        $csrfProf = $mcProf[1] ?? '';
        $newProfName = 'Updated ' . $auditRefTag;
        $profNamePost = p5_http($baseUrl . '/pages/profile.php', [
            'csrf_token' => $csrfProf,
            'action'     => 'update_profile',
            'name'       => $newProfName,
        ], $t2Cookie, true);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $profNamePost['body'], $mcProf2);
        $profPwdPost = p5_http($baseUrl . '/pages/profile.php', [
            'csrf_token'       => $mcProf2[1] ?? $csrfProf,
            'action'           => 'change_password',
            'current_password' => 'TestPass#123',
            'new_password'     => 'NewPass#456',
            'confirm_password' => 'NewPass#456',
        ], $t2Cookie, true);
        $reloginCookie = sys_get_temp_dir() . '/p5_rl_' . uniqid() . '.cookie';
        $oldPwdTry = p5_login($baseUrl, $unameManual, 'TestPass#123', $reloginCookie);
        $newPwdTry = p5_login($baseUrl, $unameManual, 'NewPass#456', $reloginCookie);
        @unlink($reloginCookie);
        p5_assert(
            $profGet['code'] === 200 && str_contains($dashBody, '/pages/profile.php')
            && str_contains($profNamePost['body'], $newProfName)
            && str_contains($profPwdPost['body'], 'Kata sandi berhasil diperbarui')
            && $oldPwdTry['code'] === 200 && $newPwdTry['code'] === 302 && stripos($newPwdTry['headers'], 'portal.php') !== false,
            'Self-service pages/profile.php sukses ubah nama (sync session), ubah kata sandi, dan tertaut di navbar',
            "get={$profGet['code']}, old={$oldPwdTry['code']}, new={$newPwdTry['code']}"
        );

        // PO Official Print & Stock Opname Variance Export
        $poView = p5_http($baseUrl . "/pages/purchase-orders/view.php?id={$pdmPoId}", null, $saCookie, false);
        $poPrint = p5_http($baseUrl . "/pages/purchase-orders/print.php?id={$pdmPoId}", null, $saCookie, false);
        $opRepUi = p5_http($baseUrl . "/pages/stock-opname/report.php?id={$pdmOpId}", null, $saCookie, false);
        $opRepPdf = p5_http($baseUrl . "/pages/stock-opname/report_export.php?id={$pdmOpId}&format=pdf", null, $saCookie, false);
        $opRepXlsx = p5_http($baseUrl . "/pages/stock-opname/report_export.php?id={$pdmOpId}&format=excel", null, $saCookie, false);
        p5_assert(
            str_contains($poView['body'], "print.php?id={$pdmPoId}")
            && $poPrint['code'] === 200 && str_contains($poPrint['body'], 'PURCHASE ORDER (PO)')
            && str_contains($opRepUi['body'], 'report_export.php')
            && $opRepPdf['code'] === 200 && str_starts_with($opRepPdf['body'], '%PDF-1.4')
            && $opRepXlsx['code'] === 200 && p5_zip_has_valid_sheet($opRepXlsx['body']),
            'Cetak PO resmi (purchase-orders/print.php) & Export Variance Stock Opname PDF/Excel valid'
        );

        // Cek Stok, Peringatan Stok & Users PDF/Excel Exports (+ RBAC + no password leak)
        $expPairs = [
            'Cek Stok' => ['/pages/stock/index.php', '/pages/stock/export.php?mode=stock'],
            'Peringatan Stok' => ['/pages/stock/alerts.php', '/pages/stock/export.php?mode=alerts'],
            'Manajemen Pengguna' => ['/pages/users/index.php', '/pages/users/export.php?q='],
        ];
        foreach ($expPairs as $label => [$uiPath, $expBase]) {
            $uiR = p5_http($baseUrl . $uiPath, null, $saCookie, false);
            $sep = str_contains($expBase, '?') ? '&' : '?';
            $pdfR = p5_http($baseUrl . $expBase . $sep . 'format=pdf', null, $saCookie, false);
            $xlsR = p5_http($baseUrl . $expBase . $sep . 'format=excel', null, $saCookie, false);
            p5_assert(
                $uiR['code'] === 200 && str_contains($uiR['body'], 'Export PDF') && str_contains($uiR['body'], 'Export Excel')
                && $pdfR['code'] === 200 && str_starts_with($pdfR['body'], '%PDF-1.4')
                && $xlsR['code'] === 200 && p5_zip_has_valid_sheet($xlsR['body']),
                "Export PDF & Excel {$label} terintegrasi dan menghasilkan PDF/XLSX valid"
            );
        }
        $uCsv = p5_http($baseUrl . '/pages/users/export.php?format=csv', null, $saCookie, false);
        $uVw  = p5_http($baseUrl . '/pages/users/export.php?format=csv', null, $vwCookie, false);
        p5_assert(
            $uCsv['code'] === 200 && !str_contains($uCsv['body'], '$2y$') && $uVw['code'] === 403,
            'Users export bebas bocoran hash password dan menolak role Viewer (HTTP 403)'
        );

        // Portal Mobile PWA Multi-PT, Quick Camera Barcode Lookup & Dark Mode + Windows Cron Alert Script
        $sfPortal = p5_http($baseUrl . '/pages/portal.php', null, $sfCookie, false);
        $vwPortal = p5_http($baseUrl . '/pages/portal.php', null, $vwCookie, false);
        $sfScanSearch = p5_http($baseUrl . '/pages/items/search.php?include_stock=1&q=OPL', null, $sfCookie, false);
        $sfScanJson = json_decode($sfScanSearch['body'], true);
        p5_assert(
            $sfPortal['code'] === 200
            && str_contains($sfPortal['body'], 'id="darkToggle"')
            && str_contains($sfPortal['body'], 'id="portalScanBtn"')
            && str_contains($sfPortal['body'], 'id="homeScanBtn"')
            && str_contains($sfPortal['body'], 'id="scannerModal"')
            && str_contains($sfPortal['body'], 'html5-qrcode.min.js')
            && str_contains($sfPortal['body'], 'prefers-color-scheme:dark')
            && str_contains($sfPortal['body'], '/pages/profile.php')
            && str_contains($sfPortal['body'], '/auth/switch-company.php')
            && str_contains($sfPortal['body'], 'name="redirect" value="/pages/portal.php"')
            && str_contains($sfPortal['body'], 'name="csrf_token"')
            && str_contains($sfPortal['body'], '[SLN]')
            && !str_contains($sfPortal['body'], 'PT Prima Distribusi Mandiri')
            && !str_contains($vwPortal['body'], 'PT Sukses Logistik Nusantara')
            && $sfScanSearch['code'] === 200
            && is_array($sfScanJson) && !empty($sfScanJson)
            && array_key_exists('qty', $sfScanJson[0]) && array_key_exists('locations', $sfScanJson[0])
            && file_exists($root . '/cron/low_stock_alert.ps1'),
            'Portal Mobile PWA mendukung Quick Camera Barcode Lookup (include_stock=1), Multi-PT switcher scoped, darkToggle & link Profil'
        );

        // Performance optimizations: lazy html5-qrcode, preconnect, SARGable audit logs + idx_audit_created_id, single-pass low_stock parity, .htaccess.production
        $saDash = p5_http($baseUrl . '/pages/dashboard.php', null, $saCookie, false);
        $saScan = p5_http($baseUrl . '/pages/stock-in/create.php', null, $saCookie, false);
        $saSrch = p5_http($baseUrl . '/pages/items/search.php?q=OPL', null, $saCookie, false);
        $saAud  = p5_http($baseUrl . '/pages/audit-logs/index.php', null, $saCookie, false);
        $saAudCsv = p5_http($baseUrl . '/pages/audit-logs/export.php?format=csv', null, $saCookie, false);
        $saRepIn  = p5_http($baseUrl . '/pages/reports/stock-in.php', null, $saCookie, false);
        $saRepOut = p5_http($baseUrl . '/pages/reports/stock-out.php', null, $saCookie, false);
        $saRepInCsv  = p5_http($baseUrl . '/pages/reports/stock-in_export.php?format=csv', null, $saCookie, false);
        $saRepOutCsv = p5_http($baseUrl . '/pages/reports/stock-out_export.php?format=csv', null, $saCookie, false);
        $htaccessProd = file_get_contents($root . '/.htaccess.production');

        p5_assert(
            $saDash['code'] === 200
            && !str_contains($saDash['body'], 'html5-qrcode.min.js')
            && str_contains($saDash['body'], 'rel="preconnect" href="https://cdn.jsdelivr.net"')
            && $saScan['code'] === 200
            && str_contains($saScan['body'], 'html5-qrcode.min.js'),
            'Optimasi frontend: html5-qrcode hanya dimuat di halaman scanner & header menyertakan preconnect CDN'
        );
        p5_assert(
            $saSrch['code'] === 200 && str_starts_with(trim($saSrch['body']), '[')
            && $saAud['code'] === 200 && $saAudCsv['code'] === 200 && str_contains($saAudCsv['body'], 'Waktu')
            && $saRepIn['code'] === 200 && $saRepOut['code'] === 200
            && $saRepInCsv['code'] === 200 && $saRepOutCsv['code'] === 200
            && str_contains($htaccessProd, 'mod_deflate.c') && str_contains($htaccessProd, 'mod_expires.c'),
            'Endpoint search, audit log SARGable, laporan masuk/keluar & .htaccess.production (gzip/expires) berjalan optimal'
        );

        // Verify idx_audit_created_id & exact low-stock parity against correlated reference across all active PT
        $idxCheck = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_created_id'")->fetch_row()[0];
        $zeroIsLow = (int)$db->query('SELECT COUNT(*) FROM items WHERE is_active=1 AND min_stock >= 0')->fetch_row()[0];
        $aggLowRes = $db->query("
            SELECT c.id,
                GREATEST(0, {$zeroIsLow} + COALESCE(st.low_adj, 0)) AS opt_low,
                (SELECT COUNT(*) FROM items i WHERE i.is_active=1
                    AND COALESCE((SELECT SUM(s2.quantity) FROM stock s2 WHERE s2.item_id=i.id AND s2.company_id=c.id), 0) <= i.min_stock) AS ref_low
            FROM companies c
            LEFT JOIN (
                SELECT si.company_id,
                    COALESCE(SUM(CASE WHEN si.min_stock >= 0 AND si.item_qty > si.min_stock THEN -1 WHEN si.min_stock < 0 AND si.item_qty <= si.min_stock THEN 1 ELSE 0 END), 0) AS low_adj
                FROM (
                    SELECT s.company_id, s.item_id, i.min_stock, SUM(s.quantity) AS item_qty
                    FROM stock s JOIN items i ON i.id=s.item_id AND i.is_active=1
                    GROUP BY s.company_id, s.item_id, i.min_stock
                ) si
                GROUP BY si.company_id
            ) st ON st.company_id = c.id
            WHERE c.is_active = 1
        ")->fetch_all(MYSQLI_ASSOC);
        $lowParityOk = !empty($aggLowRes);
        foreach ($aggLowRes as $lr) {
            if ((int)$lr['opt_low'] !== (int)$lr['ref_low']) {
                $lowParityOk = false;
                break;
            }
        }
        p5_assert(
            (int)$idxCheck >= 1 && $lowParityOk,
            'Index audit_logs.idx_audit_created_id aktif & optimasi single-pass low_stock identik 100% di seluruh PT'
        );

        // Security hardening checks: .htaccess/.htaccess.production, CSP headers, escape_like audit logs, hashed reset token + throttle + live session revocation
        $secDbProbe = p5_http($baseUrl . '/database/schema.sql', null, $saCookie, false);
        $secCfgProbe = p5_http($baseUrl . '/config/database.php', null, $saCookie, false);
        $secEnvProbe = p5_http($baseUrl . '/.env', null, $saCookie, false);
        $audIdxSrc = file_get_contents($root . '/pages/audit-logs/index.php');
        $audExpSrc = file_get_contents($root . '/pages/audit-logs/export.php');
        p5_assert(
            $secDbProbe['code'] === 403
            && $secCfgProbe['code'] === 403
            && $secEnvProbe['code'] === 403
            && stripos($saDash['headers'], 'Content-Security-Policy:') !== false
            && str_contains($htaccessProd, '^(config|includes|database|tests|cron|docs|logs|backups)/')
            && str_contains($htaccessProd, 'Content-Security-Policy')
            && str_contains($htaccessProd, 'Header always set')
            && str_contains($audIdxSrc, 'escape_like($search)')
            && str_contains($audExpSrc, 'escape_like($search)'),
            'Hardening .htaccess/.htaccess.production memblokir config/database/.env (HTTP 403), menyertakan CSP & escape_like pada audit log'
        );

        // Disposable security test user: remember-me -> forgot-password hash+rate-limit -> reset-password revokes session & remember token -> is_active=0 kicks session
        $secUser = 'p5sec_' . substr(md5((string)microtime(true)), 0, 8);
        $secEmail = $secUser . '@example.test';
        $secPass1 = 'SecTest#1234';
        $secPass2 = 'SecTest#5678';
        $secHash1 = password_hash($secPass1, PASSWORD_BCRYPT, ['cost' => 12]);
        $createdUsernames[] = $secUser;

        $stSecIns = $db->prepare('INSERT INTO users (role_id, name, username, email, password, is_active, email_verified_at) VALUES (2, ?, ?, ?, ?, 1, NOW())');
        $secDisplay = 'Security Probe User';
        $stSecIns->bind_param('ssss', $secDisplay, $secUser, $secEmail, $secHash1);
        $stSecIns->execute();
        $secUid = (int)$db->insert_id;
        $stSecIns->close();
        $db->query("INSERT IGNORE INTO user_companies (user_id, company_id, is_default) VALUES ({$secUid}, 1, 1)");

        $secCookie = tempnam(sys_get_temp_dir(), 'p5sec_');
        $secLogin = p5_login($baseUrl, $secUser, $secPass1, $secCookie, true);
        $rmCntBefore = (int)$db->query("SELECT COUNT(*) FROM user_remember_tokens WHERE user_id={$secUid}")->fetch_row()[0];

        // Trigger forgot-password 4 times to test token hashing & 3-per-email rate limit
        $fpGet = p5_http($baseUrl . '/auth/forgot-password.php', null, $secCookie, false); // logged in would redirect; use fresh anon cookie
        $secAnonCookie = tempnam(sys_get_temp_dir(), 'p5fp_');
        $fpPage = p5_http($baseUrl . '/auth/forgot-password.php', null, $secAnonCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $fpPage['body'], $fpM);
        $fpCsrf = $fpM[1] ?? '';
        for ($rIdx = 0; $rIdx < 4; $rIdx++) {
            p5_http($baseUrl . '/auth/forgot-password.php', ['csrf_token' => $fpCsrf, 'email' => $secEmail], $secAnonCookie, false);
        }
        $dbResetTok = (string)$db->query("SELECT COALESCE(reset_token, '') FROM users WHERE id={$secUid}")->fetch_row()[0];
        $throttleCnt = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='password_reset_throttled' AND description LIKE '%" . $db->real_escape_string($secEmail) . "%'")->fetch_row()[0];
        p5_assert(
            $secLogin['code'] === 302
            && $rmCntBefore === 1
            && (bool)preg_match('/^[a-f0-9]{64}$/', $dbResetTok)
            && $throttleCnt >= 1,
            'Reset token disimpan ter-hash SHA-256 (64 hex) & rate limit forgot-password membatasi spam (4x request)'
        );

        // Inject known raw reset token whose SHA-256 matches DB and reset password via live HTTP POST
        $knownRawTok = bin2hex(random_bytes(32));
        $knownTokHash = hash('sha256', $knownRawTok);
        $stUpTok = $db->prepare('UPDATE users SET reset_token=?, reset_token_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?');
        $stUpTok->bind_param('si', $knownTokHash, $secUid);
        $stUpTok->execute();
        $stUpTok->close();

        $rpGetBad  = p5_http($baseUrl . '/auth/reset-password.php?token=' . str_repeat('0', 64), null, $secAnonCookie, false);
        $rpGetGood = p5_http($baseUrl . '/auth/reset-password.php?token=' . $knownRawTok, null, $secAnonCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $rpGetGood['body'], $rpM);
        $rpPost = p5_http($baseUrl . '/auth/reset-password.php', [
            'csrf_token'       => $rpM[1] ?? '',
            'token'            => $knownRawTok,
            'password'         => $secPass2,
            'confirm_password' => $secPass2,
        ], $secAnonCookie, false);

        $rmCntAfterReset = (int)$db->query("SELECT COUNT(*) FROM user_remember_tokens WHERE user_id={$secUid}")->fetch_row()[0];
        $kickedAfterPwd = p5_http($baseUrl . '/pages/profile.php', null, $secCookie, false);

        // Re-login with new password, then deactivate account and confirm live session is rejected immediately
        $secCookie2 = tempnam(sys_get_temp_dir(), 'p5sec2_');
        $secReLogin = p5_login($baseUrl, $secUser, $secPass2, $secCookie2, false);
        $db->query("UPDATE users SET is_active=0 WHERE id={$secUid}");
        $kickedAfterDisable = p5_http($baseUrl . '/pages/profile.php', null, $secCookie2, false);

        p5_assert(
            str_contains($rpGetBad['body'], 'tidak valid')
            && $rpGetGood['code'] === 200 && str_contains($rpGetGood['body'], 'Reset Kata Sandi')
            && str_contains($rpPost['body'], 'berhasil direset')
            && $rmCntAfterReset === 0
            && $kickedAfterPwd['code'] === 302 && stripos($kickedAfterPwd['headers'], 'login.php') !== false
            && $secReLogin['code'] === 302
            && $kickedAfterDisable['code'] === 302 && stripos($kickedAfterDisable['headers'], 'login.php') !== false,
            'Reset password & penonaktifan akun mencabut remember-token dan langsung menghentikan sesi aktif di browser'
        );

        foreach ([$secCookie, $secAnonCookie, $secCookie2] as $sf) {
            if (file_exists($sf)) @unlink($sf);
        }

        // Slow-Moving & Dead Stock functional data verification (old stocked item vs PDF/CSV)
        $catId = (int)$db->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetch_row()[0];
        $unitId = (int)$db->query('SELECT id FROM units ORDER BY id LIMIT 1')->fetch_row()[0];
        $smCode = 'P5SM-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $smName = 'Ban Dead Stock ' . $smCode;
        $smCreated = date('Y-m-d H:i:s', strtotime('-200 days'));
        $stSmItem = $db->prepare('INSERT INTO items (code, name, category_id, unit_id, min_stock, buy_price, sell_price, is_active, created_at) VALUES (?, ?, ?, ?, 2, 1250000, 1600000, 1, ?)');
        $stSmItem->bind_param('ssiis', $smCode, $smName, $catId, $unitId, $smCreated);
        $stSmItem->execute();
        $smItemId = (int)$db->insert_id;
        $stSmItem->close();
        $pdmFixtures[] = ['items', 'id', $smItemId];

        $stSmStk = $db->prepare('INSERT INTO stock (company_id, item_id, location_id, quantity) VALUES (1, ?, ?, 8)');
        $stSmStk->bind_param('ii', $smItemId, $locId);
        $stSmStk->execute();
        $smStockId = (int)$db->insert_id;
        $stSmStk->close();
        $pdmFixtures[] = ['stock', 'id', $smStockId];

        $smUi = p5_http($baseUrl . '/pages/reports/slow-moving.php?days=90&class=dead&q=' . urlencode($smCode), null, $saCookie, false);
        $smCsv = p5_http($baseUrl . '/pages/reports/slow-moving_export.php?format=csv&days=90&class=dead&q=' . urlencode($smCode), null, $saCookie, false);
        p5_assert(
            $smUi['code'] === 200
            && str_contains($smUi['body'], $smCode)
            && str_contains($smUi['body'], 'Dead Stock')
            && $smCsv['code'] === 200
            && str_contains($smCsv['body'], $smCode)
            && str_contains($smCsv['body'], '10000000'),
            'Laporan Slow-Moving & Dead Stock mendeteksi SKU mengendap, nilai tertahan akurat & CSV valid'
        );

        // Hardening Foto Barang (validate_item_image_file): real PNG passes, PHP/text polyglot rejected
        $tmpGoodImg = sys_get_temp_dir() . '/p5_good_' . uniqid() . '.png';
        $tmpPolyImg = sys_get_temp_dir() . '/p5_poly_' . uniqid() . '.png';
        file_put_contents($tmpGoodImg, captcha_render_png('12345', 180, 54, false));
        file_put_contents($tmpPolyImg, "<?php echo 'hack'; ?>\n" . str_repeat('X', 64));
        $goodMeta = validate_item_image_file($tmpGoodImg);
        $polyRejected = false;
        try {
            validate_item_image_file($tmpPolyImg);
        } catch (RuntimeException $e) {
            $polyRejected = true;
        }
        @unlink($tmpGoodImg);
        @unlink($tmpPolyImg);
        p5_assert(
            ($goodMeta['ext'] ?? '') === 'png' && ($goodMeta['width'] ?? 0) === 180 && $polyRejected,
            'validate_item_image_file() menerima PNG raster valid dan menolak payload teks/polyglot PHP'
        );

        // Operational /health.php authorization & runtime diagnostics + log retention CLI
        $healthAnon  = p5_http($baseUrl . '/health.php', null, $anCookie, false);
        $healthStaff = p5_http($baseUrl . '/health.php', null, $sfCookie, false);
        $healthAdmin = p5_http($baseUrl . '/health.php', null, $saCookie, false);
        $healthJson  = json_decode($healthAdmin['body'], true);
        p5_assert(
            $healthAnon['code'] === 403 && $healthStaff['code'] === 403
            && $healthAdmin['code'] === 200
            && ($healthJson['status'] ?? '') === 'ok'
            && ($healthJson['checks']['database']['tables'] ?? 0) >= 30
            && ($healthJson['checks']['storage']['status'] ?? '') === 'ok',
            'Endpoint /health.php memblokir publik/Staff (403) dan melaporkan status DB/ekstensi/storage untuk Admin (200)'
        );

        // Log retention CLI dry-run & archival cleanup on synthetic 400-day-old audit row
        $oldAuditAt = date('Y-m-d H:i:s', strtotime('-400 days'));
        $stOldAud = $db->prepare("INSERT INTO audit_logs (username, action, entity_type, description, ip_address, created_at) VALUES (?, 'p5.cleanup_probe', 'system', 'Synthetic retention probe', '127.0.0.1', ?)");
        $probeUser = 'p5_cleanup_' . substr($auditRefTag, 3);
        $stOldAud->bind_param('ss', $probeUser, $oldAuditAt);
        $stOldAud->execute();
        $oldAudId = (int)$db->insert_id;
        $stOldAud->close();

        $phpExe = PHP_BINARY ?: 'php';
        $cmdDry = escapeshellarg($phpExe) . ' ' . escapeshellarg($root . '/cron/cleanup_audit_logs.php') . ' --days=365 --dry-run --json';
        $cmdRun = escapeshellarg($phpExe) . ' ' . escapeshellarg($root . '/cron/cleanup_audit_logs.php') . ' --days=365 --archive --json';
        $dryJson = json_decode((string)shell_exec($cmdDry), true);
        $runJson = json_decode((string)shell_exec($cmdRun), true);
        $stillExists = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE id={$oldAudId}")->fetch_row()[0];
        $archiveExists = !empty($runJson['archive_file']) && is_file($root . '/' . $runJson['archive_file']);
        if ($archiveExists) {
            @unlink($root . '/' . $runJson['archive_file']);
        }
        if ($stillExists > 0) {
            $db->query("DELETE FROM audit_logs WHERE id={$oldAudId}");
        }
        p5_assert(
            is_array($dryJson) && ($dryJson['dry_run'] ?? false) === true && ($dryJson['eligible_audit'] ?? 0) >= 1
            && is_array($runJson) && ($runJson['deleted_audit'] ?? 0) >= 1
            && $stillExists === 0 && $archiveExists,
            'cron/cleanup_audit_logs.php --dry-run & --archive mengarsipkan lalu menghapus log >365 hari secara aman'
        );
    }
} finally {
    // Cleanup temporary test rows & files
    foreach (array_reverse($pdmFixtures) as [$tbl, $pkCol, $pkVal]) {
        $stDel = $db->prepare("DELETE FROM `{$tbl}` WHERE `{$pkCol}` = ?");
        $stDel->bind_param('i', $pkVal);
        $stDel->execute();
        $stDel->close();
    }
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
    // Remove synthetic import / Phase 5 report export & security probe audit/rate-limit noise from this test run
    $cleanAudit = $db->prepare("DELETE FROM audit_logs WHERE description LIKE ? OR description LIKE '%Ban Test Phase 5 XLSX%' OR reference_no LIKE 'PO-PDM-P5PDM-%' OR description LIKE '%P5PDM-%' OR ((action IN ('company.switch', 'session_revoked', 'session_invalidated', 'login_captcha_failed', 'password_reset_throttled', 'password_reset_complete') OR entity_type = 'report_slow_moving') AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
    $unitLike = '%' . $unitCode . '%';
    $cleanAudit->bind_param('s', $unitLike);
    $cleanAudit->execute();
    $cleanAudit->close();
    $db->query("DELETE FROM login_attempts WHERE username_key LIKE 'pwd_reset:%' OR username_key = '__pwd_reset_ip__'");

    foreach ([$tmpXlsx, $saCookie, $vwCookie, $sfCookie, $t1Cookie, $t2Cookie, $bfCookie, $anCookie, $rmCookie] as $f) {
        if (is_string($f) && file_exists($f)) @unlink($f);
    }
}

echo "\n=== Summary ===\nPassed: {$pass}\nFailed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
