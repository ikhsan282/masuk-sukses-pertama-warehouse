<?php
/**
 * Pure-PHP Master Data Export Engine (PDF 1.4 & OOXML .xlsx)
 * Zero external / Composer / ext-zip dependency.
 */

defined('EXPORT_MASTER_PDF_MAX_ROWS')  || define('EXPORT_MASTER_PDF_MAX_ROWS', 3000);
defined('EXPORT_MASTER_XLSX_MAX_ROWS') || define('EXPORT_MASTER_XLSX_MAX_ROWS', 25000);

// ── Minimal RFC-1951 ZIP Archive Builder (works without ext-zip) ───────────────

function export_zip_build(array $files): string {
    $dataSection = '';
    $centralDir  = '';
    $offset      = 0;
    $count       = 0;

    $dt      = getdate();
    $dosTime = (($dt['hours'] & 0x1f) << 11) | (($dt['minutes'] & 0x3f) << 5) | (($dt['seconds'] >> 1) & 0x1f);
    $dosDate = ((max(1980, $dt['year']) - 1980) << 9) | (($dt['mon'] & 0x0f) << 5) | ($dt['mday'] & 0x1f);
    $useDeflate = function_exists('gzdeflate');

    foreach ($files as $name => $content) {
        $name    = ltrim((string)$name, '/');
        $raw     = (string)$content;
        $uLen    = strlen($raw);
        $crc     = crc32($raw) & 0xffffffff;
        $method  = 0;
        $comp    = $raw;

        if ($useDeflate && $uLen > 0) {
            $deflated = gzdeflate($raw, 6);
            if ($deflated !== false && strlen($deflated) < $uLen) {
                $comp   = $deflated;
                $method = 8;
            }
        }
        $cLen    = strlen($comp);
        $nameLen = strlen($name);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $cLen,
            $uLen,
            $nameLen,
            0
        );

        $dataSection .= $localHeader . $name . $comp;

        $centralDir .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0x0800,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $cLen,
            $uLen,
            $nameLen,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $nameLen + $cLen;
        $count++;
    }

    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        strlen($centralDir),
        strlen($dataSection),
        0
    );

    return $dataSection . $centralDir . $eocd;
}

// ── OOXML Spreadsheet (.xlsx) Builder ─────────────────────────────────────────

function export_xlsx_col_letter(int $index): string {
    $letter = '';
    $n = $index + 1;
    while ($n > 0) {
        $mod = ($n - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $n = intdiv($n - 1, 26);
    }
    return $letter;
}

function export_xlsx_xml_text(string $value): string {
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function export_xlsx_build(string $title, string $subtitle, array $columns, array $rows, string $sheetTitle = 'Data'): string {
    $sheetTitle = preg_replace('/[:\\\\\/?*\[\]]/', '-', $sheetTitle) ?? 'Data';
    $sheetTitle = mb_substr(trim($sheetTitle) ?: 'Data', 0, 31, 'UTF-8');

    $colCount = max(1, count($columns));
    $lastCol  = export_xlsx_col_letter($colCount - 1);

    // Track visible lengths per column to auto-size nicely
    $widths = [];
    foreach ($columns as $idx => $col) {
        $widths[$idx] = max((float)($col['xlsx_width'] ?? 10), mb_strlen($col['label'], 'UTF-8') + 4);
    }

    $sheetRowsXml = '';

    // Row 1: Title banner
    $sheetRowsXml .= '<row r="1" ht="24" customHeight="1">'
        . '<c r="A1" t="inlineStr" s="1"><is><t xml:space="preserve">' . export_xlsx_xml_text($title) . '</t></is></c>';
    for ($c = 1; $c < $colCount; $c++) {
        $sheetRowsXml .= '<c r="' . export_xlsx_col_letter($c) . '1" s="1"/>';
    }
    $sheetRowsXml .= '</row>';

    // Row 2: Subtitle / filter scope
    $sheetRowsXml .= '<row r="2" ht="18" customHeight="1">'
        . '<c r="A2" t="inlineStr" s="2"><is><t xml:space="preserve">' . export_xlsx_xml_text($subtitle) . '</t></is></c>';
    for ($c = 1; $c < $colCount; $c++) {
        $sheetRowsXml .= '<c r="' . export_xlsx_col_letter($c) . '2" s="2"/>';
    }
    $sheetRowsXml .= '</row>';

    // Row 3: Spacer
    $sheetRowsXml .= '<row r="3" ht="8" customHeight="1"/>';

    // Row 4: Table header
    $sheetRowsXml .= '<row r="4" ht="22" customHeight="1">';
    foreach ($columns as $idx => $col) {
        $ref = export_xlsx_col_letter($idx) . '4';
        $sheetRowsXml .= '<c r="' . $ref . '" t="inlineStr" s="3"><is><t xml:space="preserve">'
            . export_xlsx_xml_text($col['label']) . '</t></is></c>';
    }
    $sheetRowsXml .= '</row>';

    // Row 5+: Data rows
    $excelRow = 5;
    foreach ($rows as $row) {
        $sheetRowsXml .= '<row r="' . $excelRow . '">';
        foreach ($columns as $idx => $col) {
            $ref  = export_xlsx_col_letter($idx) . $excelRow;
            $type = $col['type'] ?? 'text';
            $val  = $row[$idx] ?? '';

            if ($type === 'int' || $type === 'currency') {
                $num = is_numeric($val) ? ($type === 'int' ? (int)$val : (float)$val) : 0;
                $style = $type === 'int' ? '5' : '6';
                $dispLen = strlen(number_format((float)$num, 0, ',', '.')) + 3;
                if ($dispLen > $widths[$idx]) {
                    $widths[$idx] = min(48, (float)$dispLen);
                }
                $sheetRowsXml .= '<c r="' . $ref . '" t="n" s="' . $style . '"><v>' . $num . '</v></c>';
            } else {
                $str = function_exists('csv_safe_cell') ? csv_safe_cell((string)$val) : (string)$val;
                $len = mb_strlen($str, 'UTF-8') + 3;
                if ($len > $widths[$idx]) {
                    $widths[$idx] = min(48, (float)$len);
                }
                $style = ($col['align'] ?? 'L') === 'C' ? '7' : '4';
                $sheetRowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
                    . export_xlsx_xml_text($str) . '</t></is></c>';
            }
        }
        $sheetRowsXml .= '</row>';
        $excelRow++;
    }

    $lastRow = max(4, $excelRow - 1);
    $colsXml = '<cols>';
    foreach ($widths as $idx => $w) {
        $c1 = $idx + 1;
        $colsXml .= '<col min="' . $c1 . '" max="' . $c1 . '" width="' . number_format(min(48, max(6, $w)), 2, '.', '') . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
        . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . $colsXml
        . '<sheetData>' . $sheetRowsXml . '</sheetData>'
        . '<autoFilter ref="A4:' . $lastCol . $lastRow . '"/>'
        . '<mergeCells count="2"><mergeCell ref="A1:' . $lastCol . '1"/><mergeCell ref="A2:' . $lastCol . '2"/></mergeCells>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
        . '<fonts count="4">'
        .   '<font><sz val="10"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="13"/><color rgb="FF146C43"/><name val="Calibri"/></font>'
        .   '<font><i/><sz val="9"/><color rgb="FF4B5563"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF198754"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . export_xlsx_xml_text($sheetTitle) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    return export_zip_build([
        '[Content_Types].xml'        => $contentTypesXml,
        '_rels/.rels'                => $rootRelsXml,
        'xl/workbook.xml'            => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $wbRelsXml,
        'xl/styles.xml'              => $stylesXml,
        'xl/worksheets/sheet1.xml'   => $sheetXml,
    ]);
}

function export_xlsx_send(string $filename, string $title, string $subtitle, array $columns, array $rows, string $sheetTitle = 'Data'): void {
    $bytes = export_xlsx_build($title, $subtitle, $columns, $rows, $sheetTitle);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}

// ── Pure-PHP Vector PDF 1.4 Table Builder ─────────────────────────────────────

function export_pdf_winansi(string $text): string {
    $normalized = strtr($text, [
        "\u{2022}" => '-',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\r\n"     => ' ',
        "\r"       => ' ',
        "\n"       => ' ',
        "\t"       => ' ',
    ]);
    $converted = function_exists('iconv')
        ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $normalized)
        : $normalized;
    if ($converted === false) {
        $converted = $normalized;
    }
    return preg_replace('/[\x00-\x1F\x7F]/', ' ', $converted) ?? '';
}

function export_pdf_escape(string $winAnsi): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $winAnsi);
}

function export_pdf_text_width(string $winAnsi, float $fontSize, bool $bold = false): float {
    $w = 0.0;
    $len = strlen($winAnsi);
    for ($i = 0; $i < $len; $i++) {
        $ch = $winAnsi[$i];
        if (str_contains('ilIjft1.,:;!\'| -', $ch)) {
            $w += 0.31;
        } elseif ($ch === 'W' || $ch === 'M' || $ch === '@' || $ch === '%') {
            $w += 0.94;
        } elseif ($ch === 'm' || $ch === 'w') {
            $w += 0.78;
        } elseif (str_contains('CDGHNOQRU', $ch)) {
            $w += 0.75;
        } elseif ($ch >= 'A' && $ch <= 'Z') {
            $w += 0.69;
        } elseif ($ch >= '0' && $ch <= '9') {
            $w += 0.57;
        } else {
            $w += 0.56;
        }
    }
    return $w * $fontSize * ($bold ? 1.07 : 1.0);
}

function export_pdf_wrap(string $text, float $maxWidth, float $fontSize): array {
    $str = trim(export_pdf_winansi($text));
    if ($str === '') {
        return [''];
    }
    if (export_pdf_text_width($str, $fontSize) <= $maxWidth) {
        return [$str];
    }

    $lines   = [];
    $current = '';
    $words   = preg_split('/\s+/', $str) ?: [$str];

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        if (export_pdf_text_width($candidate, $fontSize) <= $maxWidth) {
            $current = $candidate;
            continue;
        }
        if ($current !== '') {
            $lines[] = $current;
            $current = '';
        }
        // Hard-break long tokens (e.g., long SKUs or emails)
        if (export_pdf_text_width($word, $fontSize) > $maxWidth) {
            $chunk = '';
            $wlen = strlen($word);
            for ($i = 0; $i < $wlen; $i++) {
                $ch = $word[$i];
                if ($chunk !== '' && export_pdf_text_width($chunk . $ch, $fontSize) > $maxWidth) {
                    $lines[] = $chunk;
                    $chunk = $ch;
                } else {
                    $chunk .= $ch;
                }
            }
            $current = $chunk;
        } else {
            $current = $word;
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }
    return $lines ?: [''];
}

function export_pdf_draw_text(float $x, float $y, string $winAnsi, float $size, bool $bold = false): string {
    $font = $bold ? '/F2' : '/F1';
    return sprintf("BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, export_pdf_escape($winAnsi));
}

function export_pdf_build(string $title, string $subtitle, array $columns, array $rows, string $orientation = 'L'): string {
    $isLandscape = strtoupper($orientation) === 'L';
    $pageW = $isLandscape ? 841.89 : 595.28;
    $pageH = $isLandscape ? 595.28 : 841.89;

    $marginX = 28.0;
    $marginY = 30.0;
    $usableW = $pageW - (2 * $marginX);

    // Proportional column widths scaled to exact table width
    $rawWeights = [];
    foreach ($columns as $col) {
        $rawWeights[] = max(12.0, (float)($col['pdf_width'] ?? 60));
    }
    $weightSum = array_sum($rawWeights) ?: 1.0;
    $colWidths = array_map(static fn(float $w): float => ($w / $weightSum) * $usableW, $rawWeights);

    $cellPadX   = 4.5;
    $cellPadY   = 3.8;
    $headerFs   = 8.0;
    $bodyFs     = 7.6;
    $lineHeight = 9.8;
    $headerRowH = 21.0;
    $footerArea = 18.0;

    $pageBodies = [];

    $startPage = static function (bool $isFirst) use ($title, $subtitle, $columns, $colWidths, $marginX, $pageH, $marginY, $usableW, $cellPadX, $headerFs, $headerRowH): array {
        $y = $pageH - $marginY;
        $buf = "0.50 w\n";

        // Banner title & subtitle
        $buf .= "0.078 0.424 0.263 rg\n";
        $buf .= export_pdf_draw_text($marginX, $y - 12, export_pdf_winansi($title), $isFirst ? 13.0 : 10.5, true);
        $y -= ($isFirst ? 18.0 : 15.0);

        $buf .= "0.333 0.376 0.420 rg\n";
        $buf .= export_pdf_draw_text($marginX, $y - 8, export_pdf_winansi($subtitle), 8.0, false);
        $y -= 16.0;

        // Emerald table header bar
        $buf .= "0.098 0.529 0.329 rg\n";
        $buf .= sprintf("%.2F %.2F %.2F %.2F re f\n", $marginX, $y - $headerRowH, $usableW, $headerRowH);

        // Header borders & white bold labels
        $buf .= "0.078 0.424 0.263 RG\n";
        $buf .= sprintf("%.2F %.2F %.2F %.2F re S\n", $marginX, $y - $headerRowH, $usableW, $headerRowH);
        $x = $marginX;
        $buf .= "1 1 1 rg\n";
        foreach ($columns as $idx => $col) {
            $cw    = $colWidths[$idx];
            $label = export_pdf_winansi($col['label']);
            $align = $col['align'] ?? 'L';
            $tw    = export_pdf_text_width($label, $headerFs, true);
            if ($align === 'R') {
                $tx = max($x + $cellPadX, $x + $cw - $cellPadX - $tw);
            } elseif ($align === 'C') {
                $tx = max($x + $cellPadX, $x + (($cw - $tw) / 2.0));
            } else {
                $tx = $x + $cellPadX;
            }
            $buf .= export_pdf_draw_text($tx, $y - 13.5, $label, $headerFs, true);
            if ($idx > 0) {
                $buf .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $y, $x, $y - $headerRowH);
            }
            $x += $cw;
        }
        $y -= $headerRowH;
        return [$buf, $y];
    };

    [$curBuf, $curY] = $startPage(true);

    if (empty($rows)) {
        $emptyH = 24.0;
        $curBuf .= "0.816 0.843 0.871 RG\n";
        $curBuf .= sprintf("%.2F %.2F %.2F %.2F re S\n", $marginX, $curY - $emptyH, $usableW, $emptyH);
        $curBuf .= "0.420 0.447 0.502 rg\n";
        $curBuf .= export_pdf_draw_text($marginX + 10, $curY - 15, 'Tidak ada data untuk diekspor.', $bodyFs, false);
        $pageBodies[] = $curBuf;
    } else {
        foreach ($rows as $rowIdx => $row) {
            $wrappedCols = [];
            $maxLines = 1;
            foreach ($columns as $idx => $col) {
                $cw   = $colWidths[$idx];
                $type = $col['type'] ?? 'text';
                $raw  = $row[$idx] ?? '';
                if ($type === 'int') {
                    $disp = number_format((int)$raw, 0, ',', '.');
                } elseif ($type === 'currency') {
                    $disp = 'Rp ' . number_format((float)$raw, 0, ',', '.');
                } else {
                    $disp = (string)$raw;
                }
                $lines = export_pdf_wrap($disp, max(10.0, $cw - (2 * $cellPadX) - 1.5), $bodyFs);
                $wrappedCols[$idx] = $lines;
                if (count($lines) > $maxLines) {
                    $maxLines = count($lines);
                }
            }
            $rowH = ($maxLines * $lineHeight) + (2 * $cellPadY);

            if (($curY - $rowH) < ($marginY + $footerArea)) {
                $pageBodies[] = $curBuf;
                [$curBuf, $curY] = $startPage(false);
            }

            // Zebra striping on odd visual rows
            if ($rowIdx % 2 === 1) {
                $curBuf .= "0.969 0.980 0.973 rg\n";
                $curBuf .= sprintf("%.2F %.2F %.2F %.2F re f\n", $marginX, $curY - $rowH, $usableW, $rowH);
            }

            // Grid border
            $curBuf .= "0.820 0.835 0.859 RG\n";
            $curBuf .= sprintf("%.2F %.2F %.2F %.2F re S\n", $marginX, $curY - $rowH, $usableW, $rowH);

            $x = $marginX;
            $curBuf .= "0.122 0.161 0.216 rg\n";
            foreach ($columns as $idx => $col) {
                $cw    = $colWidths[$idx];
                $align = $col['align'] ?? 'L';
                if ($idx > 0) {
                    $curBuf .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $curY, $x, $curY - $rowH);
                }
                foreach ($wrappedCols[$idx] as $lineNo => $lineTxt) {
                    $tw = export_pdf_text_width($lineTxt, $bodyFs);
                    if ($align === 'R') {
                        $tx = max($x + $cellPadX, $x + $cw - $cellPadX - $tw);
                    } elseif ($align === 'C') {
                        $tx = max($x + $cellPadX, $x + (($cw - $tw) / 2.0));
                    } else {
                        $tx = $x + $cellPadX;
                    }
                    $ty = $curY - $cellPadY - ($bodyFs * 0.85) - ($lineNo * $lineHeight);
                    $curBuf .= export_pdf_draw_text($tx, $ty, $lineTxt, $bodyFs, false);
                }
                $x += $cw;
            }
            $curY -= $rowH;
        }
        $pageBodies[] = $curBuf;
    }

    // Stamp page footers with exact total page count
    $totalPages = count($pageBodies);
    $printedAt  = date('d/m/Y H:i');
    foreach ($pageBodies as $i => $stream) {
        $pNum = $i + 1;
        $footerY = $marginY - 14.0;
        $stream .= "0.420 0.447 0.502 rg\n";
        $stream .= export_pdf_draw_text($marginX, $footerY, "MSP Warehouse - Dicetak {$printedAt}", 7.5, false);
        $pageLbl = "Hal. {$pNum} / {$totalPages}";
        $lblW = export_pdf_text_width($pageLbl, 7.5);
        $stream .= export_pdf_draw_text($pageW - $marginX - $lblW, $footerY, $pageLbl, 7.5, false);
        $pageBodies[$i] = $stream;
    }

    // Assemble PDF 1.4 objects cleanly with exact xref byte offsets
    $objects = [];
    $pageObjIds = [];

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    $nextId = 5;
    $useFlate = function_exists('gzcompress');
    foreach ($pageBodies as $stream) {
        $contentId = $nextId++;
        $pageId    = $nextId++;
        $pageObjIds[] = $pageId;

        if ($useFlate) {
            $compressed = gzcompress($stream, 6);
            if ($compressed !== false) {
                $objects[$contentId] = "<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
            } else {
                $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            }
        } else {
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[$pageId] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
            $pageW,
            $pageH,
            $contentId
        );
    }

    $kidsRefs = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjIds));
    $objects[2] = "<< /Type /Pages /Kids [{$kidsRefs}] /Count {$totalPages} >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxObj = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxObj; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    return $pdf;
}

function export_pdf_send(string $filename, string $title, string $subtitle, array $columns, array $rows, string $orientation = 'L'): void {
    $bytes = export_pdf_build($title, $subtitle, $columns, $rows, $orientation);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}

// ── Master Entity Export Registry & Dispatcher ────────────────────────────────

function master_export_fetch(mysqli $db, string $entity, int $maxRows): array {
    $compName = current_company()['name'] ?? 'PT Masuk Sukses Pertama';
    $compCode = current_company()['code'] ?? 'MSP';
    $activeCid = current_company_id();
    $limitPlusOne = $maxRows + 1;

    switch ($entity) {
        case 'companies':
            $st = $db->prepare('SELECT c.code, c.name, c.is_holding, c.phone, c.email, c.npwp, c.address, c.is_active,
                    (SELECT COUNT(DISTINCT s.item_id) FROM stock s WHERE s.company_id = c.id) AS total_items,
                    (SELECT COALESCE(SUM(s.quantity), 0) FROM stock s WHERE s.company_id = c.id) AS total_stock
                FROM companies c ORDER BY c.is_holding DESC, c.name ASC LIMIT ?');
            $st->bind_param('i', $limitPlusOne);
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [
                    $i + 1,
                    $r['code'],
                    $r['name'],
                    $r['is_holding'] ? 'Induk (Holding)' : 'Anak Perusahaan',
                    $r['phone'] ?? '',
                    $r['email'] ?? '',
                    $r['npwp'] ?? '',
                    $r['address'] ?? '',
                    (int)$r['total_items'],
                    (int)$r['total_stock'],
                    $r['is_active'] ? 'Aktif' : 'Nonaktif',
                ];
            }
            return [
                'permission'  => 'companies.view',
                'filename'    => 'master_perusahaan_' . date('Ymd'),
                'sheet'       => 'Perusahaan',
                'title'       => 'Master Data Perusahaan (Holding & Subsidiaries)',
                'subtitle'    => 'Total: ' . count($rows) . ' perusahaan' . ($truncated ? ' (dibatasi ' . $maxRows . ')' : ''),
                'orientation' => 'L',
                'columns'     => [
                    ['label' => '#',               'type' => 'int',  'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
                    ['label' => 'Kode',            'type' => 'text', 'align' => 'C', 'pdf_width' => 45,  'xlsx_width' => 10],
                    ['label' => 'Nama Perusahaan', 'type' => 'text', 'align' => 'L', 'pdf_width' => 115, 'xlsx_width' => 28],
                    ['label' => 'Tipe',            'type' => 'text', 'align' => 'C', 'pdf_width' => 70,  'xlsx_width' => 18],
                    ['label' => 'Telepon',         'type' => 'text', 'align' => 'L', 'pdf_width' => 72,  'xlsx_width' => 16],
                    ['label' => 'Email',           'type' => 'text', 'align' => 'L', 'pdf_width' => 95,  'xlsx_width' => 24],
                    ['label' => 'NPWP',            'type' => 'text', 'align' => 'L', 'pdf_width' => 88,  'xlsx_width' => 22],
                    ['label' => 'Alamat',          'type' => 'text', 'align' => 'L', 'pdf_width' => 140, 'xlsx_width' => 34],
                    ['label' => 'Item',            'type' => 'int',  'align' => 'R', 'pdf_width' => 40,  'xlsx_width' => 10],
                    ['label' => 'Total Stok',      'type' => 'int',  'align' => 'R', 'pdf_width' => 52,  'xlsx_width' => 12],
                    ['label' => 'Status',          'type' => 'text', 'align' => 'C', 'pdf_width' => 45,  'xlsx_width' => 10],
                ],
                'rows' => $rows,
            ];

        case 'items':
            $search    = grid_search_term('q');
            $catFilter = req_int('category_id');
            $whereParts = ['i.is_active=1'];
            $params = [$activeCid];
            $types  = 'i';
            $fParams = [];
            $fTypes  = '';
            if ($search !== '') {
                $whereParts[] = '(i.name LIKE ? OR i.code LIKE ?)';
                $like = '%' . escape_like($search) . '%';
                $fParams[] = $like;
                $fParams[] = $like;
                $fTypes .= 'ss';
            }
            if ($catFilter > 0) {
                $whereParts[] = 'i.category_id=?';
                $fParams[] = $catFilter;
                $fTypes .= 'i';
            }
            $where = 'WHERE ' . implode(' AND ', $whereParts);
            $sql = "SELECT i.code, i.name, c.name AS cat_name, u.abbreviation AS unit_abbr,
                    COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id=?), 0) AS total_stock,
                    i.min_stock, i.buy_price, i.sell_price, i.description
                FROM items i
                JOIN categories c ON c.id=i.category_id
                JOIN units u ON u.id=i.unit_id
                {$where} ORDER BY i.code ASC LIMIT ?";
            $bindParams = array_merge($params, $fParams, [$limitPlusOne]);
            $bindTypes  = $types . $fTypes . 'i';
            $st = $db->prepare($sql);
            $st->bind_param($bindTypes, ...$bindParams);
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [
                    $i + 1,
                    $r['code'],
                    $r['name'],
                    $r['cat_name'],
                    $r['unit_abbr'],
                    (int)$r['total_stock'],
                    (int)$r['min_stock'],
                    (float)$r['buy_price'],
                    (float)$r['sell_price'],
                    $r['description'] ?? '',
                ];
            }
            $notes = ["PT: [{$compCode}] {$compName}"];
            if ($search !== '') $notes[] = "Cari: \"{$search}\"";
            if ($catFilter > 0) $notes[] = "Kategori #{$catFilter}";
            $notes[] = 'Data: ' . count($rows) . ($truncated ? " (maks. {$maxRows})" : '');
            return [
                'permission'  => 'items.view',
                'filename'    => 'master_barang_' . strtolower($compCode) . '_' . date('Ymd'),
                'sheet'       => 'Barang',
                'title'       => 'Master Data Barang MSP Warehouse',
                'subtitle'    => implode(' | ', $notes),
                'orientation' => 'L',
                'columns'     => [
                    ['label' => '#',             'type' => 'int',      'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
                    ['label' => 'Kode',          'type' => 'text',     'align' => 'L', 'pdf_width' => 78,  'xlsx_width' => 16],
                    ['label' => 'Nama Barang',   'type' => 'text',     'align' => 'L', 'pdf_width' => 165, 'xlsx_width' => 34],
                    ['label' => 'Kategori',      'type' => 'text',     'align' => 'L', 'pdf_width' => 92,  'xlsx_width' => 22],
                    ['label' => 'Satuan',        'type' => 'text',     'align' => 'C', 'pdf_width' => 42,  'xlsx_width' => 10],
                    ['label' => "Stok ({$compCode})", 'type' => 'int', 'align' => 'R', 'pdf_width' => 52,  'xlsx_width' => 12],
                    ['label' => 'Stok Min',      'type' => 'int',      'align' => 'R', 'pdf_width' => 45,  'xlsx_width' => 10],
                    ['label' => 'Harga Beli',    'type' => 'currency', 'align' => 'R', 'pdf_width' => 76,  'xlsx_width' => 16],
                    ['label' => 'Harga Jual',    'type' => 'currency', 'align' => 'R', 'pdf_width' => 76,  'xlsx_width' => 16],
                    ['label' => 'Deskripsi',     'type' => 'text',     'align' => 'L', 'pdf_width' => 135, 'xlsx_width' => 30],
                ],
                'rows' => $rows,
            ];

        case 'categories':
            $search = grid_search_term('q');
            $where  = $search !== '' ? 'WHERE c.name LIKE ? OR c.code LIKE ?' : '';
            $sql    = "SELECT c.code, c.name, c.description,
                    (SELECT COUNT(*) FROM items i WHERE i.category_id=c.id AND i.is_active=1) AS item_count
                FROM categories c {$where} ORDER BY c.name ASC LIMIT ?";
            $st = $db->prepare($sql);
            if ($search !== '') {
                $like = '%' . escape_like($search) . '%';
                $st->bind_param('ssi', $like, $like, $limitPlusOne);
            } else {
                $st->bind_param('i', $limitPlusOne);
            }
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [$i + 1, $r['code'], $r['name'], $r['description'] ?? '', (int)$r['item_count']];
            }
            return [
                'permission'  => 'categories.view',
                'filename'    => 'master_kategori_' . date('Ymd'),
                'sheet'       => 'Kategori',
                'title'       => 'Master Data Kategori Barang',
                'subtitle'    => ($search !== '' ? "Cari: \"{$search}\" | " : '') . 'Total: ' . count($rows) . ' kategori' . ($truncated ? " (maks. {$maxRows})" : ''),
                'orientation' => 'P',
                'columns'     => [
                    ['label' => '#',             'type' => 'int',  'align' => 'C', 'pdf_width' => 28,  'xlsx_width' => 6],
                    ['label' => 'Kode',          'type' => 'text', 'align' => 'C', 'pdf_width' => 70,  'xlsx_width' => 14],
                    ['label' => 'Nama Kategori', 'type' => 'text', 'align' => 'L', 'pdf_width' => 155, 'xlsx_width' => 28],
                    ['label' => 'Deskripsi',     'type' => 'text', 'align' => 'L', 'pdf_width' => 220, 'xlsx_width' => 38],
                    ['label' => 'Jml Barang',    'type' => 'int',  'align' => 'R', 'pdf_width' => 66,  'xlsx_width' => 13],
                ],
                'rows' => $rows,
            ];

        case 'units':
            $sql = "SELECT u.name, u.abbreviation,
                    (SELECT COUNT(*) FROM items i WHERE i.unit_id=u.id AND i.is_active=1) AS item_count
                FROM units u ORDER BY u.name ASC LIMIT ?";
            $st = $db->prepare($sql);
            $st->bind_param('i', $limitPlusOne);
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [$i + 1, $r['name'], $r['abbreviation'], (int)$r['item_count']];
            }
            return [
                'permission'  => 'units.view',
                'filename'    => 'master_satuan_' . date('Ymd'),
                'sheet'       => 'Satuan',
                'title'       => 'Master Data Satuan Barang',
                'subtitle'    => 'Total: ' . count($rows) . ' satuan' . ($truncated ? " (maks. {$maxRows})" : ''),
                'orientation' => 'P',
                'columns'     => [
                    ['label' => '#',           'type' => 'int',  'align' => 'C', 'pdf_width' => 36,  'xlsx_width' => 6],
                    ['label' => 'Nama Satuan', 'type' => 'text', 'align' => 'L', 'pdf_width' => 260, 'xlsx_width' => 26],
                    ['label' => 'Singkatan',   'type' => 'text', 'align' => 'C', 'pdf_width' => 130, 'xlsx_width' => 14],
                    ['label' => 'Jml Barang',  'type' => 'int',  'align' => 'R', 'pdf_width' => 113, 'xlsx_width' => 14],
                ],
                'rows' => $rows,
            ];

        case 'suppliers':
            $search = grid_search_term('q');
            $where  = $search !== '' ? 'WHERE name LIKE ? OR code LIKE ? OR contact_person LIKE ?' : '';
            $sql    = "SELECT code, name, contact_person, phone, email, address, is_active FROM suppliers {$where} ORDER BY name ASC LIMIT ?";
            $st = $db->prepare($sql);
            if ($search !== '') {
                $like = '%' . escape_like($search) . '%';
                $st->bind_param('sssi', $like, $like, $like, $limitPlusOne);
            } else {
                $st->bind_param('i', $limitPlusOne);
            }
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [
                    $i + 1,
                    $r['code'],
                    $r['name'],
                    $r['contact_person'] ?? '',
                    $r['phone'] ?? '',
                    $r['email'] ?? '',
                    $r['address'] ?? '',
                    $r['is_active'] ? 'Aktif' : 'Nonaktif',
                ];
            }
            return [
                'permission'  => 'suppliers.view',
                'filename'    => 'master_supplier_' . date('Ymd'),
                'sheet'       => 'Supplier',
                'title'       => 'Master Data Supplier',
                'subtitle'    => ($search !== '' ? "Cari: \"{$search}\" | " : '') . 'Total: ' . count($rows) . ' supplier' . ($truncated ? " (maks. {$maxRows})" : ''),
                'orientation' => 'L',
                'columns'     => [
                    ['label' => '#',             'type' => 'int',  'align' => 'C', 'pdf_width' => 26,  'xlsx_width' => 6],
                    ['label' => 'Kode',          'type' => 'text', 'align' => 'C', 'pdf_width' => 62,  'xlsx_width' => 14],
                    ['label' => 'Nama Supplier', 'type' => 'text', 'align' => 'L', 'pdf_width' => 150, 'xlsx_width' => 30],
                    ['label' => 'Kontak',        'type' => 'text', 'align' => 'L', 'pdf_width' => 95,  'xlsx_width' => 20],
                    ['label' => 'Telepon',       'type' => 'text', 'align' => 'L', 'pdf_width' => 88,  'xlsx_width' => 18],
                    ['label' => 'Email',         'type' => 'text', 'align' => 'L', 'pdf_width' => 125, 'xlsx_width' => 26],
                    ['label' => 'Alamat',        'type' => 'text', 'align' => 'L', 'pdf_width' => 190, 'xlsx_width' => 36],
                    ['label' => 'Status',        'type' => 'text', 'align' => 'C', 'pdf_width' => 49,  'xlsx_width' => 10],
                ],
                'rows' => $rows,
            ];

        case 'locations':
            $search = grid_search_term('q');
            $where  = $search !== '' ? 'WHERE l.name LIKE ? OR l.code LIKE ?' : '';
            $sql    = "SELECT l.code, l.name, l.description,
                    (SELECT COALESCE(SUM(s.quantity), 0) FROM stock s WHERE s.location_id = l.id) AS total_stock,
                    l.is_active
                FROM locations l {$where} ORDER BY l.code ASC LIMIT ?";
            $st = $db->prepare($sql);
            if ($search !== '') {
                $like = '%' . escape_like($search) . '%';
                $st->bind_param('ssi', $like, $like, $limitPlusOne);
            } else {
                $st->bind_param('i', $limitPlusOne);
            }
            $st->execute();
            $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $truncated = count($raw) > $maxRows;
            if ($truncated) array_pop($raw);

            $rows = [];
            foreach ($raw as $i => $r) {
                $rows[] = [
                    $i + 1,
                    $r['code'],
                    $r['name'],
                    $r['description'] ?? '',
                    (int)$r['total_stock'],
                    $r['is_active'] ? 'Aktif' : 'Nonaktif',
                ];
            }
            return [
                'permission'  => 'locations.view',
                'filename'    => 'master_lokasi_' . date('Ymd'),
                'sheet'       => 'Lokasi Rak',
                'title'       => 'Master Data Lokasi Rak / Bin',
                'subtitle'    => ($search !== '' ? "Cari: \"{$search}\" | " : '') . 'Total: ' . count($rows) . ' lokasi' . ($truncated ? " (maks. {$maxRows})" : ''),
                'orientation' => 'L',
                'columns'     => [
                    ['label' => '#',           'type' => 'int',  'align' => 'C', 'pdf_width' => 28,  'xlsx_width' => 6],
                    ['label' => 'Kode',        'type' => 'text', 'align' => 'C', 'pdf_width' => 75,  'xlsx_width' => 14],
                    ['label' => 'Nama Lokasi', 'type' => 'text', 'align' => 'L', 'pdf_width' => 210, 'xlsx_width' => 30],
                    ['label' => 'Deskripsi',   'type' => 'text', 'align' => 'L', 'pdf_width' => 330, 'xlsx_width' => 40],
                    ['label' => 'Total Stok',  'type' => 'int',  'align' => 'R', 'pdf_width' => 76,  'xlsx_width' => 12],
                    ['label' => 'Status',      'type' => 'text', 'align' => 'C', 'pdf_width' => 66,  'xlsx_width' => 10],
                ],
                'rows' => $rows,
            ];
    }

    throw new InvalidArgumentException('Entitas master export tidak dikenal.');
}

function master_export_serve(string $entity): void {
    $permMap = [
        'companies'  => 'companies.view',
        'items'      => 'items.view',
        'categories' => 'categories.view',
        'units'      => 'units.view',
        'suppliers'  => 'suppliers.view',
        'locations'  => 'locations.view',
    ];
    if (!isset($permMap[$entity])) {
        http_response_code(404);
        exit('Endpoint export tidak ditemukan.');
    }

    require_perm($permMap[$entity]);
    $db = getDB();
    grid_apply_timeout($db, 30.0);

    $format = strtolower(req_str('format', 'excel'));
    if (!in_array($format, ['pdf', 'excel'], true)) {
        $format = 'excel';
    }

    $maxRows = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $dataset = master_export_fetch($db, $entity, $maxRows);

    if ($format === 'pdf') {
        export_pdf_send(
            $dataset['filename'] . '.pdf',
            $dataset['title'],
            $dataset['subtitle'],
            $dataset['columns'],
            $dataset['rows'],
            $dataset['orientation']
        );
    }

    export_xlsx_send(
        $dataset['filename'] . '.xlsx',
        $dataset['title'],
        $dataset['subtitle'],
        $dataset['columns'],
        $dataset['rows'],
        $dataset['sheet']
    );
}
