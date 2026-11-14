<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('companies.view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('companies.create')) {
        $code       = strtoupper(req_str('code'));
        $name       = req_str('name');
        $address    = req_str('address');
        $phone      = req_str('phone');
        $email      = req_str('email');
        $npwp       = req_str('npwp');
        $is_holding = req_int('is_holding') ? 1 : 0;

        if (!$code || !$name) {
            set_flash('error', 'Kode dan nama perusahaan wajib diisi.');
        } else {
            $st = $db->prepare('INSERT INTO companies (code, name, address, phone, email, npwp, is_holding, is_active) VALUES (?,?,?,?,?,?,?,1)');
            $st->bind_param('ssssssi', $code, $name, $address, $phone, $email, $npwp, $is_holding);
            if ($st->execute()) {
                $new_id = $db->insert_id;
                // Automatically grant access to creator user
                $uid = current_user()['id'];
                $db->query("INSERT IGNORE INTO user_companies (user_id, company_id, is_default) VALUES ($uid, $new_id, 0)");
                set_flash('success', "Perusahaan <strong>{$name}</strong> berhasil ditambahkan.");
            } else {
                set_flash('error', 'Gagal menyimpan. Kode perusahaan mungkin sudah digunakan.');
            }
            $st->close();
        }
    }

    if ($action === 'edit' && can('companies.edit')) {
        $id         = req_int('id');
        $code       = strtoupper(req_str('code'));
        $name       = req_str('name');
        $address    = req_str('address');
        $phone      = req_str('phone');
        $email      = req_str('email');
        $npwp       = req_str('npwp');
        $is_holding = req_int('is_holding') ? 1 : 0;
        $is_active  = req_int('is_active');

        $st = $db->prepare('UPDATE companies SET code=?, name=?, address=?, phone=?, email=?, npwp=?, is_holding=?, is_active=? WHERE id=?');
        $st->bind_param('ssssssiii', $code, $name, $address, $phone, $email, $npwp, $is_holding, $is_active, $id);
        if ($st->execute()) {
            set_flash('success', 'Data perusahaan berhasil diperbarui.');
            // Refresh current session active company if same id
            if (current_company_id() === $id) {
                $_SESSION['active_company'] = [
                    'id' => $id, 'code' => $code, 'name' => $name, 'address' => $address,
                    'phone' => $phone, 'email' => $email, 'npwp' => $npwp, 'is_holding' => $is_holding
                ];
            }
        } else {
            set_flash('error', 'Gagal memperbarui data.');
        }
        $st->close();
    }

    if ($action === 'delete' && can('companies.delete')) {
        $id = req_int('id');
        if ($id === 1) {
            set_flash('error', 'Perusahaan induk holding (MSP) tidak dapat dihapus.');
        } else {
            // Check if there are transactions associated
            $cnt = 0;
            $chk = $db->prepare('SELECT COUNT(*) FROM stock WHERE company_id=? AND quantity > 0');
            $chk->bind_param('i', $id);
            $chk->execute();
            $chk->bind_result($cnt);
            $chk->fetch();
            $chk->close();

            if ($cnt > 0) {
                set_flash('error', 'Perusahaan tidak dapat dihapus karena masih memiliki saldo stok.');
            } else {
                $st = $db->prepare('DELETE FROM companies WHERE id=?');
                $st->bind_param('i', $id);
                if ($st->execute()) {
                    set_flash('success', 'Perusahaan berhasil dihapus.');
                } else {
                    set_flash('error', 'Gagal menghapus. Perusahaan terkait dengan riwayat transaksi.');
                }
                $st->close();
            }
        }
    }

    redirect(APP_URL . '/pages/companies/index.php');
}

$edit_row = null;
if (req_int('edit')) {
    $st = $db->prepare('SELECT * FROM companies WHERE id=?');
    $eid = req_int('edit');
    $st->bind_param('i', $eid);
    $st->execute();
    $edit_row = $st->get_result()->fetch_assoc();
    $st->close();
}

$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$total    = (int)$db->query('SELECT COUNT(*) FROM companies')->fetch_row()[0];
$pag      = paginate($total, $page, $per_page);
$offset   = $pag['offset'];
$limit    = $pag['per_page'];

$st = $db->prepare('SELECT c.*,
    (SELECT COUNT(DISTINCT s.item_id) FROM stock s WHERE s.company_id = c.id) AS total_items,
    (SELECT COALESCE(SUM(s.quantity), 0) FROM stock s WHERE s.company_id = c.id) AS total_stock
    FROM companies c
    JOIN (SELECT id FROM companies ORDER BY is_holding DESC, name ASC LIMIT ? OFFSET ?) pg ON pg.id = c.id
    ORDER BY c.is_holding DESC, c.name ASC');
$st->bind_param('ii', $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title = 'Kelola Perusahaan (PT)';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Perusahaan</li>
    </ol></nav>
    <h4 class="mb-0"><i class="bi bi-buildings me-2 text-primary"></i>Daftar Perusahaan (Holding & Subsidiaries)</h4>
  </div>
  <?php if (can('companies.create')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah PT / Perusahaan
  </button>
  <?php endif; ?>
</div>

<div class="card table-card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Kode</th>
          <th>Nama Perusahaan</th>
          <th>Kontak</th>
          <th>Alamat & NPWP</th>
          <th class="text-center">Tipe</th>
          <th class="text-center">Total Stok</th>
          <th class="text-center">Status</th>
          <th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Belum ada data perusahaan</td></tr>
      <?php else: foreach ($rows as $i => $r): ?>
        <tr>
          <td class="small text-muted"><?= $pag['offset'] + $i + 1 ?></td>
          <td><span class="badge bg-primary-subtle text-primary border"><?= htmlspecialchars($r['code']) ?></span></td>
          <td>
            <div class="fw-bold"><?= htmlspecialchars($r['name']) ?></div>
          </td>
          <td class="small">
            <?php if (!empty($r['phone'])): ?><div><i class="bi bi-telephone text-muted me-1"></i><?= htmlspecialchars($r['phone']) ?></div><?php endif; ?>
            <?php if (!empty($r['email'])): ?><div><i class="bi bi-envelope text-muted me-1"></i><?= htmlspecialchars($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="small text-muted">
            <div><?= htmlspecialchars($r['address'] ?? '-') ?></div>
            <?php if (!empty($r['npwp'])): ?><div class="fw-semibold">NPWP: <?= htmlspecialchars($r['npwp']) ?></div><?php endif; ?>
          </td>
          <td class="text-center">
            <?= $r['is_holding'] ? '<span class="badge bg-info-subtle text-info border">Induk (Holding)</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Anak Perusahaan</span>' ?>
          </td>
          <td class="text-center fw-bold"><?= number_format((float)$r['total_stock']) ?> pcs</td>
          <td class="text-center">
            <?= $r['is_active'] ? '<span class="badge badge-ok">Aktif</span>' : '<span class="badge badge-zero">Nonaktif</span>' ?>
          </td>
          <td class="text-center text-nowrap">
            <?php if (can('companies.edit')): ?>
            <a href="?edit=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (can('companies.delete') && !$r['is_holding']): ?>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus perusahaan '<?= htmlspecialchars($r['name']) ?>'?" title="Hapus">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'perusahaan') ?>
</div>

<!-- Modal Form (Tambah / Edit) -->
<div class="modal fade" id="formModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit_row ? 'edit' : 'create' ?>">
        <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= $edit_row['id'] ?>"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title"><?= $edit_row ? 'Edit Perusahaan' : 'Tambah Perusahaan Baru' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2 mb-3">
            <div class="col-sm-4">
              <label class="form-label small fw-semibold required">Kode PT</label>
              <input type="text" name="code" class="form-control form-control-sm text-uppercase" 
                     value="<?= htmlspecialchars($edit_row['code'] ?? '') ?>" placeholder="e.g. SLN" required>
            </div>
            <div class="col-sm-8">
              <label class="form-label small fw-semibold required">Nama Perusahaan</label>
              <input type="text" name="name" class="form-control form-control-sm" 
                     value="<?= htmlspecialchars($edit_row['name'] ?? '') ?>" placeholder="PT Contoh Sukses" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Alamat Lengkap</label>
            <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="Alamat kantor / gudang..."><?= htmlspecialchars($edit_row['address'] ?? '') ?></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">No. Telepon</label>
              <input type="text" name="phone" class="form-control form-control-sm" 
                     value="<?= htmlspecialchars($edit_row['phone'] ?? '') ?>" placeholder="021-...">
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Email</label>
              <input type="email" name="email" class="form-control form-control-sm" 
                     value="<?= htmlspecialchars($edit_row['email'] ?? '') ?>" placeholder="info@company.co.id">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">NPWP</label>
            <input type="text" name="npwp" class="form-control form-control-sm" 
                   value="<?= htmlspecialchars($edit_row['npwp'] ?? '') ?>" placeholder="00.000.000.0-000.000">
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="is_holding" id="isHolding" value="1" <?= !empty($edit_row['is_holding']) ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="isHolding">Perusahaan Induk (Holding)</label>
          </div>
          <?php if ($edit_row): ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Status Operasional</label>
            <select name="is_active" class="form-select form-select-sm">
              <option value="1" <?= $edit_row['is_active'] ? 'selected' : '' ?>>Aktif</option>
              <option value="0" <?= !$edit_row['is_active'] ? 'selected' : '' ?>>Nonaktif</option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit_row): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  new bootstrap.Modal(document.getElementById('formModal')).show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
