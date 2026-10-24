<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_perm('dashboard.view');

$db = getDB();

// Stats
$total_items = $db->query('SELECT COUNT(*) FROM items WHERE is_active=1')->fetch_row()[0];
$total_suppliers = $db->query('SELECT COUNT(*) FROM suppliers WHERE is_active=1')->fetch_row()[0];
$total_locations = $db->query('SELECT COUNT(*) FROM locations WHERE is_active=1')->fetch_row()[0];

// Inventory value
$inv_value = $db->query('SELECT COALESCE(SUM(s.quantity * i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id')->fetch_row()[0];

// Low stock items
$low_stock_count = $db->query('SELECT COUNT(*) FROM (SELECT i.id FROM items i LEFT JOIN stock s ON s.item_id=i.id WHERE i.is_active=1 GROUP BY i.id,i.min_stock HAVING COALESCE(SUM(s.quantity),0)<=i.min_stock) low')->fetch_row()[0];
$low_stock = $db->query('SELECT i.code, i.name, i.min_stock, COALESCE(SUM(s.quantity),0) AS total_stock,
    GREATEST(i.min_stock*2-COALESCE(SUM(s.quantity),0),1) AS suggested_qty,
    u.abbreviation FROM items i
    LEFT JOIN stock s ON s.item_id=i.id
    LEFT JOIN units u ON u.id=i.unit_id
    WHERE i.is_active=1
    GROUP BY i.id, i.min_stock, i.code, i.name, u.abbreviation HAVING total_stock <= i.min_stock
    ORDER BY total_stock ASC LIMIT 10')->fetch_all(MYSQLI_ASSOC);

// Today transactions
$today = date('Y-m-d');
$today_in  = $db->query("SELECT COUNT(*) FROM stock_in WHERE transaction_date='{$today}'")->fetch_row()[0];
$today_out = $db->query("SELECT COUNT(*) FROM stock_out WHERE transaction_date='{$today}'")->fetch_row()[0];

// Recent mutations
$recent = $db->query('SELECT m.created_at, m.type, m.quantity, i.name AS item_name,
    l.name AS location_name, m.reference_no
    FROM mutations m
    JOIN items i ON i.id=m.item_id
    JOIN locations l ON l.id=m.location_id
    ORDER BY m.id DESC LIMIT 8')->fetch_all(MYSQLI_ASSOC);

// Chart data: last 7 days transactions
$chart_days = [];
$chart_in = [];
$chart_out = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_days[] = date('d M', strtotime($date));
    $in_count = $db->query("SELECT COUNT(*) FROM stock_in WHERE transaction_date='$date'")->fetch_row()[0];
    $out_count = $db->query("SELECT COUNT(*) FROM stock_out WHERE transaction_date='$date'")->fetch_row()[0];
    $chart_in[] = (int)$in_count;
    $chart_out[] = (int)$out_count;
}

// Top items by stock value
$top_items = $db->query('SELECT i.name, COALESCE(SUM(s.quantity * i.buy_price),0) AS value
    FROM items i LEFT JOIN stock s ON s.item_id=i.id
    WHERE i.is_active=1 GROUP BY i.id ORDER BY value DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);

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
