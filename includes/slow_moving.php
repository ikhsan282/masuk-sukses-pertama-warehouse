<?php
/**
 * Shared query builder and helpers for Slow-Moving & Dead Stock analytics.
 */

function slow_moving_filters(): array {
    $days_raw = req_int('days', 90);
    $days     = in_array($days_raw, [30, 60, 90, 180], true) ? $days_raw : 90;
    $cls_raw  = req_str('class');
    $cls      = in_array($cls_raw, ['dead', 'slow'], true) ? $cls_raw : '';
    $cat_id   = req_int('category_id');
    $search   = grid_search_term('q');
    $cf       = resolve_company_filter('company_id');

    return [
        'days'        => $days,
        'class'       => $cls,
        'category_id' => $cat_id,
        'q'           => $search,
        'cf'          => $cf,
    ];
}

/**
 * Membangun klausa FROM/JOIN, WHERE, dan parameter binding secara terpusat.
 */
function slow_moving_build_sql(array $f): array {
    $comp_in = $f['cf']['sql_in'];
    $days    = (int)$f['days'];
    $cls     = (string)$f['class'];
    $cat_id  = (int)$f['category_id'];
    $search  = (string)$f['q'];

    $from = "FROM items i
        JOIN categories c ON c.id = i.category_id
        JOIN units u ON u.id = i.unit_id
        JOIN (
            SELECT s.item_id, SUM(s.quantity) AS total_stock
            FROM stock s
            WHERE s.company_id IN ($comp_in)
            GROUP BY s.item_id
            HAVING SUM(s.quantity) > 0
        ) st ON st.item_id = i.id
        LEFT JOIN (
            SELECT m.item_id, MAX(m.created_at) AS last_out_at
            FROM mutations m
            WHERE m.company_id IN ($comp_in) AND m.type = 'out'
            GROUP BY m.item_id
        ) mo ON mo.item_id = i.id";

    $where_parts = [
        'i.is_active = 1',
        'COALESCE(DATE(mo.last_out_at), DATE(i.created_at)) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)',
    ];
    $params = [$days];
    $types  = 'i';

    if ($cls === 'dead') {
        $where_parts[] = '(mo.last_out_at IS NULL OR DATE(mo.last_out_at) <= DATE_SUB(CURDATE(), INTERVAL 180 DAY))';
    } elseif ($cls === 'slow') {
        $where_parts[] = '(mo.last_out_at IS NOT NULL AND DATE(mo.last_out_at) > DATE_SUB(CURDATE(), INTERVAL 180 DAY))';
    }

    if ($cat_id > 0) {
        $where_parts[] = 'i.category_id = ?';
        $params[] = $cat_id;
        $types   .= 'i';
    }

    if ($search !== '') {
        $like = '%' . escape_like($search) . '%';
        $where_parts[] = '(i.code LIKE ? OR i.name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }

    return [
        'from'   => $from,
        'where'  => 'WHERE ' . implode(' AND ', $where_parts),
        'params' => $params,
        'types'  => $types,
    ];
}

function slow_moving_classify(?string $last_out_at, int $idle_days): array {
    $is_dead = ($last_out_at === null || $last_out_at === '' || $idle_days >= 180);
    return $is_dead
        ? ['dead', 'Dead Stock', 'badge-zero']
        : ['slow', 'Slow-Moving', 'badge-low'];
}
