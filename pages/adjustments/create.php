<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('adjustments.create');
$db = getDB();

$comp_id = current_company_id();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $location_id = req_int('location_id');
    $trans_date  = req_str('transaction_date');
    $notes       = req_str('notes');
    $item_ids    = $_POST['item_id'] ?? [];
    $physical    = $_POST['physical_qty'] ?? [];
    $reasons     = $_POST['reason'] ?? [];

    if (!$location_id) $errors[] = 'Lokasi wajib dipilih.';
    if (!$trans_date)  $errors[] = 'Tanggal wajib diisi.';

    $valid_items = [];
    foreach ($item_ids as $k => $iid) {
        $iid = (int)$iid;
        $sys = (int)($physical[$k]['system'] ?? 0);
        $phy = (int)($physical[$k]['physical'] ?? 0);
        $rsn = trim($reasons[$k] ?? '');
        if ($iid > 0) $valid_items[] = [$iid, $sys, $phy, $rsn];
    }
    if (empty($valid_items)) $errors[] = 'Tambahkan minimal 1 barang.';

    // Validate item ids exist and get system stock
    if (empty($errors)) {
        foreach ($valid_items as $k => [$iid, $sys, $phy, $rsn]) {
            $sc = $db->prepare('SELECT COALESCE(quantity,0) FROM stock WHERE item_id=? AND location_id=? AND company_id=?');
            $sc->bind_param('iii', $iid, $location_id, $comp_id);
            $sc->execute();
            $sc->bind_result($sys_real);
            $sc->fetch();
            $sc->close();
            // refresh system qty from DB (authoritative)
            $sys_real = (int)$sys_real;
            $valid_items[$k][1] = $sys_real;
            if ($phy < 0) {
                $errors[] = 'Stok fisik tidak boleh negatif.';
            }
            if ($phy !== $sys_real && $rsn === '') {
                $errors[] = 'Alasan wajib diisi untuk setiap barang yang memiliki selisih.';
            }
        }
    }

    if (empty($errors)) {
        $ref_no  = generate_ref('ADJ-', $comp_id);
        $user_id = current_user()['id'];
        $db->begin_transaction();
        try {
            $st = $db->prepare('INSERT INTO stock_adjustments (reference_no,location_id,company_id,user_id,notes,transaction_date) VALUES (?,?,?,?,?,?)');
            $st->bind_param('siiiss', $ref_no, $location_id, $comp_id, $user_id, $notes, $trans_date);
            $st->execute();
            $adj_id = $db->insert_id;
            $st->close();

            $st2 = $db->prepare('INSERT INTO stock_adjustment_details (adjustment_id,item_id,system_qty,physical_qty,difference,reason) VALUES (?,?,?,?,?,?)');
            foreach ($valid_items as [$iid, $sys, $phy, $rsn]) {
                $diff = $phy - $sys;
                $st2->bind_param('iiiiis', $adj_id, $iid, $sys, $phy, $diff, $rsn);
                $st2->execute();
            }
            $st2->close();
            $db->commit();
            set_flash('success', "Penyesuaian stok <strong>{$ref_no}</strong> tersimpan sebagai <strong>DRAFT</strong>. Menunggu persetujuan untuk diterapkan.");
            redirect(APP_URL . '/pages/adjustments/index.php');
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = 'Transaksi gagal: ' . $e->getMessage();
        }
    }
}

$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$items     = $db->query('SELECT i.id,i.code,i.name,u.abbreviation FROM items i JOIN units u ON u.id=i.unit_id WHERE i.is_active=1 ORDER BY i.name')->fetch_all(MYSQLI_ASSOC);

// system stock map: [location_id][item_id] => qty
$stock_map = [];
$sq = $db->query('SELECT location_id, item_id, quantity FROM stock WHERE company_id=' . (int)$comp_id);
while ($r = $sq->fetch_assoc()) {
    $stock_map[(int)$r['location_id']][(int)$r['item_id']] = (int)$r['quantity'];
}

$page_title = 'Stok Opname'; include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/adjustments/index.php">Stok Opname</a></li>
    <li class="breadcrumb-item active">Buat Opname</li>
  </ol></nav>
  <h4><i class="bi bi-clipboard-check me-2 text-primary"></i>Stok Opname (Penyesuaian)</h4>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="POST">
<?= csrf_field() ?>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Tanggal Opname</label>
          <input type="date" name="transaction_date" class="form-control" value="<?= htmlspecialchars(req_str('transaction_date', date('Y-m-d'))) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Lokasi</label>
          <select name="location_id" class="form-select ts-select" required>
            <option value="">— Pilih —</option>
            <?php foreach ($locations as $l): ?>
            <option value="<?= $l['id'] ?>" <?= req_int('location_id') == $l['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($l['code']) ?> — <?= htmlspecialchars($l['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Catatan</label>
          <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars(req_str('notes')) ?></textarea>
        </div>
        <div class="alert alert-info small mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Isi <strong>Stok Fisik</strong> = jumlah yang benar-benar dihitung di rak.
          Selisih dihitung otomatis. Dokumen disimpan sebagai <strong>draft</strong> dan harus
          <strong>di-approve</strong> sebelum stok sistem berubah.
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold">Daftar Barang Dihitung</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="bi bi-plus-lg me-1"></i>Tambah Baris</button>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr>
            <th style="min-width:240px">Barang</th>
            <th style="width:100px">Stok Sistem</th>
            <th style="width:100px">Stok Fisik</th>
            <th style="width:90px">Selisih</th>
            <th style="min-width:180px">Alasan</th>
            <th></th>
          </tr></thead>
          <tbody id="itemsBody">
            <tr>
              <td>
                <div class="input-group input-group-sm">
                  <select name="item_id[0]" class="form-select form-select-sm item-select ts-select" required>
                    <option value="">— Pilih —</option>
                    <?php foreach ($items as $it): ?>
                    <option value="<?= $it['id'] ?>" data-code="<?= htmlspecialchars($it['code']) ?>">[<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['abbreviation']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-outline-secondary scan-btn" title="Scan Barcode/QR">
                    <i class="bi bi-upc-scan"></i>
                  </button>
                </div>
              </td>
              <td><input type="number" name="physical_qty[0][system]" class="form-control form-control-sm sys-qty" min="0" value="0" readonly title="Otomatis dari sistem setelah lokasi dipilih"></td>
              <td><input type="number" name="physical_qty[0][physical]" class="form-control form-control-sm phy-qty" min="0" value="0" required></td>
              <td><input type="text" class="form-control form-control-sm diff-display bg-light" value="0" readonly tabindex="-1"></td>
              <td><input type="text" name="reason[0]" class="form-control form-control-sm" maxlength="255" placeholder="Rusak / salah hitung / dll"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Draft Opname</button>
  <a href="<?= APP_URL ?>/pages/adjustments/index.php" class="btn btn-outline-secondary">Batal</a>
</div>
</form>
<script>
// System stock lookup: STOCK[location_id][item_id] = qty
const STOCK = <?= json_encode($stock_map) ?>;

function fillSystemQty() {
  const loc = parseInt(document.querySelector('select[name="location_id"]')?.value) || 0;
  document.querySelectorAll('#itemsBody tr').forEach(row => {
    const itemId = parseInt(row.querySelector('.item-select')?.value) || 0;
    const sysEl  = row.querySelector('.sys-qty');
    if (sysEl) sysEl.value = (STOCK[loc] && STOCK[loc][itemId]) || 0;
    const phy = parseFloat(row.querySelector('.phy-qty')?.value) || 0;
    const sys = parseFloat(sysEl?.value) || 0;
    const diffEl = row.querySelector('.diff-display');
    if (diffEl) diffEl.value = phy - sys;
  });
}

document.querySelector('select[name="location_id"]')?.addEventListener('change', fillSystemQty);
document.addEventListener('change', e => { if (e.target.matches('.item-select')) fillSystemQty(); });
</script>
<?php include __DIR__ . '/../../includes/scanner-modal.php'; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
