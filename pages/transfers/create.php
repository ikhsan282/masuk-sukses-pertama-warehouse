<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.create');
$db = getDB();

$allowed_from_ids = array_values(array_filter(array_map(fn($c) => (int)$c['id'], user_companies())));
$all_companies    = $db->query('SELECT id,code,name,is_holding FROM companies WHERE is_active=1 ORDER BY is_holding DESC, name ASC')->fetch_all(MYSQLI_ASSOC);
$active_comp_ids  = array_map(fn($c) => (int)$c['id'], $all_companies);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $from_loc  = req_int('from_location_id');
    $to_loc    = req_int('to_location_id');
    $trans_date= req_str('transaction_date');
    $notes     = req_str('notes');
    $item_ids  = $_POST['item_id'] ?? [];
    $quantities= $_POST['quantity'] ?? [];

    $from_company_id = req_int('from_company_id') ?: current_company_id();
    $to_company_id   = req_int('to_company_id') ?: $from_company_id;

    if (!in_array($from_company_id, $allowed_from_ids, true)) {
        $errors[] = 'PT asal tidak valid atau di luar hak akses Anda.';
    }
    if (!in_array($to_company_id, $active_comp_ids, true)) {
        $errors[] = 'PT tujuan tidak valid atau tidak aktif.';
    }
    if (!$from_loc)          $errors[] = 'Lokasi asal wajib dipilih.';
    if (!$to_loc)            $errors[] = 'Lokasi tujuan wajib dipilih.';
    if ($from_loc===$to_loc && $from_company_id===$to_company_id) {
        $errors[] = 'Lokasi asal dan tujuan tidak boleh sama pada PT yang sama.';
    }
    if (!$trans_date)        $errors[] = 'Tanggal transaksi wajib diisi.';

    $valid_items = [];
    foreach ($item_ids as $k => $iid) {
        $iid=(int)$iid; $qty=(int)($quantities[$k]??0);
        if ($iid>0 && $qty>0) $valid_items[]=[$iid,$qty];
    }
    if (empty($valid_items)) $errors[] = 'Tambahkan minimal 1 barang.';

    // Stock check at the selected source location for from_company.
    if (empty($errors)) {
        try {
            assert_location_stock_available($db, $from_loc, $valid_items, $from_company_id);
        } catch (RuntimeException $e) {
            $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }

    if (empty($errors)) {
        $ref_no  = generate_ref('TR-', $from_company_id);
        $user_id = current_user()['id'];
        $db->begin_transaction();
        try {
            $st=$db->prepare('INSERT INTO transfers (reference_no,from_company_id,to_company_id,from_location_id,to_location_id,user_id,notes,transaction_date) VALUES (?,?,?,?,?,?,?,?)');
            $st->bind_param('siiiiiss',$ref_no,$from_company_id,$to_company_id,$from_loc,$to_loc,$user_id,$notes,$trans_date);
            $st->execute(); $tr_id=$db->insert_id; $st->close();

            $st2=$db->prepare('INSERT INTO transfer_details (transfer_id,item_id,quantity) VALUES (?,?,?)');
            foreach ($valid_items as [$iid,$qty]) {
                $st2->bind_param('iii',$tr_id,$iid,$qty); $st2->execute();
                update_stock($iid,$from_loc,-$qty, $from_company_id);
                update_stock($iid,$to_loc,$qty, $to_company_id);
                log_mutation($iid,$from_loc,'transfer_out',$qty,$ref_no,'transfer',$tr_id,$user_id,$notes, $from_company_id);
                log_mutation($iid,$to_loc,'transfer_in',$qty,$ref_no,'transfer',$tr_id,$user_id,$notes, $to_company_id);
            }
            $st2->close();
            $db->commit();
            audit_log('transfer.create', 'transfer', (int)$tr_id, $ref_no, "Transfer lokasi {$ref_no} (" . count($valid_items) . ' item)', (int)$from_company_id);
            set_flash('success',"Transfer <strong>{$ref_no}</strong> berhasil disimpan.");
            redirect(APP_URL.'/pages/transfers/index.php');
        } catch (Exception $e) {
            $db->rollback(); $errors[]='Transaksi gagal: '.$e->getMessage();
        }
    }
}

$active_cid = current_company_id();
$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$items     = $db->query("SELECT i.id,i.code,i.name,u.abbreviation FROM items i JOIN units u ON u.id=i.unit_id WHERE i.is_active=1 ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);

$stock_map = [];
if (!empty($allowed_from_ids)) {
    $in_sql = implode(',', $allowed_from_ids);
    $sq = $db->query("SELECT company_id, location_id, item_id, quantity FROM stock WHERE company_id IN ($in_sql) AND quantity > 0");
    while ($r = $sq->fetch_assoc()) {
        $stock_map[(int)$r['company_id']][(int)$r['location_id']][(int)$r['item_id']] = (int)$r['quantity'];
    }
}
$page_title='Transfer Lokasi'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/transfers/index.php">Transfer</a></li>
    <li class="breadcrumb-item active">Tambah</li>
  </ol></nav>
  <h4><i class="bi bi-arrow-left-right me-2 text-primary"></i>Transfer Antar Lokasi</h4>
</div>

<?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e):?><li><?=$e?></li><?php endforeach;?></ul></div><?php endif;?>

<form method="POST">
<?=csrf_field()?>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Tanggal</label>
          <input type="date" name="transaction_date" class="form-control" value="<?=htmlspecialchars(req_str('transaction_date',date('Y-m-d')))?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">PT Asal</label>
          <select name="from_company_id" class="form-select form-select-sm" required>
            <?php foreach (user_companies() as $uc): ?>
            <option value="<?=$uc['id']?>" <?=(req_int('from_company_id') ?: $active_cid)==$uc['id']?'selected':''?>>
              <?=htmlspecialchars($uc['code'])?> — <?=htmlspecialchars($uc['name'])?><?=$uc['id']==$active_cid?' (Aktif)':''?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Lokasi Asal</label>
          <select name="from_location_id" class="form-select ts-select" required>
            <option value="">— Pilih —</option>
            <?php foreach($locations as $l):?>
            <option value="<?=$l['id']?>" <?=req_int('from_location_id')==$l['id']?'selected':''?>>
              <?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?>
            </option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">PT Tujuan</label>
          <select name="to_company_id" class="form-select form-select-sm" required>
            <?php foreach ($all_companies as $ac): ?>
            <option value="<?=$ac['id']?>" <?=(req_int('to_company_id') ?: $active_cid)==$ac['id']?'selected':''?>>
              <?=htmlspecialchars($ac['code'])?> — <?=htmlspecialchars($ac['name'])?><?=$ac['id']==$active_cid?' (PT Sama)':''?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text small">Pilih PT yang sama untuk pindah rak biasa, atau pilih PT lain untuk transfer kepemilikan stok antar-PT.</div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold required">Lokasi Tujuan</label>
          <select name="to_location_id" class="form-select ts-select" required>
            <option value="">— Pilih —</option>
            <?php foreach($locations as $l):?>
            <option value="<?=$l['id']?>" <?=req_int('to_location_id')==$l['id']?'selected':''?>>
              <?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?>
            </option>
            <?php endforeach;?>
          </select>
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
        <span class="fw-semibold">Daftar Barang</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="bi bi-plus-lg me-1"></i>Tambah Baris</button>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th style="min-width:260px">Barang</th><th style="min-width:120px">Qty</th><th></th></tr></thead>
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
                <div class="stock-hint small text-muted mt-1"></div>
              </td>
              <td><input type="number" name="quantity[0]" class="form-control form-control-sm item-qty" min="1" value="1" required></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-trash"></i></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Transfer</button>
  <a href="<?=APP_URL?>/pages/transfers/index.php" class="btn btn-outline-secondary">Batal</a>
</div>
</form>
<script>
const transferStockMap = <?= json_encode($stock_map) ?>;
function updateTransferHints() {
  const fromComp = document.querySelector('[name="from_company_id"]')?.value || '';
  const fromLoc  = document.querySelector('[name="from_location_id"]')?.value || '';
  const locStocks = transferStockMap[fromComp]?.[fromLoc] || {};
  document.querySelectorAll('#itemsBody tr').forEach(tr => {
    const sel  = tr.querySelector('.item-select');
    const hint = tr.querySelector('.stock-hint');
    if (!sel || !hint) return;
    if (!sel.value || !fromComp || !fromLoc) {
      hint.textContent = '';
      hint.className = 'stock-hint small text-muted mt-1';
      return;
    }
    const avail = locStocks[sel.value] ?? 0;
    hint.textContent = 'Stok tersedia di PT & lokasi asal: ' + avail.toLocaleString('id-ID');
    hint.className = 'stock-hint small mt-1 ' + (avail > 0 ? 'text-success fw-semibold' : 'text-danger');
  });
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelector('[name="from_company_id"]')?.addEventListener('change', updateTransferHints);
  document.querySelector('[name="from_location_id"]')?.addEventListener('change', updateTransferHints);
  document.getElementById('itemsBody')?.addEventListener('change', e => {
    if (e.target.classList.contains('item-select')) updateTransferHints();
  });
  document.getElementById('addItemRow')?.addEventListener('click', () => setTimeout(updateTransferHints, 50));
  updateTransferHints();
});
</script>
<?php include __DIR__.'/../../includes/scanner-modal.php';?>
<?php include __DIR__.'/../../includes/footer.php';?>
