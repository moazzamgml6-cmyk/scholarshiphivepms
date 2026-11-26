<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/task_service.php';

$u = require_role('admin');

// ---------- STATS ----------
$totalActivities = (int)q("SELECT COUNT(*) FROM task_activity_logs")->fetchColumn();
$totalAssignedLogs = (int)q("SELECT COUNT(*) FROM task_activity_logs WHERE action = 'assigned'")->fetchColumn();
$totalSubmissions = (int)q("SELECT COUNT(*) FROM task_activity_logs WHERE action = 'submitted'")->fetchColumn();
$totalApprovals = (int)q("SELECT COUNT(*) FROM task_activity_logs WHERE action = 'approved'")->fetchColumn();
$totalCorrections = (int)q("SELECT COUNT(*) FROM task_activity_logs WHERE action = 'correction_requested'")->fetchColumn();

// ---------- FILTERS & PAGINATION ----------
$fq = trim((string)get('q'));
$f_action = trim((string)get('action'));
$f_role = trim((string)get('role'));
$f_from = trim((string)get('date_from'));
$f_to = trim((string)get('date_to'));

$where = ["1=1"];
$params = [];

if ($fq !== '') {
    $where[] = "(s.name LIKE ? OR s.cnic LIKE ? OR s.id = ? OR t.title LIKE ? OR tal.details LIKE ?)";
    $like = "%$fq%";
    array_push($params, $like, $like, is_numeric($fq) ? (int)$fq : 0, $like, $like);
}
if ($f_action !== '' && $f_action !== 'all') {
    $where[] = "tal.action = ?";
    $params[] = $f_action;
}
if ($f_role !== '' && $f_role !== 'all') {
    $where[] = "tal.actor_role = ?";
    $params[] = $f_role;
}
if ($f_from !== '') {
    $where[] = "tal.created_at >= ?";
    $params[] = $f_from . ' 00:00:00';
}
if ($f_to !== '') {
    $where[] = "tal.created_at <= ?";
    $params[] = $f_to . ' 23:59:59';
}

$page = max(1, (int)get('page'));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*)
             FROM task_activity_logs tal
             JOIN student_tasks st ON st.id = tal.student_task_id
             JOIN students s ON s.id = st.student_id
             JOIN tasks t ON t.id = st.task_id
             WHERE " . implode(' AND ', $where);
$totalRows = (int)q($countSql, $params)->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT tal.*,
               st.id AS student_task_id, st.status AS current_task_status, st.biometric_status,
               s.id AS student_id, s.name AS student_name, s.cnic AS student_cnic, s.branch,
               t.title AS task_title,
               u.name AS actor_user_name, u.email AS actor_user_email
        FROM task_activity_logs tal
        JOIN student_tasks st ON st.id = tal.student_task_id
        JOIN students s ON s.id = st.student_id
        JOIN tasks t ON t.id = st.task_id
        LEFT JOIN users u ON u.id = tal.actor_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY tal.id DESC LIMIT $perPage OFFSET $offset";

$logs = q($sql, $params)->fetchAll();

page_start('Task History & Audit Logs', $u, 'Super Admin Portal');
?>

<style>
.history-admin-layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 960px) {
  .history-admin-layout { grid-template-columns: 1fr; }
  .sidebar-panel { position: static !important; }
}
.sidebar-panel {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 1.25rem 1rem;
  box-shadow: var(--shadow-sm);
  position: sticky;
  top: 75px;
}
.sidebar-nav-title {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--muted);
  font-weight: 700;
  margin-bottom: 0.75rem;
  padding-left: 0.5rem;
}
.sidebar-menu {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.sidebar-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border-radius: 8px;
  color: var(--ink);
  font-weight: 500;
  font-size: 0.88rem;
  text-decoration: none;
  transition: all 0.15s ease;
}
.sidebar-item:hover {
  background: var(--bg);
  color: var(--brand);
}
.sidebar-item.active {
  background: var(--brand-subtle, rgba(37,99,235,0.08));
  color: var(--brand);
  font-weight: 700;
}
.sidebar-badge {
  font-size: 0.75rem;
  padding: 2px 7px;
  border-radius: 10px;
  background: var(--surface-raised, #e5e7eb);
  color: var(--muted);
}
.active .sidebar-badge {
  background: var(--brand);
  color: #fff;
}

/* KPI Grid */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.kpi-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 1.15rem;
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.kpi-label {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
  font-weight: 600;
}
.kpi-val {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--ink);
  margin-top: 0.35rem;
}
.kpi-sub {
  font-size: 0.78rem;
  color: var(--muted);
  margin-top: 0.25rem;
}

/* Action Badges */
.badge-assigned { background: #dbeafe; color: #1e40af; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-submitted { background: #fef3c7; color: #92400e; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-approved { background: #dcfce7; color: #166534; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-correction { background: #ffedd5; color: #9a3412; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-cancelled { background: #fee2e2; color: #991b1b; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }

/* Transition Arrow */
.status-pill {
  font-size: 0.75rem;
  padding: 2px 6px;
  border-radius: 4px;
  background: var(--bg);
  border: 1px solid var(--line);
  color: var(--ink);
  font-weight: 500;
}
</style>

<div class="task-page-header" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h1 style="font-size: 1.65rem; font-weight: 700; margin: 0 0 0.25rem 0;">Task Activity History & Audit Trail</h1>
      <p style="color: var(--muted); margin: 0; font-size: 0.92rem;">
        Complete chronological audit logs of all student task assignments, progress updates, mobile submissions, and admin reviews.
      </p>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center;">
      <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Task Assignment
      </a>
      <a href="<?= h(url('admin/notifications.php')) ?>" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        Notification Management
      </a>
    </div>
  </div>
</div>

<div class="history-admin-layout">

  <!-- LEFT NAVIGATION SIDEBAR -->
  <aside class="sidebar-panel">
    <div class="sidebar-nav-title">PMS Navigation</div>
    <div class="sidebar-menu">
      <a href="<?= h(url('admin/index.php')) ?>" class="sidebar-item">
        <span>Dashboard</span>
      </a>
      <a href="<?= h(url('admin/students.php')) ?>" class="sidebar-item">
        <span>Students</span>
      </a>
      <a href="<?= h(url('admin/task_assignment.php')) ?>" class="sidebar-item">
        <span>Task Management</span>
      </a>
      <a href="<?= h(url('admin/notifications.php')) ?>" class="sidebar-item">
        <span>Notification Management</span>
      </a>
      <a href="<?= h(url('admin/task_history.php')) ?>" class="sidebar-item active">
        <span>Task History</span>
        <span class="sidebar-badge"><?= number_format($totalActivities) ?></span>
      </a>
      <a href="<?= h(url('admin/reports.php')) ?>" class="sidebar-item">
        <span>Reports</span>
      </a>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--line); margin: 1.25rem 0;">

    <div class="sidebar-nav-title">Filter by Action</div>
    <div class="sidebar-menu">
      <a href="?action=all" class="sidebar-item <?= $f_action === 'all' || $f_action === '' ? 'active' : '' ?>">
        <span>All Activities</span>
        <span class="sidebar-badge"><?= $totalActivities ?></span>
      </a>
      <a href="?action=assigned" class="sidebar-item <?= $f_action === 'assigned' ? 'active' : '' ?>">
        <span>Assignments</span>
        <span class="sidebar-badge"><?= $totalAssignedLogs ?></span>
      </a>
      <a href="?action=submitted" class="sidebar-item <?= $f_action === 'submitted' ? 'active' : '' ?>">
        <span>Submissions</span>
        <span class="sidebar-badge"><?= $totalSubmissions ?></span>
      </a>
      <a href="?action=approved" class="sidebar-item <?= $f_action === 'approved' ? 'active' : '' ?>">
        <span>Approvals</span>
        <span class="sidebar-badge"><?= $totalApprovals ?></span>
      </a>
      <a href="?action=correction_requested" class="sidebar-item <?= $f_action === 'correction_requested' ? 'active' : '' ?>">
        <span>Corrections</span>
        <span class="sidebar-badge"><?= $totalCorrections ?></span>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT AREA -->
  <main>

    <!-- KPI STATS -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Total Task Events</div>
        <div class="kpi-val"><?= number_format($totalActivities) ?></div>
        <div class="kpi-sub">Lifetime audit entries</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #2563eb;">
        <div class="kpi-label">Tasks Assigned</div>
        <div class="kpi-val" style="color: #2563eb;"><?= number_format($totalAssignedLogs) ?></div>
        <div class="kpi-sub">Direct & template assignments</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #f59e0b;">
        <div class="kpi-label">Mobile Submissions</div>
        <div class="kpi-val" style="color: #d97706;"><?= number_format($totalSubmissions) ?></div>
        <div class="kpi-sub">Submitted by students in app</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #16a34a;">
        <div class="kpi-label">Admin Approvals</div>
        <div class="kpi-val" style="color: #16a34a;"><?= number_format($totalApprovals) ?></div>
        <div class="kpi-sub">Verified & completed tasks</div>
      </div>
    </div>

    <!-- FILTERS BAR -->
    <div class="card" style="margin-bottom: 1.5rem;">
      <form method="get" action="admin/task_history.php" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 8px; align-items: end;">
        <div>
          <label style="font-size: 0.75rem; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px;">Search Student / Task / Notes</label>
          <input type="text" name="q" value="<?= h($fq) ?>" placeholder="Search name, ID, CNIC, title..." class="form-control" style="font-size: 0.85rem;">
        </div>

        <div>
          <label style="font-size: 0.75rem; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px;">Action Event</label>
          <select name="action" class="form-control" style="font-size: 0.85rem;">
            <option value="all" <?= $f_action === 'all' || $f_action === '' ? 'selected' : '' ?>>All Actions</option>
            <option value="assigned" <?= $f_action === 'assigned' ? 'selected' : '' ?>>Assigned</option>
            <option value="submitted" <?= $f_action === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="approved" <?= $f_action === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="correction_requested" <?= $f_action === 'correction_requested' ? 'selected' : '' ?>>Correction Requested</option>
            <option value="cancelled" <?= $f_action === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <div>
          <label style="font-size: 0.75rem; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px;">Actor Role</label>
          <select name="role" class="form-control" style="font-size: 0.85rem;">
            <option value="all" <?= $f_role === 'all' || $f_role === '' ? 'selected' : '' ?>>All Roles</option>
            <option value="admin" <?= $f_role === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="officer" <?= $f_role === 'officer' ? 'selected' : '' ?>>Officer</option>
            <option value="student" <?= $f_role === 'student' ? 'selected' : '' ?>>Student</option>
            <option value="system" <?= $f_role === 'system' ? 'selected' : '' ?>>System</option>
          </select>
        </div>

        <div>
          <label style="font-size: 0.75rem; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px;">Date From</label>
          <input type="date" name="date_from" value="<?= h($f_from) ?>" class="form-control" style="font-size: 0.85rem;">
        </div>

        <div style="display: flex; gap: 4px;">
          <button type="submit" class="btn btn-sm btn-outline">Filter</button>
          <?php if ($fq !== '' || ($f_action !== '' && $f_action !== 'all') || ($f_role !== '' && $f_role !== 'all') || $f_from !== ''): ?>
            <a href="<?= h(url('admin/task_history.php')) ?>" class="btn btn-sm btn-ghost">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- LOGS TABLE -->
    <div class="card">
      <div class="card-header" style="border-bottom: 1px solid var(--line); padding-bottom: 0.75rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0;">Task Audit Trail (<?= number_format($totalRows) ?> entries)</h2>
        <span style="font-size: 0.8rem; color: var(--muted);">Recorded automatically on every status transition</span>
      </div>

      <div class="table-responsive">
        <table class="table" style="font-size: 0.86rem; width: 100%;">
          <thead>
            <tr>
              <th style="width: 50px;">#</th>
              <th>Date & Time</th>
              <th>Task</th>
              <th>Student</th>
              <th>Action</th>
              <th>Status Transition</th>
              <th>Actor</th>
              <th>Details & Notes</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr>
                <td colspan="9" style="text-align: center; color: var(--muted); padding: 2.5rem;">
                  No task activity logs match your filter criteria.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($logs as $log): ?>
                <tr>
                  <td><?= $log['id'] ?></td>
                  <td style="white-space: nowrap; color: var(--muted); font-size: 0.8rem;">
                    <?= h(substr($log['created_at'], 0, 16)) ?>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--ink);">
                      <?= h($log['task_title']) ?>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--muted);">
                      Task #<?= $log['student_task_id'] ?>
                    </div>
                  </td>
                  <td>
                    <a href="<?= h(url('admin/student.php?id=' . $log['student_id'])) ?>" style="font-weight: 600;">
                      <?= h($log['student_name']) ?>
                    </a>
                    <div style="font-size: 0.75rem; color: var(--muted);">
                      ID: #<?= $log['student_id'] ?><?= !empty($log['student_cnic']) ? ' Â· ' . h($log['student_cnic']) : '' ?>
                    </div>
                  </td>
                  <td>
                    <?php
                    $act = $log['action'];
                    $actClass = match($act) {
                      'assigned'             => 'badge-assigned',
                      'submitted'            => 'badge-submitted',
                      'approved'             => 'badge-approved',
                      'correction_requested' => 'badge-correction',
                      'cancelled'            => 'badge-cancelled',
                      default                => 'badge-assigned',
                    };
                    ?>
                    <span class="<?= $actClass ?>"><?= h(ucwords(str_replace('_', ' ', $act))) ?></span>
                  </td>
                  <td>
                    <?php if (!empty($log['previous_status']) || !empty($log['new_status'])): ?>
                      <div style="display: flex; align-items: center; gap: 4px; font-size: 0.78rem;">
                        <span class="status-pill"><?= h($log['previous_status'] ?: 'â€”') ?></span>
                        <span style="color: var(--muted);">&rarr;</span>
                        <span class="status-pill" style="font-weight: 700;"><?= h($log['new_status'] ?: 'â€”') ?></span>
                      </div>
                    <?php else: ?>
                      <span style="color: var(--muted); font-size: 0.78rem;">â€”</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-weight: 500;">
                      <?= h($log['actor_user_name'] ?: ($log['actor_role'] === 'student' ? $log['student_name'] : 'System')) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--muted); text-transform: capitalize;">
                      <?= h($log['actor_role']) ?>
                    </div>
                  </td>
                  <td>
                    <div style="max-width: 260px; font-size: 0.82rem; color: var(--ink); word-break: break-word;">
                      <?= h($log['details'] ?: 'â€”') ?>
                    </div>
                  </td>
                  <td style="font-size: 0.75rem; font-family: monospace; color: var(--muted);">
                    <?= h($log['ip_address'] ?: 'â€”') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--line);">
          <div style="font-size: 0.85rem; color: var(--muted);">
            Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> total events)
          </div>
          <div style="display: flex; gap: 4px;">
            <?php if ($page > 1): ?>
              <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($fq) ?>&action=<?= urlencode($f_action) ?>&role=<?= urlencode($f_role) ?>&date_from=<?= urlencode($f_from) ?>" class="btn btn-xs btn-outline">&laquo; Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($fq) ?>&action=<?= urlencode($f_action) ?>&role=<?= urlencode($f_role) ?>&date_from=<?= urlencode($f_from) ?>" class="btn btn-xs btn-outline">Next &raquo;</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

  </main>

</div>

<?php
// Layout footer
?>
</div>
</div>
</body>
</html>
