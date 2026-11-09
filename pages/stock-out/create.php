<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_out.create');
$db = getDB();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $location_id = req_int('location_id');
    $trans_date  = req_str('transaction_date');
    $recipient   = req_str('recipient');
    $purpose     = req_str('purpose');
    $notes       = req_str('notes');
    $item_ids    = $_POST['item_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $sell_prices = $_POST['sell_price'] ?? [];

    if (!$location_id) $errors[] = 'Lokasi asal wajib dipilih.';
    if (!$trans_date)  $errors[] = 'Tanggal transaksi wajib diisi.';

    $valid_items = [];
    foreach ($item_ids as $k => $iid) {
        $iid = (int)$iid; $qty = (int)($quantities[$k]??0); $sp = (float)($sell_prices[$k]??0);
        if ($iid > 0 && $qty > 0) $valid_items[] = [$iid, $qty, $sp];
    }
    if (empty($valid_items)) $errors[] = 'Tambahkan minimal 1 barang dengan kuantitas > 0.';

    $company_id = req_int('company_id') ?: current_company_id();

    // Check available stock per item at the selected source location for this company.
    if (empty($errors)) {
        try {
            assert_location_stock_available($db, $location_id, $valid_items, $company_id);
        } catch (RuntimeException $e) {
            $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }

    if (empty($errors)) {
        $ref_no  = generate_ref('SO-', $company_id);
        $user_id = current_user()['id'];
        $db->begin_transaction();
        try {
            $st = $db->prepare('INSERT INTO stock_out (reference_no,company_id,location_id,user_id,recipient,purpose,notes,transaction_date) VALUES (?,?,?,?,?,?,?,?)');
            $st->bind_param('siiissss',$ref_no,$company_id,$location_id,$user_id,$recipient,$purpose,$notes,$trans_date);
            $st->execute(); $so_id = $db->insert_id; $st->close();

            $st2 = $db->prepare('INSERT INTO stock_out_details (stock_out_id,item_id,quantity,sell_price) VALUES (?,?,?,?)');
            foreach ($valid_items as [$iid,$qty,$sp]) {
                $st2->bind_param('iiid',$so_id,$iid,$qty,$sp); $st2->execute();
                update_stock($iid,$location_id,-$qty, $company_id);
                log_mutation($iid,$location_id,'out',$qty,$ref_no,'stock_out',$so_id,$user_id,$notes, $company_id);
            }
            $st2->close();
            $db->commit();
            set_flash('success',"Barang keluar <strong>{$ref_no}</strong> berhasil disimpan.");
            redirect(APP_URL.'/pages/stock-out/index.php');
        } catch (Exception $e) {
            $db->rollback(); $errors[] = 'Transaksi gagal: '.$e->getMessage();
        }
    }
}

$active_cid = current_company_id();
$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$items     = $db->query("SELECT i.id,i.code,i.name,u.abbreviation FROM items i JOIN units u ON u.id=i.unit_id WHERE i.is_active=1 AND (i.company_id IS NULL OR i.company_id = $active_cid) ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);
$page_title='Tambah Barang Keluar'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/stock-out/index.php">Barang Keluar</a></li>
    <li class="breadcrumb-item active">Tambah</li>
  </ol></nav>
  <h4><i class="bi bi-box-arrow-up me-2 text-primary"></i>Input Barang Keluar</h4>
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
          <label class="form-label small fw-semibold required">Lokasi Asal</label>
          <select name="location_id" class="form-select ts-select" required>
            <option value="">— Pilih Lokasi —</option>
            <?php foreach($locations as $l):?>
            <option value="<?=$l['id']?>" <?=req_int('location_id')==$l['id']?'selected':''?>>
              <?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?>
            </option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Penerima</label>
          <input type="text" name="recipient" class="form-control" placeholder="Nama penerima" value="<?=htmlspecialchars(req_str('recipient'))?>">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Keperluan</label>
          <input type="text" name="purpose" class="form-control" placeholder="Keperluan pengeluaran" value="<?=htmlspecialchars(req_str('purpose'))?>">
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
        <span class="fw-semibold">Daftar Barang Keluar</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="bi bi-plus-lg me-1"></i>Tambah Baris</button>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th style="min-width:220px">Barang</th><th style="min-width:90px">Qty</th><th style="min-width:140px">Harga Jual</th><th></th></tr></thead>
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
                  <button type="button" class="btn btn-outline-secondary scan-btn" title="Scan Barcode/QR">
                    <i class="bi bi-upc-scan"></i>
                  </button>
                </div>
              </td>
              <td><input type="number" name="quantity[0]" class="form-control form-control-sm item-qty" min="1" value="1" required></td>
              <td><input type="number" name="sell_price[0]" class="form-control form-control-sm item-price" min="0" step="any" value="0"></td>
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
  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Barang Keluar</button>
  <a href="<?=APP_URL?>/pages/stock-out/index.php" class="btn btn-outline-secondary">Batal</a>
</div>
</form>
<?php include __DIR__.'/../../includes/scanner-modal.php';?>
<?php include __DIR__.'/../../includes/footer.php';?>
