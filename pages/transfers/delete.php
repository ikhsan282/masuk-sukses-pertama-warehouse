<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.delete');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL.'/pages/transfers/index.php');
verify_csrf();

$id = req_int('id');
$db = getDB();

$st=$db->prepare('SELECT from_location_id, to_location_id, from_company_id, to_company_id FROM transfers WHERE id=?');
$st->bind_param('i',$id); $st->execute();
$header=$st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/transfers/index.php'); }

$st2=$db->prepare('SELECT item_id, quantity FROM transfer_details WHERE transfer_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details=$st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$db->begin_transaction();
try {
    $undo_lines = array_map(fn($d) => [(int)$d['item_id'], (int)$d['quantity']], $details);
    assert_location_stock_available($db, (int)$header['to_location_id'], $undo_lines, (int)$header['to_company_id']);
    foreach ($details as $d) {
        update_stock($d['item_id'],$header['from_location_id'],$d['quantity'],(int)$header['from_company_id']); // restore source
        update_stock($d['item_id'],$header['to_location_id'],-$d['quantity'],(int)$header['to_company_id']);  // undo destination
    }
    $type='transfer';
    $dm=$db->prepare('DELETE FROM mutations WHERE reference_type=? AND reference_id=?');
    $dm->bind_param('si',$type,$id); $dm->execute(); $dm->close();
    $del=$db->prepare('DELETE FROM transfers WHERE id=?');
    $del->bind_param('i',$id); $del->execute(); $del->close();
    $db->commit();
    audit_log('transfer.delete', 'transfer', $id, '', "Menghapus transfer #{$id} dan mengembalikan stok");
    set_flash('success','Transfer berhasil dihapus dan stok dikembalikan.');
} catch (Exception $e) {
    $db->rollback();
    set_flash('error','Gagal: '.$e->getMessage());
}
redirect(APP_URL.'/pages/transfers/index.php');
