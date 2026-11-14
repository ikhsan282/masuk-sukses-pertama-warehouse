<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.delete');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(APP_URL.'/pages/items/index.php'); }
verify_csrf();

$id = req_int('id');
$db = getDB();
$st = $db->prepare('UPDATE items SET is_active=0 WHERE id=?');
$st->bind_param('i', $id);
if ($st->execute() && $st->affected_rows > 0) {
    audit_log('item.delete', 'item', $id, '', "Menonaktifkan barang #{$id}");
    set_flash('success','Barang berhasil dihapus.');
} else {
    set_flash('error','Gagal menghapus barang.');
}
$st->close();
redirect(APP_URL . '/pages/items/index.php');
