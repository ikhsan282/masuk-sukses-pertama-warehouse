<?php
/**
 * Low Stock Email Alert
 * Run daily via cron: /opt/data/bin/php /path/to/includes/low_stock_alerts.php
 */

// Allow CLI execution only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Query low-stock items (stock <= min_stock)
 * Returns array of items with: id, code, name, min_stock, total_stock, suggested_qty, unit, buy_price, stock_value
 */
function get_low_stock_items(?int $company_id = null): array {
    $db = getDB();
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    
    $where_stock = $company_id ? 'WHERE s.company_id = ' . (int)$company_id : '';

    $query = "
        SELECT 
            i.id,
            i.code,
            i.name,
            i.min_stock,
            u.abbreviation AS unit,
            i.buy_price,
            COALESCE(st.qty, 0) AS total_stock,
            GREATEST(i.min_stock * 2 - COALESCE(st.qty, 0), 1) AS suggested_qty,
            COALESCE(st.qty, 0) * i.buy_price AS stock_value
        FROM items i
        JOIN units u ON u.id = i.unit_id
        LEFT JOIN (
            SELECT s.item_id, SUM(s.quantity) AS qty
            FROM stock s
            {$where_stock}
            GROUP BY s.item_id
        ) st ON st.item_id = i.id
        WHERE i.is_active = 1 AND COALESCE(st.qty, 0) <= i.min_stock
        ORDER BY total_stock ASC, i.name ASC
    ";
    
    $result = $db->query($query);
    if (!$result) {
        error_log("Low stock query failed: " . $db->error);
        return [];
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Build HTML email body for low-stock items
 */
function build_low_stock_email_body(array $items): string {
    $count = count($items);
    $total_value = array_sum(array_column($items, 'stock_value'));
    $total_suggested_value = 0;
    
    foreach ($items as $item) {
        $total_suggested_value += $item['suggested_qty'] * $item['buy_price'];
    }
    
    $body = '<p style="color:#d32f2f;font-weight:bold;">🔴 Peringatan Stok Menipis</p>';
    $body .= '<p>Terdapat <strong>' . $count . ' item</strong> dengan stok di bawah atau sama dengan stok minimum.</p>';
    $body .= '<p style="font-size:13px;color:#666;">Nilai stok saat ini: <strong>' . idr($total_value) . '</strong><br>';
    $body .= 'Estimasi biaya pengisian ulang: <strong>' . idr($total_suggested_value) . '</strong></p>';
    
    $body .= '<table style="width:100%;border-collapse:collapse;margin-top:16px;font-size:13px;">';
    $body .= '<thead><tr style="background:#f5f5f5;text-align:left;">';
    $body .= '<th style="padding:8px;border:1px solid #ddd;">Kode</th>';
    $body .= '<th style="padding:8px;border:1px solid #ddd;">Nama Barang</th>';
    $body .= '<th style="padding:8px;border:1px solid #ddd;text-align:center;">Stok</th>';
    $body .= '<th style="padding:8px;border:1px solid #ddd;text-align:center;">Min</th>';
    $body .= '<th style="padding:8px;border:1px solid #ddd;text-align:center;">Saran Pesan</th>';
    $body .= '<th style="padding:8px;border:1px solid #ddd;text-align:right;">Estimasi</th>';
    $body .= '</tr></thead><tbody>';
    
    foreach ($items as $item) {
        $stock_badge = $item['total_stock'] == 0 ? 
            '<span style="background:#ef5350;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">HABIS</span>' :
            '<span style="background:#ffa726;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">MENIPIS</span>';
        
        $body .= '<tr>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;"><code>' . htmlspecialchars($item['code']) . '</code></td>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;">' . htmlspecialchars($item['name']) . '</td>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;text-align:center;">' . $stock_badge . ' ' . (int)$item['total_stock'] . ' ' . htmlspecialchars($item['unit']) . '</td>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;text-align:center;">' . (int)$item['min_stock'] . '</td>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;text-align:center;font-weight:bold;">' . (int)$item['suggested_qty'] . ' ' . htmlspecialchars($item['unit']) . '</td>';
        $body .= '<td style="padding:8px;border:1px solid #ddd;text-align:right;">' . idr($item['suggested_qty'] * $item['buy_price']) . '</td>';
        $body .= '</tr>';
    }
    
    $body .= '</tbody></table>';
    $body .= '<p style="margin-top:24px;font-size:13px;color:#666;">Segera buat Purchase Order untuk mengisi ulang stok barang.</p>';
    $body .= '<p style="margin-top:8px;"><a href="' . APP_URL . '/pages/stock/alerts.php" class="btn">Lihat Detail Stok</a></p>';
    
    return $body;
}

/**
 * Send low-stock alert email to admin
 * Returns true on success, false on failure
 */
function send_low_stock_email(string $admin_email): bool {
    $items = get_low_stock_items();
    
    if (empty($items)) {
        echo date('Y-m-d H:i:s') . " - No low stock items. Email not sent.\n";
        return true; // Not an error condition
    }
    
    $subject = '[' . APP_NAME . '] Peringatan Stok Menipis - ' . count($items) . ' Item';
    $body = build_low_stock_email_body($items);
    $html = email_template('Peringatan Stok Menipis', $body);
    
    $result = send_mail($admin_email, $subject, $html);
    
    if ($result) {
        echo date('Y-m-d H:i:s') . " - Low stock alert sent to {$admin_email} (" . count($items) . " items)\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Failed to send email to {$admin_email}\n";
        error_log("Low stock email failed: mail() returned false");
    }
    
    return $result;
}

// CLI execution
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    // Check for admin email argument or use default
    $admin_email = $argv[1] ?? 'admin@example.com';
    
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        echo "Error: Invalid email address: {$admin_email}\n";
        echo "Usage: php " . basename(__FILE__) . " [admin@example.com]\n";
        exit(1);
    }
    
    echo "Low Stock Alert Check\n";
    echo "=====================\n";
    echo "Admin Email: {$admin_email}\n";
    echo "Checking inventory...\n\n";
    
    $success = send_low_stock_email($admin_email);
    exit($success ? 0 : 1);
}
