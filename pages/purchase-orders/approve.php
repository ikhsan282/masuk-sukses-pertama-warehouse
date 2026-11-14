<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_perm('po.approve');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/pages/purchase-orders/index.php');
}

verify_csrf();
$db = getDB();
$id = req_int('id');
$action = req_str('action');
$notes = trim($_POST['approval_notes'] ?? '');

if (!in_array($action, ['approve', 'reject'], true)) {
    set_flash('error', 'Aksi tidak valid.');
    redirect(APP_URL . '/pages/purchase-orders/index.php');
}

$chk = $db->prepare("SELECT id, company_id FROM purchase_orders WHERE id=?");
$chk->bind_param('i', $id);
$chk->execute();
$chkRow = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$chkRow) {
    set_flash('error', 'Purchase order tidak ditemukan.');
    redirect(APP_URL . '/pages/purchase-orders/index.php');
}
require_company_access((int)$chkRow['company_id']);

$db->begin_transaction();
try {
    $st = $db->prepare("SELECT id, reference_no, approval_status FROM purchase_orders WHERE id=? FOR UPDATE");
    $st->bind_param('i', $id);
    $st->execute();
    $po = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$po) {
        throw new RuntimeException('Purchase order tidak ditemukan.');
    }

    if ($po['approval_status'] !== 'pending') {
        throw new RuntimeException('Hanya PO dengan status pending yang dapat diapprove/reject.');
    }

    $user_id = current_user()['id'];
    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    
    $upd = $db->prepare("UPDATE purchase_orders SET approval_status=?, approved_by=?, approved_at=NOW(), approval_notes=? WHERE id=?");
    $upd->bind_param('sisi', $new_status, $user_id, $notes, $id);
    $upd->execute();
    $upd->close();

    $db->commit();

    audit_log(
        $action === 'approve' ? 'po.approve' : 'po.reject',
        'purchase_order',
        (int)$id,
        (string)($po['reference_no'] ?? ''),
        $action === 'approve' ? "Approve PO {$po['reference_no']}" : "Reject PO {$po['reference_no']}"
    );
    
    $msg = $action === 'approve' 
        ? "PO {$po['reference_no']} telah diapprove."
        : "PO {$po['reference_no']} telah direject.";
    set_flash('success', $msg);
} catch (Throwable $e) {
    $db->rollback();
    set_flash('error', 'Gagal memproses approval: ' . $e->getMessage());
}

redirect(APP_URL . '/pages/purchase-orders/index.php');
