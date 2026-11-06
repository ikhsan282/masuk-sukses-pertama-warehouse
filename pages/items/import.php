<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/item-import.php';

require_perm('items.create');
$db = getDB();
$errors = [];
$preview = null;
$upload_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    if (isset($_POST['action']) && $_POST['action'] === 'clear') {
        $key = req_str('upload_key');
        $filename = 'msp_import_' . hash('sha256', session_id() . ':' . $key);
        $path = sys_get_temp_dir() . '/' . $filename;
        if (file_exists($path)) @unlink($path);
        set_flash('info', 'Preview dibersihkan.');
        redirect(APP_URL . '/pages/items/import.php');
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'confirm') {
        $key = req_str('upload_key');
        $filename = 'msp_import_' . hash('sha256', session_id() . ':' . $key);
        $path = sys_get_temp_dir() . '/' . $filename;
        if (!file_exists($path)) {
            set_flash('error', 'Data upload tidak ditemukan. Upload ulang.');
            redirect(APP_URL . '/pages/items/import.php');
        }
        
        $extension = req_str('extension');
        try {
            $raw_rows = item_import_read($path, $extension);
            $preview_rows = item_import_preview($db, $raw_rows);
            
            $valid = array_filter($preview_rows, fn($r) => empty($r['errors']) && !$r['exists']);
            if (empty($valid)) {
                set_flash('error', 'Tidak ada baris yang dapat diimpor.');
                redirect(APP_URL . '/pages/items/import.php');
            }
            
            $db->begin_transaction();
            $inserted = 0;
            $stmt = $db->prepare('INSERT INTO items (code,name,category_id,unit_id,min_stock,buy_price,sell_price,description,is_active)
                VALUES (?,?,?,?,?,?,?,?,1)');
            
            foreach ($valid as $row) {
                $stmt->bind_param('ssiiidds',
                    $row['code'], $row['name'], $row['category_id'], $row['unit_id'],
                    $row['min_stock'], $row['buy_price'], $row['sell_price'], $row['description']);
                if ($stmt->execute()) $inserted++;
            }
            $stmt->close();
            $db->commit();
            
            @unlink($path);
            set_flash('success', "Berhasil mengimpor $inserted barang.");
            redirect(APP_URL . '/pages/items/index.php');
            
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = $e->getMessage();
        }
    }
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            $errors[] = 'Format file harus CSV atau XLSX.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Ukuran file maksimal 5 MB.';
        } else {
            try {
                $raw_rows = item_import_read($file['tmp_name'], $extension);
                $preview = item_import_preview($db, $raw_rows);
                
                $upload_key = bin2hex(random_bytes(16));
                $temp_path = sys_get_temp_dir() . '/msp_import_' . $upload_key;
                move_uploaded_file($file['tmp_name'], $temp_path);
                
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    } else {
        $errors[] = 'File tidak dipilih atau terjadi kesalahan upload.';
    }
}

$page_title = 'Import Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/items/index.php">Data Barang</a></li>
    <li class="breadcrumb-item active">Import</li>
  </ol></nav>
  <h4><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Import Barang dari Excel/CSV</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <strong>Error:</strong>
  <ul class="mb-0"><?php foreach($errors as $err): ?><li><?=htmlspecialchars($err)?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($preview === null): ?>
<div class="row">
  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Upload File</div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <?=csrf_field()?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Pilih File Excel/CSV</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx" required>
            <div class="form-text">Format: CSV atau XLSX, maksimal 5 MB, maksimal 1.000 baris data.</div>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-eye me-1"></i>Preview Data</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm border-info">
      <div class="card-header bg-info bg-opacity-10 fw-semibold text-info"><i class="bi bi-info-circle me-1"></i>Format Kolom</div>
      <div class="card-body">
        <p class="small mb-2">Kolom <strong>wajib</strong>:</p>
        <ul class="small mb-3">
          <li><code>Kode</code> (maks 30 karakter, unik)</li>
          <li><code>Nama</code> (maks 150 karakter)</li>
          <li><code>Kategori</code> (kode/nama kategori yang sudah ada)</li>
          <li><code>Satuan</code> (nama/singkatan satuan yang sudah ada)</li>
        </ul>
        <p class="small mb-2">Kolom <strong>opsional</strong>:</p>
        <ul class="small mb-0">
          <li><code>Stok Minimum</code> (angka, default 0)</li>
          <li><code>Harga Beli</code> (angka, default 0)</li>
          <li><code>Harga Jual</code> (angka, default 0)</li>
          <li><code>Deskripsi</code></li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php else:
  $valid_count = count(array_filter($preview, fn($r) => empty($r['errors']) && !$r['exists']));
  $exists_count = count(array_filter($preview, fn($r) => $r['exists']));
  $error_count = count(array_filter($preview, fn($r) => !empty($r['errors'])));
?>
<div class="card shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Preview Data (<?=count($preview)?> baris)</span>
    <div class="d-flex gap-2">
      <span class="badge bg-success"><?=$valid_count?> Siap Import</span>
      <?php if($exists_count):?><span class="badge bg-warning"><?=$exists_count?> Sudah Ada</span><?php endif;?>
      <?php if($error_count):?><span class="badge bg-danger"><?=$error_count?> Error</span><?php endif;?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr><th>Brs</th><th>Kode</th><th>Nama</th><th>Kategori</th><th>Satuan</th><th>Min</th><th>B.Beli</th><th>H.Jual</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach($preview as $row):
        $status_class = !empty($row['errors']) ? 'table-danger' : ($row['exists'] ? 'table-warning' : '');
      ?>
        <tr class="<?=$status_class?>">
          <td class="small text-muted"><?=$row['line']?></td>
          <td><code class="small"><?=htmlspecialchars($row['code'])?></code></td>
          <td class="small"><?=htmlspecialchars(substr($row['name'],0,40))?></td>
          <td class="small"><?=htmlspecialchars($row['category'])?></td>
          <td class="small"><?=htmlspecialchars($row['unit'])?></td>
          <td class="small"><?=$row['min_stock']?></td>
          <td class="small"><?=idr($row['buy_price'])?></td>
          <td class="small"><?=idr($row['sell_price'])?></td>
          <td class="small">
            <?php if($row['exists']):?><span class="badge bg-warning">Sudah Ada</span>
            <?php elseif(!empty($row['errors'])):?><span class="badge bg-danger" title="<?=htmlspecialchars(implode(', ',$row['errors']))?>">Error</span>
            <?php else:?><span class="badge bg-success">OK</span><?php endif;?>
          </td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-between">
    <form method="POST" class="d-inline">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="clear">
      <input type="hidden" name="upload_key" value="<?=htmlspecialchars($upload_key)?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Batalkan</button>
    </form>
    <?php if($valid_count > 0):?>
    <form method="POST" class="d-inline">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="upload_key" value="<?=htmlspecialchars($upload_key)?>">
      <input type="hidden" name="extension" value="<?=htmlspecialchars($extension)?>">
      <button type="submit" class="btn btn-sm btn-success" data-confirm="Import <?=$valid_count?> barang?">
        <i class="bi bi-check-circle me-1"></i>Konfirmasi Import (<?=$valid_count?> barang)
      </button>
    </form>
    <?php endif;?>
  </div>
</div>
<?php endif;?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
