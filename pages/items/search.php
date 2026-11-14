<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (empty($_SESSION['user'])) {
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
    $st = $db->prepare('(SELECT i.id, i.code, i.name, u.abbreviation
            FROM items i JOIN units u ON u.id=i.unit_id
            WHERE i.is_active=1 AND i.code LIKE ?
            ORDER BY i.code LIMIT ?)
        UNION DISTINCT
        (SELECT i.id, i.code, i.name, u.abbreviation
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
            $st2 = $db->prepare("SELECT i.id, i.code, i.name, u.abbreviation
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
    $st = $db->prepare('SELECT i.id, i.code, i.name, u.abbreviation
        FROM items i JOIN units u ON u.id=i.unit_id
        WHERE i.is_active=1 ORDER BY i.code LIMIT ?');
    $st->bind_param('i', $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array_map(static function (array $r): array {
    return [
        'value' => (string)$r['id'],
        'text'  => '[' . $r['code'] . '] ' . $r['name'] . ' (' . $r['abbreviation'] . ')',
        'code'  => $r['code'],
    ];
}, $rows));
