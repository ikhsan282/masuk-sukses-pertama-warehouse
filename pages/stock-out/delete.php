<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_out.delete');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL.'/pages/stock-out/index.php');
verify_csrf();

$id = req_int('id');
$db = getDB();

$st = $db->prepare('SELECT location_id, company_id FROM stock_out WHERE id=?');
$st->bind_param('i',$id); $st->execute();
$header = $st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/stock-out/index.php'); }

$st2 = $db->prepare('SELECT item_id, quantity FROM stock_out_details WHERE stock_out_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details = $st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$db->begin_transaction();
try {
    foreach ($details as $d) {
        update_stock($d['item_id'], $header['location_id'], $d['quantity'], (int)$header['company_id']); // restore
    }
    $dm = $db->prepare('DELETE FROM mutations WHERE reference_type=? AND reference_id=?');
    $type = 'stock_out';
    $dm->bind_param('si',$type,$id); $dm->execute(); $dm->close();
    $del = $db->prepare('DELETE FROM stock_out WHERE id=?');
    $del->bind_param('i',$id); $del->execute(); $del->close();
    $db->commit();
    set_flash('success','Transaksi barang keluar berhasil dihapus dan stok dikembalikan.');
} catch (Exception $e) {
    $db->rollback();
    set_flash('error','Gagal menghapus: '.$e->getMessage());
}
redirect(APP_URL.'/pages/stock-out/index.php');
