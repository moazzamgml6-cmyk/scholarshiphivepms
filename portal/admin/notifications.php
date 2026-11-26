<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/fcm_service.php';

$u = require_role('admin');

// ---------- POST ACTION HANDLERS ----------
if (is_post()) {
    csrf_check();
    $action = post('action');

    // 1. Send / Broadcast Notification
    if ($action === 'send_notification') {
        $targetMode = post('target_mode') ?: 'single';
        $title = trim((string)post('title'));
        $message = trim((string)post('message'));
        $type = post('notification_type') ?: 'announcement';
        $imageUrl = trim((string)post('image_url')) ?: null;

        if ($title === '') {
            flash('Notification title is required.', 'bad');
            redirect('admin/notifications.php#send-form');
        }
        if ($message === '') {
            flash('Notification message body is required.', 'bad');
            redirect('admin/notifications.php#send-form');
        }

        $studentIds = [];
        if ($targetMode === 'single') {
            $sid = (int)post('student_id');
            if ($sid > 0) {
                $studentIds[] = $sid;
            } else {
                flash('Please select a student recipient.', 'bad');
                redirect('admin/notifications.php#send-form');
            }
        } elseif ($targetMode === 'multiple') {
            $rawIds = post('student_ids') ?: [];
            if (is_array($rawIds)) {
                foreach ($rawIds as $id) {
                    $val = (int)$id;
                    if ($val > 0) $studentIds[] = $val;
                }
            }
            if (empty($studentIds)) {
                flash('Please select at least one student recipient.', 'bad');
                redirect('admin/notifications.php#send-form');
            }
        } elseif ($targetMode === 'all') {
            // All active students
            $rows = q("SELECT id FROM students WHERE csc_status != 'Cancelled' OR csc_status IS NULL")->fetchAll();
            foreach ($rows as $r) {
                $studentIds[] = (int)$r['id'];
            }
            if (empty($studentIds)) {
                flash('No active students found in database.', 'bad');
                redirect('admin/notifications.php#send-form');
            }
        }

        try {
            $res = PmsNotificationService::broadcastCustomNotification(
                $studentIds,
                $title,
                $message,
                $type,
                $imageUrl,
                (int)$u['id']
            );

            $recipCount = $res['total_recipients'];
            $sentCount = $res['sent_count'];
            $failedCount = $res['failed_count'];
            $noTokenCount = $res['no_token_count'];

            $statusMsg = "Broadcast dispatched to {$recipCount} student(s): {$sentCount} push delivered, {$noTokenCount} without registered device, {$failedCount} delivery errors. In-app notifications saved for all.";
            flash($statusMsg, $sentCount > 0 ? 'ok' : 'bad');
        } catch (Exception $e) {
            flash('Error broadcasting notification: ' . $e->getMessage(), 'bad');
        }
        redirect('admin/notifications.php');
    }

    // 2. Retry Single Failed Notification
    if ($action === 'retry_notification') {
        $logId = (int)post('log_id');
        try {
            $res = PmsNotificationService::retryNotification($logId);
            if (!empty($res['success'])) {
                flash("Notification #{$logId} retry succeeded! Delivered to student device.", 'ok');
            } else {
                flash("Notification #{$logId} retry failed: " . ($res['error'] ?? 'Unknown error'), 'bad');
            }
        } catch (Exception $e) {
            flash("Error retrying notification: " . $e->getMessage(), 'bad');
        }
        redirect('admin/notifications.php#logs-section');
    }

    // 3. Retry All Failed Notifications
    if ($action === 'retry_all_failed') {
        try {
            $res = PmsNotificationService::retryAllFailed();
            $retried = $res['retried'];
            $succeeded = $res['succeeded'];
            $failed = $res['failed'];
            flash("Batch retry finished: {$retried} attempted, {$succeeded} succeeded, {$failed} failed.", $succeeded > 0 ? 'ok' : 'bad');
        } catch (Exception $e) {
            flash("Error during batch retry: " . $e->getMessage(), 'bad');
        }
        redirect('admin/notifications.php#logs-section');
    }
}

// ---------- METRICS & STATS ----------
$totalLogs = (int)q("SELECT COUNT(*) FROM notification_logs")->fetchColumn();
$totalSent = (int)q("SELECT COUNT(*) FROM notification_logs WHERE status = 'sent'")->fetchColumn();
$totalFailed = (int)q("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed'")->fetchColumn();
$totalNoToken = (int)q("SELECT COUNT(*) FROM notification_logs WHERE status = 'no_token'")->fetchColumn();
$activeTokens = (int)q("SELECT COUNT(*) FROM device_tokens WHERE is_active = 1")->fetchColumn();
$totalInApp = (int)q("SELECT COUNT(*) FROM notifications")->fetchColumn();

// ---------- FILTER & PAGINATION FOR DELIVERY LOGS ----------
$fq = trim((string)get('q'));
$f_status = trim((string)get('status'));
$f_type = trim((string)get('type'));

$where = ["1=1"];
$params = [];

if ($fq !== '') {
    $where[] = "(s.name LIKE ? OR s.cnic LIKE ? OR s.email LIKE ? OR nl.student_id = ? OR nl.title LIKE ? OR nl.body LIKE ?)";
    $like = "%$fq%";
    array_push($params, $like, $like, $like, is_numeric($fq) ? (int)$fq : 0, $like, $like);
}
if ($f_status !== '' && $f_status !== 'all') {
    $where[] = "nl.status = ?";
    $params[] = $f_status;
}
if ($f_type !== '' && $f_type !== 'all') {
    $where[] = "nl.notification_type = ?";
    $params[] = $f_type;
}

$page = max(1, (int)get('page'));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)q("SELECT COUNT(*) FROM notification_logs nl LEFT JOIN students s ON s.id = nl.student_id WHERE " . implode(' AND ', $where), $params)->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logs = q("SELECT nl.*, s.name AS student_name, s.cnic AS student_cnic, s.email AS student_email, s.branch, s.intake
           FROM notification_logs nl
           LEFT JOIN students s ON s.id = nl.student_id
           WHERE " . implode(' AND ', $where) . "
           ORDER BY nl.id DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

// Students list with registered device indicator for recipient selection
$studentsWithTokens = q("SELECT s.id, s.name, s.cnic, s.email, s.branch, s.intake, s.counselor,
                                (SELECT COUNT(*) FROM device_tokens dt WHERE dt.student_id = s.id AND dt.is_active = 1) AS token_count
                         FROM students s
                         ORDER BY s.name ASC")->fetchAll();

$branches = array_unique(array_filter(array_column($studentsWithTokens, 'branch')));
$intakes = array_unique(array_filter(array_column($studentsWithTokens, 'intake')));

page_start('Notification Management', $u, 'Super Admin Portal');
?>

<style>
.notif-admin-layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 960px) {
  .notif-admin-layout { grid-template-columns: 1fr; }
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

/* Form & Live Preview Columns */
.send-container {
  display: grid;
  grid-template-columns: 1.25fr 0.95fr;
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 900px) {
  .send-container { grid-template-columns: 1fr; }
}

/* Phone Mockup Preview */
.phone-mockup {
  background: #0f172a;
  border-radius: 28px;
  padding: 18px 14px;
  box-shadow: 0 20px 40px rgba(0,0,0,0.25), inset 0 0 0 2px #334155;
  color: #f8fafc;
  width: 100%;
  max-width: 360px;
  margin: 0 auto;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.phone-notch {
  width: 110px;
  height: 18px;
  background: #1e293b;
  border-radius: 0 0 10px 10px;
  margin: -10px auto 14px auto;
}
.phone-status-bar {
  display: flex;
  justify-content: space-between;
  font-size: 0.7rem;
  color: #94a3b8;
  padding: 0 8px 12px 8px;
}
.phone-notif-card {
  background: rgba(30, 41, 59, 0.95);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.35);
  backdrop-filter: blur(10px);
}
.phone-notif-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.75rem;
  color: #94a3b8;
  margin-bottom: 6px;
}
.phone-app-icon {
  width: 18px;
  height: 18px;
  border-radius: 4px;
  background: var(--brand, #2563eb);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: #fff;
  font-weight: bold;
}
.phone-notif-title {
  font-size: 0.92rem;
  font-weight: 700;
  color: #ffffff;
  margin-bottom: 4px;
  line-height: 1.3;
  word-break: break-word;
}
.phone-notif-body {
  font-size: 0.82rem;
  color: #cbd5e1;
  line-height: 1.35;
  word-break: break-word;
}
.phone-notif-footer {
  margin-top: 8px;
  font-size: 0.7rem;
  color: #64748b;
  display: flex;
  justify-content: space-between;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding-top: 6px;
}

/* Recipient Selector Styles */
.recipient-list-box {
  max-height: 240px;
  overflow-y: auto;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--bg);
  padding: 6px;
}
.recipient-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  transition: background 0.1s ease;
}
.recipient-item:hover {
  background: var(--surface);
}
.recipient-item label {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  flex: 1;
}

/* Target mode pills */
.target-mode-group {
  display: flex;
  gap: 8px;
  margin-bottom: 1rem;
}
.target-mode-pill {
  flex: 1;
  padding: 10px;
  text-align: center;
  border: 2px solid var(--line);
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
  font-size: 0.85rem;
  transition: all 0.15s ease;
  user-select: none;
}
.target-mode-pill.active {
  border-color: var(--brand);
  background: var(--brand-subtle, rgba(37,99,235,0.06));
  color: var(--brand);
}

/* Badges */
.badge-sent { background: #dcfce7; color: #166534; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-failed { background: #fee2e2; color: #991b1b; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-notoken { background: #fef3c7; color: #92400e; font-weight: 600; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; }
.badge-announcement { background: #e0e7ff; color: #3730a3; padding: 2px 7px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
.badge-task { background: #dbeafe; color: #1e40af; padding: 2px 7px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
.badge-alert { background: #ffe4e6; color: #9f1239; padding: 2px 7px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
</style>

<div class="task-page-header" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h1 style="font-size: 1.65rem; font-weight: 700; margin: 0 0 0.25rem 0;">Push Notification & Alert Management</h1>
      <p style="color: var(--muted); margin: 0; font-size: 0.92rem;">
        Directly broadcast instant Firebase Cloud Messaging (FCM) push alerts and announcements to students' Flutter mobile apps.
      </p>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center;">
      <a href="#send-form" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        Compose Notification
      </a>
      <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Task Assignment
      </a>
    </div>
  </div>
</div>

<div class="notif-admin-layout">

  <!-- LEFT NAVIGATION SIDEBAR -->
  <aside class="sidebar-panel">
    <div class="sidebar-nav-title">PMS Navigation</div>
    <div class="sidebar-menu">
      <a href="<?= h(url('admin/index.php')) ?>" class="sidebar-item">
        <span>Dashboard</span>
      </a>
      <a href="<?= h(url('admin/students.php')) ?>" class="sidebar-item">
        <span>Students</span>
        <span class="sidebar-badge"><?= count($studentsWithTokens) ?></span>
      </a>
      <a href="<?= h(url('admin/task_assignment.php')) ?>" class="sidebar-item">
        <span>Task Management</span>
      </a>
      <a href="<?= h(url('admin/notifications.php')) ?>" class="sidebar-item active">
        <span>Notification Management</span>
        <span class="sidebar-badge"><?= $totalLogs ?></span>
      </a>
      <a href="<?= h(url('admin/task_history.php')) ?>" class="sidebar-item">
        <span>Task History</span>
      </a>
      <a href="<?= h(url('admin/reports.php')) ?>" class="sidebar-item">
        <span>Reports</span>
      </a>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--line); margin: 1.25rem 0;">

    <div class="sidebar-nav-title">Quick Actions</div>
    <div class="sidebar-menu">
      <a href="#send-form" class="sidebar-item">
        <span>+ Send Broadcast</span>
      </a>
      <a href="#logs-section" class="sidebar-item">
        <span>View Delivery Logs</span>
      </a>
      <a href="?status=failed#logs-section" class="sidebar-item">
        <span>Failed Deliveries</span>
        <?php if ($totalFailed > 0): ?>
          <span class="sidebar-badge" style="background: #fee2e2; color: #991b1b;"><?= $totalFailed ?></span>
        <?php endif; ?>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT AREA -->
  <main>

    <!-- KPI STATS CARDS -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Total Push Dispatched</div>
        <div class="kpi-val"><?= number_format($totalLogs) ?></div>
        <div class="kpi-sub"><?= number_format($totalInApp) ?> in-app notifications</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #16a34a;">
        <div class="kpi-label">Delivered / Sent</div>
        <div class="kpi-val" style="color: #16a34a;"><?= number_format($totalSent) ?></div>
        <div class="kpi-sub">FCM confirmed delivery</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #dc2626;">
        <div class="kpi-label">Failed Deliveries</div>
        <div class="kpi-val" style="color: #dc2626;"><?= number_format($totalFailed) ?></div>
        <div class="kpi-sub">Retryable notifications</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #f59e0b;">
        <div class="kpi-label">No Registered Token</div>
        <div class="kpi-val" style="color: #d97706;"><?= number_format($totalNoToken) ?></div>
        <div class="kpi-sub">In-app preserved in portal</div>
      </div>
      <div class="kpi-card" style="border-left: 4px solid #2563eb;">
        <div class="kpi-label">Active Student Devices</div>
        <div class="kpi-val" style="color: #2563eb;"><?= number_format($activeTokens) ?></div>
        <div class="kpi-sub">Flutter FCM tokens active</div>
      </div>
    </div>

    <!-- COMPOSE & PREVIEW SECTION -->
    <div class="card" id="send-form" style="margin-bottom: 2rem;">
      <div class="card-header" style="border-bottom: 1px solid var(--line); padding-bottom: 1rem; margin-bottom: 1.25rem;">
        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 0.25rem 0;">Compose & Broadcast Push Notification</h2>
        <p style="color: var(--muted); margin: 0; font-size: 0.88rem;">
          Send real-time alerts directly to students' Flutter application and lock permanent records in database.
        </p>
      </div>

      <div class="send-container">
        
        <!-- LEFT: FORM -->
        <form method="post" id="broadcastForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_notification">

          <!-- Target Mode Selector -->
          <label style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.5rem;">Target Audience</label>
          <div class="target-mode-group">
            <div class="target-mode-pill active" onclick="setTargetMode('single')">
              <input type="radio" name="target_mode" value="single" id="modeSingle" checked style="display: none;">
              Single Student
            </div>
            <div class="target-mode-pill" onclick="setTargetMode('multiple')">
              <input type="radio" name="target_mode" value="multiple" id="modeMultiple" style="display: none;">
              Multiple Students
            </div>
            <div class="target-mode-pill" onclick="setTargetMode('all')">
              <input type="radio" name="target_mode" value="all" id="modeAll" style="display: none;">
              All Active Students
            </div>
          </div>

          <!-- Single Student Dropdown -->
          <div id="singleStudentContainer" style="margin-bottom: 1rem;">
            <label for="studentSelect" style="font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Select Student Recipient <span style="color: #dc2626;">*</span></label>
            <select name="student_id" id="studentSelect" class="form-control" style="width: 100%;" onchange="updateSelectedInfo()">
              <option value="">-- Choose Student by Name, ID, or CNIC --</option>
              <?php foreach ($studentsWithTokens as $s): ?>
                <option value="<?= $s['id'] ?>" data-tokens="<?= $s['token_count'] ?>" data-name="<?= h($s['name']) ?>" data-cnic="<?= h($s['cnic'] ?? '') ?>" data-branch="<?= h($s['branch'] ?? '') ?>">
                  <?= h($s['name']) ?> (ID: #<?= $s['id'] ?><?= !empty($s['cnic']) ? ' Â· ' . h($s['cnic']) : '' ?>) <?= $s['token_count'] > 0 ? 'ðŸ“± Active' : 'âš  No Token' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Multiple Students Search & Multi-Select Box -->
          <div id="multiStudentContainer" style="display: none; margin-bottom: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
              <label style="font-weight: 600; font-size: 0.85rem;">Select Students (<span id="selectedCounter">0</span> selected)</label>
              <div style="display: flex; gap: 6px;">
                <button type="button" class="btn btn-xs btn-outline" onclick="selectAllFiltered(true)">Select All</button>
                <button type="button" class="btn btn-xs btn-outline" onclick="selectAllFiltered(false)">Clear</button>
              </div>
            </div>

            <!-- Filters -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 6px; margin-bottom: 6px;">
              <input type="text" id="multiSearchInput" class="form-control" placeholder="Search name, ID, CNIC..." oninput="filterRecipientList()" style="font-size: 0.82rem; padding: 6px 10px;">
              <select id="branchFilter" class="form-control" onchange="filterRecipientList()" style="font-size: 0.82rem; padding: 6px 10px;">
                <option value="">All Branches</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= h($b) ?>"><?= h($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Scrollable Checklist -->
            <div class="recipient-list-box" id="recipientChecklist">
              <?php foreach ($studentsWithTokens as $s): ?>
                <div class="recipient-item" data-search="<?= strtolower(h($s['name'] . ' ' . $s['id'] . ' ' . ($s['cnic'] ?? '') . ' ' . ($s['branch'] ?? ''))) ?>" data-branch="<?= h($s['branch'] ?? '') ?>">
                  <label>
                    <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>" onchange="updateSelectedCounter()">
                    <span>
                      <strong><?= h($s['name']) ?></strong> <span style="color: var(--muted); font-size: 0.78rem;">#<?= $s['id'] ?></span>
                      <?php if (!empty($s['branch'])): ?>
                        <span style="font-size: 0.72rem; color: var(--muted);">Â· <?= h($s['branch']) ?></span>
                      <?php endif; ?>
                    </span>
                  </label>
                  <?php if ($s['token_count'] > 0): ?>
                    <span style="font-size: 0.72rem; color: #16a34a; font-weight: 600;">âœ“ Registered</span>
                  <?php else: ?>
                    <span style="font-size: 0.72rem; color: #d97706;">No Device</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- All Students Broadcast Warning -->
          <div id="allStudentsContainer" style="display: none; margin-bottom: 1rem; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px;">
            <div style="display: flex; gap: 8px; align-items: center; color: #1e40af; font-size: 0.88rem; font-weight: 600;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span>Broadcast to All <?= count($studentsWithTokens) ?> Active Students</span>
            </div>
            <p style="margin: 6px 0 0 0; font-size: 0.82rem; color: #1e40af;">
              Every active student enrolled in ScholarshipHive PMS will receive this push notification on their Android/iOS Flutter app and in their web student dashboard.
            </p>
          </div>

          <!-- Notification Type & Title -->
          <div style="display: grid; grid-template-columns: 140px 1fr; gap: 10px; margin-bottom: 1rem;">
            <div>
              <label for="notifType" style="font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Type</label>
              <select name="notification_type" id="notifType" class="form-control" onchange="updateLivePreview()">
                <option value="announcement">Announcement</option>
                <option value="task_reminder">Task Reminder</option>
                <option value="visa_update">Visa Update</option>
                <option value="urgent_alert">Urgent Alert</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <div>
              <label for="notifTitle" style="font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Notification Title <span style="color: #dc2626;">*</span></label>
              <input type="text" name="title" id="notifTitle" class="form-control" placeholder="e.g. Scholarship Interview Schedule" required maxlength="100" oninput="updateLivePreview()">
            </div>
          </div>

          <!-- Message Body -->
          <div style="margin-bottom: 1rem;">
            <label for="notifBody" style="font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Message Body <span style="color: #dc2626;">*</span></label>
            <textarea name="message" id="notifBody" rows="3" class="form-control" placeholder="Write the complete notification message that students will read..." required maxlength="400" oninput="updateLivePreview()"></textarea>
            <div style="display: flex; justify-content: space-between; margin-top: 4px; font-size: 0.75rem; color: var(--muted);">
              <span>Direct Flutter push notification</span>
              <span id="charCounter">0 / 400</span>
            </div>
          </div>

          <!-- Optional Image URL -->
          <div style="margin-bottom: 1.25rem;">
            <label for="notifImage" style="font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Optional Image / Banner URL</label>
            <input type="url" name="image_url" id="notifImage" class="form-control" placeholder="https://example.com/banner.jpg" style="font-size: 0.85rem;">
          </div>

          <!-- Submit Button -->
          <button type="button" class="btn btn-primary" onclick="confirmBroadcastSubmit()" style="width: 100%; padding: 10px; font-weight: 700; display: inline-flex; justify-content: center; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Send Push Notification Now
          </button>
        </form>

        <!-- RIGHT: LIVE INTERACTIVE SMARTPHONE NOTIFICATION PREVIEW -->
        <div>
          <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px; color: var(--muted);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
            <span>Live Student Smartphone Preview</span>
          </div>

          <div class="phone-mockup">
            <div class="phone-notch"></div>
            <div class="phone-status-bar">
              <span>9:41</span>
              <span>LTE â–ª 100%</span>
            </div>

            <!-- Phone notification card -->
            <div class="phone-notif-card">
              <div class="phone-notif-header">
                <div class="phone-app-icon">SH</div>
                <strong style="color: #f8fafc;">ScholarshipHive PMS</strong>
                <span>â€¢ Just now</span>
                <span id="previewTypeBadge" style="margin-left: auto; font-size: 0.65rem; background: rgba(37,99,235,0.3); color: #93c5fd; padding: 2px 6px; border-radius: 4px;">Announcement</span>
              </div>
              <div class="phone-notif-title" id="previewTitle">Scholarship Notification Title</div>
              <div class="phone-notif-body" id="previewBody">Compose your message to view real-time student mobile appearance here.</div>
              <div class="phone-notif-footer">
                <span>Tap to open ScholarshipHive Flutter App</span>
                <span style="color: #38bdf8;">PMS Mobile</span>
              </div>
            </div>

            <div style="margin-top: 2rem; text-align: center; color: #64748b; font-size: 0.75rem;">
              <span>â—‹ Home Indicator</span>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- DELIVERY LOGS & AUDIT SECTION -->
    <div class="card" id="logs-section">
      <div class="card-header" style="border-bottom: 1px solid var(--line); padding-bottom: 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
        <div>
          <h2 style="font-size: 1.2rem; font-weight: 700; margin: 0 0 0.25rem 0;">Push Delivery Logs & Audit Trail</h2>
          <p style="color: var(--muted); margin: 0; font-size: 0.85rem;">
            Real-time delivery verification across all student devices. Failed deliveries can be re-dispatched instantly.
          </p>
        </div>
        <?php if ($totalFailed > 0): ?>
          <form method="post" onsubmit="return confirm('Retry all <?= $totalFailed ?> failed notifications?');" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="retry_all_failed">
            <button type="submit" class="btn btn-sm btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
              Retry All Failed (<?= $totalFailed ?>)
            </button>
          </form>
        <?php endif; ?>
      </div>

      <!-- Filters Bar -->
      <form method="get" action="admin/notifications.php" style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem; margin-bottom: 1rem; display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 8px;">
        <input type="text" name="q" value="<?= h($fq) ?>" placeholder="Search student name, ID, CNIC, or title..." class="form-control" style="font-size: 0.85rem;">
        
        <select name="status" class="form-control" style="font-size: 0.85rem;">
          <option value="all" <?= $f_status === 'all' || $f_status === '' ? 'selected' : '' ?>>All Statuses</option>
          <option value="sent" <?= $f_status === 'sent' ? 'selected' : '' ?>>Delivered / Sent</option>
          <option value="failed" <?= $f_status === 'failed' ? 'selected' : '' ?>>Failed</option>
          <option value="no_token" <?= $f_status === 'no_token' ? 'selected' : '' ?>>No Device Token</option>
        </select>

        <select name="type" class="form-control" style="font-size: 0.85rem;">
          <option value="all" <?= $f_type === 'all' || $f_type === '' ? 'selected' : '' ?>>All Types</option>
          <option value="task_assigned" <?= $f_type === 'task_assigned' ? 'selected' : '' ?>>Task Assigned</option>
          <option value="announcement" <?= $f_type === 'announcement' ? 'selected' : '' ?>>Announcement</option>
          <option value="task_reminder" <?= $f_type === 'task_reminder' ? 'selected' : '' ?>>Task Reminder</option>
          <option value="urgent_alert" <?= $f_type === 'urgent_alert' ? 'selected' : '' ?>>Urgent Alert</option>
        </select>

        <div style="display: flex; gap: 4px;">
          <button type="submit" class="btn btn-sm btn-outline">Filter</button>
          <?php if ($fq !== '' || ($f_status !== '' && $f_status !== 'all') || ($f_type !== '' && $f_type !== 'all')): ?>
            <a href="<?= h(url('admin/notifications.php')) ?>" class="btn btn-sm btn-ghost">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Logs Table -->
      <div class="table-responsive">
        <table class="table" style="font-size: 0.86rem; width: 100%;">
          <thead>
            <tr>
              <th style="width: 50px;">#</th>
              <th>Timestamp</th>
              <th>Student Recipient</th>
              <th>Notification</th>
              <th>Type</th>
              <th>Status</th>
              <th>Retries</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr>
                <td colspan="8" style="text-align: center; color: var(--muted); padding: 2rem;">
                  No notification logs found matching your filters.
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
                    <?php if (!empty($log['student_name'])): ?>
                      <a href="<?= h(url('admin/student.php?id=' . $log['student_id'])) ?>" style="font-weight: 600;">
                        <?= h($log['student_name']) ?>
                      </a>
                      <div style="font-size: 0.75rem; color: var(--muted);">
                        ID: #<?= $log['student_id'] ?><?= !empty($log['student_cnic']) ? ' Â· ' . h($log['student_cnic']) : '' ?>
                      </div>
                    <?php else: ?>
                      <span style="color: var(--muted);">System / Student #<?= $log['student_id'] ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--ink);"><?= h($log['title']) ?></div>
                    <div style="color: var(--muted); font-size: 0.8rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                      <?= h($log['body']) ?>
                    </div>
                  </td>
                  <td>
                    <?php 
                    $t = $log['notification_type'];
                    $tClass = match($t) {
                      'task_assigned' => 'badge-task',
                      'announcement'  => 'badge-announcement',
                      'urgent_alert'  => 'badge-alert',
                      default         => 'badge-announcement',
                    };
                    ?>
                    <span class="<?= $tClass ?>"><?= h(ucwords(str_replace('_', ' ', $t))) ?></span>
                  </td>
                  <td>
                    <?php if ($log['status'] === 'sent'): ?>
                      <span class="badge-sent">âœ“ Delivered</span>
                    <?php elseif ($log['status'] === 'failed'): ?>
                      <span class="badge-failed" title="<?= h($log['error_message']) ?>">âœ• Failed</span>
                      <?php if (!empty($log['error_message'])): ?>
                        <div style="font-size: 0.72rem; color: #dc2626; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                          <?= h($log['error_message']) ?>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge-notoken" title="No active mobile device registered">No Device</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: center; font-size: 0.8rem;"><?= (int)$log['retry_count'] ?></td>
                  <td style="text-align: right;">
                    <?php if ($log['status'] !== 'sent'): ?>
                      <form method="post" style="display: inline-block; margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retry_notification">
                        <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline" title="Retry push notification delivery">
                          Retry
                        </button>
                      </form>
                    <?php else: ?>
                      <span style="color: var(--muted); font-size: 0.75rem;">Delivered</span>
                    <?php endif; ?>
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
            Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> total logs)
          </div>
          <div style="display: flex; gap: 4px;">
            <?php if ($page > 1): ?>
              <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($fq) ?>&status=<?= urlencode($f_status) ?>&type=<?= urlencode($f_type) ?>#logs-section" class="btn btn-xs btn-outline">&laquo; Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($fq) ?>&status=<?= urlencode($f_status) ?>&type=<?= urlencode($f_type) ?>#logs-section" class="btn btn-xs btn-outline">Next &raquo;</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

  </main>

</div>

<!-- BROADCAST CONFIRMATION MODAL -->
<div id="broadcastModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
  <div style="background: var(--surface); border-radius: 12px; width: 90%; max-width: 480px; padding: 1.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.3); border: 1px solid var(--line);">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
      <div style="width: 36px; height: 36px; border-radius: 50%; background: #dbeafe; color: #1e40af; display: flex; align-items: center; justify-content: center;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
      </div>
      <div>
        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700;">Confirm Push Broadcast</h3>
        <p style="margin: 0; font-size: 0.82rem; color: var(--muted);">Direct push notification dispatch</p>
      </div>
    </div>

    <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 12px; margin-bottom: 1.25rem;">
      <div style="font-size: 0.85rem; margin-bottom: 6px;">
        <strong>Target Recipients:</strong> <span id="modalRecipCount" style="color: var(--brand); font-weight: 700;">1 student</span>
      </div>
      <div style="font-size: 0.85rem; margin-bottom: 6px;">
        <strong>Notification Title:</strong> <span id="modalTitlePreview" style="font-weight: 600;"></span>
      </div>
      <div style="font-size: 0.82rem; color: var(--muted); max-height: 80px; overflow-y: auto;">
        <strong>Message:</strong> <span id="modalBodyPreview"></span>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px;">
      <button type="button" class="btn btn-outline" onclick="closeBroadcastModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitBroadcastFormConfirmed()">Confirm & Send</button>
    </div>
  </div>
</div>

<script>
let currentTargetMode = 'single';

function setTargetMode(mode) {
  currentTargetMode = mode;
  document.querySelectorAll('.target-mode-pill').forEach(el => el.classList.remove('active'));
  
  const singleContainer = document.getElementById('singleStudentContainer');
  const multiContainer = document.getElementById('multiStudentContainer');
  const allContainer = document.getElementById('allStudentsContainer');

  if (mode === 'single') {
    document.getElementById('modeSingle').checked = true;
    event.currentTarget.classList.add('active');
    singleContainer.style.display = 'block';
    multiContainer.style.display = 'none';
    allContainer.style.display = 'none';
  } else if (mode === 'multiple') {
    document.getElementById('modeMultiple').checked = true;
    event.currentTarget.classList.add('active');
    singleContainer.style.display = 'none';
    multiContainer.style.display = 'block';
    allContainer.style.display = 'none';
  } else if (mode === 'all') {
    document.getElementById('modeAll').checked = true;
    event.currentTarget.classList.add('active');
    singleContainer.style.display = 'none';
    multiContainer.style.display = 'none';
    allContainer.style.display = 'block';
  }
}

function updateLivePreview() {
  const title = document.getElementById('notifTitle').value.trim();
  const body = document.getElementById('notifBody').value.trim();
  const type = document.getElementById('notifType').value;

  document.getElementById('previewTitle').textContent = title || 'Scholarship Notification Title';
  document.getElementById('previewBody').textContent = body || 'Compose your message to view real-time student mobile appearance here.';
  
  const typeNames = {
    'announcement': 'Announcement',
    'task_reminder': 'Task Reminder',
    'visa_update': 'Visa Update',
    'urgent_alert': 'Urgent Alert',
    'custom': 'Alert'
  };
  document.getElementById('previewTypeBadge').textContent = typeNames[type] || 'Alert';
  document.getElementById('charCounter').textContent = body.length + ' / 400';
}

function filterRecipientList() {
  const q = document.getElementById('multiSearchInput').value.toLowerCase().trim();
  const branch = document.getElementById('branchFilter').value;
  const items = document.querySelectorAll('.recipient-item');

  items.forEach(item => {
    const searchData = item.getAttribute('data-search') || '';
    const itemBranch = item.getAttribute('data-branch') || '';
    const matchSearch = q === '' || searchData.includes(q);
    const matchBranch = branch === '' || itemBranch === branch;

    if (matchSearch && matchBranch) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
}

function selectAllFiltered(select) {
  const items = document.querySelectorAll('.recipient-item');
  items.forEach(item => {
    if (item.style.display !== 'none') {
      const cb = item.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = select;
    }
  });
  updateSelectedCounter();
}

function updateSelectedCounter() {
  const checked = document.querySelectorAll('#recipientChecklist input[type="checkbox"]:checked');
  document.getElementById('selectedCounter').textContent = checked.length;
}

function confirmBroadcastSubmit() {
  const title = document.getElementById('notifTitle').value.trim();
  const body = document.getElementById('notifBody').value.trim();

  if (!title) {
    alert('Please enter a notification title.');
    document.getElementById('notifTitle').focus();
    return;
  }
  if (!body) {
    alert('Please enter a notification message body.');
    document.getElementById('notifBody').focus();
    return;
  }

  let recipText = '';
  if (currentTargetMode === 'single') {
    const sel = document.getElementById('studentSelect');
    if (!sel.value) {
      alert('Please select a student recipient.');
      sel.focus();
      return;
    }
    const opt = sel.options[sel.selectedIndex];
    recipText = opt.text;
  } else if (currentTargetMode === 'multiple') {
    const checked = document.querySelectorAll('#recipientChecklist input[type="checkbox"]:checked');
    if (checked.length === 0) {
      alert('Please select at least one student from the list.');
      return;
    }
    recipText = checked.length + ' selected student(s)';
  } else if (currentTargetMode === 'all') {
    recipText = 'All active students (' + <?= count($studentsWithTokens) ?> + ' students)';
  }

  document.getElementById('modalRecipCount').textContent = recipText;
  document.getElementById('modalTitlePreview').textContent = title;
  document.getElementById('modalBodyPreview').textContent = body;

  const modal = document.getElementById('broadcastModal');
  modal.style.display = 'flex';
}

function closeBroadcastModal() {
  document.getElementById('broadcastModal').style.display = 'none';
}

function submitBroadcastFormConfirmed() {
  closeBroadcastModal();
  document.getElementById('broadcastForm').submit();
}
</script>

<?php
// Layout footer
?>
</div>
</div>
</body>
</html>
