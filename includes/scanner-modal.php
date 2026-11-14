<?php $GLOBALS['msp_needs_scanner'] = true; ?>
<!-- Barcode/QR Scanner Modal (reusable) -->
<div class="modal fade" id="scannerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-upc-scan me-2"></i>Scan Barcode/QR</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="scanner-reader" style="width:100%;max-width:500px;margin:0 auto"></div>
        <div class="alert alert-info small mt-3 mb-0">
          <i class="bi bi-info-circle me-1"></i>Arahkan kamera ke barcode/QR code barang
        </div>
      </div>
    </div>
  </div>
</div>
