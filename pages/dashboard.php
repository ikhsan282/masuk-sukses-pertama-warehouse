<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_perm('dashboard.view');

$db = getDB();
grid_apply_timeout($db, 10.0);

// Context
$cid         = current_company_id();
$active_comp = current_company();
$user_comps  = user_companies();

$total_items     = (int)$db->query("SELECT COUNT(*) FROM items WHERE is_active=1")->fetch_row()[0];
$total_suppliers = (int)$db->query('SELECT COUNT(*) FROM suppliers WHERE is_active=1')->fetch_row()[0];
$total_locations = (int)$db->query('SELECT COUNT(*) FROM locations WHERE is_active=1')->fetch_row()[0];

// Inventory value (uses idx_stock_comp_item_qty)
$inv_value = (float)$db->query("SELECT COALESCE(SUM(s.quantity * i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id WHERE s.company_id=$cid")->fetch_row()[0];

// Low stock count (shared 60s session cache + correlated covering-index scalar subquery)
$cached_low = $_SESSION['low_stock_cache'][$cid] ?? null;
if (is_array($cached_low) && (time() - (int)($cached_low['ts'] ?? 0)) < 60) {
    $low_stock_count = (int)$cached_low['count'];
} else {
    $low_stock_count = (int)$db->query("SELECT COUNT(*) FROM items i
        WHERE i.is_active=1
        AND COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id=$cid), 0) <= i.min_stock")->fetch_row()[0];
    $_SESSION['low_stock_cache'][$cid] = ['count' => $low_stock_count, 'ts' => time()];
}

// Fast top-10 lowest-stock list: short-circuit on zero-stock items before scanning stocked items
$low_stock = $db->query("SELECT i.id, i.code, i.name, i.min_stock, 0 AS total_stock,
    GREATEST(i.min_stock*2,1) AS suggested_qty, u.abbreviation
    FROM items i
    LEFT JOIN units u ON u.id=i.unit_id
    WHERE i.is_active=1
    AND NOT EXISTS (SELECT 1 FROM stock s WHERE s.item_id=i.id AND s.company_id=$cid AND s.quantity > 0)
    ORDER BY i.id ASC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

if (count($low_stock) < 10) {
    $rem = 10 - count($low_stock);
    $more_low = $db->query("SELECT i.id, i.code, i.name, i.min_stock, st.qty AS total_stock,
        GREATEST(i.min_stock*2-st.qty,1) AS suggested_qty, u.abbreviation
        FROM (
            SELECT s.item_id, SUM(s.quantity) AS qty
            FROM stock s
            WHERE s.company_id=$cid AND s.quantity > 0
            GROUP BY s.item_id
        ) st
        JOIN items i ON i.id=st.item_id
        LEFT JOIN units u ON u.id=i.unit_id
        WHERE i.is_active=1 AND st.qty <= i.min_stock
        ORDER BY st.qty ASC LIMIT $rem")->fetch_all(MYSQLI_ASSOC);
    $low_stock = array_merge($low_stock, $more_low);
}

// Today transactions & quantities
$today = date('Y-m-d');
$today_in_row = $db->query("SELECT COUNT(DISTINCT si.id) AS cnt, COALESCE(SUM(d.quantity), 0) AS qty
    FROM stock_in si
    LEFT JOIN stock_in_details d ON d.stock_in_id = si.id
    WHERE si.transaction_date='{$today}' AND si.company_id=$cid")->fetch_assoc();
$today_in     = (int)($today_in_row['cnt'] ?? 0);
$today_in_qty = (int)($today_in_row['qty'] ?? 0);

$today_out_row = $db->query("SELECT COUNT(DISTINCT so.id) AS cnt, COALESCE(SUM(d.quantity), 0) AS qty
    FROM stock_out so
    LEFT JOIN stock_out_details d ON d.stock_out_id = so.id
    WHERE so.transaction_date='{$today}' AND so.company_id=$cid")->fetch_assoc();
$today_out     = (int)($today_out_row['cnt'] ?? 0);
$today_out_qty = (int)($today_out_row['qty'] ?? 0);

// Pending operational workflow tasks for active PT
$pending_po_approval = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE company_id=$cid AND approval_status='pending'")->fetch_row()[0];
$pending_po_receive  = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE company_id=$cid AND approval_status='approved' AND status IN ('pending','partial')")->fetch_row()[0];
$pending_adj_draft   = (int)$db->query("SELECT COUNT(*) FROM stock_adjustments WHERE company_id=$cid AND status='draft'")->fetch_row()[0];
$pending_opname      = (int)$db->query("SELECT COUNT(*) FROM stock_opname WHERE company_id=$cid AND status='draft'")->fetch_row()[0];
$total_pending_tasks = $pending_po_approval + $pending_po_receive + $pending_adj_draft + $pending_opname;

// Recent mutations (with location & reference)
$recent = $db->query("SELECT m.created_at, m.type, m.quantity, i.id AS item_id, i.name AS item_name,
    u.abbreviation, l.name AS location_name, m.reference_no
    FROM mutations m
    JOIN items i ON i.id=m.item_id
    LEFT JOIN units u ON u.id=i.unit_id
    JOIN locations l ON l.id=m.location_id
    WHERE m.company_id=$cid
    ORDER BY m.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Chart data: last 30 days (supports client toggle 7/30 days and docs/qty)
$start_30 = date('Y-m-d', strtotime('-29 days'));
$in_cnt_map = [];
$in_qty_map = [];
$rs_in = $db->query("SELECT si.transaction_date, COUNT(DISTINCT si.id) AS cnt, COALESCE(SUM(d.quantity), 0) AS qty
    FROM stock_in si
    LEFT JOIN stock_in_details d ON d.stock_in_id = si.id
    WHERE si.company_id=$cid AND si.transaction_date BETWEEN '$start_30' AND '$today'
    GROUP BY si.transaction_date");
while ($r = $rs_in->fetch_assoc()) {
    $in_cnt_map[$r['transaction_date']] = (int)$r['cnt'];
    $in_qty_map[$r['transaction_date']] = (int)$r['qty'];
}

$out_cnt_map = [];
$out_qty_map = [];
$rs_out = $db->query("SELECT so.transaction_date, COUNT(DISTINCT so.id) AS cnt, COALESCE(SUM(d.quantity), 0) AS qty
    FROM stock_out so
    LEFT JOIN stock_out_details d ON d.stock_out_id = so.id
    WHERE so.company_id=$cid AND so.transaction_date BETWEEN '$start_30' AND '$today'
    GROUP BY so.transaction_date");
while ($r = $rs_out->fetch_assoc()) {
    $out_cnt_map[$r['transaction_date']] = (int)$r['cnt'];
    $out_qty_map[$r['transaction_date']] = (int)$r['qty'];
}

$days_30    = [];
$in_cnt_30  = [];
$out_cnt_30 = [];
$in_qty_30  = [];
$out_qty_30 = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_30[]    = date('d M', strtotime($d));
    $in_cnt_30[]  = $in_cnt_map[$d] ?? 0;
    $out_cnt_30[] = $out_cnt_map[$d] ?? 0;
    $in_qty_30[]  = $in_qty_map[$d] ?? 0;
    $out_qty_30[] = $out_qty_map[$d] ?? 0;
}

// Top 5 items by stock value (drive from stock table instead of scanning 1M items)
$top_items = $db->query("SELECT i.name, (st.qty * i.buy_price) AS value
    FROM (
        SELECT s.item_id, SUM(s.quantity) AS qty
        FROM stock s
        WHERE s.company_id=$cid AND s.quantity > 0
        GROUP BY s.item_id
    ) st
    JOIN items i ON i.id=st.item_id
    WHERE i.is_active=1
    ORDER BY value DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Multi-PT summary table when user has access to > 1 company
$multi_pt_summary = [];
if (count($user_comps) > 1) {
    $comp_ids_sql = implode(',', array_map(static fn($c) => (int)$c['id'], $user_comps));
    $multi_pt_summary = $db->query("
        SELECT c.id, c.code, c.name, c.is_holding,
            COALESCE(st.sku_count, 0) AS sku_count,
            COALESCE(st.total_qty, 0) AS total_qty,
            COALESCE(st.inv_value, 0) AS inv_value,
            (SELECT COUNT(*) FROM items i
             WHERE i.is_active = 1
               AND COALESCE((SELECT SUM(s2.quantity) FROM stock s2 WHERE s2.item_id = i.id AND s2.company_id = c.id), 0) <= i.min_stock
            ) AS low_stock_count
        FROM companies c
        LEFT JOIN (
            SELECT s.company_id,
                   COUNT(DISTINCT CASE WHEN s.quantity > 0 THEN s.item_id END) AS sku_count,
                   SUM(s.quantity) AS total_qty,
                   SUM(s.quantity * i.buy_price) AS inv_value
            FROM stock s
            JOIN items i ON i.id = s.item_id AND i.is_active = 1
            WHERE s.company_id IN ($comp_ids_sql)
            GROUP BY s.company_id
        ) st ON st.company_id = c.id
        WHERE c.id IN ($comp_ids_sql) AND c.is_active = 1
        ORDER BY c.is_holding DESC, c.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
}

$full_inv_value    = idr($inv_value);
$compact_inv_value = compact_idr($inv_value);

$page_title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
  <div>
    <h4><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</h4>
    <p class="text-muted small mb-0">
      Selamat datang, <?= htmlspecialchars(current_user()['name']) ?>!
      <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><?= htmlspecialchars($active_comp['code'] ?? 'MSP') ?></span>
      &bull; Hari ini <?= tgl($today) ?>
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (can('stock_in.create')): ?>
    <a href="<?= APP_URL ?>/pages/stock-in/create.php" class="btn btn-sm btn-success text-nowrap">
      <i class="bi bi-box-arrow-in-down me-1"></i>Barang Masuk
    </a>
    <?php endif; ?>
    <?php if (can('stock_out.create')): ?>
    <a href="<?= APP_URL ?>/pages/stock-out/create.php" class="btn btn-sm btn-outline-danger text-nowrap">
      <i class="bi bi-box-arrow-up me-1"></i>Barang Keluar
    </a>
    <?php endif; ?>
    <?php if (can('purchase_orders.create')): ?>
    <a href="<?= APP_URL ?>/pages/purchase-orders/form.php" class="btn btn-sm btn-outline-primary text-nowrap">
      <i class="bi bi-cart-plus me-1"></i>Buat PO
    </a>
    <?php endif; ?>
    <?php if (can('transfers.create')): ?>
    <a href="<?= APP_URL ?>/pages/transfers/create.php" class="btn btn-sm btn-outline-secondary text-nowrap">
      <i class="bi bi-arrow-left-right me-1"></i>Transfer
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stat cards (uniform h-100 height) -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="card stat-card p-3 h-100">
      <div class="d-flex align-items-center gap-3 h-100">
        <div class="icon-box bg-primary-subtle text-primary flex-shrink-0"><i class="bi bi-box-seam-fill"></i></div>
        <div class="min-w-0">
          <div class="fs-4 fw-bold lh-sm"><?= number_format($total_items, 0, ',', '.') ?></div>
          <div class="text-muted small">Total Barang</div>
          <div class="text-muted small text-truncate" style="font-size:.75rem"><?= number_format($total_suppliers, 0, ',', '.') ?> supplier &bull; <?= number_format($total_locations, 0, ',', '.') ?> lokasi</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card p-3 h-100">
      <div class="d-flex align-items-center gap-3 h-100">
        <div class="icon-box bg-warning-subtle text-warning flex-shrink-0"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="min-w-0">
          <div class="fs-4 fw-bold text-warning lh-sm"><?= number_format($low_stock_count, 0, ',', '.') ?></div>
          <div class="text-muted small">Stok Menipis</div>
          <div class="small" style="font-size:.75rem">
            <?php if (can('stock.alerts')): ?>
              <a href="<?= APP_URL ?>/pages/stock/alerts.php" class="text-decoration-none text-muted">Cek peringatan stok &rsaquo;</a>
            <?php else: ?>
              <span class="text-muted">Pada/di bawah stok min.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card p-3 h-100">
      <div class="d-flex align-items-center gap-3 h-100">
        <div class="icon-box bg-success-subtle text-success flex-shrink-0"><i class="bi bi-currency-dollar"></i></div>
        <div class="min-w-0">
          <div class="fs-4 fw-bold text-nowrap lh-sm" title="<?= htmlspecialchars($full_inv_value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($compact_inv_value, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-muted small">Nilai Inventori</div>
          <div class="text-muted text-truncate" style="font-size:.75rem" title="<?= htmlspecialchars($full_inv_value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($full_inv_value, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card p-3 h-100">
      <div class="d-flex align-items-center gap-3 h-100">
        <div class="icon-box bg-info-subtle text-info flex-shrink-0"><i class="bi bi-activity"></i></div>
        <div class="min-w-0">
          <div class="fs-4 fw-bold lh-sm"><?= number_format($today_in + $today_out, 0, ',', '.') ?></div>
          <div class="text-muted small">Transaksi Hari Ini</div>
          <div class="text-muted small text-truncate" style="font-size:.75rem">+<?= number_format($today_in_qty, 0, ',', '.') ?> masuk &bull; -<?= number_format($today_out_qty, 0, ',', '.') ?> keluar</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Pending operational tasks strip -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2 px-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="d-flex align-items-center gap-2 small fw-semibold">
        <i class="bi bi-list-check text-primary"></i>
        <span>Tugas Tertunda (<?= htmlspecialchars($active_comp['code'] ?? 'MSP') ?>)</span>
        <?php if ($total_pending_tasks === 0): ?>
          <span class="badge bg-success-subtle text-success border border-success-subtle fw-normal">Semua selesai</span>
        <?php else: ?>
          <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><?= $total_pending_tasks ?> perlu tindak lanjut</span>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 small">
        <?php if (can('po.approve') || can('purchase_orders.view')): ?>
        <a href="<?= APP_URL ?>/pages/purchase-orders/index.php" class="badge text-decoration-none py-1 px-2 <?= $pending_po_approval > 0 ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-body-tertiary text-muted border' ?>">
          PO Menunggu Approval: <strong><?= $pending_po_approval ?></strong>
        </a>
        <?php endif; ?>
        <?php if (can('purchase_orders.receive') || can('purchase_orders.view')): ?>
        <a href="<?= APP_URL ?>/pages/purchase-orders/index.php" class="badge text-decoration-none py-1 px-2 <?= $pending_po_receive > 0 ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-body-tertiary text-muted border' ?>">
          PO Siap Diterima: <strong><?= $pending_po_receive ?></strong>
        </a>
        <?php endif; ?>
        <?php if (can('adjustments.approve') || can('adjustments.view')): ?>
        <a href="<?= APP_URL ?>/pages/adjustments/index.php" class="badge text-decoration-none py-1 px-2 <?= $pending_adj_draft > 0 ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-body-tertiary text-muted border' ?>">
          Draft Adjustment: <strong><?= $pending_adj_draft ?></strong>
        </a>
        <?php endif; ?>
        <?php if (can('stock_opname.view')): ?>
        <a href="<?= APP_URL ?>/pages/stock-opname/index.php" class="badge text-decoration-none py-1 px-2 <?= $pending_opname > 0 ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-body-tertiary text-muted border' ?>">
          Draft Opname: <strong><?= $pending_opname ?></strong>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Low stock alert -->
  <div class="col-lg-6">
    <div class="card table-card h-100">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Stok Menipis</span>
        <?php if (can('stock.alerts')): ?>
          <a href="<?= APP_URL ?>/pages/stock/alerts.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
        <?php elseif (can('stock.view')): ?>
          <a href="<?= APP_URL ?>/pages/stock/index.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
        <?php endif; ?>
      </div>
      <?php if (empty($low_stock)): ?>
        <div class="card-body text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-3"></i><br>Semua stok aman</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead>
            <tr>
              <th class="ps-3">Kode</th>
              <th>Nama Barang</th>
              <th class="text-center">Stok</th>
              <th class="text-center">Min</th>
              <th class="text-end pe-3">Saran Order</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($low_stock as $r): ?>
            <tr>
              <td class="ps-3"><code class="small"><?= htmlspecialchars($r['code']) ?></code></td>
              <td class="small">
                <?php if (can('items.view')): ?>
                  <a href="<?= APP_URL ?>/pages/items/view.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none fw-medium"><?= htmlspecialchars($r['name']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($r['name']) ?>
                <?php endif; ?>
              </td>
              <td class="text-center text-nowrap">
                <span class="badge <?= (int)$r['total_stock'] === 0 ? 'badge-zero' : 'badge-low' ?>">
                  <?= (int)$r['total_stock'] ?> <?= htmlspecialchars($r['abbreviation']) ?>
                </span>
              </td>
              <td class="text-center small text-muted"><?= (int)$r['min_stock'] ?></td>
              <td class="text-end pe-3 small fw-semibold text-primary text-nowrap">+<?= number_format((int)$r['suggested_qty'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent mutations -->
  <div class="col-lg-6">
    <div class="card table-card h-100">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Mutasi Terbaru</span>
        <?php if (can('reports.view')): ?>
          <a href="<?= APP_URL ?>/pages/reports/mutation.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
        <?php endif; ?>
      </div>
      <?php if (empty($recent)): ?>
        <div class="card-body text-center text-muted py-4">Belum ada mutasi</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr><th class="ps-3">Waktu</th><th>Barang &amp; Lokasi</th><th>Tipe</th><th class="text-end pe-3">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r):
            $type_map = ['in'=>['Masuk','success'],'out'=>['Keluar','danger'],'transfer_in'=>['T.Masuk','info'],'transfer_out'=>['T.Keluar','warning'],'adjustment'=>['Opname','primary']];
            [$label,$color] = $type_map[$r['type']] ?? ['?','secondary'];
          ?>
            <tr>
              <td class="ps-3 small text-muted text-nowrap"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
              <td class="small">
                <div class="fw-medium">
                  <?php if (can('items.view')): ?>
                    <a href="<?= APP_URL ?>/pages/items/view.php?id=<?= (int)$r['item_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($r['item_name']) ?></a>
                  <?php else: ?>
                    <?= htmlspecialchars($r['item_name']) ?>
                  <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:.75rem">
                  <?= htmlspecialchars($r['location_name']) ?>
                  <?php if (!empty($r['reference_no'])): ?>
                    &bull; <code><?= htmlspecialchars($r['reference_no']) ?></code>
                  <?php endif; ?>
                </div>
              </td>
              <td><span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border"><?= $label ?></span></td>
              <td class="text-end pe-3 small fw-semibold text-nowrap"><?= number_format((int)$r['quantity'], 0, ',', '.') ?> <span class="text-muted fw-normal"><?= htmlspecialchars($r['abbreviation'] ?? '') ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Charts row -->
<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
        <span class="fw-semibold" id="chartHeading"><i class="bi bi-bar-chart-line me-2"></i>Transaksi 7 Hari Terakhir</span>
        <div class="d-flex flex-wrap gap-2">
          <div class="btn-group btn-group-sm" role="group" aria-label="Rentang Chart">
            <button type="button" class="btn btn-primary active" data-chart-days="7">7 Hari</button>
            <button type="button" class="btn btn-outline-secondary" data-chart-days="30">30 Hari</button>
          </div>
          <div class="btn-group btn-group-sm" role="group" aria-label="Metrik Chart">
            <button type="button" class="btn btn-secondary active" data-chart-metric="docs">Dokumen</button>
            <button type="button" class="btn btn-outline-secondary" data-chart-metric="qty">Qty Unit</button>
          </div>
        </div>
      </div>
      <div class="card-body">
        <canvas id="transactionChart" height="90"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold py-2">
        <i class="bi bi-pie-chart me-2"></i>Top 5 Nilai Inventori
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($top_items)): ?>
          <div class="text-center text-muted py-4">
            <i class="bi bi-pie-chart fs-2 d-block mb-2 opacity-50"></i>
            <div class="small">Belum ada stok bernilai di <?= htmlspecialchars($active_comp['code'] ?? 'PT ini') ?>.</div>
          </div>
        <?php else: ?>
          <canvas id="valueChart" height="170"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Multi-PT cross-company summary (shown when user holds access to >1 company) -->
<?php if (count($multi_pt_summary) > 1): ?>
<div class="card shadow-sm mt-3">
  <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
    <span class="fw-semibold"><i class="bi bi-buildings me-2 text-primary"></i>Ringkasan Inventori Lintas Entitas (Multi-PT)</span>
    <?php if (can('companies.view')): ?>
      <a href="<?= APP_URL ?>/pages/companies/index.php" class="btn btn-sm btn-outline-secondary">Kelola PT</a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Kode</th>
          <th>Perusahaan</th>
          <th>Peran Entitas</th>
          <th class="text-end">SKU Berstok</th>
          <th class="text-end">Total Unit</th>
          <th class="text-end">Nilai Inventori</th>
          <th class="text-center pe-3">Stok Menipis</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($multi_pt_summary as $pt):
        $is_curr = (int)$pt['id'] === (int)$cid;
      ?>
        <tr class="<?= $is_curr ? 'table-active' : '' ?>">
          <td class="ps-3"><span class="badge <?= $pt['is_holding'] ? 'bg-primary' : 'bg-secondary' ?>"><?= htmlspecialchars($pt['code']) ?></span></td>
          <td class="small fw-semibold">
            <?= htmlspecialchars($pt['name']) ?>
            <?php if ($is_curr): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Aktif</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= $pt['is_holding'] ? 'Induk (Holding)' : 'Anak Perusahaan' ?></td>
          <td class="text-end small"><?= number_format((int)$pt['sku_count'], 0, ',', '.') ?></td>
          <td class="text-end small fw-semibold"><?= number_format((int)$pt['total_qty'], 0, ',', '.') ?></td>
          <td class="text-end small text-nowrap"><?= idr((float)$pt['inv_value']) ?></td>
          <td class="text-center pe-3">
            <span class="badge <?= (int)$pt['low_stock_count'] > 0 ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-success-subtle text-success border border-success-subtle' ?>">
              <?= number_format((int)$pt['low_stock_count'], 0, ',', '.') ?> item
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Today summary -->
<div class="row g-3 mt-1">
  <div class="col-md-6">
    <div class="card p-3 shadow-sm border-top-0 border-end-0 border-bottom-0 border-start border-4 border-success">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-box-arrow-in-down text-success fs-4"></i>
        <div>
          <div class="fw-bold fs-5"><?= $today_in ?> transaksi <span class="fs-6 fw-normal text-muted">(+<?= number_format($today_in_qty, 0, ',', '.') ?> unit)</span></div>
          <div class="small text-muted">Transaksi Masuk Hari Ini</div>
        </div>
        <?php if (can('stock_in.view')): ?>
        <a href="<?= APP_URL ?>/pages/stock-in/index.php" class="btn btn-sm btn-outline-success ms-auto">Detail</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3 shadow-sm border-top-0 border-end-0 border-bottom-0 border-start border-4 border-danger">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-box-arrow-up text-danger fs-4"></i>
        <div>
          <div class="fw-bold fs-5"><?= $today_out ?> transaksi <span class="fs-6 fw-normal text-muted">(-<?= number_format($today_out_qty, 0, ',', '.') ?> unit)</span></div>
          <div class="small text-muted">Transaksi Keluar Hari Ini</div>
        </div>
        <?php if (can('stock_out.view')): ?>
        <a href="<?= APP_URL ?>/pages/stock-out/index.php" class="btn btn-sm btn-outline-danger ms-auto">Detail</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
const CHART_SERIES = {
  days30:    <?= json_encode($days_30) ?>,
  inDocs30:  <?= json_encode($in_cnt_30) ?>,
  outDocs30: <?= json_encode($out_cnt_30) ?>,
  inQty30:   <?= json_encode($in_qty_30) ?>,
  outQty30:  <?= json_encode($out_qty_30) ?>
};

function getThemeColors() {
  const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  return {
    text: dark ? '#cbd5e1' : '#475569',
    grid: dark ? 'rgba(148,163,184,0.14)' : 'rgba(15,23,42,0.06)'
  };
}

let activeDays = 7;
let activeMetric = 'docs';

function sliceSeries() {
  const n = activeDays;
  const labels = CHART_SERIES.days30.slice(-n);
  const inData = (activeMetric === 'qty' ? CHART_SERIES.inQty30 : CHART_SERIES.inDocs30).slice(-n);
  const outData = (activeMetric === 'qty' ? CHART_SERIES.outQty30 : CHART_SERIES.outDocs30).slice(-n);
  return { labels, inData, outData };
}

const initSlice = sliceSeries();
const initColors = getThemeColors();

const txChart = new Chart(document.getElementById('transactionChart'), {
  type: 'line',
  data: {
    labels: initSlice.labels,
    datasets: [{
      label: 'Masuk (Dokumen)',
      data: initSlice.inData,
      borderColor: '#198754',
      backgroundColor: 'rgba(25,135,84,0.12)',
      tension: 0.3,
      fill: true
    }, {
      label: 'Keluar (Dokumen)',
      data: initSlice.outData,
      borderColor: '#dc3545',
      backgroundColor: 'rgba(220,53,69,0.12)',
      tension: 0.3,
      fill: true
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
      legend: { position: 'top', labels: { color: initColors.text } }
    },
    scales: {
      x: {
        ticks: { color: initColors.text, maxTicksLimit: 10 },
        grid: { color: initColors.grid }
      },
      y: {
        beginAtZero: true,
        ticks: { color: initColors.text, precision: 0 },
        grid: { color: initColors.grid }
      }
    }
  }
});

let valChart = null;
const valCanvas = document.getElementById('valueChart');
if (valCanvas) {
  valChart = new Chart(valCanvas, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($top_items, 'name')) ?>,
      datasets: [{
        data: <?= json_encode(array_column($top_items, 'value')) ?>,
        backgroundColor: ['#198754','#2d6a4f','#ffc107','#dc3545','#6c757d']
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, color: initColors.text } },
        tooltip: {
          callbacks: {
            label: ctx => ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID')
          }
        }
      }
    }
  });
}

function updateTxChart() {
  const s = sliceSeries();
  const unitLbl = activeMetric === 'qty' ? 'Unit' : 'Dokumen';
  txChart.data.labels = s.labels;
  txChart.data.datasets[0].label = 'Masuk (' + unitLbl + ')';
  txChart.data.datasets[0].data = s.inData;
  txChart.data.datasets[1].label = 'Keluar (' + unitLbl + ')';
  txChart.data.datasets[1].data = s.outData;
  txChart.update();
  const heading = document.getElementById('chartHeading');
  if (heading) {
    heading.innerHTML = '<i class="bi bi-bar-chart-line me-2"></i>Transaksi ' + activeDays + ' Hari Terakhir (' + unitLbl + ')';
  }
}

document.querySelectorAll('[data-chart-days]').forEach(btn => {
  btn.addEventListener('click', () => {
    activeDays = parseInt(btn.dataset.chartDays, 10) || 7;
    document.querySelectorAll('[data-chart-days]').forEach(b => {
      const on = b === btn;
      b.classList.toggle('btn-primary', on);
      b.classList.toggle('active', on);
      b.classList.toggle('btn-outline-secondary', !on);
    });
    updateTxChart();
  });
});

document.querySelectorAll('[data-chart-metric]').forEach(btn => {
  btn.addEventListener('click', () => {
    activeMetric = btn.dataset.chartMetric === 'qty' ? 'qty' : 'docs';
    document.querySelectorAll('[data-chart-metric]').forEach(b => {
      const on = b === btn;
      b.classList.toggle('btn-secondary', on);
      b.classList.toggle('active', on);
      b.classList.toggle('btn-outline-secondary', !on);
    });
    updateTxChart();
  });
});

function syncChartTheme() {
  const c = getThemeColors();
  txChart.options.plugins.legend.labels.color = c.text;
  txChart.options.scales.x.ticks.color = c.text;
  txChart.options.scales.x.grid.color = c.grid;
  txChart.options.scales.y.ticks.color = c.text;
  txChart.options.scales.y.grid.color = c.grid;
  txChart.update('none');
  if (valChart) {
    valChart.options.plugins.legend.labels.color = c.text;
    valChart.update('none');
  }
}

new MutationObserver(syncChartTheme).observe(document.documentElement, {
  attributes: true,
  attributeFilter: ['data-bs-theme']
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
