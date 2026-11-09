<?php

function po_valid_items(array $item_ids, array $quantities, array $prices): array {
    $items = [];
    foreach ($item_ids as $k => $raw_id) {
        $id = (int)$raw_id;
        $qty = (int)($quantities[$k] ?? 0);
        $price = (float)($prices[$k] ?? 0);
        if ($id > 0 && $qty > 0 && $price >= 0) $items[$id] = [$id, $qty, $price];
    }
    return array_values($items);
}

function receive_purchase_order_partial(mysqli $db, int $po_id, array $receive_quantities, int $user_id): string {
    $db->begin_transaction();
    try {
        $st = $db->prepare("SELECT reference_no,company_id,supplier_id,location_id,notes,status,approval_status FROM purchase_orders WHERE id=? FOR UPDATE");
        $st->bind_param('i', $po_id); $st->execute();
        $po = $st->get_result()->fetch_assoc(); $st->close();
        if (!$po) throw new RuntimeException('Purchase order tidak ditemukan.');
        if ($po['approval_status'] !== 'approved') throw new RuntimeException('Hanya PO yang sudah diapprove yang dapat diterima.');
        if (!in_array($po['status'], ['pending', 'partial'], true)) throw new RuntimeException('Purchase order sudah diproses atau belum dipesan.');

        $company_id = (int)($po['company_id'] ?: 1);

        $detail = $db->prepare('SELECT item_id,quantity,received_quantity,buy_price FROM purchase_order_details WHERE po_id=?');
        $detail->bind_param('i', $po_id); $detail->execute();
        $items = $detail->get_result()->fetch_all(MYSQLI_ASSOC); $detail->close();
        if (!$items) throw new RuntimeException('Purchase order tidak memiliki barang.');

        // Validate receive quantities
        $to_receive = [];
        foreach ($items as $item) {
            $item_id = (int)$item['item_id'];
            $qty_ordered = (int)$item['quantity'];
            $qty_received = (int)$item['received_quantity'];
            $qty_incoming = (int)($receive_quantities[$item_id] ?? 0);
            
            if ($qty_incoming < 0) throw new RuntimeException('Quantity tidak boleh negatif.');
            if ($qty_incoming === 0) continue;
            if ($qty_received + $qty_incoming > $qty_ordered) {
                throw new RuntimeException("Item ID $item_id: jumlah terima melebihi jumlah order.");
            }
            
            $to_receive[] = ['item_id' => $item_id, 'quantity' => $qty_incoming, 'buy_price' => (float)$item['buy_price']];
        }

        if (empty($to_receive)) throw new RuntimeException('Tidak ada barang yang diterima.');

        // Create stock-in record
        $ref = 'SI-' . $po['reference_no'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $today = date('Y-m-d');
        $notes = trim('Penerimaan ' . $po['reference_no'] . '. ' . ($po['notes'] ?? ''));
        $insert = $db->prepare('INSERT INTO stock_in (reference_no,company_id,supplier_id,location_id,user_id,notes,transaction_date) VALUES (?,?,?,?,?,?,?)');
        $insert->bind_param('siiiiss', $ref, $company_id, $po['supplier_id'], $po['location_id'], $user_id, $notes, $today);
        $insert->execute(); $stock_in_id = $db->insert_id; $insert->close();

        // Add stock and update received quantities
        $line = $db->prepare('INSERT INTO stock_in_details (stock_in_id,item_id,quantity,buy_price) VALUES (?,?,?,?)');
        $stock = $db->prepare('INSERT INTO stock (item_id,location_id,company_id,quantity) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)');
        $mutation = $db->prepare("INSERT INTO mutations (item_id,location_id,company_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes) VALUES (?,?,?,'in',?,?,'stock_in',?,?,?)");
        $update_detail = $db->prepare('UPDATE purchase_order_details SET received_quantity=received_quantity+? WHERE po_id=? AND item_id=?');
        
        foreach ($to_receive as $item) {
            $item_id = $item['item_id']; $qty = $item['quantity']; $price = $item['buy_price'];
            $line->bind_param('iiid', $stock_in_id, $item_id, $qty, $price); $line->execute();
            $stock->bind_param('iiii', $item_id, $po['location_id'], $company_id, $qty); $stock->execute();
            $mutation->bind_param('iiiisiis', $item_id, $po['location_id'], $company_id, $qty, $ref, $stock_in_id, $user_id, $notes); $mutation->execute();
            $update_detail->bind_param('iii', $qty, $po_id, $item_id); $update_detail->execute();
        }
        $line->close(); $stock->close(); $mutation->close(); $update_detail->close();

        // Calculate new PO status
        $check = $db->prepare('SELECT COUNT(*) AS total, SUM(IF(received_quantity>=quantity,1,0)) AS completed FROM purchase_order_details WHERE po_id=?');
        $check->bind_param('i', $po_id); $check->execute();
        $status_data = $check->get_result()->fetch_assoc(); $check->close();
        
        $new_status = ($status_data['completed'] == $status_data['total']) ? 'completed' : 'partial';
        
        $update = $db->prepare("UPDATE purchase_orders SET status=?,received_by=?,received_at=NOW() WHERE id=?");
        $update->bind_param('sii', $new_status, $user_id, $po_id); $update->execute(); $update->close();
        
        $db->commit();
        return $ref;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function receive_purchase_order(mysqli $db, int $po_id, int $user_id): string {
    // Backwards compatibility: receive all items at full quantity
    $detail = $db->prepare('SELECT item_id,quantity FROM purchase_order_details WHERE po_id=?');
    $detail->bind_param('i', $po_id); $detail->execute();
    $items = $detail->get_result()->fetch_all(MYSQLI_ASSOC); $detail->close();
    
    $receive_qty = [];
    foreach ($items as $item) {
        $receive_qty[(int)$item['item_id']] = (int)$item['quantity'];
    }
    
    return receive_purchase_order_partial($db, $po_id, $receive_qty, $user_id);
}
