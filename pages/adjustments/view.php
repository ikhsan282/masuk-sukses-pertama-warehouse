<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('adjustments.view');
$db = getDB();

$id = req_int('id');
$st = $db->prepare('SELECT a.*, l.name AS loc_name, l.code AS loc_code, u.name AS user_name,
    au.name AS approver_name
    FROM stock_adjustments a
    JOIN locations l ON l.id=a.location_id
    JOIN users u ON u.id=a.user_id
    LEFT JOIN users au ON au.id=a.approved_by
    WHERE a.id=?');
$st->bind_param('i', $id); $st->execute();
$adj = $st->get_result()->fetch_assoc(); $st->close();
if (!$adj) { set_flash('error', 'Penyesuaian stok tidak ditemukan.'); redirect(APP_URL . '/pages/adjustments/index.php'); }

$dt = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM stock_adjustment_details d
    JOIN items i ON i.id=d.item_id
    JOIN units u ON u.id=i.unit_id
    WHERE d.adjustment_id=?');
$dt->bind_param('i', $id); $dt->execute();
$details = $dt->get_result()->fetch_all(MYSQLI_ASSOC); $dt->close();

// ── Approve handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_str('action') === 'approve') {
    require_perm('adjustments.approve');
    verify_csrf();

    if ($adj['status'] !== 'draft') {
        set_flash('error', 'Dokumen ini sudah diproses sebelumnya.');
        redirect(APP_URL . '/pages/adjustments/view.php?id=' . $id);
    }

    $user_id = current_user()['id'];
    $db->begin_transaction();
    try {
        // 1. Mark approved
        $up = $db->prepare('UPDATE stock_adjustments SET status="approved", approved_by=?, approved_at=NOW() WHERE id=? AND status="draft"');
        $up->bind_param('ii', $user_id, $id);
        $up->execute();
        if ($up->affected_rows === 0) throw new Exception('Dokumen sudah di-approve oleh user lain.');
        $up->close();

        // 2. Apply stock changes + mutation log
        $adj_company_id = (int)($adj['company_id'] ?: 1);
        foreach ($details as $d) {
            $diff = (int)$d['difference'];
            if ($diff === 0) continue;
            update_stock((int)$d['item_id'], (int)$adj['location_id'], $diff, $adj_company_id);
            log_mutation((int)$d['item_id'], (int)$adj['location_id'], 'adjustment', abs($diff),
                $adj['reference_no'], 'adjustment', (int)$adj['id'], $user_id,
                'Stok opname: ' . $d['system_qty'] . ' → ' . $d['physical_qty'] . ($d['reason'] ? ' — ' . $d['reason'] : ''),
                $adj_company_id);
        }

        $db->commit();
        audit_log('adjustment.approve', 'stock_adjustment', (int)($adj['id'] ?? req_int('id')), (string)$adj['reference_no'], "Approve penyesuaian stok {$adj['reference_no']}", isset($adj['company_id']) ? (int)$adj['company_id'] : null);
        set_flash('success', "Penyesuaian <strong>{$adj['reference_no']}</strong> berhasil di-approve. Stok sistem telah diperbarui.");
    } catch (Exception $e) {
        $db->rollback();
        set_flash('error', 'Gagal approve: ' . $e->getMessage());
    }
    redirect(APP_URL . '/pages/adjustments/view.php?id=' . $id);
}

$page_title = 'Detail Stok Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/adjustments/index.php">Stok Opname</a></li>
    <li class="breadcrumb-item active"><?= htmlspecialchars($adj['reference_no']) ?></li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-clipboard-check me-2 text-primary"></i><?= htmlspecialchars($adj['reference_no']) ?></h4>
    <span class="badge <?= $adj['status'] === 'approved' ? 'bg-success-subtle text-success border' : 'bg-warning-subtle text-warning border' ?> fs-6">
      <?= $adj['status'] === 'approved' ? 'Approved' : 'Draft' ?>
    </span>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-2"></i>Informasi</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted small" style="width:45%">Tanggal</td><td><?= tgl($adj['transaction_date']) ?></td></tr>
          <tr><td class="text-muted small">Lokasi</td><td><?= htmlspecialchars($adj['loc_code']) ?> — <?= htmlspecialchars($adj['loc_name']) ?></td></tr>
          <tr><td class="text-muted small">Dibuat oleh</td><td class="small"><?= htmlspecialchars($adj['user_name']) ?></td></tr>
          <?php if ($adj['approved_by']): ?>
          <tr><td class="text-muted small">Disetujui oleh</td><td class="small"><?= htmlspecialchars($adj['approver_name']) ?></td></tr>
          <tr><td class="text-muted small">Waktu approval</td><td class="small"><?= date('d/m/Y H:i', strtotime($adj['approved_at'])) ?></td></tr>
          <?php endif; ?>
          <tr><td class="text-muted small">Catatan</td><td class="small"><?= nl2br(htmlspecialchars($adj['notes'] ?: '-')) ?></td></tr>
        </table>
      </div>
    </div>

    <?php if ($adj['status'] === 'draft' && can('adjustments.approve')): ?>
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <?php if ($adj['user_id'] === current_user()['id']): ?>
          <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>
            Anda tidak dapat menyetujui dokumen yang Anda buat sendiri. Minta admin lain untuk approve.</div>
        <?php else: ?>
        <form method="POST" onsubmit="return confirm('Approve dokumen ini? Stok sistem akan langsung disesuaikan.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="approve">
          <p class="small text-muted mb-2"><i class="bi bi-shield-check me-1"></i>Approve akan langsung mengubah stok sistem sesuai selisih.</p>
          <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-circle me-1"></i>Approve & Terapkan</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="card table-card">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check me-2"></i>Hasil Penghitungan (<?= count($details) ?> item)</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr>
            <th>Kode</th><th>Nama Barang</th>
            <th class="text-center">Stok Sistem</th>
            <th class="text-center">Stok Fisik</th>
            <th class="text-center">Selisih</th>
            <th>Alasan</th>
          </tr></thead>
          <tbody>
          <?php foreach ($details as $d):
            $diff = (int)$d['difference'];
            $diff_cls = $diff > 0 ? 'text-success fw-bold' : ($diff < 0 ? 'text-danger fw-bold' : 'text-muted');
          ?>
            <tr>
              <td><code class="small"><?= htmlspecialchars($d['item_code']) ?></code></td>
              <td class="small"><?= htmlspecialchars($d['item_name']) ?></td>
              <td class="text-center small"><?= $d['system_qty'] ?> <?= htmlspecialchars($d['abbreviation']) ?></td>
              <td class="text-center small"><?= $d['physical_qty'] ?> <?= htmlspecialchars($d['abbreviation']) ?></td>
              <td class="text-center <?= $diff_cls ?>">
                <?= $diff > 0 ? '+' . $diff : $diff ?> <?= htmlspecialchars($d['abbreviation']) ?>
              </td>
              <td class="small text-muted"><?= htmlspecialchars($d['reason'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
