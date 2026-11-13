<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_perm('dashboard.view');

$db = getDB();
grid_apply_timeout($db, 10.0);

// Stats
$cid = current_company_id();
$active_comp = current_company();

$total_items = $db->query("SELECT COUNT(*) FROM items WHERE is_active=1")->fetch_row()[0];
$total_suppliers = $db->query('SELECT COUNT(*) FROM suppliers WHERE is_active=1')->fetch_row()[0];
$total_locations = $db->query('SELECT COUNT(*) FROM locations WHERE is_active=1')->fetch_row()[0];

// Inventory value (uses idx_stock_comp_item_qty)
$inv_value = $db->query("SELECT COALESCE(SUM(s.quantity * i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id WHERE s.company_id=$cid")->fetch_row()[0];

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
$low_stock = $db->query("SELECT i.code, i.name, i.min_stock, 0 AS total_stock,
    GREATEST(i.min_stock*2,1) AS suggested_qty, u.abbreviation
    FROM items i
    LEFT JOIN units u ON u.id=i.unit_id
    WHERE i.is_active=1
    AND NOT EXISTS (SELECT 1 FROM stock s WHERE s.item_id=i.id AND s.company_id=$cid AND s.quantity > 0)
    ORDER BY i.id ASC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

if (count($low_stock) < 10) {
    $rem = 10 - count($low_stock);
    $more_low = $db->query("SELECT i.code, i.name, i.min_stock, st.qty AS total_stock,
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

// Today transactions (indexed via idx_stock_in_comp_date / idx_stock_out_comp_date)
$today = date('Y-m-d');
$today_in  = $db->query("SELECT COUNT(*) FROM stock_in WHERE transaction_date='{$today}' AND company_id=$cid")->fetch_row()[0];
$today_out = $db->query("SELECT COUNT(*) FROM stock_out WHERE transaction_date='{$today}' AND company_id=$cid")->fetch_row()[0];

// Recent mutations
$recent = $db->query("SELECT m.created_at, m.type, m.quantity, i.name AS item_name,
    l.name AS location_name, m.reference_no
    FROM mutations m
    JOIN items i ON i.id=m.item_id
    JOIN locations l ON l.id=m.location_id
    WHERE m.company_id=$cid
    ORDER BY m.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Chart data: last 7 days transactions (2 grouped queries instead of 14 queries in a loop)
$start_7 = date('Y-m-d', strtotime('-6 days'));
$in_map = [];
$rs_in = $db->query("SELECT transaction_date, COUNT(*) AS cnt FROM stock_in WHERE company_id=$cid AND transaction_date BETWEEN '$start_7' AND '$today' GROUP BY transaction_date");
while ($r = $rs_in->fetch_assoc()) $in_map[$r['transaction_date']] = (int)$r['cnt'];
$out_map = [];
$rs_out = $db->query("SELECT transaction_date, COUNT(*) AS cnt FROM stock_out WHERE company_id=$cid AND transaction_date BETWEEN '$start_7' AND '$today' GROUP BY transaction_date");
while ($r = $rs_out->fetch_assoc()) $out_map[$r['transaction_date']] = (int)$r['cnt'];

$chart_days = [];
$chart_in = [];
$chart_out = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_days[] = date('d M', strtotime($date));
    $chart_in[]   = $in_map[$date] ?? 0;
    $chart_out[]  = $out_map[$date] ?? 0;
}

// Top items by stock value (drive from stock table instead of scanning 1M items)
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

$page_title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <div><h4><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</h4>
    <p class="text-muted small mb-0">Selamat datang, <?= htmlspecialchars(current_user()['name']) ?>! Hari ini <?= tgl($today) ?></p>
  </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box bg-primary-subtle text-primary"><i class="bi bi-box-seam-fill"></i></div>
        <div>
          <div class="fs-4 fw-bold"><?= $total_items ?></div>
          <div class="text-muted small">Total Barang</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box bg-warning-subtle text-warning"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div>
          <div class="fs-4 fw-bold text-warning"><?= $low_stock_count ?></div>
          <div class="text-muted small">Stok Menipis</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box bg-success-subtle text-success"><i class="bi bi-currency-dollar"></i></div>
        <div>
          <div class="fs-5 fw-bold"><?= idr((float)$inv_value) ?></div>
          <div class="text-muted small">Nilai Inventori</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box bg-info-subtle text-info"><i class="bi bi-activity"></i></div>
        <div>
          <div class="fs-4 fw-bold"><?= $today_in + $today_out ?></div>
          <div class="text-muted small">Transaksi Hari Ini</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Low stock alert -->
  <div class="col-lg-6">
    <div class="card table-card">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Stok Menipis</span>
        <a href="<?= APP_URL ?>/pages/stock/index.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
      </div>
      <?php if (empty($low_stock)): ?>
        <div class="card-body text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-3"></i><br>Semua stok aman</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Kode</th><th>Nama Barang</th><th class="text-center">Stok</th><th class="text-center">Min</th></tr></thead>
          <tbody>
          <?php foreach ($low_stock as $r): ?>
            <tr>
              <td><code class="small"><?= htmlspecialchars($r['code']) ?></code></td>
              <td class="small"><?= htmlspecialchars($r['name']) ?></td>
              <td class="text-center">
                <span class="badge <?= $r['total_stock'] == 0 ? 'badge-zero' : 'badge-low' ?>">
                  <?= $r['total_stock'] ?> <?= htmlspecialchars($r['abbreviation']) ?>
                </span>
              </td>
              <td class="text-center small"><?= $r['min_stock'] ?></td>
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
    <div class="card table-card">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Mutasi Terbaru</span>
        <a href="<?= APP_URL ?>/pages/reports/mutation.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
      </div>
      <?php if (empty($recent)): ?>
        <div class="card-body text-center text-muted py-4">Belum ada mutasi</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Waktu</th><th>Barang</th><th>Tipe</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r):
            $type_map = ['in'=>['Masuk','success'],'out'=>['Keluar','danger'],'transfer_in'=>['T.Masuk','info'],'transfer_out'=>['T.Keluar','warning'],'adjustment'=>['Opname','primary']];
            [$label,$color] = $type_map[$r['type']] ?? ['?','secondary'];
          ?>
            <tr>
              <td class="small text-muted"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
              <td class="small"><?= htmlspecialchars($r['item_name']) ?></td>
              <td><span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border"><?= $label ?></span></td>
              <td class="text-end small fw-semibold"><?= $r['quantity'] ?></td>
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
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold py-2">
        <i class="bi bi-bar-chart-line me-2"></i>Transaksi 7 Hari Terakhir
      </div>
      <div class="card-body">
        <canvas id="transactionChart" height="80"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold py-2">
        <i class="bi bi-pie-chart me-2"></i>Top 5 Nilai Inventori
      </div>
      <div class="card-body">
        <canvas id="valueChart" height="160"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Today summary -->
<div class="row g-3 mt-1">
  <div class="col-md-6">
    <div class="card p-3 border-start border-4 border-success">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-box-arrow-in-down text-success fs-4"></i>
        <div><div class="fw-bold fs-5"><?= $today_in ?></div><div class="small text-muted">Transaksi Masuk Hari Ini</div></div>
        <a href="<?= APP_URL ?>/pages/stock-in/index.php" class="btn btn-sm btn-outline-success ms-auto">Detail</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3 border-start border-4 border-danger">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-box-arrow-up text-danger fs-4"></i>
        <div><div class="fw-bold fs-5"><?= $today_out ?></div><div class="small text-muted">Transaksi Keluar Hari Ini</div></div>
        <a href="<?= APP_URL ?>/pages/stock-out/index.php" class="btn btn-sm btn-outline-danger ms-auto">Detail</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
// Transaction chart
new Chart(document.getElementById('transactionChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_days) ?>,
    datasets: [{
      label: 'Masuk',
      data: <?= json_encode($chart_in) ?>,
      borderColor: '#198754',
      backgroundColor: 'rgba(25,135,84,0.1)',
      tension: 0.3,
      fill: true
    }, {
      label: 'Keluar',
      data: <?= json_encode($chart_out) ?>,
      borderColor: '#dc3545',
      backgroundColor: 'rgba(220,53,69,0.1)',
      tension: 0.3,
      fill: true
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: { legend: { position: 'top' } },
    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
  }
});

// Value pie chart
new Chart(document.getElementById('valueChart'), {
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
      legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
      tooltip: {
        callbacks: {
          label: ctx => ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID')
        }
      }
    }
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
