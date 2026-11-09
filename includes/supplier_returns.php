<?php

function supplier_return_items(array $item_ids, array $quantities, array $prices): array {
    $items = [];
    foreach ($item_ids as $k => $raw_id) {
        $id = (int)$raw_id;
        $qty = (int)($quantities[$k] ?? 0);
        $price = (float)($prices[$k] ?? 0);
        if ($id > 0 && $qty > 0 && $price >= 0) $items[$id] = [$id, $qty, $price];
    }
    $items = array_values($items);
    usort($items, fn($a, $b) => $a[0] <=> $b[0]); // consistent lock order
    return $items;
}

function create_supplier_return(mysqli $db, int $supplier_id, int $location_id, int $user_id,
    string $date, string $reason, string $notes, array $items, ?int $company_id = null): int {
    if (!$supplier_id || !$location_id || !$date || $reason === '' || !$items) {
        throw new RuntimeException('Data retur belum lengkap.');
    }
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    $company_id = $company_id ?: 1;

    $db->begin_transaction();
    try {
        $stock = $db->prepare('SELECT COALESCE(quantity,0) FROM stock WHERE item_id=? AND location_id=? AND company_id=? FOR UPDATE');
        foreach ($items as [$item_id, $qty]) {
            $stock->bind_param('iii', $item_id, $location_id, $company_id); $stock->execute();
            $available = 0; $stock->bind_result($available); $stock->fetch(); $stock->free_result();
            if ($qty > $available) {
                $in = $db->prepare('SELECT name FROM items WHERE id=?');
                $in->bind_param('i', $item_id); $in->execute();
                $in->bind_result($iname); $in->fetch(); $in->close();
                throw new RuntimeException("Stok {$iname} tidak cukup. Tersedia: {$available}.");
            }
        }
        $stock->close();

        $ref = generate_ref('RS-', $company_id);
        $insert = $db->prepare("INSERT INTO supplier_returns (reference_no,company_id,supplier_id,location_id,user_id,reason,notes,transaction_date) VALUES (?,?,?,?,?,?,?,?)");
        $insert->bind_param('siiiisss', $ref, $company_id, $supplier_id, $location_id, $user_id, $reason, $notes, $date);
        $insert->execute(); $return_id = $db->insert_id; $insert->close();

        $line = $db->prepare('INSERT INTO supplier_return_details (return_id,item_id,quantity,buy_price) VALUES (?,?,?,?)');
        $decrement = $db->prepare('UPDATE stock SET quantity=quantity-? WHERE item_id=? AND location_id=? AND company_id=?');
        $mutation = $db->prepare("INSERT INTO mutations (item_id,location_id,company_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes) VALUES (?,?,?,'out',?,?,'supplier_return',?,?,?)");
        foreach ($items as [$item_id, $qty, $price]) {
            $line->bind_param('iiid', $return_id, $item_id, $qty, $price); $line->execute();
            $decrement->bind_param('iiii', $qty, $item_id, $location_id, $company_id); $decrement->execute();
            $mutation->bind_param('iiiisiis', $item_id, $location_id, $company_id, $qty, $ref, $return_id, $user_id, $reason); $mutation->execute();
        }
        $line->close(); $decrement->close(); $mutation->close();
        $db->commit();
        return $return_id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

// Cancels a return: row is kept for history, stock is restored, reversal is logged.
function cancel_supplier_return(mysqli $db, int $id, int $user_id): void {
    $db->begin_transaction();
    try {
        $st = $db->prepare('SELECT reference_no,location_id,company_id,status FROM supplier_returns WHERE id=? FOR UPDATE');
        $st->bind_param('i', $id); $st->execute();
        $ret = $st->get_result()->fetch_assoc(); $st->close();
        if (!$ret) throw new RuntimeException('Retur supplier tidak ditemukan.');
        if ($ret['status'] !== 'completed') throw new RuntimeException('Retur supplier sudah dibatalkan.');

        $company_id = (int)($ret['company_id'] ?: 1);

        $st = $db->prepare('SELECT item_id,quantity FROM supplier_return_details WHERE return_id=?');
        $st->bind_param('i', $id); $st->execute();
        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

        $restore = $db->prepare('INSERT INTO stock (item_id,location_id,company_id,quantity) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)');
        $mutation = $db->prepare("INSERT INTO mutations (item_id,location_id,company_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes) VALUES (?,?,?,'in',?,?,'supplier_return',?,?,'Pembatalan retur supplier')");
        foreach ($items as $item) {
            $restore->bind_param('iiii', $item['item_id'], $ret['location_id'], $company_id, $item['quantity']); $restore->execute();
            $mutation->bind_param('iiiisii', $item['item_id'], $ret['location_id'], $company_id, $item['quantity'], $ret['reference_no'], $id, $user_id); $mutation->execute();
        }
        $restore->close(); $mutation->close();

        $up = $db->prepare("UPDATE supplier_returns SET status='cancelled' WHERE id=?");
        $up->bind_param('i', $id); $up->execute(); $up->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
