<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (empty($_SESSION['user']) || !auth_validate_active_session(false)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([]);
    exit;
}
if (!can('items.view') && !can('reports.view') && !can('stock_opname.count') && !can('stock.view')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([]);
    exit;
}

release_session_lock();
$db = getDB();
grid_apply_timeout($db, 5.0);
$q  = grid_search_term('q', 80);
$limit = 30;
$rows = [];

if ($q !== '') {
    $prefix = escape_like($q) . '%';
    // Fast B-Tree index range scan on separate branches (avoids OR full-table scan at 1M rows)
    $st = $db->prepare('(SELECT i.id, i.code, i.name, i.min_stock, u.abbreviation
            FROM items i JOIN units u ON u.id=i.unit_id
            WHERE i.is_active=1 AND i.code LIKE ?
            ORDER BY i.code LIMIT ?)
        UNION DISTINCT
        (SELECT i.id, i.code, i.name, i.min_stock, u.abbreviation
            FROM items i JOIN units u ON u.id=i.unit_id
            WHERE i.is_active=1 AND i.name LIKE ?
            ORDER BY i.name LIMIT ?)
        LIMIT ?');
    $st->bind_param('sisii', $prefix, $limit, $prefix, $limit, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // Substring fallback only if prefix yielded <30 items and query >= 3 chars
    if (count($rows) < $limit && mb_strlen($q, 'UTF-8') >= 3) {
        try {
            grid_apply_timeout($db, 2.5);
            $sub = '%' . escape_like($q) . '%';
            $seen = array_map(static fn($r) => (int)$r['id'], $rows);
            $excl = $seen ? ' AND i.id NOT IN (' . implode(',', $seen) . ')' : '';
            $rem  = $limit - count($rows);
            $st2 = $db->prepare("SELECT i.id, i.code, i.name, i.min_stock, u.abbreviation
                FROM items i JOIN units u ON u.id=i.unit_id
                WHERE i.is_active=1 $excl AND (i.code LIKE ? OR i.name LIKE ?)
                ORDER BY i.code LIMIT ?");
            $st2->bind_param('ssi', $sub, $sub, $rem);
            $st2->execute();
            $rows = array_merge($rows, $st2->get_result()->fetch_all(MYSQLI_ASSOC));
            $st2->close();
        } catch (mysqli_sql_exception $e) {
            if (!in_array($e->getCode(), [1969, 3024], true)) throw $e;
        }
    }
} else {
    $st = $db->prepare('SELECT i.id, i.code, i.name, i.min_stock, u.abbreviation
        FROM items i JOIN units u ON u.id=i.unit_id
        WHERE i.is_active=1 ORDER BY i.code LIMIT ?');
    $st->bind_param('i', $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

$include_stock = (req_int('include_stock') === 1);
$stock_map = [];
if ($include_stock && !empty($rows)) {
    $cid = current_company_id();
    $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $rows)));
    foreach ($rows as $r) {
        $stock_map[(int)$r['id']] = ['min_stock' => (int)$r['min_stock'], 'qty' => 0, 'locations' => []];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st_stk = $db->prepare("SELECT s.item_id, l.code AS loc_code, l.name AS loc_name, s.quantity
        FROM stock s
        JOIN locations l ON l.id = s.location_id
        WHERE s.company_id = ? AND s.item_id IN ($ph) AND s.quantity > 0
        ORDER BY s.quantity DESC, l.code ASC");
    $st_stk->bind_param('i' . str_repeat('i', count($ids)), $cid, ...$ids);
    $st_stk->execute();
    foreach ($st_stk->get_result()->fetch_all(MYSQLI_ASSOC) as $sr) {
        $iid = (int)$sr['item_id'];
        if (!isset($stock_map[$iid])) {
            $stock_map[$iid] = ['min_stock' => 0, 'qty' => 0, 'locations' => []];
        }
        $qVal = (int)$sr['quantity'];
        $stock_map[$iid]['qty'] += $qVal;
        $stock_map[$iid]['locations'][] = [
            'code' => (string)$sr['loc_code'],
            'name' => (string)$sr['loc_name'],
            'qty'  => $qVal,
        ];
    }
    $st_stk->close();
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array_map(static function (array $r) use ($include_stock, $stock_map): array {
    $item = [
        'value' => (string)$r['id'],
        'text'  => '[' . $r['code'] . '] ' . $r['name'] . ' (' . $r['abbreviation'] . ')',
        'code'  => $r['code'],
    ];
    if ($include_stock) {
        $iid = (int)$r['id'];
        $sm  = $stock_map[$iid] ?? ['min_stock' => 0, 'qty' => 0, 'locations' => []];
        $item['name']      = (string)$r['name'];
        $item['unit']      = (string)$r['abbreviation'];
        $item['min_stock'] = $sm['min_stock'];
        $item['qty']       = $sm['qty'];
        $item['locations'] = $sm['locations'];
    }
    return $item;
}, $rows));
