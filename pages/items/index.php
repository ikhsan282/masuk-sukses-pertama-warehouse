<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.view');
$db = getDB();

$search     = grid_search_term('q');
$cat_filter = req_int('category_id');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();
$active_comp_code = current_company()['code'] ?? 'MSP';

$where_parts = ['i.is_active=1'];
$params = [];
$types  = '';
if ($search)     { $where_parts[] = '(i.name LIKE ? OR i.code LIKE ?)'; $like = '%' . escape_like($search) . '%'; $params[] = &$like; $params[] = &$like; $types .= 'ss'; }
if ($cat_filter) { $where_parts[] = 'i.category_id=?'; $params[] = &$cat_filter; $types .= 'i'; }
$where = 'WHERE ' . implode(' AND ', $where_parts);

$cq = $db->prepare("SELECT COUNT(*) FROM items i $where");
if ($types) $cq->bind_param($types, ...$params);
$cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag = paginate($total, $page, $per_page);
$offset = $pag['offset']; $limit = $pag['per_page'];
$types2 = $types . 'ii';
$p2 = $params; $p2[] = &$limit; $p2[] = &$offset;

$st = $db->prepare("SELECT i.*, c.name AS cat_name, u.abbreviation AS unit_abbr,
    COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id=$active_cid), 0) AS total_stock
    FROM (SELECT i.id FROM items i $where ORDER BY i.code LIMIT ? OFFSET ?) pg
    JOIN items i ON i.id = pg.id
    JOIN categories c ON c.id=i.category_id
    JOIN units u ON u.id=i.unit_id
    ORDER BY i.code");
$st->bind_param($types2, ...$p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$categories = $db->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$page_title = 'Data Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-box-seam me-2 text-primary"></i>Data Barang</h4>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <?= master_export_dropdown_html(['q' => $search, 'category_id' => $cat_filter ?: '']) ?>
    <?php if (can('items.create')): ?>
    <a href="<?= APP_URL ?>/pages/items/import.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel/CSV
    </a>
    <a href="<?= APP_URL ?>/pages/items/create.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Tambah Barang
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= grid_filter_search_html($search, 'Cari kode / nama barang...') ?>
      <select name="category_id" class="form-select form-select-sm grid-select-md" aria-label="Filter Kategori">
        <option value="">Semua Kategori</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $cat_filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= grid_filter_actions_html($pag['per_page'], (bool)($search || $cat_filter)) ?>
    </form>
    <?php if (can('items.labels')): ?>
    <div class="grid-toolbar-actions">
      <form id="labelForm" method="GET" action="<?= APP_URL ?>/pages/items/labels.php" class="m-0">
        <button class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-upc me-1"></i>Cetak Label Terpilih</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr><th><?php if(can('items.labels')):?><input class="form-check-input" type="checkbox" onclick="document.querySelectorAll('[name=\'id[]\']').forEach(x=>x.checked=this.checked)"><?php else:?>#<?php endif;?></th><th>Foto</th><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Satuan</th>
        <th class="text-end">Stok (<?= htmlspecialchars($active_comp_code) ?>)</th><th class="text-end">Harga Beli</th><th class="text-end">Harga Jual</th><th class="text-center">Aksi</th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach ($rows as $i => $r):
        $stock_class = $r['total_stock'] == 0 ? 'badge-zero' : ($r['total_stock'] <= $r['min_stock'] ? 'badge-low' : 'badge-ok');
      ?>
        <tr>
          <td class="small text-muted"><?php if(can('items.labels')):?><input form="labelForm" class="form-check-input" type="checkbox" name="id[]" value="<?=$r['id']?>"><?php else:?><?= $pag['offset']+$i+1 ?><?php endif;?></td>
          <td>
            <?php if (!empty($r['image'])): ?>
              <img src="<?= APP_URL ?>/<?= htmlspecialchars($r['image']) ?>" alt="" class="rounded border" style="width:44px;height:44px;object-fit:cover">
            <?php else: ?>
              <span class="d-inline-flex align-items-center justify-content-center bg-light border rounded text-muted" style="width:44px;height:44px"><i class="bi bi-image"></i></span>
            <?php endif; ?>
          </td>
          <td><code class="small"><?= htmlspecialchars($r['code']) ?></code></td>
          <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td class="small"><?= htmlspecialchars($r['cat_name']) ?></td>
          <td class="small"><?= htmlspecialchars($r['unit_abbr']) ?></td>
          <td class="text-end">
            <span class="badge <?= $stock_class ?>"><?= $r['total_stock'] ?></span>
          </td>
          <td class="text-end small"><?= idr((float)$r['buy_price']) ?></td>
          <td class="text-end small"><?= idr((float)$r['sell_price']) ?></td>
          <td class="text-center text-nowrap">
            <a href="<?= APP_URL ?>/pages/items/view.php?id=<?= $r['id'] ?>" class="btn btn-action btn-outline-info" title="Detail"><i class="bi bi-eye"></i></a>
            <?php if (can('items.edit')): ?>
            <a href="<?= APP_URL ?>/pages/items/edit.php?id=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (can('items.delete')): ?>
            <form method="POST" action="<?= APP_URL ?>/pages/items/delete.php" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus barang '<?= htmlspecialchars($r['name']) ?>'?" title="Hapus"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'barang', ['q' => $search, 'category_id' => $cat_filter ?: '']) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
