<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/supplier_returns.php';

require_perm('supplier_returns.create');
$db = getDB();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $supplier_id = req_int('supplier_id');
    $location_id = req_int('location_id');
    $trans_date  = req_str('transaction_date');
    $reason      = req_str('reason');
    $notes       = req_str('notes');
    $item_ids    = $_POST['item_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $buy_prices  = $_POST['buy_price'] ?? [];

    if (!$supplier_id) $errors[] = 'Supplier wajib dipilih.';
    if (!$location_id) $errors[] = 'Lokasi wajib dipilih.';
    if (!$trans_date)  $errors[] = 'Tanggal transaksi wajib diisi.';
    if (!$reason)      $errors[] = 'Alasan retur wajib diisi.';

    $valid_items = supplier_return_items($item_ids, $quantities, $buy_prices);
    if (empty($valid_items)) $errors[] = 'Tambahkan minimal 1 barang dengan kuantitas > 0.';

    if (empty($errors)) {
        $user_id = current_user()['id'];
        $company_id = req_int('company_id') ?: current_company_id();
        require_company_access($company_id);
        try {
            $return_id = create_supplier_return($db, $supplier_id, $location_id, $user_id, $trans_date, $reason, $notes, $valid_items, $company_id);
            $st = $db->prepare('SELECT reference_no FROM supplier_returns WHERE id=?');
            $st->bind_param('i', $return_id); $st->execute();
            $st->bind_result($ref_no); $st->fetch(); $st->close();
            audit_log('supplier_return.create', 'supplier_return', (int)$return_id, (string)$ref_no, "Membuat retur supplier {$ref_no} (" . count($valid_items) . ' item)', (int)$company_id);
            set_flash('success', "Retur supplier <strong>{$ref_no}</strong> berhasil disimpan.");
            redirect(APP_URL.'/pages/supplier-returns/index.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$active_cid = current_company_id();
$suppliers = $db->query('SELECT id,code,name FROM suppliers WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$items     = $db->query("SELECT i.id,i.code,i.name,u.abbreviation FROM items i JOIN units u ON u.id=i.unit_id WHERE i.is_active=1 ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);
$page_title='Buat Retur Supplier'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/supplier-returns/index.php">Retur Supplier</a></li>
    <li class="breadcrumb-item active">Buat</li>
  </ol></nav>
  <h4><i class="bi bi-arrow-return-left me-2 text-primary"></i>Buat Retur Supplier</h4>
</div>

<?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e):?><li><?=$e?></li><?php endforeach;?></ul></div><?php endif;?>

<form method="POST">
<?=csrf_field()?>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Tanggal Transaksi</label>
          <input type="date" name="transaction_date" class="form-control" value="<?=htmlspecialchars(req_str('transaction_date',date('Y-m-d')))?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Supplier</label>
          <select name="supplier_id" class="form-select ts-select" required>
            <option value="">— Pilih Supplier —</option>
            <?php foreach($suppliers as $s):?>
            <option value="<?=$s['id']?>" <?=req_int('supplier_id')==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Lokasi Asal</label>
          <select name="location_id" class="form-select ts-select" required>
            <option value="">— Pilih Lokasi —</option>
            <?php foreach($locations as $l):?>
            <option value="<?=$l['id']?>" <?=req_int('location_id')==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Alasan Retur</label>
          <input type="text" name="reason" class="form-control" placeholder="Misal: Barang rusak, salah kirim" value="<?=htmlspecialchars(req_str('reason'))?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Catatan</label>
          <textarea name="notes" class="form-control" rows="2"><?=htmlspecialchars(req_str('notes'))?></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold">Daftar Barang Retur</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="bi bi-plus-lg me-1"></i>Tambah Baris</button>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th style="min-width:220px">Barang</th><th style="min-width:90px">Qty</th><th style="min-width:140px">Harga Beli</th><th></th></tr></thead>
          <tbody id="itemsBody">
            <tr>
              <td>
                <div class="input-group input-group-sm">
                  <select name="item_id[0]" class="form-select form-select-sm item-select ts-select" required>
                    <option value="">— Pilih —</option>
                    <?php foreach($items as $it):?>
                    <option value="<?=$it['id']?>" data-code="<?=htmlspecialchars($it['code'])?>">[<?=htmlspecialchars($it['code'])?>] <?=htmlspecialchars($it['name'])?> (<?=htmlspecialchars($it['abbreviation'])?>)</option>
                    <?php endforeach;?>
                  </select>
                  <button type="button" class="btn btn-outline-secondary scan-btn" title="Scan Barcode/QR"><i class="bi bi-upc-scan"></i></button>
                </div>
              </td>
              <td><input type="number" name="quantity[0]" class="form-control form-control-sm item-qty" min="1" value="1" required></td>
              <td><input type="number" name="buy_price[0]" class="form-control form-control-sm item-price" min="0" step="any" value="0"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white text-end">
        <span class="text-muted small">Total: </span><span class="fw-bold" id="grandTotal">Rp 0</span>
      </div>
    </div>
  </div>
</div>
<div class="d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Retur</button>
  <a href="<?=APP_URL?>/pages/supplier-returns/index.php" class="btn btn-outline-secondary">Batal</a>
</div>
</form>
<?php include __DIR__.'/../../includes/scanner-modal.php';?>
<?php include __DIR__.'/../../includes/footer.php';?>
