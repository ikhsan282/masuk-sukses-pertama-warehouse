<?php
/**
 * Master Data Export (PDF & Excel OOXML .xlsx) Automated Verification Suite
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

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/export.php';

$pass = 0;
$fail = 0;

function me_assert(bool $cond, string $label, string $detail = ''): void
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

echo "=== Master Export (PDF & Excel XLSX) Verification Suite ===\n\n";

// ── 1. Unit Tests: Dropdown UI Helper & Registry Consistency ──────────────────
$dropdownHtml = master_export_dropdown_html(['q' => 'OPL "test"', 'category_id' => 2, 'empty' => '']);
me_assert(
    str_contains($dropdownHtml, 'Export')
    && str_contains($dropdownHtml, 'Export PDF')
    && str_contains($dropdownHtml, 'Export Excel')
    && str_contains($dropdownHtml, 'format=pdf')
    && str_contains($dropdownHtml, 'format=excel')
    && !str_contains($dropdownHtml, 'empty='),
    'master_export_dropdown_html() merender dropdown Export PDF & Excel dengan filter non-kosong'
);

$masterPages = [
    'pages/companies/index.php',
    'pages/items/index.php',
    'pages/categories/index.php',
    'pages/units/index.php',
    'pages/suppliers/index.php',
    'pages/locations/index.php',
];
$allUiOk = true;
$missingUi = [];
foreach ($masterPages as $mp) {
    $src = file_get_contents(__DIR__ . '/../' . $mp);
    $ep  = str_replace('index.php', 'export.php', $mp);
    if (!str_contains($src, 'master_export_dropdown_html(') || !file_exists(__DIR__ . '/../' . $ep)) {
        $allUiOk = false;
        $missingUi[] = $mp;
    }
}
me_assert($allUiOk, 'Seluruh 6 halaman Data Master memiliki dropdown Export & file export.php', implode(', ', $missingUi));

// ── 2. Unit Tests: XLSX OOXML Binary + CWE-1236 Neutralization + Inflate Check ─
$cols = [
    ['label' => '#',     'type' => 'int',      'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
    ['label' => 'Kode',  'type' => 'text',     'align' => 'L', 'pdf_width' => 80,  'xlsx_width' => 16],
    ['label' => 'Nama',  'type' => 'text',     'align' => 'L', 'pdf_width' => 180, 'xlsx_width' => 30],
    ['label' => 'Harga', 'type' => 'currency', 'align' => 'R', 'pdf_width' => 80,  'xlsx_width' => 16],
];
$sampleRows = [
    [1, '00123', '=CMD|"/C calc"!A0', 210000],
    [2, 'MSP-02', "+SUM(A1:A2) & <Tag> 'Test'", 350000],
];

$xlsxBytes = export_xlsx_build('Judul <Uji> & Ekspor', 'PT: MSP | Cakupan Test', $cols, $sampleRows, 'Sheet:Data/1');
me_assert(str_starts_with($xlsxBytes, "PK\x03\x04"), 'export_xlsx_build() menghasilkan header ZIP PK valid');

// Parse ZIP entries manually to inspect xl/worksheets/sheet1.xml & styles.xml
function parse_zip_entries(string $zip): array {
    $entries = [];
    $pos = 0;
    $len = strlen($zip);
    while ($pos + 30 <= $len) {
        if (substr($zip, $pos, 4) !== "PK\x03\x04") break;
        $hdr = unpack('Vsig/vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($zip, $pos, 30));
        $nameStart = $pos + 30;
        $name = substr($zip, $nameStart, $hdr['nlen']);
        $dataStart = $nameStart + $hdr['nlen'] + $hdr['elen'];
        $comp = substr($zip, $dataStart, $hdr['csize']);
        $raw = $hdr['method'] === 8 ? @gzinflate($comp) : $comp;
        $entries[$name] = $raw;
        $pos = $dataStart + $hdr['csize'];
    }
    return $entries;
}

$zipEntries = parse_zip_entries($xlsxBytes);
me_assert(
    isset($zipEntries['[Content_Types].xml'], $zipEntries['xl/workbook.xml'], $zipEntries['xl/styles.xml'], $zipEntries['xl/worksheets/sheet1.xml']),
    'Arsip XLSX memuat [Content_Types].xml, workbook.xml, styles.xml, & sheet1.xml'
);

$sheetXmlStr = (string)($zipEntries['xl/worksheets/sheet1.xml'] ?? '');
$sheetParsed = @simplexml_load_string($sheetXmlStr);
me_assert($sheetParsed !== false, 'xl/worksheets/sheet1.xml valid secara sintaks XML');
$decodedC5 = $sheetParsed ? (string)($sheetParsed->sheetData->row[4]->c[2]->is->t ?? '') : '';
$decodedC6 = $sheetParsed ? (string)($sheetParsed->sheetData->row[5]->c[2]->is->t ?? '') : '';
me_assert(
    str_contains($sheetXmlStr, '&apos;=CMD|&quot;/C calc&quot;!A0')
    && str_contains($sheetXmlStr, '&apos;+SUM(A1:A2) &amp; &lt;Tag&gt; &apos;Test&apos;')
    && $decodedC5 === '\'=CMD|"/C calc"!A0'
    && $decodedC6 === '\'+SUM(A1:A2) & <Tag> \'Test\'',
    'Cell string XLSX menetralkan payload formula CWE-1236 (=, +) & meng-escape karakter XML'
);

// ── 3. Unit Tests: Vector PDF 1.4 Multi-Page Structure & WinAnsi Safety ───────
$manyRows = [];
for ($r = 1; $r <= 95; $r++) {
    $manyRows[] = [$r, 'KODE-' . $r, 'Ban Radial Heavy Duty Panjang Sekali Nomor ' . $r . ' Untuk Uji Bungkus Teks & Halaman', $r * 10000];
}
$pdfBytes = export_pdf_build('Laporan Uji Multi Halaman', 'PT Masuk Sukses Pertama — Uji WinAnsi', $cols, $manyRows, 'L');
me_assert(str_starts_with($pdfBytes, '%PDF-1.4'), 'export_pdf_build() diawali signature %PDF-1.4');
me_assert(str_contains($pdfBytes, "\nxref\n") && str_contains($pdfBytes, "%%EOF\n"), 'export_pdf_build() menyertakan tabel xref dan EOF valid');
me_assert(preg_match('/\/Count\s+([2-9]|\d{2,})/', $pdfBytes) === 1, 'export_pdf_build() otomatis memecah 95 baris menjadi multi-halaman (>1 pages)');

// ── 4. Live Apache HTTP Tests for All 6 Master Exports + RBAC ─────────────────
$baseUrl = rtrim(getenv('UAT_BASE_URL') ?: 'http://localhost:8080/masuk-sukses-pertama-warehouse', '/');

function me_http(string $url, ?array $postData, string $cookieFile, bool $follow = false): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ];
    if ($postData !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postData);
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

$saCookie = sys_get_temp_dir() . '/me_sa_' . uniqid() . '.cookie';
$vCookie  = sys_get_temp_dir() . '/me_vw_' . uniqid() . '.cookie';
$anonCookie = sys_get_temp_dir() . '/me_an_' . uniqid() . '.cookie';

try {
    // Guest redirect
    $anon = me_http($baseUrl . '/pages/items/export.php?format=pdf', null, $anonCookie, false);
    if ($anon['code'] === 0) {
        me_assert(false, 'Koneksi Apache live untuk uji Master Export', $anon['error']);
    } else {
        me_assert($anon['code'] === 302 && stripos($anon['headers'], 'login.php') !== false, 'Guest mengakses master export diredirect ke login.php');

        // Login superadmin
        $lg = me_http($baseUrl . '/auth/login.php', null, $saCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lg['body'], $m);
        me_http($baseUrl . '/auth/login.php', [
            'csrf_token' => $m[1] ?? '',
            'username'   => 'superadmin',
            'password'   => 'P@ssw0rd',
        ], $saCookie, false);

        // Login viewer
        $vg = me_http($baseUrl . '/auth/login.php', null, $vCookie, false);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $vg['body'], $mv);
        me_http($baseUrl . '/auth/login.php', [
            'csrf_token' => $mv[1] ?? '',
            'username'   => 'viewer',
            'password'   => 'P@ssw0rd',
        ], $vCookie, false);

        // RBAC: Viewer cannot export companies (403), CAN export items (200)
        $vCompExp = me_http($baseUrl . '/pages/companies/export.php?format=pdf', null, $vCookie, false);
        $vItemExp = me_http($baseUrl . '/pages/items/export.php?format=excel', null, $vCookie, false);
        me_assert($vCompExp['code'] === 403, 'RBAC: Viewer ditolak (HTTP 403) pada companies/export.php');
        me_assert($vItemExp['code'] === 200 && str_starts_with($vItemExp['body'], "PK\x03\x04"), 'RBAC: Viewer diizinkan mengunduh items/export.php?format=excel');

        // All 6 masters: index HTML contains Export dropdown + PDF & Excel endpoints work cleanly
        $masters = ['companies', 'items', 'categories', 'units', 'suppliers', 'locations'];
        foreach ($masters as $dir) {
            $idx = me_http($baseUrl . "/pages/{$dir}/index.php", null, $saCookie, false);
            me_assert(
                $idx['code'] === 200
                && str_contains($idx['body'], 'export.php?format=pdf')
                && str_contains($idx['body'], 'export.php?format=excel')
                && str_contains($idx['body'], 'Export PDF')
                && str_contains($idx['body'], 'Export Excel'),
                "UI Master {$dir}: tombol Export PDF & Excel terender di index.php"
            );

            $pdfRes = me_http($baseUrl . "/pages/{$dir}/export.php?format=pdf", null, $saCookie, false);
            me_assert(
                $pdfRes['code'] === 200
                && stripos($pdfRes['headers'], 'application/pdf') !== false
                && str_starts_with($pdfRes['body'], '%PDF-1.4')
                && str_contains($pdfRes['body'], '%%EOF'),
                "Live PDF Master {$dir}: Content-Type application/pdf & binary PDF 1.4 valid (" . strlen($pdfRes['body']) . " B)"
            );

            $xlRes = me_http($baseUrl . "/pages/{$dir}/export.php?format=excel", null, $saCookie, false);
            $entries = parse_zip_entries($xlRes['body']);
            $xmlOk = isset($entries['xl/worksheets/sheet1.xml']) && @simplexml_load_string((string)$entries['xl/worksheets/sheet1.xml']) !== false;
            me_assert(
                $xlRes['code'] === 200
                && stripos($xlRes['headers'], 'spreadsheetml.sheet') !== false
                && str_starts_with($xlRes['body'], "PK\x03\x04")
                && $xmlOk,
                "Live Excel Master {$dir}: Content-Type XLSX & XML sheet1 valid (" . strlen($xlRes['body']) . " B)"
            );
        }

        // Filtered items export verification (?q=OPL)
        $filtXl = me_http($baseUrl . '/pages/items/export.php?q=OPL&format=excel', null, $saCookie, false);
        $fEntries = parse_zip_entries($filtXl['body']);
        $fSheet = (string)($fEntries['xl/worksheets/sheet1.xml'] ?? '');
        me_assert(
            $filtXl['code'] === 200 && str_contains($fSheet, 'Cari: &quot;OPL&quot;') && str_contains($fSheet, 'OPL'),
            'Filtered Export items (?q=OPL&format=excel) membawa parameter pencarian ke file XLSX'
        );
    }
} finally {
    @unlink($saCookie);
    @unlink($vCookie);
    @unlink($anonCookie);
}

echo "\n=== Summary ===\nPassed: {$pass}\nFailed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
