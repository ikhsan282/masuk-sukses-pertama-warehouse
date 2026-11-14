<?php
/**
 * Shared HTML layout wrapper for public authentication pages (login, forgot/reset password, email verify).
 * Ensures unified Bootstrap dark-mode initialization, theme toggle button, and cache-busted assets.
 */

if (!function_exists('auth_layout_start')) {
    /**
     * Render document <head> and opening .auth-wrapper / .auth-card with theme toggle button.
     */
    function auth_layout_start(string $title, string $card_class = ''): void
    {
        $css_ver = @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1';
        $card_cls = 'auth-card' . ($card_class !== '' ? ' ' . trim($card_class) : '');
        ?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#198754">
  <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <script>(function(){try{var t=localStorage.getItem('mw_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $css_ver ?>">
</head>
<body>
<div class="auth-wrapper">
  <div class="<?= htmlspecialchars($card_cls) ?>">
    <button type="button" class="btn btn-sm btn-outline-secondary auth-theme-toggle" id="darkToggle" title="Ganti tema (Terang / Gelap)" aria-label="Ganti tema terang atau gelap">
      <i class="bi bi-moon-stars"></i>
    </button>
<?php
    }
}

if (!function_exists('auth_layout_end')) {
    /**
     * Close .auth-card / .auth-wrapper and emit theme toggle behavior plus optional page script.
     */
    function auth_layout_end(string $extra_script = ''): void
    {
        ?>  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('darkToggle');
  function syncThemeUI() {
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    if (btn) {
      var icon = btn.querySelector('i');
      if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
      btn.title = dark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap';
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
  }
  if (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('mw_theme', next); } catch (e) {}
      syncThemeUI();
    });
  }
  try {
    var mq = matchMedia('(prefers-color-scheme:dark)');
    if (mq && mq.addEventListener) {
      mq.addEventListener('change', function (ev) {
        if (!localStorage.getItem('mw_theme')) {
          document.documentElement.setAttribute('data-bs-theme', ev.matches ? 'dark' : 'light');
          syncThemeUI();
        }
      });
    }
  } catch (e) {}
  syncThemeUI();
})();
</script>
<?php if ($extra_script !== ''): ?>
<script>
<?= $extra_script ?>
</script>
<?php endif; ?>
</body>
</html>
<?php
    }
}
