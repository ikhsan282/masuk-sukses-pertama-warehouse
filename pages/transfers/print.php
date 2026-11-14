<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.view');
$db = getDB();
$id = req_int('id');

$st = $db->prepare('SELECT t.*, fl.name AS from_loc, tl.name AS to_loc, u.name AS user_name,
    c.code AS from_comp_code, c.name AS company_name, c.address AS company_address, c.phone AS company_phone, c.npwp AS company_npwp,
    tc.code AS to_comp_code, tc.name AS to_comp_name
    FROM transfers t 
    JOIN locations fl ON fl.id=t.from_location_id
    JOIN locations tl ON tl.id=t.to_location_id 
    JOIN users u ON u.id=t.user_id 
    LEFT JOIN companies c ON c.id=t.from_company_id
    LEFT JOIN companies tc ON tc.id=t.to_company_id
    WHERE t.id=?');
$st->bind_param('i',$id); $st->execute();
$header=$st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/transfers/index.php'); }
require_any_company_access([(int)$header['from_company_id'], (int)$header['to_company_id']], false);

$comp_name = $header['company_name'] ?: COMPANY_NAME;
$comp_addr = $header['company_address'] ?: COMPANY_ADDRESS;
$comp_info = [];
if (!empty($header['company_phone'])) $comp_info[] = 'Telp: ' . $header['company_phone'];
if (!empty($header['company_npwp']))  $comp_info[] = 'NPWP: ' . $header['company_npwp'];
$comp_extra = $comp_info ? ' | ' . implode(' | ', $comp_info) : '';

$st2=$db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM transfer_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id WHERE d.transfer_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details=$st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Transfer - <?=htmlspecialchars($header['reference_no'])?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:12px/1.5 Arial,sans-serif;color:#000;background:#fff}
.container{max-width:800px;margin:20px auto;padding:0 15px}
.header{text-align:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:20px}
.header h2{font-size:20px;margin-bottom:5px}
.header p{font-size:11px;color:#555}
.info-box{border:1px solid #ddd;padding:10px;margin-bottom:15px}
.info-row{display:flex;margin-bottom:4px}
.info-label{width:140px;font-weight:600;font-size:11px}
.info-value{flex:1;font-size:11px}
.highlight{background:#fff3cd;padding:2px 4px;border-radius:2px}
table{width:100%;border-collapse:collapse;margin-bottom:15px}
thead{background:#f5f5f5}
th,td{border:1px solid #ddd;padding:8px 6px;font-size:11px}
th{font-weight:600;text-align:left}
.text-center{text-align:center}
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
    <h3 style="margin-top:10px;font-size:15px;text-decoration:underline">
      <?= ($header['from_company_id'] ?? 1) != ($header['to_company_id'] ?? 1) ? 'SURAT TRANSFER BARANG ANTAR-PT' : 'SURAT TRANSFER BARANG' ?>
    </h3>
  </div>
  
  <div class="info-box">
    <div class="info-row"><span class="info-label">No. Referensi</span><span class="info-value fw-bold"><?=htmlspecialchars($header['reference_no'])?></span></div>
    <div class="info-row"><span class="info-label">Tanggal</span><span class="info-value"><?=tgl($header['transaction_date'])?></span></div>
    <div class="info-row"><span class="info-label">PT & Lokasi Asal</span><span class="info-value"><span class="highlight fw-bold"><?=htmlspecialchars(($header['from_comp_code'] ?? 'MSP') . ' — ' . $header['from_loc'])?></span></span></div>
    <div class="info-row"><span class="info-label">PT & Lokasi Tujuan</span><span class="info-value"><span class="highlight fw-bold"><?=htmlspecialchars(($header['to_comp_code'] ?? 'MSP') . ' — ' . $header['to_loc'])?></span></span></div>
    <div class="info-row"><span class="info-label">Dicatat Oleh</span><span class="info-value"><?=htmlspecialchars($header['user_name'])?></span></div>
  </div>
  
  <table>
    <thead><tr><th style="width:30px">No</th><th style="width:90px">Kode</th><th>Nama Barang</th><th class="text-center" style="width:90px">Quantity</th></tr></thead>
    <tbody>
    <?php foreach($details as $i=>$d):?>
    <tr>
      <td class="text-center"><?=$i+1?></td>
      <td><?=htmlspecialchars($d['item_code'])?></td>
      <td><?=htmlspecialchars($d['item_name'])?></td>
      <td class="text-center fw-bold"><?=$d['quantity']?> <?=htmlspecialchars($d['abbreviation'])?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  
  <?php if($header['notes']):?>
  <div style="border:1px solid #ddd;padding:10px;margin-bottom:15px;background:#f9f9f9">
    <strong style="font-size:11px">Catatan:</strong><br>
    <span style="font-size:11px"><?=nl2br(htmlspecialchars($header['notes']))?></span>
  </div>
  <?php endif;?>
  
  <div class="signature">
    <div><p>Diserahkan Oleh<br>(Lokasi Asal)</p><span>_________________</span></div>
    <div><p>Diterima Oleh<br>(Lokasi Tujuan)</p><span>_________________</span></div>
    <div><p>Mengetahui</p><span>Kepala Gudang</span></div>
  </div>
</div>
</body>
</html>
