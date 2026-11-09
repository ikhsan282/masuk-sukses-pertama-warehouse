<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_in.view');
$db = getDB();
$id = req_int('id');

$st = $db->prepare('SELECT si.*, s.name AS supplier_name, s.phone AS supplier_phone, s.address AS supplier_address,
    l.name AS location_name, u.name AS user_name,
    c.name AS company_name, c.address AS company_address, c.phone AS company_phone, c.npwp AS company_npwp
    FROM stock_in si 
    JOIN suppliers s ON s.id=si.supplier_id
    JOIN locations l ON l.id=si.location_id 
    JOIN users u ON u.id=si.user_id 
    LEFT JOIN companies c ON c.id=si.company_id
    WHERE si.id=?');
$st->bind_param('i',$id); $st->execute();
$header = $st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/stock-in/index.php'); }

$comp_name = $header['company_name'] ?: COMPANY_NAME;
$comp_addr = $header['company_address'] ?: COMPANY_ADDRESS;
$comp_info = [];
if (!empty($header['company_phone'])) $comp_info[] = 'Telp: ' . $header['company_phone'];
if (!empty($header['company_npwp']))  $comp_info[] = 'NPWP: ' . $header['company_npwp'];
$comp_extra = $comp_info ? ' | ' . implode(' | ', $comp_info) : '';

$st2 = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM stock_in_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id WHERE d.stock_in_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details = $st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();
$total = array_sum(array_map(fn($d)=>$d['quantity']*$d['buy_price'],$details));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Barang Masuk - <?=htmlspecialchars($header['reference_no'])?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:12px/1.5 Arial,sans-serif;color:#000;background:#fff}
.container{max-width:800px;margin:20px auto;padding:0 15px}
.header{text-align:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:20px}
.header h2{font-size:20px;margin-bottom:5px}
.header p{font-size:11px;color:#555}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:20px}
.info-box{border:1px solid #ddd;padding:10px}
.info-box h4{font-size:13px;margin-bottom:8px;border-bottom:1px solid #eee;padding-bottom:4px}
.info-row{display:flex;margin-bottom:4px}
.info-label{width:120px;font-weight:600;font-size:11px}
.info-value{flex:1;font-size:11px}
table{width:100%;border-collapse:collapse;margin-bottom:15px}
thead{background:#f5f5f5}
th,td{border:1px solid #ddd;padding:8px 6px;font-size:11px}
th{font-weight:600;text-align:left}
.text-center{text-align:center}
.text-end{text-align:right}
.fw-bold{font-weight:700}
.signature{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:40px}
.signature div{text-align:center}
.signature p{margin-bottom:60px;font-size:11px}
.signature span{display:block;border-top:1px solid #000;padding-top:4px;font-size:11px}
@media print{
  body{margin:0;padding:0}
  .container{margin:0;padding:15px}
  .no-print{display:none}
}
@media screen{
  .print-btn{position:fixed;bottom:20px;right:20px;padding:12px 24px;background:#007bff;color:#fff;border:none;border-radius:6px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);font-size:14px}
  .print-btn:hover{background:#0056b3}
}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">🖨️ Cetak</button>
<div class="container">
  <div class="header">
    <h2><?= htmlspecialchars($comp_name) ?></h2>
    <p><?= htmlspecialchars($comp_addr) ?><?= htmlspecialchars($comp_extra) ?></p>
    <h3 style="margin-top:10px;font-size:15px;text-decoration:underline">BUKTI PENERIMAAN BARANG</h3>
  </div>
  
  <div class="info-grid">
    <div class="info-box">
      <h4>Informasi Transaksi</h4>
      <div class="info-row"><span class="info-label">No. Referensi</span><span class="info-value fw-bold"><?=htmlspecialchars($header['reference_no'])?></span></div>
      <div class="info-row"><span class="info-label">Tanggal</span><span class="info-value"><?=tgl($header['transaction_date'])?></span></div>
      <div class="info-row"><span class="info-label">Lokasi</span><span class="info-value"><?=htmlspecialchars($header['location_name'])?></span></div>
      <div class="info-row"><span class="info-label">Dicatat Oleh</span><span class="info-value"><?=htmlspecialchars($header['user_name'])?></span></div>
    </div>
    <div class="info-box">
      <h4>Supplier</h4>
      <div class="info-row"><span class="info-label">Nama</span><span class="info-value fw-bold"><?=htmlspecialchars($header['supplier_name'])?></span></div>
      <div class="info-row"><span class="info-label">Telepon</span><span class="info-value"><?=htmlspecialchars($header['supplier_phone']??'-')?></span></div>
      <?php if($header['supplier_address']):?>
      <div class="info-row"><span class="info-label">Alamat</span><span class="info-value"><?=htmlspecialchars($header['supplier_address'])?></span></div>
      <?php endif;?>
    </div>
  </div>
  
  <table>
    <thead><tr><th style="width:30px">No</th><th style="width:80px">Kode</th><th>Nama Barang</th><th class="text-center" style="width:70px">Qty</th><th class="text-end" style="width:100px">Harga Beli</th><th class="text-end" style="width:110px">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach($details as $i=>$d):?>
    <tr>
      <td class="text-center"><?=$i+1?></td>
      <td><?=htmlspecialchars($d['item_code'])?></td>
      <td><?=htmlspecialchars($d['item_name'])?></td>
      <td class="text-center"><?=$d['quantity']?> <?=htmlspecialchars($d['abbreviation'])?></td>
      <td class="text-end"><?=idr($d['buy_price'])?></td>
      <td class="text-end"><?=idr($d['quantity']*$d['buy_price'])?></td>
    </tr>
    <?php endforeach;?>
    <tr style="background:#f5f5f5">
      <td colspan="5" class="text-end fw-bold">Total Nilai</td>
      <td class="text-end fw-bold"><?=idr($total)?></td>
    </tr>
    </tbody>
  </table>
  
  <?php if($header['notes']):?>
  <div style="border:1px solid #ddd;padding:10px;margin-bottom:15px;background:#f9f9f9">
    <strong style="font-size:11px">Catatan:</strong><br>
    <span style="font-size:11px"><?=nl2br(htmlspecialchars($header['notes']))?></span>
  </div>
  <?php endif;?>
  
  <div class="signature">
    <div><p>Dikirim Oleh</p><span>Supplier</span></div>
    <div><p>Diterima Oleh</p><span><?=htmlspecialchars($header['user_name'])?></span></div>
    <div><p>Mengetahui</p><span>Kepala Gudang</span></div>
  </div>
</div>
</body>
</html>
