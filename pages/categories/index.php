<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('categories.view');
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('categories.create')) {
        $code = strtoupper(req_str('code'));
        $name = req_str('name');
        $desc = req_str('description');
        if (!$code || !$name) { set_flash('error', 'Kode dan nama wajib diisi.'); }
        else {
            $st = $db->prepare('INSERT INTO categories (code,name,description) VALUES (?,?,?)');
            $st->bind_param('sss', $code, $name, $desc);
            if ($st->execute()) set_flash('success', 'Kategori berhasil ditambahkan.');
            else set_flash('error', 'Kode sudah digunakan.');
            $st->close();
        }
    }

    if ($action === 'edit' && can('categories.edit')) {
        $id   = req_int('id');
        $code = strtoupper(req_str('code'));
        $name = req_str('name');
        $desc = req_str('description');
        $st = $db->prepare('UPDATE categories SET code=?,name=?,description=? WHERE id=?');
        $st->bind_param('sssi', $code, $name, $desc, $id);
        if ($st->execute()) set_flash('success', 'Kategori berhasil diperbarui.');
        else set_flash('error', 'Gagal memperbarui kategori.');
        $st->close();
    }

    if ($action === 'delete' && can('categories.delete')) {
        $id = req_int('id');
        $st = $db->prepare('DELETE FROM categories WHERE id=?');
        $st->bind_param('i', $id);
        if ($st->execute()) set_flash('success', 'Kategori berhasil dihapus.');
        else set_flash('error', 'Gagal menghapus. Kategori masih digunakan.');
        $st->close();
    }
    redirect(APP_URL . '/pages/categories/index.php');
}

// Fetch for edit modal
$edit_row = null;
if (req_int('edit')) {
    $st = $db->prepare('SELECT * FROM categories WHERE id=?');
    $st->bind_param('i', $_GET['edit']);
    $st->execute();
    $edit_row = $st->get_result()->fetch_assoc();
    $st->close();
}

// List
$search   = grid_search_term('q');
$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$where    = $search ? "WHERE name LIKE ? OR code LIKE ?" : '';
$count_q  = $db->prepare("SELECT COUNT(*) FROM categories $where");
if ($search) { $like = '%' . escape_like($search) . '%'; $count_q->bind_param('ss', $like, $like); }
$count_q->execute(); $count_q->bind_result($total); $count_q->fetch(); $count_q->close();

$pag    = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];
$st = $db->prepare("SELECT * FROM categories $where ORDER BY name LIMIT ? OFFSET ?");
if ($search) { $st->bind_param('ssii', $like, $like, $limit, $offset); }
else { $st->bind_param('ii', $limit, $offset); }
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title = 'Kategori Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-tags me-2 text-primary"></i>Kategori Barang</h4>
  <?php if (can('categories.create')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
  </button>
  <?php endif; ?>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:260px"
        placeholder="Cari kategori..." value="<?= htmlspecialchars($search) ?>">
      <button class="btn btn-sm btn-outline-secondary">Cari</button>
      <?php if ($search): ?><a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Kategori</th><th>Deskripsi</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach ($rows as $i => $r): ?>
        <tr>
          <td class="small text-muted"><?= $pag['offset'] + $i + 1 ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($r['code']) ?></span></td>
          <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($r['description'] ?? '') ?></td>
          <td class="text-center">
            <?php if (can('categories.edit')): ?>
            <a href="?edit=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary" title="Edit">
              <i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (can('categories.delete')): ?>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus kategori '<?= htmlspecialchars($r['name']) ?>'?" title="Hapus">
                <i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'kategori', ['q' => $search]) ?>
</div>

<!-- Add/Edit Modal -->
<?php $is_edit = !is_null($edit_row); ?>
<div class="modal fade <?= $is_edit ? 'show' : '' ?>" id="formModal" tabindex="-1"
  <?= $is_edit ? 'style="display:block" aria-modal="true"' : '' ?>>
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $is_edit ? 'edit' : 'create' ?>">
        <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= $edit_row['id'] ?>"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title"><?= $is_edit ? 'Edit' : 'Tambah' ?> Kategori</h5>
          <a href="?" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Kode Kategori</label>
            <input type="text" name="code" class="form-control" placeholder="KAT-001"
              value="<?= htmlspecialchars($edit_row['code'] ?? '') ?>" required maxlength="20">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Nama Kategori</label>
            <input type="text" name="name" class="form-control" placeholder="Nama kategori"
              value="<?= htmlspecialchars($edit_row['name'] ?? '') ?>" required maxlength="100">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Deskripsi</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Opsional"><?= htmlspecialchars($edit_row['description'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <a href="?" class="btn btn-secondary btn-sm">Batal</a>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-save me-1"></i>Simpan
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($is_edit): ?><div class="modal-backdrop fade show"></div><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
