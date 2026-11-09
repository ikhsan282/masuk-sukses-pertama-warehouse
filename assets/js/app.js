// Masuk Sukses Pertama Warehouse – app.js

document.addEventListener('DOMContentLoaded', () => {

  // ── Dark mode ─────────────────────────────────────────────
  const darkToggle = document.getElementById('darkToggle');
  function syncThemeIcon() {
    const icon = darkToggle?.querySelector('i');
    if (icon) icon.className = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
  }
  darkToggle?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    localStorage.setItem('mw_theme', next);
    syncThemeIcon();
  });
  syncThemeIcon();

  // ── PWA service worker ───────────────────────────────────
  if ('serviceWorker' in navigator && window.APP_URL) {
    navigator.serviceWorker.register(window.APP_URL + '/sw.js').catch(err => console.warn('SW registration failed:', err));
  }

  const sidebar       = document.getElementById('sidebar');
  const toggleBtn     = document.getElementById('sidebarToggle');
  const toggleMobile  = document.getElementById('sidebarToggleMobile');

  // Restore collapsed state
  if (localStorage.getItem('sidebarCollapsed') === '1') {
    sidebar?.classList.add('collapsed');
  }

  toggleBtn?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
  });

  // ── Sidebar toggle (mobile) ──────────────────────────────
  let overlay = document.querySelector('.sidebar-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
  }

  toggleMobile?.addEventListener('click', () => {
    sidebar?.classList.toggle('mobile-open');
    overlay.classList.toggle('show');
  });
  overlay.addEventListener('click', () => {
    sidebar?.classList.remove('mobile-open');
    overlay.classList.remove('show');
  });

  // ── Auto-dismiss alerts after 5s ────────────────────────
  document.querySelectorAll('.alert-dismissible').forEach(el => {
    setTimeout(() => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
      bsAlert?.close();
    }, 5000);
  });

  // ── Confirm delete ───────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      const msg = el.dataset.confirm || 'Yakin ingin menghapus data ini?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // ── Dynamic item rows (stock-in / stock-out / transfers / adjustments) ──
  const addRowBtn = document.getElementById('addItemRow');
  const itemsBody = document.getElementById('itemsBody');

  addRowBtn?.addEventListener('click', () => {
    const rows = itemsBody.querySelectorAll('tr');
    const first = rows[0];
    if (!first) return;
    const idx = rows.length;
    const clone = first.cloneNode(true);

    // Remove any cloned TomSelect UI wrappers so only the raw select remains
    clone.querySelectorAll('.ts-wrapper').forEach(w => w.remove());

    // Update name attributes with new index and reset form values
    clone.querySelectorAll('[name]').forEach(el => {
      el.name = el.name.replace(/\[\d+\]/, `[${idx}]`);
      if (el.classList.contains('item-qty')) {
        el.value = '1';
      } else if (el.classList.contains('sys-qty') || el.classList.contains('phy-qty')) {
        el.value = '0';
      } else if (el.tagName === 'SELECT') {
        el.selectedIndex = 0;
        el.value = '';
      } else {
        el.value = '';
      }
    });

    // Reset adjustment difference display if present
    const diffEl = clone.querySelector('.diff-display');
    if (diffEl) {
      diffEl.value = '0';
      diffEl.classList.remove('text-danger', 'text-success');
      diffEl.classList.add('text-muted');
    }

    // Clean up classes/attributes on the select element before reinitializing
    const sel = clone.querySelector('.ts-select');
    if (sel) {
      sel.style.display = '';
      sel.removeAttribute('tabindex');
      sel.classList.remove('tomselected', 'ts-hidden-accessible');
      sel.value = '';
    }

    itemsBody.appendChild(clone);

    // Initialize Tom Select on the clean new row
    if (sel && window.TomSelect) {
      new TomSelect(sel, {
        allowEmptyOption: true,
        dropdownParent: 'body',
        placeholder: sel.querySelector('option[value=""]')?.textContent || 'Pilih...'
      });
    }

    calcTotal();
  });

  // Delegated row removal
  itemsBody?.addEventListener('click', e => {
    const btn = e.target.closest('.remove-row');
    if (!btn) return;
    const rows = itemsBody.querySelectorAll('tr');
    if (rows.length <= 1) return;
    const row = btn.closest('tr');
    const sel = row?.querySelector('.ts-select');
    if (sel?.tomselect) sel.tomselect.destroy();
    row.remove();
    calcTotal();
  });

  // ── Adjustment difference calculation ─────────────────────
  document.addEventListener('input', e => {
    if (e.target.matches('.phy-qty, .sys-qty')) {
      const row = e.target.closest('tr');
      if (!row) return;
      const sys = parseFloat(row.querySelector('.sys-qty')?.value) || 0;
      const phy = parseFloat(row.querySelector('.phy-qty')?.value) || 0;
      const diff = phy - sys;
      const diffEl = row.querySelector('.diff-display');
      if (diffEl) {
        diffEl.value = diff;
        diffEl.classList.remove('text-danger', 'text-success', 'text-muted');
        if (diff > 0) diffEl.classList.add('text-success', 'fw-bold');
        else if (diff < 0) diffEl.classList.add('text-danger', 'fw-bold');
        else diffEl.classList.add('text-muted');
      }
    }
  });

  // ── Live stock total calculation ─────────────────────────
  document.addEventListener('input', e => {
    if (e.target.matches('.item-qty, .item-price')) calcTotal();
  });
  document.addEventListener('change', e => {
    if (e.target.matches('.item-qty, .item-price')) calcTotal();
  });

  function calcTotal() {
    let total = 0;
    itemsBody?.querySelectorAll('tr').forEach(row => {
      const qty   = parseFloat(row.querySelector('.item-qty')?.value) || 0;
      const price = parseFloat(row.querySelector('.item-price')?.value) || 0;
      total += qty * price;
    });
    const el = document.getElementById('grandTotal');
    if (el) el.textContent = 'Rp ' + total.toLocaleString('id-ID');
  }

  // ── Barcode / QR scanner ──────────────────────────────────
  const scannerModalEl = document.getElementById('scannerModal');
  const scannerReader  = document.getElementById('scanner-reader');
  let scanner = null;
  let scanTarget = null;
  let scannerRunning = false;

  document.addEventListener('click', e => {
    const scanButton = e.target.closest('.scan-btn');
    if (!scanButton || !scannerModalEl) return;

    scanTarget = scanButton.closest('td')?.querySelector('.item-select');
    if (!scanTarget) return;
    bootstrap.Modal.getOrCreateInstance(scannerModalEl).show();
  });

  scannerModalEl?.addEventListener('shown.bs.modal', () => {
    if (!scannerReader || scannerRunning) return;
    if (!window.Html5Qrcode) {
      scannerReader.innerHTML = '<div class="alert alert-danger">Scanner gagal dimuat. Gunakan input manual.</div>';
      return;
    }

    scanner = new Html5Qrcode('scanner-reader');
    scannerRunning = true;
    scanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 250, height: 150 } },
      decodedText => {
        const code = decodedText.trim().toLowerCase();
        const option = [...(scanTarget?.options || [])].find(opt =>
          (opt.dataset.code || '').trim().toLowerCase() === code
        );

        if (!option) {
          scannerReader.querySelector('.scan-not-found')?.remove();
          const message = document.createElement('div');
          message.className = 'alert alert-warning scan-not-found mt-2 mb-0';
          message.textContent = `Kode "${decodedText}" tidak ditemukan di master barang.`;
          scannerReader.appendChild(message);
          return;
        }

        if (scanTarget.tomselect) {
          scanTarget.tomselect.setValue(option.value);
          scanTarget.tomselect.refreshOptions(false);
        } else {
          scanTarget.value = option.value;
        }
        scanTarget.dispatchEvent(new Event('change', { bubbles: true }));
        stopScanner(true);
      },
      () => {}
    ).catch(() => {
      scannerReader.innerHTML = '<div class="alert alert-danger">Kamera tidak dapat diakses. Pastikan izin kamera diberikan dan halaman memakai HTTPS.</div>';
      scannerRunning = false;
      scanner = null;
    });
  });

  scannerModalEl?.addEventListener('hidden.bs.modal', () => stopScanner(false));

  function stopScanner(hideModal) {
    const activeScanner = scanner;
    scanner = null;
    scannerRunning = false;
    if (activeScanner) {
      activeScanner.stop().catch(() => {}).finally(() => activeScanner.clear());
    }
    if (hideModal && scannerModalEl) {
      bootstrap.Modal.getOrCreateInstance(scannerModalEl).hide();
    }
  }

});
