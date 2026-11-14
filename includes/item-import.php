<?php
/**
 * Native CSV/XLSX item importer. No Composer dependency.
 */
function item_import_normalize_header(string $header): string {
    $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    $aliases = [
        'kode' => 'code', 'kode_barang' => 'code', 'item_code' => 'code', 'code' => 'code',
        'nama' => 'name', 'nama_barang' => 'name', 'item_name' => 'name', 'name' => 'name',
        'kategori' => 'category', 'kategori_kode' => 'category', 'category_code' => 'category', 'category' => 'category',
        'satuan' => 'unit', 'unit_abbr' => 'unit', 'abbreviation' => 'unit', 'unit' => 'unit',
        'stok_minimum' => 'min_stock', 'stok_min' => 'min_stock', 'min_stock' => 'min_stock',
        'harga_beli' => 'buy_price', 'buy_price' => 'buy_price',
        'harga_jual' => 'sell_price', 'sell_price' => 'sell_price',
        'deskripsi' => 'description', 'description' => 'description',
    ];
    return $aliases[$header] ?? $header;
}

function item_import_column_index(string $letters): int {
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + ord(strtoupper($letters[$i])) - 64;
    }
    return $index - 1;
}

function item_import_open_xml(string $xml, string $error): SimpleXMLElement {
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($parsed === false) throw new RuntimeException($error);
    return $parsed;
}

function item_import_read_csv(string $path): array {
    $first = file_get_contents($path, false, null, 0, 4096);
    if ($first === false) throw new RuntimeException('File CSV tidak dapat dibaca.');
    $delimiters = [',', ';', "\t"];
    $delimiter = ',';
    $best = -1;
    foreach ($delimiters as $candidate) {
        $count = substr_count($first, $candidate);
        if ($count > $best) { $best = $count; $delimiter = $candidate; }
    }

    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('File CSV tidak dapat dibuka.');
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = array_map(static fn($value) => trim((string)$value), $row);
        if (count($rows) > 1001) break;
    }
    fclose($handle);
    return $rows;
}

/**
 * Pure-PHP ZIP reader for OOXML (.xlsx), with ZipArchive fast path when available.
 * Enforces an entry size cap (16 MB) to mitigate zip-bomb attacks.
 */
function item_import_zip_read_entries(string $path, array $targets): array {
    $wanted = array_flip($targets);
    $found  = [];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File XLSX tidak valid atau rusak.');
        }
        foreach ($targets as $name) {
            $stat = $zip->statName($name);
            if ($stat && isset($stat['size']) && (int)$stat['size'] > 16 * 1024 * 1024) {
                $zip->close();
                throw new RuntimeException("Ukuran entry XLSX '{$name}' melebihi batas 16 MB.");
            }
            $data = $zip->getFromName($name);
            if ($data !== false) {
                $found[$name] = $data;
            }
        }
        $zip->close();
        return $found;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) < 22) {
        throw new RuntimeException('File XLSX tidak dapat dibaca atau rusak.');
    }

    $len = strlen($raw);
    $searchStart = max(0, $len - 65557);
    $eocdPos = strrpos(substr($raw, $searchStart), "\x50\x4b\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException('File XLSX bukan arsip ZIP yang valid.');
    }
    $eocdOffset = $searchStart + $eocdPos;
    $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLen', substr($raw, $eocdOffset, 22));
    if (!$eocd || $eocd['sig'] !== 0x06054b50) {
        throw new RuntimeException('Header Central Directory XLSX tidak valid.');
    }

    $cdPos = (int)$eocd['cdOffset'];
    $cdEnd = min($len, $cdPos + (int)$eocd['cdSize']);
    $maxEntryBytes = 16 * 1024 * 1024;

    while ($cdPos + 46 <= $cdEnd) {
        $hdr = unpack(
            'Vsig/vverMade/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcSize/VuSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset',
            substr($raw, $cdPos, 46)
        );
        if (!$hdr || $hdr['sig'] !== 0x02014b50) {
            break;
        }
        $namePos = $cdPos + 46;
        $entryName = substr($raw, $namePos, (int)$hdr['nameLen']);
        $cdPos += 46 + (int)$hdr['nameLen'] + (int)$hdr['extraLen'] + (int)$hdr['commentLen'];

        if (!isset($wanted[$entryName])) {
            continue;
        }
        if ((int)$hdr['uSize'] > $maxEntryBytes) {
            throw new RuntimeException("Ukuran entry XLSX '{$entryName}' melebihi batas 16 MB.");
        }

        $locOffset = (int)$hdr['localOffset'];
        if ($locOffset + 30 > $len) {
            throw new RuntimeException('Offset data XLSX korup.');
        }
        $loc = unpack('Vsig/vver/vflag/vmethod/vmtime/vmdate/Vcrc/VcSize/VuSize/vnameLen/vextraLen', substr($raw, $locOffset, 30));
        if (!$loc || $loc['sig'] !== 0x04034b50) {
            throw new RuntimeException('Local file header XLSX tidak valid.');
        }

        $compSize = (int)$hdr['cSize'];
        $dataStart = $locOffset + 30 + (int)$loc['nameLen'] + (int)$loc['extraLen'];
        if ($compSize === 0 && (int)$loc['cSize'] > 0) {
            $compSize = (int)$loc['cSize'];
        }
        if ($dataStart + $compSize > $len) {
            throw new RuntimeException('Data terkompresi XLSX terpotong.');
        }
        $payload = substr($raw, $dataStart, $compSize);

        $method = (int)$hdr['method'];
        if ($method === 0) {
            $uncompressed = $payload;
        } elseif ($method === 8) {
            if (!function_exists('gzinflate')) {
                throw new RuntimeException('Ekstensi PHP zlib diperlukan untuk membaca file XLSX.');
            }
            $uncompressed = @gzinflate($payload, $maxEntryBytes);
            if ($uncompressed === false) {
                throw new RuntimeException("Gagal mendekompression '{$entryName}' di file XLSX.");
            }
        } else {
            throw new RuntimeException("Metode kompresi ZIP ({$method}) tidak didukung.");
        }

        if (strlen($uncompressed) > $maxEntryBytes) {
            throw new RuntimeException("Ukuran entry XLSX '{$entryName}' melebihi batas 16 MB.");
        }
        $found[$entryName] = $uncompressed;
        if (count($found) === count($wanted)) {
            break;
        }
    }

    return $found;
}

function item_import_read_xlsx(string $path): array {
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('Import XLSX membutuhkan ekstensi PHP SimpleXML. Gunakan CSV atau aktifkan SimpleXML di cPanel.');
    }

    $entries = item_import_zip_read_entries($path, ['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml']);
    $main_ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $shared = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $xml = item_import_open_xml($entries['xl/sharedStrings.xml'], 'Shared strings XLSX tidak valid.');
        $root = $xml->children($main_ns);
        foreach ($root->si as $si) {
            $parts = [];
            if (isset($si->t)) $parts[] = (string)$si->t;
            foreach ($si->r as $run) $parts[] = (string)$run->t;
            $shared[] = implode('', $parts);
        }
    }

    if (!isset($entries['xl/worksheets/sheet1.xml'])) {
        throw new RuntimeException('Sheet pertama XLSX tidak ditemukan.');
    }

    $xml = item_import_open_xml($entries['xl/worksheets/sheet1.xml'], 'Data sheet XLSX tidak valid.');
    $root = $xml->children($main_ns);
    $rows = [];
    foreach ($root->sheetData->row as $row_node) {
        $values = [];
        foreach ($row_node->c as $cell) {
            $attrs = $cell->attributes();
            $ref = (string)($attrs['r'] ?? '');
            preg_match('/^([A-Z]+)/i', $ref, $match);
            if (!$match) continue;
            $column = item_import_column_index($match[1]);
            $value = '';
            $type = (string)($attrs['t'] ?? '');
            if ($type === 's') {
                $value = $shared[(int)($cell->v ?? 0)] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            } else {
                $value = (string)($cell->v ?? '');
            }
            $values[$column] = trim($value);
        }
        if ($values) {
            $max = max(array_keys($values));
            $normalized = array_fill(0, $max + 1, '');
            foreach ($values as $index => $value) $normalized[$index] = $value;
            $rows[] = $normalized;
        }
        if (count($rows) > 1001) break;
    }
    return $rows;
}

function item_import_read(string $path, string $extension): array {
    return $extension === 'xlsx' ? item_import_read_xlsx($path) : item_import_read_csv($path);
}

function item_import_preview(mysqli $db, array $raw_rows): array {
    if (count($raw_rows) < 2) throw new RuntimeException('File harus memiliki header dan minimal satu baris data.');
    if (count($raw_rows) > 1001) throw new RuntimeException('Maksimal 1.000 barang per import.');

    $headers = array_map('item_import_normalize_header', $raw_rows[0]);
    $required = ['code', 'name', 'category', 'unit'];
    foreach ($required as $required_header) {
        if (!in_array($required_header, $headers, true)) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . $required_header . '.');
        }
    }

    $category_ids = [];
    $result = $db->query('SELECT id,code,name FROM categories');
    while ($row = $result->fetch_assoc()) {
        $category_ids[strtolower($row['code'])] = (int)$row['id'];
        $category_ids[strtolower($row['name'])] = (int)$row['id'];
    }
    $unit_ids = [];
    $result = $db->query('SELECT id,name,abbreviation FROM units');
    while ($row = $result->fetch_assoc()) {
        $unit_ids[strtolower($row['name'])] = (int)$row['id'];
        $unit_ids[strtolower($row['abbreviation'])] = (int)$row['id'];
    }

    $existing = [];
    $result = $db->query('SELECT code FROM items');
    while ($row = $result->fetch_assoc()) $existing[strtolower($row['code'])] = true;

    $seen = [];
    $rows = [];
    for ($line = 1; $line < count($raw_rows); $line++) {
        $raw = $raw_rows[$line];
        $get = static function (array $keys) use ($headers, $raw): string {
            foreach ($keys as $key) {
                $index = array_search($key, $headers, true);
                if ($index !== false) return trim((string)($raw[$index] ?? ''));
            }
            return '';
        };
        $code = strtoupper($get(['code']));
        $name = $get(['name']);
        $category = $get(['category']);
        $unit = $get(['unit']);
        $min_stock = $get(['min_stock']);
        $buy_price = $get(['buy_price']);
        $sell_price = $get(['sell_price']);
        $description = $get(['description']);
        if ($code === '' && $name === '' && $category === '' && $unit === '') continue;

        $errors = [];
        if ($code === '') $errors[] = 'Kode kosong';
        elseif (strlen($code) > 30) $errors[] = 'Kode maksimal 30 karakter';
        elseif (isset($seen[strtolower($code)])) $errors[] = 'Kode duplikat di file';
        else $seen[strtolower($code)] = true;
        if ($name === '') $errors[] = 'Nama kosong';
        elseif (strlen($name) > 150) $errors[] = 'Nama maksimal 150 karakter';
        $category_id = $category_ids[strtolower($category)] ?? 0;
        if (!$category_id) $errors[] = 'Kategori tidak ditemukan';
        $unit_id = $unit_ids[strtolower($unit)] ?? 0;
        if (!$unit_id) $errors[] = 'Satuan tidak ditemukan';
        if ($min_stock === '') $min_stock = '0';
        if (!ctype_digit($min_stock)) $errors[] = 'Stok minimum harus angka bulat >= 0';
        if ($buy_price === '') $buy_price = '0';
        if (!is_numeric($buy_price) || (float)$buy_price < 0) $errors[] = 'Harga beli tidak valid';
        if ($sell_price === '') $sell_price = '0';
        if (!is_numeric($sell_price) || (float)$sell_price < 0) $errors[] = 'Harga jual tidak valid';

        $rows[] = [
            'line' => $line + 1,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'unit' => $unit,
            'category_id' => $category_id,
            'unit_id' => $unit_id,
            'min_stock' => (int)$min_stock,
            'buy_price' => (float)$buy_price,
            'sell_price' => (float)$sell_price,
            'description' => $description,
            'exists' => isset($existing[strtolower($code)]),
            'errors' => $errors,
        ];
    }
    if (!$rows) throw new RuntimeException('Tidak ada baris data yang bisa diproses.');
    return $rows;
}
