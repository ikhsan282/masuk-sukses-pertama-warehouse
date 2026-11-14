<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

auth_check();
$user = current_user();
if (!in_array($user['role_name'], ['Staff Gudang', 'Viewer'], true)) {
    redirect(APP_URL . '/pages/dashboard.php');
}

$db             = getDB();
$uid            = (int)$user['id'];
$cid            = current_company_id();
$comp           = current_company();
$user_companies = user_companies();
$search         = trim(req_str('q'));

$total_items = (int)$db->query('SELECT COUNT(*) FROM items WHERE is_active=1')->fetch_row()[0];

$stock_limit = 500;
$stock_sql = "SELECT i.code, i.name, i.min_stock, u.abbreviation, COALESCE(SUM(s.quantity), 0) AS qty
    FROM items i
    JOIN units u ON u.id = i.unit_id
    LEFT JOIN stock s ON s.item_id = i.id AND s.company_id = ?
    WHERE i.is_active = 1";
if ($search !== '') {
    $like = '%' . escape_like($search) . '%';
    $stock_sql .= ' AND (i.code LIKE ? OR i.name LIKE ?) GROUP BY i.id, i.code, i.name, i.min_stock, u.abbreviation ORDER BY i.name ASC LIMIT ?';
    $st_stock = $db->prepare($stock_sql);
    $st_stock->bind_param('issi', $cid, $like, $like, $stock_limit);
} else {
    $stock_sql .= ' GROUP BY i.id, i.code, i.name, i.min_stock, u.abbreviation ORDER BY i.name ASC LIMIT ?';
    $st_stock = $db->prepare($stock_sql);
    $st_stock->bind_param('ii', $cid, $stock_limit);
}
$st_stock->execute();
$stock = $st_stock->get_result()->fetch_all(MYSQLI_ASSOC);
$st_stock->close();

$tx = [];
$tx_queries = [
    ['Barang Masuk',  'SELECT reference_no, transaction_date, created_at FROM stock_in WHERE user_id = ? AND company_id = ? ORDER BY created_at DESC LIMIT 20', 'ii', [$uid, $cid]],
    ['Barang Keluar', 'SELECT reference_no, transaction_date, created_at FROM stock_out WHERE user_id = ? AND company_id = ? ORDER BY created_at DESC LIMIT 20', 'ii', [$uid, $cid]],
    ['Transfer',      'SELECT reference_no, transaction_date, created_at FROM transfers WHERE user_id = ? AND (from_company_id = ? OR to_company_id = ?) ORDER BY created_at DESC LIMIT 20', 'iii', [$uid, $cid, $cid]],
];
foreach ($tx_queries as [$label, $sql, $types, $bind]) {
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$bind);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $r['type'] = $label;
        $tx[] = $r;
    }
    $st->close();
}
usort($tx, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$tx = array_slice($tx, 0, 20);
$active_tab = $search !== '' ? 'stock' : 'home';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Portal Gudang - <?= e($comp['name'] ?? '') ?></title>
<meta name="theme-color" content="#198754">
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?= theme_prepaint_script() ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
<style>
  :root{--bs-primary:#198754;--bs-primary-rgb:25,135,84;--bs-link-color:#198754;--bs-link-hover-color:#146c43;--bs-link-color-rgb:25,135,84;--bs-link-hover-color-rgb:20,108,67}
  body{padding-bottom:76px}
  .view{display:none}
  .view.active{display:block}
  .bottom{position:fixed;bottom:0;left:0;right:0;background:var(--bs-body-bg);border-top:1px solid var(--bs-border-color);display:flex;z-index:10}
  .bottom button{flex:1;border:0;background:none;padding:.6rem .2rem;color:var(--bs-secondary-color);font-size:.75rem}
  .bottom button.active{color:var(--bs-primary);font-weight:600}
  .bottom i{display:block;font-size:1.3rem}
  .card{border-color:var(--bs-border-color)}
  .portal-pt-select{background-color:rgba(255,255,255,.15);border-color:rgba(255,255,255,.35);color:#fff;font-size:.78rem;max-width:210px}
  .portal-pt-select option{color:var(--bs-body-color);background-color:var(--bs-body-bg)}
</style>
</head>
<body>
<header class="sticky-top text-white p-3 d-flex justify-content-between align-items-center gap-2" style="background:#198754">
  <div class="min-w-0">
    <strong><i class="bi bi-boxes me-1"></i>Portal Gudang</strong>
    <small class="d-block text-white-50 text-truncate" style="font-size:0.75rem"><?= e($comp['name'] ?? '') ?></small>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if (count($user_companies) > 1): ?>
    <form method="POST" action="<?= APP_URL ?>/auth/switch-company.php" class="m-0">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect" value="/pages/portal.php">
      <select name="company_id" class="form-select form-select-sm portal-pt-select" aria-label="Pilih Perusahaan (PT)"
        onchange="if(this.value) this.form.submit()">
        <?php foreach ($user_companies as $uc): ?>
          <option value="<?= (int)$uc['id'] ?>" <?= (int)$uc['id'] === $cid ? 'selected' : '' ?>>
            [<?= e($uc['code']) ?>] <?= e($uc['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-light" id="darkToggle" title="Beralih ke mode gelap" aria-label="Toggle tema gelap">
      <i class="bi bi-moon-stars"></i>
    </button>
  </div>
</header>

<main class="container py-3">
  <?= flash_html() ?>

  <section class="view <?= $active_tab === 'home' ? 'active' : '' ?>" id="home">
    <h5>Halo, <?= e($user['name']) ?></h5>
    <div class="row g-2 my-3">
      <div class="col-6">
        <div class="card p-3">
          <small class="text-body-secondary">Item Aktif</small>
          <b class="fs-3"><?= number_format($total_items, 0, ',', '.') ?></b>
        </div>
      </div>
      <div class="col-6">
        <div class="card p-3">
          <small class="text-body-secondary">Aktivitas Saya (PT Aktif)</small>
          <b class="fs-3"><?= count($tx) ?></b>
        </div>
      </div>
    </div>
    <button type="button" class="btn btn-success w-100 mb-3 fw-semibold" id="homeScanBtn">
      <i class="bi bi-upc-scan me-2"></i>Scan Barcode / QR Cek Stok (<?= e($comp['code'] ?? 'PT') ?>)
    </button>
    <h6>Aktivitas Terbaru — <?= e($comp['code'] ?? 'PT') ?></h6>
    <?php if (empty($tx)): ?>
      <div class="card p-3 text-body-secondary small">Belum ada transaksi tercatat pada PT aktif.</div>
    <?php else: foreach (array_slice($tx, 0, 5) as $r): ?>
      <div class="card p-2 mb-2">
        <b><?= e($r['reference_no']) ?></b>
        <small class="text-body-secondary"><?= e($r['type']) ?> &middot; <?= date('d/m/Y', strtotime($r['transaction_date'])) ?></small>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <section class="view <?= $active_tab === 'stock' ? 'active' : '' ?>" id="stock">
    <form method="GET" class="input-group mb-2">
      <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Cari kode atau nama barang...">
      <button class="btn btn-success" type="button" id="portalScanBtn" title="Scan Barcode / QR Kamera" aria-label="Scan Barcode atau QR dengan kamera">
        <i class="bi bi-upc-scan"></i>
      </button>
      <button class="btn btn-outline-success" type="submit" title="Cari di database"><i class="bi bi-search"></i></button>
      <?php if ($search !== ''): ?>
        <a href="<?= APP_URL ?>/pages/portal.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
    <div id="portalScanResult" class="d-none mb-2"></div>
    <div class="small text-body-secondary mb-2">
      Menampilkan <?= count($stock) ?> dari <?= number_format($total_items, 0, ',', '.') ?> barang aktif (<?= e($comp['code'] ?? 'PT') ?>)<?= $total_items > $stock_limit && $search === '' ? ' — tekan Enter untuk cari ke seluruh katalog' : '' ?>
    </div>
    <div id="items">
      <?php foreach ($stock as $r):
        $qty = (int)$r['qty'];
        $min = (int)$r['min_stock'];
        $badge = $qty === 0 ? 'text-bg-danger' : ($qty <= $min ? 'text-bg-warning' : 'text-bg-success');
      ?>
        <div class="card p-3 mb-2 item" data-q="<?= e(strtolower($r['code'] . ' ' . $r['name'])) ?>">
          <div class="d-flex justify-content-between align-items-center gap-2">
            <span class="min-w-0">
              <b class="d-block text-truncate"><?= e($r['name']) ?></b>
              <small class="text-body-secondary"><?= e($r['code']) ?> &middot; Min <?= $min ?></small>
            </span>
            <span class="badge <?= $badge ?> fs-6 text-nowrap"><?= number_format($qty, 0, ',', '.') ?> <?= e($r['abbreviation']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <div id="emptyStock" class="card p-3 text-center text-body-secondary small<?= empty($stock) ? '' : ' d-none' ?>">
        Tidak ada barang yang sesuai dengan pencarian.
      </div>
    </div>
  </section>

  <section class="view <?= $active_tab === 'trx' ? 'active' : '' ?>" id="trx">
    <h5>Transaksi Saya (<?= e($comp['code'] ?? 'PT') ?>)</h5>
    <?php if (empty($tx)): ?>
      <div class="card p-3 text-body-secondary small">Belum ada transaksi Anda di perusahaan aktif.</div>
    <?php else: foreach ($tx as $r): ?>
      <div class="card p-3 mb-2">
        <b><?= e($r['reference_no']) ?></b>
        <span><?= e($r['type']) ?></span>
        <small class="text-body-secondary"><?= date('d/m/Y', strtotime($r['transaction_date'])) ?></small>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <section class="view <?= $active_tab === 'profile' ? 'active' : '' ?>" id="profile">
    <div class="card p-4 text-center">
      <i class="bi bi-person-circle fs-1 mb-2 text-primary"></i>
      <h5><?= e($user['name']) ?></h5>
      <p class="mb-3">
        <?= e($user['email']) ?><br>
        <span class="badge text-bg-primary"><?= e($user['role_name']) ?></span>
      </p>
      <div class="d-grid gap-2">
        <a class="btn btn-outline-primary" href="<?= APP_URL ?>/pages/profile.php">
          <i class="bi bi-person-gear me-1"></i>Ubah Profil &amp; Kata Sandi
        </a>
        <a class="btn btn-danger" href="<?= APP_URL ?>/auth/logout.php">
          <i class="bi bi-box-arrow-right me-1"></i>Keluar
        </a>
      </div>
    </div>
  </section>
</main>

<nav class="bottom">
  <?php foreach ([['home', 'house', 'Beranda'], ['stock', 'boxes', 'Stok'], ['trx', 'clock-history', 'Transaksi'], ['profile', 'person', 'Profil']] as $n): ?>
    <button type="button" data-view="<?= $n[0] ?>" class="<?= $active_tab === $n[0] ? 'active' : '' ?>">
      <i class="bi bi-<?= $n[1] ?>"></i><?= $n[2] ?>
    </button>
  <?php endforeach; ?>
</nav>

<?php include __DIR__ . '/../includes/scanner-modal.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/html5-qrcode/html5-qrcode.min.js?v=<?= @filemtime(__DIR__ . '/../assets/vendor/html5-qrcode/html5-qrcode.min.js') ?: '1' ?>"></script>
<?= theme_toggle_script() ?>
<script>
function switchPortalView(viewName) {
  document.querySelectorAll('.view,.bottom button').forEach(function (x) { x.classList.remove('active'); });
  var target = document.getElementById(viewName);
  var navBtn = document.querySelector('.bottom button[data-view="' + viewName + '"]');
  if (target) target.classList.add('active');
  if (navBtn) navBtn.classList.add('active');
}
document.querySelectorAll('[data-view]').forEach(function (b) {
  b.onclick = function () { switchPortalView(b.dataset.view); };
});
var qEl = document.getElementById('q');
function filterLocalStockList() {
  if (!qEl) return;
  var needle = qEl.value.toLowerCase();
  var visible = 0;
  document.querySelectorAll('.item').forEach(function (x) {
    var show = x.dataset.q.indexOf(needle) !== -1;
    x.hidden = !show;
    if (show) visible++;
  });
  var emptyEl = document.getElementById('emptyStock');
  if (emptyEl) emptyEl.classList.toggle('d-none', visible > 0);
}
if (qEl) {
  qEl.addEventListener('input', filterLocalStockList);
}

var scanResultBox = document.getElementById('portalScanResult');
var scannerModalEl = document.getElementById('scannerModal');
var scannerReader  = document.getElementById('scanner-reader');
var portalScanner  = null;
var portalScanning = false;

function renderPortalLookupCard(code, item) {
  if (!scanResultBox) return;
  scanResultBox.innerHTML = '';
  scanResultBox.classList.remove('d-none');

  var card = document.createElement('div');
  card.className = item ? 'card border-success p-3 shadow-sm' : 'alert alert-warning mb-0 d-flex justify-content-between align-items-center gap-2';

  if (!item) {
    var span = document.createElement('span');
    span.className = 'small';
    span.textContent = 'Barcode "' + code + '" tidak ditemukan di katalog aktif.';
    var closeAlert = document.createElement('button');
    closeAlert.type = 'button';
    closeAlert.className = 'btn-close btn-sm';
    closeAlert.onclick = function () { scanResultBox.classList.add('d-none'); };
    card.appendChild(span);
    card.appendChild(closeAlert);
    scanResultBox.appendChild(card);
    return;
  }

  var top = document.createElement('div');
  top.className = 'd-flex justify-content-between align-items-start gap-2 mb-2';
  var titleWrap = document.createElement('div');
  titleWrap.className = 'min-w-0';
  var badgeLabel = document.createElement('span');
  badgeLabel.className = 'badge text-bg-light border text-success mb-1';
  badgeLabel.textContent = 'Hasil Scan Kamera';
  var h = document.createElement('div');
  h.className = 'fw-bold text-truncate';
  h.textContent = item.name || item.text || code;
  var meta = document.createElement('small');
  meta.className = 'text-body-secondary d-block';
  meta.textContent = (item.code || code) + ' · Min ' + (item.min_stock ?? 0);
  titleWrap.appendChild(badgeLabel);
  titleWrap.appendChild(h);
  titleWrap.appendChild(meta);

  var rightWrap = document.createElement('div');
  rightWrap.className = 'text-end flex-shrink-0';
  var qtyVal = Number(item.qty || 0);
  var minVal = Number(item.min_stock || 0);
  var qtyBadge = document.createElement('span');
  qtyBadge.className = 'badge fs-6 ' + (qtyVal === 0 ? 'text-bg-danger' : (qtyVal <= minVal ? 'text-bg-warning' : 'text-bg-success'));
  qtyBadge.textContent = qtyVal.toLocaleString('id-ID') + ' ' + (item.unit || 'pcs');
  var dismissBtn = document.createElement('button');
  dismissBtn.type = 'button';
  dismissBtn.className = 'btn btn-link btn-sm text-secondary p-0 d-block ms-auto mt-1';
  dismissBtn.textContent = 'Tutup';
  dismissBtn.onclick = function () { scanResultBox.classList.add('d-none'); };
  rightWrap.appendChild(qtyBadge);
  rightWrap.appendChild(dismissBtn);

  top.appendChild(titleWrap);
  top.appendChild(rightWrap);
  card.appendChild(top);

  var locs = Array.isArray(item.locations) ? item.locations : [];
  var locWrap = document.createElement('div');
  locWrap.className = 'small border-top pt-2';
  if (locs.length === 0) {
    locWrap.className += ' text-body-secondary';
    locWrap.textContent = 'Belum ada stok tersedia di rak PT aktif.';
  } else {
    locWrap.textContent = 'Lokasi rak: ' + locs.map(function (l) {
      return l.code + ' (' + Number(l.qty || 0).toLocaleString('id-ID') + ')';
    }).join(', ');
  }
  card.appendChild(locWrap);
  scanResultBox.appendChild(card);
}

function handlePortalDecodedBarcode(decodedText) {
  var code = (decodedText || '').trim();
  if (!code) return;
  switchPortalView('stock');
  if (qEl) {
    qEl.value = code;
    filterLocalStockList();
  }
  stopPortalScanner(true);

  fetch('<?= APP_URL ?>/pages/items/search.php?include_stock=1&q=' + encodeURIComponent(code), { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : []; })
    .then(function (list) {
      var arr = Array.isArray(list) ? list : [];
      var exact = arr.find(function (it) {
        return String(it.code || '').trim().toLowerCase() === code.toLowerCase();
      });
      renderPortalLookupCard(code, exact || arr[0] || null);
    })
    .catch(function () {
      renderPortalLookupCard(code, null);
    });
}

function stopPortalScanner(hideModal) {
  var inst = portalScanner;
  portalScanner = null;
  portalScanning = false;
  if (inst) {
    inst.stop().catch(function () {}).finally(function () { try { inst.clear(); } catch (e) {} });
  }
  if (hideModal && scannerModalEl) {
    bootstrap.Modal.getOrCreateInstance(scannerModalEl).hide();
  }
}

['portalScanBtn', 'homeScanBtn'].forEach(function (btnId) {
  var btn = document.getElementById(btnId);
  if (btn && scannerModalEl) {
    btn.addEventListener('click', function () {
      bootstrap.Modal.getOrCreateInstance(scannerModalEl).show();
    });
  }
});

if (scannerModalEl) {
  scannerModalEl.addEventListener('shown.bs.modal', function () {
    if (!scannerReader || portalScanning) return;
    if (!window.Html5Qrcode) {
      scannerReader.innerHTML = '<div class="alert alert-danger small mb-0">Scanner kamera gagal dimuat. Gunakan pencarian teks manual.</div>';
      return;
    }
    portalScanner = new Html5Qrcode('scanner-reader');
    portalScanning = true;
    portalScanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 250, height: 150 } },
      handlePortalDecodedBarcode,
      function () {}
    ).catch(function () {
      scannerReader.innerHTML = '<div class="alert alert-danger small mb-0">Kamera tidak dapat diakses. Pastikan izin kamera aktif dan koneksi menggunakan HTTPS.</div>';
      portalScanning = false;
      portalScanner = null;
    });
  });
  scannerModalEl.addEventListener('hidden.bs.modal', function () { stopPortalScanner(false); });
}

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js');
}
</script>
</body>
</html>
