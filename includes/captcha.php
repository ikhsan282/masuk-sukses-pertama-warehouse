<?php
/**
 * Pure-PHP Adaptive Numeric Image CAPTCHA Engine
 * Zero external extension dependencies beyond standard PHP zlib (no GD/Imagick required).
 */

defined('LOGIN_CAPTCHA_THRESHOLD') || define('LOGIN_CAPTCHA_THRESHOLD', 2);
defined('LOGIN_CAPTCHA_TTL')       || define('LOGIN_CAPTCHA_TTL', 300); // 5 menit
defined('LOGIN_CAPTCHA_DIGITS')    || define('LOGIN_CAPTCHA_DIGITS', 5);

/**
 * Menghasilkan kode numerik kriptografis.
 */
function captcha_generate_code(int $digits = LOGIN_CAPTCHA_DIGITS): string {
    $digits = max(4, min(8, $digits));
    $code = '';
    for ($i = 0; $i < $digits; $i++) {
        $code .= (string)random_int(0, 9);
    }
    return $code;
}

/**
 * Membuat tantangan CAPTCHA baru ke session (menggantikan tantangan sebelumnya).
 */
function captcha_issue_challenge(int $digits = LOGIN_CAPTCHA_DIGITS): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return captcha_generate_code($digits);
    }
    $code = captcha_generate_code($digits);
    $salt = bin2hex(random_bytes(8));
    $_SESSION['login_captcha'] = [
        'salt'       => $salt,
        'hash'       => hash('sha256', $salt . ':' . $code),
        'expires_at' => time() + LOGIN_CAPTCHA_TTL,
    ];
    return $code;
}

/**
 * Memverifikasi input CAPTCHA dan langsung menghanguskan tantangan (one-time use).
 */
function captcha_verify(string $input): bool {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['login_captcha']) || !is_array($_SESSION['login_captcha'])) {
        return false;
    }
    $challenge = $_SESSION['login_captcha'];
    unset($_SESSION['login_captcha']); // One-time consumption mencegah replay

    $expiresAt = (int)($challenge['expires_at'] ?? 0);
    $salt      = (string)($challenge['salt'] ?? '');
    $hash      = (string)($challenge['hash'] ?? '');
    if ($expiresAt <= 0 || time() > $expiresAt || $salt === '' || $hash === '') {
        return false;
    }

    $normalized = trim($input);
    if ($normalized === '' || !preg_match('/^\d{4,8}$/', $normalized)) {
        return false;
    }

    $expected = hash('sha256', $salt . ':' . $normalized);
    return hash_equals($hash, $expected);
}

/**
 * Menghapus tantangan CAPTCHA aktif dari session.
 */
function captcha_clear(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['login_captcha']);
    }
}

/**
 * Mengatur warna piksel pada kanvas raster jika berada dalam batas gambar.
 *
 * @param array<int, string> $rows
 */
function _captcha_set_pixel(array &$rows, int $width, int $height, int $x, int $y, int $r, int $g, int $b): void {
    if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
        return;
    }
    $offset = 1 + ($x * 3); // byte 0 per baris adalah filter PNG 0x00
    $rows[$y][$offset]     = chr($r);
    $rows[$y][$offset + 1] = chr($g);
    $rows[$y][$offset + 2] = chr($b);
}

/**
 * Menggambar garis dengan algoritma Bresenham pada kanvas raster.
 *
 * @param array<int, string> $rows
 */
function _captcha_draw_line(array &$rows, int $width, int $height, int $x0, int $y0, int $x1, int $y1, int $r, int $g, int $b): void {
    $dx  = abs($x1 - $x0);
    $sx  = $x0 < $x1 ? 1 : -1;
    $dy  = -abs($y1 - $y0);
    $sy  = $y0 < $y1 ? 1 : -1;
    $err = $dx + $dy;

    while (true) {
        _captcha_set_pixel($rows, $width, $height, $x0, $y0, $r, $g, $b);
        if ($x0 === $x1 && $y0 === $y1) {
            break;
        }
        $e2 = 2 * $err;
        if ($e2 >= $dy) {
            $err += $dy;
            $x0  += $sx;
        }
        if ($e2 <= $dx) {
            $err += $dx;
            $y0  += $sy;
        }
    }
}

/**
 * Membentuk chunk biner standar PNG.
 */
function _captcha_png_chunk(string $type, string $data): string {
    $crc = crc32($type . $data);
    return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
}

/**
 * Merender kode numerik menjadi binary gambar PNG raster (24-bit RGB) murni dalam PHP.
 */
function captcha_render_png(string $code, int $width = 180, int $height = 54, bool $dark = false): string {
    $width  = max(120, min(320, $width));
    $height = max(40, min(100, $height));

    // Font bitmap 5x7 untuk angka 0-9 agar kontras tajam tanpa butuh ext-gd / TTF
    static $glyphs = [
        '0' => ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
        '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00110', '01000', '10000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
    ];

    if ($dark) {
        $bg        = [15, 23, 42];
        $gridDot   = [51, 65, 85];
        $noiseCols = [[71, 85, 105], [56, 189, 248], [100, 116, 139]];
        $inkCols   = [[248, 250, 252], [147, 197, 253], [165, 243, 252]];
    } else {
        $bg        = [241, 245, 249];
        $gridDot   = [203, 213, 225];
        $noiseCols = [[148, 163, 184], [100, 116, 139], [147, 197, 253]];
        $inkCols   = [[15, 23, 42], [30, 58, 138], [15, 118, 110]];
    }

    $blankRow = "\x00" . str_repeat(chr($bg[0]) . chr($bg[1]) . chr($bg[2]), $width);
    $rows     = array_fill(0, $height, $blankRow);

    // 1. Garis noise diagonal latar belakang
    for ($l = 0; $l < 5; $l++) {
        $col = $noiseCols[$l % count($noiseCols)];
        $x0  = random_int(0, (int)floor($width * 0.25));
        $y0  = random_int(2, $height - 3);
        $x1  = random_int((int)floor($width * 0.65), $width - 1);
        $y1  = random_int(2, $height - 3);
        _captcha_draw_line($rows, $width, $height, $x0, $y0, $x1, $y1, $col[0], $col[1], $col[2]);
    }

    // 2. Titik-titik latar halus
    $dotCount = (int)floor(($width * $height) / 90);
    for ($d = 0; $d < $dotCount; $d++) {
        $dx = random_int(1, $width - 2);
        $dy = random_int(1, $height - 2);
        _captcha_set_pixel($rows, $width, $height, $dx, $dy, $gridDot[0], $gridDot[1], $gridDot[2]);
    }

    // 3. Render setiap digit dengan jitter posisi, skala, dan kemiringan halus
    $chars = str_split($code);
    $count = max(1, count($chars));
    $slotW = (int)floor(($width - 24) / $count);
    $baseX = 12;

    foreach ($chars as $idx => $ch) {
        if (!isset($glyphs[$ch])) {
            continue;
        }
        $bitmap = $glyphs[$ch];
        $scaleX = random_int(3, 4);
        $scaleY = 4;
        $glyphW = 5 * $scaleX;
        $glyphH = 7 * $scaleY;

        $jitterX = random_int(-2, 3);
        $jitterY = random_int(-4, 4);
        $shear   = random_int(-1, 1); // kemiringan horizontal halus per baris glyph

        $startX = $baseX + ($idx * $slotW) + (int)floor(($slotW - $glyphW) / 2) + $jitterX;
        $startY = (int)floor(($height - $glyphH) / 2) + $jitterY;
        $ink    = $inkCols[$idx % count($inkCols)];

        for ($row = 0; $row < 7; $row++) {
            $line = $bitmap[$row];
            $rowOffset = (int)round(($row - 3) * $shear * 0.6);
            for ($col = 0; $col < 5; $col++) {
                if ($line[$col] !== '1') {
                    continue;
                }
                for ($sy = 0; $sy < $scaleY; $sy++) {
                    for ($sx = 0; $sx < $scaleX; $sx++) {
                        _captcha_set_pixel(
                            $rows,
                            $width,
                            $height,
                            $startX + ($col * $scaleX) + $sx + $rowOffset,
                            $startY + ($row * $scaleY) + $sy,
                            $ink[0],
                            $ink[1],
                            $ink[2]
                        );
                    }
                }
            }
        }
    }

    // 4. Dua garis tipis di atas digit untuk menyulitkan segmentasi OCR sederhana
    for ($l = 0; $l < 2; $l++) {
        $col = $noiseCols[($l + 1) % count($noiseCols)];
        $y0  = random_int(8, $height - 9);
        $y1  = random_int(8, $height - 9);
        _captcha_draw_line($rows, $width, $height, 4, $y0, $width - 5, $y1, $col[0], $col[1], $col[2]);
    }

    $rawImage = implode('', $rows);
    $idatData = function_exists('gzcompress')
        ? gzcompress($rawImage, 6)
        : '';

    $ihdr = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n"
        . _captcha_png_chunk('IHDR', $ihdr)
        . _captcha_png_chunk('IDAT', $idatData)
        . _captcha_png_chunk('IEND', '');
}
