<?php if ($current_user): ?>
    </div><!-- content-area -->
  </div><!-- main-content -->
</div><!-- wrapper -->
<?php endif; ?>
<script>window.APP_URL = <?= json_encode(APP_URL) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/html5-qrcode/html5-qrcode.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.ts-select').forEach(function(el) {
    if (el.tomselect) return;
    new TomSelect(el, Object.assign({
      dropdownParent: 'body',
      allowEmptyOption: true,
      placeholder: el.querySelector('option[value=""]')?.textContent || 'Pilih...'
    }, el.dataset.remoteItems && window.APP_URL ? {
      load: function(query, callback) {
        if (!query || query.length < 1) return callback();
        fetch(window.APP_URL + '/pages/items/search.php?q=' + encodeURIComponent(query))
          .then(function(r) { return r.ok ? r.json() : []; })
          .then(function(items) { callback(items); })
          .catch(function() { callback(); });
      }
    } : {}));
  });
});
</script>
</body>
</html>
