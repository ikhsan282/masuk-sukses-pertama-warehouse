<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.view');
$db = getDB();

$search = req_str('q');
$cat_filter = req_int('category_id');
$page = max(1, req_int('page', 1));
$active_cid = current_company_id();

$where_parts = ['i.is_active=1', '(i.company_id IS NULL OR i.company_id=?)'];
$params = [&$active_cid];
$types  = 'i';
if ($search)     { $where_parts[] = '(i.name LIKE ? OR i.code LIKE ?)'; $like="%$search%"; $params[]=&$like; $params[]=&$like; $types.='ss'; }
if ($cat_filter) { $where_parts[] = 'i.category_id=?'; $params[]=&$cat_filter; $types.='i'; }
$where = 'WHERE ' . implode(' AND ', $where_parts);

$cq = $db->prepare("SELECT COUNT(*) FROM items i $where");
if ($types) $cq->bind_param($types, ...$params);
$cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag = paginate($total, $page);
$offset = $pag['offset']; $limit = PER_PAGE;
$types2 = $types.'ii';
$p2 = $params; $p2[]=&$limit; $p2[]=&$offset;

$st = $db->prepare("SELECT i.*, c.name AS cat_name, u.abbreviation AS unit_abbr,
    comp.code AS comp_code,
    COALESCE(SUM(s.quantity),0) AS total_stock
    FROM items i
    JOIN categories c ON c.id=i.category_id
    JOIN units u ON u.id=i.unit_id
    LEFT JOIN companies comp ON comp.id=i.company_id
    LEFT JOIN stock s ON s.item_id=i.id AND s.company_id=$active_cid
    $where GROUP BY i.id ORDER BY i.code LIMIT ? OFFSET ?");
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
  <?php if (can('items.create')): ?>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/pages/items/import.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel/CSV
    </a>
    <a href="<?= APP_URL ?>/pages/items/create.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Tambah Barang
    </a>
  </div>
  <?php endif; ?>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between gap-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:240px"
        placeholder="Cari kode / nama..." value="<?= htmlspecialchars($search) ?>">
      <select name="category_id" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Semua Kategori</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $cat_filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <?php if ($search||$cat_filter): ?><a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i> Reset</a><?php endif; ?>
    </form>
    <?php if (can('items.labels')): ?>
    <form id="labelForm" method="GET" action="<?= APP_URL ?>/pages/items/labels.php">
      <button class="btn btn-sm btn-outline-dark"><i class="bi bi-upc me-1"></i>Cetak Label Terpilih</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr><th><?php if(can('items.labels')):?><input class="form-check-input" type="checkbox" onclick="document.querySelectorAll('[name=\'id[]\']').forEach(x=>x.checked=this.checked)"><?php else:?>#<?php endif;?></th><th>Foto</th><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Satuan</th>
        <th class="text-end">Stok</th><th class="text-end">Harga Beli</th><th class="text-end">Harga Jual</th><th class="text-center">Aksi</th></tr>
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
          <td>
            <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
            <?php if (empty($r['company_id'])): ?>
              <span class="badge bg-info-subtle text-info border" style="font-size:0.65rem;">🌐 Global</span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.65rem;">🏢 <?= htmlspecialchars($r['comp_code'] ?? 'PT') ?></span>
            <?php endif; ?>
          </td>
          <td class="small"><?= htmlspecialchars($r['cat_name']) ?></td>
          <td class="small"><?= htmlspecialchars($r['unit_abbr']) ?></td>
          <td class="text-end">
            <span class="badge <?= $stock_class ?>"><?= $r['total_stock'] ?></span>
          </td>
          <td class="text-end small"><?= idr((float)$r['buy_price']) ?></td>
          <td class="text-end small"><?= idr((float)$r['sell_price']) ?></td>
          <td class="text-center">
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
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?= count($rows) ?> dari <?= $total ?> barang</small>
    <?= pagination_html($pag, '?' . http_build_query(['q'=>$search,'category_id'=>$cat_filter]) . '&page=%d') ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
