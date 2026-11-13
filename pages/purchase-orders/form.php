<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/purchase_orders.php';
require_perm(req_int('id') ? 'purchase_orders.edit' : 'purchase_orders.create');
$db=getDB(); $id=req_int('id'); $errors=[]; $po=null; $details=[];
if($id){$st=$db->prepare("SELECT * FROM purchase_orders WHERE id=? AND status='draft'");$st->bind_param('i',$id);$st->execute();$po=$st->get_result()->fetch_assoc();$st->close();if(!$po){set_flash('error','Hanya draft PO yang dapat diedit.');redirect(APP_URL.'/pages/purchase-orders/index.php');}$st=$db->prepare('SELECT * FROM purchase_order_details WHERE po_id=?');$st->bind_param('i',$id);$st->execute();$details=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $supplier=req_int('supplier_id');$location=req_int('location_id');$order_date=req_str('order_date');$expected=req_str('expected_date');$notes=req_str('notes');$valid=po_valid_items($_POST['item_id']??[],$_POST['quantity']??[],$_POST['buy_price']??[]);
 if(!$supplier)$errors[]='Supplier wajib dipilih.';if(!$location)$errors[]='Lokasi wajib dipilih.';if(!$order_date)$errors[]='Tanggal PO wajib diisi.';if(!$valid)$errors[]='Tambahkan minimal satu barang dengan qty lebih dari nol.';
 if(!$errors){$db->begin_transaction();try{if($id){$st=$db->prepare("UPDATE purchase_orders SET supplier_id=?,location_id=?,order_date=?,expected_date=NULLIF(?,''),notes=? WHERE id=? AND status='draft'");$st->bind_param('iisssi',$supplier,$location,$order_date,$expected,$notes,$id);$st->execute();$st->close();$del=$db->prepare('DELETE FROM purchase_order_details WHERE po_id=?');$del->bind_param('i',$id);$del->execute();$del->close();}else{$company_id=req_int('company_id')?:current_company_id();$ref=generate_ref('PO-',$company_id);$user=current_user()['id'];$st=$db->prepare("INSERT INTO purchase_orders(reference_no,company_id,supplier_id,location_id,user_id,order_date,expected_date,notes) VALUES(?,?,?,?,?,?,NULLIF(?,''),?)");$st->bind_param('siiiisss',$ref,$company_id,$supplier,$location,$user,$order_date,$expected,$notes);$st->execute();$id=$db->insert_id;$st->close();}$line=$db->prepare('INSERT INTO purchase_order_details(po_id,item_id,quantity,buy_price) VALUES(?,?,?,?)');foreach($valid as [$iid,$qty,$price]){$line->bind_param('iiid',$id,$iid,$qty,$price);$line->execute();}$line->close();$db->commit();set_flash('success','Draft purchase order tersimpan.');redirect(APP_URL.'/pages/purchase-orders/index.php');}catch(Throwable $e){$db->rollback();$errors[]='Gagal menyimpan: '.$e->getMessage();}}
 $po=array_merge($po??[],['supplier_id'=>$supplier,'location_id'=>$location,'order_date'=>$order_date,'expected_date'=>$expected,'notes'=>$notes]);$details=array_map(fn($x)=>['item_id'=>$x[0],'quantity'=>$x[1],'buy_price'=>$x[2]],$valid);
}
$active_cid=current_company_id();
$suppliers=$db->query('SELECT id,code,name FROM suppliers WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);$locations=$db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);$items=$db->query("SELECT id,code,name,buy_price FROM items WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
if(!$details&&isset($_GET['suggested'])){$details=$db->query("SELECT i.id item_id,i.buy_price,GREATEST(i.min_stock*2-COALESCE(s.total_stock,0),1) quantity FROM items i LEFT JOIN (SELECT item_id,SUM(quantity) total_stock FROM stock WHERE company_id=$active_cid GROUP BY item_id) s ON s.item_id=i.id WHERE i.is_active=1 AND COALESCE(s.total_stock,0)<=i.min_stock ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);}
if(!$details)$details=[['item_id'=>0,'quantity'=>1,'buy_price'=>0]];
$page_title=$id?'Edit Purchase Order':'Buat Purchase Order';include __DIR__.'/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-cart-check me-2 text-primary"></i><?=e($page_title)?></h4></div>
<?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul></div><?php endif;?>
<form method="post"><?=csrf_field()?><div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-3"><div class="col-md-3"><label class="form-label">Tanggal PO</label><input type="date" required name="order_date" class="form-control" value="<?=e($po['order_date']??date('Y-m-d'))?>"></div><div class="col-md-3"><label class="form-label">Estimasi Tiba</label><input type="date" name="expected_date" class="form-control" value="<?=e($po['expected_date']??'')?>"></div><div class="col-md-3"><label class="form-label">Supplier</label><select required name="supplier_id" class="form-select ts-select"><option value="">Pilih</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>" <?=($po['supplier_id']??0)==$s['id']?'selected':''?>>[<?=e($s['code'])?>] <?=e($s['name'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">Lokasi Tujuan</label><select required name="location_id" class="form-select ts-select"><option value="">Pilih</option><?php foreach($locations as $l):?><option value="<?=$l['id']?>" <?=($po['location_id']??0)==$l['id']?'selected':''?>><?=e($l['code'])?> — <?=e($l['name'])?></option><?php endforeach;?></select></div><div class="col-12"><label class="form-label">Catatan</label><textarea name="notes" class="form-control"><?=e($po['notes']??'')?></textarea></div></div></div></div>
<div class="card table-card mb-3"><div class="card-header bg-white d-flex justify-content-between"><strong>Barang</strong><button type="button" class="btn btn-sm btn-outline-primary" id="addLine">Tambah Baris</button></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Barang</th><th width="140">Qty</th><th width="190">Harga Beli</th><th width="60"></th></tr></thead><tbody id="lines"><?php foreach($details as $k=>$d):?><tr><td><select required name="item_id[]" class="form-select form-select-sm ts-select"><option value="">Pilih</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-price="<?=$it['buy_price']?>" <?=($d['item_id']??0)==$it['id']?'selected':''?>>[<?=e($it['code'])?>] <?=e($it['name'])?></option><?php endforeach;?></select></td><td><input required min="1" type="number" name="quantity[]" class="form-control form-control-sm" value="<?=(int)($d['quantity']??1)?>"></td><td><input required min="0" step="0.01" type="number" name="buy_price[]" class="form-control form-control-sm" value="<?=e((string)($d['buy_price']??0))?>"></td><td><button type="button" class="btn btn-sm btn-outline-danger remove"><i class="bi bi-trash"></i></button></td></tr><?php endforeach;?></tbody></table></div></div><button class="btn btn-primary">Simpan Draft</button> <a href="index.php" class="btn btn-outline-secondary">Batal</a></form>
<script>
const body = document.getElementById('lines');
document.getElementById('addLine').onclick = () => {
  const row = body.rows[0].cloneNode(true);
  row.querySelectorAll('.ts-wrapper').forEach(w => w.remove());
  row.querySelectorAll('input').forEach(i => {
    i.value = i.name.includes('quantity') ? '1' : '0';
  });
  const sel = row.querySelector('select');
  if (sel) {
    sel.style.display = '';
    sel.removeAttribute('tabindex');
    sel.classList.remove('tomselected', 'ts-hidden-accessible');
    sel.selectedIndex = 0;
    sel.value = '';
  }
  body.appendChild(row);
  if (sel && window.TomSelect) {
    new TomSelect(sel, {
      allowEmptyOption: true,
      dropdownParent: 'body',
      placeholder: 'Pilih...'
    });
  }
};
body.addEventListener('click', e => {
  if (e.target.closest('.remove') && body.rows.length > 1) {
    const row = e.target.closest('tr');
    const sel = row.querySelector('select');
    if (sel?.tomselect) sel.tomselect.destroy();
    row.remove();
  }
});
body.addEventListener('change', e => {
  if (e.target.matches('select[name="item_id[]"]')) {
    const o = e.target.selectedOptions[0];
    if (o?.dataset.price) {
      e.target.closest('tr').querySelector('[name="buy_price[]"]').value = o.dataset.price;
    }
  }
});
</script>
<?php include __DIR__.'/../../includes/footer.php';?>
