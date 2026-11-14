<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/slow_moving.php';

require_perm('reports.view');
ob_start();
$db = getDB();
grid_apply_timeout($db);

$f          = slow_moving_filters();
$cf         = $f['cf'];
$days       = $f['days'];
$cls        = $f['class'];
$cat_filter = $f['category_id'];
$search     = $f['q'];
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();

$sql_parts = slow_moving_build_sql($f);
$from      = $sql_parts['from'];
$where     = $sql_parts['where'];
$params    = $sql_parts['params'];
$types     = $sql_parts['types'];

// Summary count + total capital tied up in one pass
$cs = $db->prepare("SELECT COUNT(*), COALESCE(SUM(st.total_stock * i.buy_price), 0) $from $where");
$cs->bind_param($types, ...$params);
$cs->execute();
$cs->bind_result($total, $total_held_value);
$cs->fetch();
$cs->close();

$total            = (int)$total;
$total_held_value = (float)$total_held_value;

$pag    = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];

$q_params = array_merge($params, [$limit, $offset]);
$q_types  = $types . 'ii';

$st = $db->prepare("SELECT i.id, i.code, i.name, c.name AS cat_name, u.abbreviation,
    i.buy_price, st.total_stock,
    (st.total_stock * i.buy_price) AS held_value,
    mo.last_out_at,
    DATEDIFF(CURDATE(), COALESCE(DATE(mo.last_out_at), DATE(i.created_at))) AS idle_days
    $from $where
    ORDER BY held_value DESC, idle_days DESC, i.code ASC
    LIMIT ? OFFSET ?");
$st->bind_param($q_types, ...$q_params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$categories = $db->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$export_params = [
    'company_id'  => $cf['param_value'],
    'category_id' => $cat_filter ?: '',
    'days'        => $days,
    'class'       => $cls,
    'q'           => $search,
];

$page_title = 'Laporan Slow-Moving & Dead Stock';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-hourglass-split me-2 text-warning"></i>Laporan Slow-Moving &amp; Dead Stock</h4>
    <div class="small text-muted">Pemantauan stok fisik (&gt; 0) tanpa barang keluar &ge; <?= $days ?> hari beserta nilai modal tertahan.</div>
  </div>
  <?= report_export_dropdown_html($export_params, 'slow-moving_export.php') ?>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>Cakupan: <strong><?= htmlspecialchars($cf['label']) ?></strong> &bull;
  SKU mengendap (&ge; <?= $days ?> hari): <strong><?= number_format($total, 0, ',', '.') ?> barang</strong> &bull;
  Total modal tertahan: <strong><?= idr($total_held_value) ?></strong>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= grid_filter_search_html($search, 'Cari kode / nama barang...') ?>
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <select name="category_id" class="form-select form-select-sm grid-select-md" aria-label="Filter Kategori">
        <option value="">Semua Kategori</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="days" class="form-select form-select-sm grid-select-sm" aria-label="Ambang Hari Mengendap">
        <?php foreach ([30, 60, 90, 180] as $dOpt): ?>
        <option value="<?= $dOpt ?>" <?= $days === $dOpt ? 'selected' : '' ?>>&ge; <?= $dOpt ?> Hari</option>
        <?php endforeach; ?>
      </select>
      <select name="class" class="form-select form-select-sm grid-select-sm" aria-label="Klasifikasi Stok">
        <option value="">Semua Status</option>
        <option value="dead" <?= $cls === 'dead' ? 'selected' : '' ?>>Dead Stock</option>
        <option value="slow" <?= $cls === 'slow' ? 'selected' : '' ?>>Slow-Moving</option>
      </select>
      <?= grid_filter_actions_html($pag['per_page'], (bool)($search !== '' || $cat_filter || $days !== 90 || $cls !== '' || $cf['is_all'] || isset($_GET['company_id']))) ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Kode</th>
          <th>Nama Barang</th>
          <th>Kategori</th>
          <th class="text-center">Stok</th>
          <th class="text-end">H. Beli</th>
          <th class="text-end">Nilai Tertahan</th>
          <th class="text-center">Keluar Terakhir</th>
          <th class="text-center">Umur</th>
          <th class="text-center">Klasifikasi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada stok slow-moving atau dead stock pada kriteria ini.</td></tr>
      <?php else: foreach ($rows as $i => $r):
        $idleDays = max(0, (int)$r['idle_days']);
        [, $clsLabel, $clsBadge] = slow_moving_classify($r['last_out_at'] ?? null, $idleDays);
      ?>
        <tr>
          <td class="small text-muted"><?= $pag['offset'] + $i + 1 ?></td>
          <td><a href="<?= APP_URL ?>/pages/items/view.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none"><code><?= htmlspecialchars($r['code']) ?></code></a></td>
          <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td class="small"><?= htmlspecialchars($r['cat_name']) ?></td>
          <td class="text-center fw-bold"><?= number_format((int)$r['total_stock'], 0, ',', '.') ?> <small class="text-muted"><?= htmlspecialchars($r['abbreviation']) ?></small></td>
          <td class="text-end small"><?= idr((float)$r['buy_price']) ?></td>
          <td class="text-end small fw-semibold"><?= idr((float)$r['held_value']) ?></td>
          <td class="text-center small text-muted"><?= !empty($r['last_out_at']) ? date('d/m/Y', strtotime((string)$r['last_out_at'])) : 'Belum Pernah' ?></td>
          <td class="text-center"><span class="fw-semibold"><?= number_format($idleDays, 0, ',', '.') ?></span> <small class="text-muted">hr</small></td>
          <td class="text-center"><span class="badge <?= $clsBadge ?>"><?= $clsLabel ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'barang', $export_params) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
