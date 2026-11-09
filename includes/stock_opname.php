<?php
function create_stock_opname(mysqli $db, string $date, int $user_id, string $notes = '', ?int $company_id = null): int {
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    $company_id = $company_id ?: 1;

    $stmt = $db->prepare('INSERT INTO stock_opname (opname_date,user_id,notes,company_id) VALUES (?,?,?,?)');
    $stmt->bind_param('sisi', $date, $user_id, $notes, $company_id);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

function save_stock_opname_count(mysqli $db, int $session_id, int $item_id, int $location_id, int $physical_qty): array {
    if ($physical_qty < 0) throw new InvalidArgumentException('Stok fisik tidak boleh negatif.');
    $db->begin_transaction();
    try {
        $session = $db->prepare('SELECT status, company_id FROM stock_opname WHERE session_id=? FOR UPDATE');
        $session->bind_param('i', $session_id);
        $session->execute();
        $row = $session->get_result()->fetch_assoc();
        $session->close();
        if (!$row) throw new RuntimeException('Sesi opname tidak ditemukan.');
        if ($row['status'] !== 'draft') throw new RuntimeException('Sesi sudah finalized dan tidak dapat diubah.');

        $company_id = (int)($row['company_id'] ?: 1);

        $stock = $db->prepare('SELECT i.id, COALESCE(s.quantity,0) quantity FROM items i
            LEFT JOIN stock s ON s.item_id=i.id AND s.location_id=? AND s.company_id=? WHERE i.id=? AND i.is_active=1');
        $stock->bind_param('iii', $location_id, $company_id, $item_id);
        $stock->execute();
        $current = $stock->get_result()->fetch_assoc();
        $stock->close();
        if (!$current) throw new RuntimeException('Barang aktif tidak ditemukan.');
        $system_qty = (int)$current['quantity'];
        $variance = $physical_qty - $system_qty;

        $save = $db->prepare('INSERT INTO stock_opname_items
            (session_id,item_id,location_id,system_qty,physical_qty,variance)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE system_qty=VALUES(system_qty),physical_qty=VALUES(physical_qty),variance=VALUES(variance),counted_at=CURRENT_TIMESTAMP');
        $save->bind_param('iiiiii', $session_id, $item_id, $location_id, $system_qty, $physical_qty, $variance);
        $save->execute();
        $save->close();
        $db->commit();
        return ['system_qty' => $system_qty, 'physical_qty' => $physical_qty, 'variance' => $variance];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function finalize_stock_opname(mysqli $db, int $session_id, int $user_id): array {
    $db->begin_transaction();
    try {
        $session = $db->prepare('SELECT opname_date,status,company_id FROM stock_opname WHERE session_id=? FOR UPDATE');
        $session->bind_param('i', $session_id);
        $session->execute();
        $header = $session->get_result()->fetch_assoc();
        $session->close();
        if (!$header) throw new RuntimeException('Sesi opname tidak ditemukan.');
        if ($header['status'] !== 'draft') throw new RuntimeException('Sesi sudah finalized.');

        $company_id = (int)($header['company_id'] ?: 1);

        $items = $db->prepare('SELECT oi.*,i.name FROM stock_opname_items oi JOIN items i ON i.id=oi.item_id
            WHERE oi.session_id=? ORDER BY oi.location_id,oi.item_id FOR UPDATE');
        $items->bind_param('i', $session_id);
        $items->execute();
        $rows = $items->get_result()->fetch_all(MYSQLI_ASSOC);
        $items->close();
        if (!$rows) throw new RuntimeException('Belum ada hasil hitung untuk difinalisasi.');

        $lock = $db->prepare('SELECT quantity FROM stock WHERE item_id=? AND location_id=? AND company_id=? FOR UPDATE');
        foreach ($rows as $row) {
            $lock->bind_param('iii', $row['item_id'], $row['location_id'], $company_id);
            $lock->execute();
            $current = $lock->get_result()->fetch_assoc();
            if ((int)($current['quantity'] ?? 0) !== (int)$row['system_qty']) {
                throw new RuntimeException("Stok {$row['name']} berubah setelah dihitung. Perbarui hitungan sebelum finalisasi.");
            }
        }
        $lock->close();

        $by_location = [];
        foreach ($rows as $row) $by_location[(int)$row['location_id']][] = $row;
        $adjustment_ids = [];
        foreach ($by_location as $location_id => $location_rows) {
            $ref = generate_ref('OPN-', $company_id);
            $notes = "Finalisasi stock opname sesi #{$session_id}";
            $adj = $db->prepare('INSERT INTO stock_adjustments
                (reference_no,company_id,location_id,user_id,approved_by,approved_at,status,notes,transaction_date,opname_session_id)
                VALUES (?,?,?,?, ?,NOW(),"approved",?,?,?)');
            $adj->bind_param('siiiissi', $ref, $company_id, $location_id, $user_id, $user_id, $notes, $header['opname_date'], $session_id);
            $adj->execute();
            $adjustment_id = $db->insert_id;
            $adj->close();
            $adjustment_ids[] = $adjustment_id;

            $detail = $db->prepare('INSERT INTO stock_adjustment_details
                (adjustment_id,item_id,system_qty,physical_qty,difference,reason) VALUES (?,?,?,?,?,?)');
            $set_stock = $db->prepare('INSERT INTO stock (item_id,location_id,company_id,quantity) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');
            $mutation = $db->prepare('INSERT INTO mutations
                (item_id,location_id,company_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes)
                VALUES (?,?,?, "adjustment",?, ?,"adjustment",?,?,?)');
            foreach ($location_rows as $row) {
                $reason = 'Stock opname';
                $detail->bind_param('iiiiis', $adjustment_id, $row['item_id'], $row['system_qty'], $row['physical_qty'], $row['variance'], $reason);
                $detail->execute();
                if ((int)$row['variance'] === 0) continue;
                $set_stock->bind_param('iiii', $row['item_id'], $location_id, $company_id, $row['physical_qty']);
                $set_stock->execute();
                $qty = abs((int)$row['variance']);
                $mutation_notes = "Stock opname: {$row['system_qty']} → {$row['physical_qty']}";
                $mutation->bind_param('iiiisiis', $row['item_id'], $location_id, $company_id, $qty, $ref, $adjustment_id, $user_id, $mutation_notes);
                $mutation->execute();
            }
            $detail->close();
            $set_stock->close();
            $mutation->close();
        }

        $done = $db->prepare('UPDATE stock_opname SET status="finalized",finalized_by=?,finalized_at=NOW() WHERE session_id=?');
        $done->bind_param('ii', $user_id, $session_id);
        $done->execute();
        $done->close();
        $db->commit();
        return $adjustment_ids;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
