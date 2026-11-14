<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('users.view');
$db = getDB();
audit_logs_ensure_table($db);
grid_apply_timeout($db, 15.0);

$default_from = date('Y-m-d', strtotime('-29 days'));
$default_to   = date('Y-m-d');
$date_from    = req_date('date_from', $default_from);
$date_to      = req_date('date_to', $default_to);
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}
$action_grp   = req_str('action_group');
$search       = grid_search_term('q');
$per_page     = grid_per_page([10, 20, 50, 100], 20);
$page         = max(1, req_int('page', 1));
$cf           = resolve_company_filter('company_id');

// Include global events (company_id IS NULL, e.g. login/export/item master) unless filtered strictly
$comp_in = $cf['sql_in'] ?: '0';
$where   = ['DATE(al.created_at) BETWEEN ? AND ?', "(al.company_id IN ($comp_in) OR al.company_id IS NULL)"];
$params  = [$date_from, $date_to];
$types   = 'ss';

$group_map = [
    'auth'        => ['login_success', 'login_failed', 'login_locked', 'login_blocked', 'login_unverified', 'logout'],
    'master'      => ['item.create', 'item.update', 'item.delete', 'items.import', 'user.create', 'user.update', 'user.delete', 'user.verify', 'role.permissions'],
    'transaction' => ['stock_in.create', 'stock_in.delete', 'stock_out.create', 'stock_out.delete', 'transfer.create', 'transfer.delete', 'supplier_return.create'],
    'approval'    => ['po.approve', 'po.reject', 'po.receive', 'adjustment.create', 'adjustment.approve', 'stock_opname.create', 'stock_opname.finalize'],
    'export'      => ['export.pdf', 'export.excel', 'export.csv', 'export.print'],
];
if ($action_grp !== '' && isset($group_map[$action_grp])) {
    $acts = $group_map[$action_grp];
    $ph   = implode(',', array_fill(0, count($acts), '?'));
    $where[] = "al.action IN ($ph)";
    foreach ($acts as $a) {
        $params[] = $a;
        $types   .= 's';
    }
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(al.username LIKE ? OR al.action LIKE ? OR al.reference_no LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$wsql = implode(' AND ', $where);
$base_sql = "FROM audit_logs al
    LEFT JOIN companies c ON c.id = al.company_id
    WHERE $wsql";

$cs = $db->prepare("SELECT COUNT(*) $base_sql");
$cs->bind_param($types, ...$params);
$cs->execute();
$cs->bind_result($total);
$cs->fetch();
$cs->close();

$pag = paginate($total, $page, $per_page);
$st = $db->prepare("SELECT al.*, c.code AS company_code, c.name AS company_name
    $base_sql
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT ? OFFSET ?");
$ptypes  = $types . 'ii';
$pparams = array_merge($params, [$pag['per_page'], $pag['offset']]);
$st->bind_param($ptypes, ...$pparams);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$filter_params = [
    'company_id'   => $cf['param_value'],
    'date_from'    => $date_from,
    'date_to'      => $date_to,
    'action_group' => $action_grp,
    'q'            => $search,
];
$export_params = array_merge($filter_params, []);

function audit_action_badge(string $action): array {
    if (str_contains($action, 'failed') || str_contains($action, 'locked') || str_contains($action, 'blocked') || str_contains($action, 'reject') || str_contains($action, 'delete')) {
        return ['danger', $action];
    }
    if (str_contains($action, 'approve') || str_contains($action, 'finalize') || str_contains($action, 'receive') || str_contains($action, 'verify')) {
        return ['success', $action];
    }
    if (str_starts_with($action, 'export.')) {
        return ['info', $action];
    }
    if (str_contains($action, 'login') || $action === 'logout') {
        return ['primary', $action];
    }
    return ['secondary', $action];
}

$page_title = 'Audit Log Aktivitas';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-journal-text me-2 text-primary"></i>Audit Log Aktivitas</h4>
    <div class="small text-muted">Jejak audit keamanan login, persetujuan dokumen, transaksi stok, dan ekspor data.</div>
  </div>
  <?= report_export_dropdown_html($export_params, 'export.php') ?>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white grid-toolbar">
    <form method="GET" class="grid-filter-form">
      <?= grid_filter_search_html($search, 'Cari username, aksi, ref, IP...') ?>
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <select name="action_group" class="form-select form-select-sm grid-select-status" aria-label="Kategori Aktivitas">
        <option value="">Semua Kategori</option>
        <option value="auth" <?= $action_grp === 'auth' ? 'selected' : '' ?>>Autentikasi &amp; Login</option>
        <option value="approval" <?= $action_grp === 'approval' ? 'selected' : '' ?>>Approval &amp; Opname</option>
        <option value="transaction" <?= $action_grp === 'transaction' ? 'selected' : '' ?>>Transaksi Gudang</option>
        <option value="master" <?= $action_grp === 'master' ? 'selected' : '' ?>>Master Data &amp; User</option>
        <option value="export" <?= $action_grp === 'export' ? 'selected' : '' ?>>Ekspor Data</option>
      </select>
      <?= grid_filter_date_range_html('date_from', $date_from, 'date_to', $date_to) ?>
      <?= grid_filter_actions_html(
          $per_page,
          $search !== '' || $action_grp !== '' || $cf['is_all'] || $date_from !== $default_from || $date_to !== $default_to
      ) ?>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Waktu</th>
          <th>Entitas PT</th>
          <th>Pengguna</th>
          <th>Aksi</th>
          <th>Referensi</th>
          <th>Keterangan</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-5">
            <i class="bi bi-journal-x fs-2 d-block mb-2 opacity-50"></i>
            Belum ada catatan aktivitas yang cocok dengan filter.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($rows as $r):
            [$badgeColor, $actLabel] = audit_action_badge((string)$r['action']);
        ?>
        <tr>
          <td class="ps-3 text-nowrap small text-muted"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
          <td>
            <?php if (!empty($r['company_code'])): ?>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="<?= e($r['company_name'] ?? '') ?>"><?= e($r['company_code']) ?></span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Global</span>
            <?php endif; ?>
          </td>
          <td><span class="fw-semibold small"><?= e($r['username'] !== '' ? $r['username'] : 'system') ?></span></td>
          <td><span class="badge bg-<?= $badgeColor ?> bg-opacity-10 text-<?= $badgeColor ?> border border-<?= $badgeColor ?> border-opacity-25"><?= e($actLabel) ?></span></td>
          <td class="small"><?= $r['reference_no'] !== '' ? '<code>' . e($r['reference_no']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= e($r['description'] !== '' ? $r['description'] : '—') ?></td>
          <td class="small text-muted font-monospace"><?= e($r['ip_address'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= grid_footer_html($pag, 'aktivitas', $filter_params) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
