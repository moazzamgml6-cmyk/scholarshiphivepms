<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/task_service.php';
require APP . '/fcm_service.php';

$u = require_role('admin');

// ---------- POST ACTION HANDLERS ----------
if (is_post()) {
    csrf_check();
    $action = post('action');

    // 1. Assign Task (Complete Direct Form Implementation)
    if ($action === 'assign_task') {
        $studentId = (int)post('student_id');
        $title = trim((string)post('title'));
        $description = trim((string)post('description'));
        $priority = post('priority') ?: 'Normal';
        $dueDate = trim((string)post('due_date'));
        $requiresBiometric = post('requires_biometric') === '1' || post('requires_biometric') === 'Yes';
        $studentNotes = trim((string)post('student_notes'));

        if ($studentId <= 0) {
            flash('Please select a student from the list.', 'bad');
            redirect('admin/task_assignment.php#assign-form-card');
        }
        if ($title === '') {
            flash('Task Title is required.', 'bad');
            redirect('admin/task_assignment.php#assign-form-card');
        }

        try {
            $result = PmsTaskService::assignTaskDirect(
                $studentId,
                $title,
                $description,
                $priority,
                $dueDate,
                $requiresBiometric,
                (int)$u['id'],
                $studentNotes
            );

            $studentName = $result['student']['name'] ?? ("Student #" . $studentId);
            $fcm = $result['fcm_result'];
            
            if (!empty($fcm['confirmed'])) {
                flash("Task '{$title}' successfully assigned to {$studentName}. Firebase push notification confirmed and sent to student device.", 'ok');
            } else {
                flash("Task '{$title}' successfully assigned to {$studentName} (Status: Pending). [Notification Note: {$fcm['status_text']}]", 'ok');
            }
        } catch (Exception $e) {
            flash("Error assigning task: " . $e->getMessage(), 'bad');
        }
        redirect('admin/task_assignment.php');
    }

    // 2. Create Task Template (Catalog)
    if ($action === 'create_template') {
        $title = trim((string)post('title'));
        $desc = trim((string)post('description'));
        $priority = post('priority') ?: 'Normal';
        $category = post('category') ?: 'general';
        $days = (int)(post('default_deadline_days') ?: 7);
        $reqBio = post('requires_biometric') === '1';

        try {
            $id = PmsTaskService::createTask($title, $desc, $priority, $category, $days, $reqBio, (int)$u['id']);
            flash("Task template '$title' created successfully.", 'ok');
        } catch (Exception $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('admin/task_assignment.php');
    }

    // 3. Review Task (Approve / Reject / Request Correction)
    if ($action === 'review_task') {
        $stId = (int)post('student_task_id');
        $decision = post('decision'); // 'approve', 'reject', 'request_correction'
        $feedback = trim((string)post('feedback'));

        try {
            $res = PmsTaskService::reviewTask($stId, (int)$u['id'], $decision, $feedback, client_ip());
            $msg = ($decision === 'approve') ? "Task #$stId approved and marked Completed." : "Task #$stId decision recorded.";
            if (!empty($res['all_done'])) {
                $msg .= " All assigned tasks completed for this student!";
            }
            flash($msg, 'ok');
        } catch (Exception $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('admin/task_assignment.php');
    }

    // 4. Cancel Task
    if ($action === 'cancel_task') {
        $stId = (int)post('student_task_id');
        $reason = trim((string)post('reason'));
        try {
            PmsTaskService::cancelTask($stId, (int)$u['id'], $reason, client_ip());
            flash("Task #$stId cancelled.", 'ok');
        } catch (Exception $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('admin/task_assignment.php');
    }
}

// ---------- QUERY STATS & DATA ----------
$totalStudents = (int)q("SELECT COUNT(*) FROM students")->fetchColumn();
$totalAssigned = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status != 'Cancelled'")->fetchColumn();
$completed = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'Completed'")->fetchColumn();
$underReview = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status IN ('Submitted', 'Under Review')")->fetchColumn();
$pending = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status IN ('Pending', 'Assigned')")->fetchColumn();
$inProgress = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'In Progress'")->fetchColumn();
$bioVerified = (int)q("SELECT COUNT(*) FROM student_tasks WHERE biometric_status = 'Verified'")->fetchColumn();
$overdue = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status != 'Completed' AND status != 'Cancelled' AND deadline IS NOT NULL AND deadline < datetime('now')")->fetchColumn();

// Filter parameters (Search by name, student ID, or ID card number)
$fq = trim((string)get('q'));
$f_student = (int)get('student_id');
$fs = trim((string)get('status'));
$fp = trim((string)get('priority'));
$fb = trim((string)get('bio'));

$where = ["1=1"];
$params = [];

if ($fq !== '') {
    $where[] = "(s.name LIKE ? OR s.cnic LIKE ? OR s.email LIKE ? OR s.id = ? OR t.title LIKE ?)";
    $like = "%$fq%";
    array_push($params, $like, $like, $like, is_numeric($fq) ? (int)$fq : 0, $like);
}
if ($f_student > 0) {
    $where[] = "st.student_id = ?";
    $params[] = $f_student;
}
if ($fs !== '' && $fs !== 'all') {
    $where[] = "st.status = ?";
    $params[] = $fs;
}
if ($fp !== '' && $fp !== 'all') {
    $where[] = "st.priority = ?";
    $params[] = $fp;
}
if ($fb === 'verified') {
    $where[] = "st.biometric_status = 'Verified'";
} elseif ($fb === 'pending') {
    $where[] = "st.biometric_status = 'Pending'";
}

$page = max(1, (int)get('page'));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$totalRows = (int)q("SELECT COUNT(*) FROM student_tasks st JOIN tasks t ON t.id = st.task_id JOIN students s ON s.id = st.student_id WHERE " . implode(' AND ', $where), $params)->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$studentTasks = q("SELECT st.*, 
                          COALESCE(st.assigned_at, st.assigned_date, st.created_at) AS display_assigned_at,
                          t.title AS task_title, t.description AS task_description, t.requires_biometric, t.category,
                          s.name AS student_name, s.email AS student_email, s.cnic AS student_cnic,
                          u.name AS assigned_by_name
                   FROM student_tasks st
                   JOIN tasks t ON t.id = st.task_id
                   JOIN students s ON s.id = st.student_id
                   LEFT JOIN users u ON u.id = st.assigned_by
                   WHERE " . implode(' AND ', $where) . "
                   ORDER BY st.id DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

$templates = q("SELECT * FROM tasks WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$allStudents = q("SELECT id, name, cnic, email FROM students ORDER BY name ASC")->fetchAll();

page_start('Task Assignment', $u, 'Super Admin Portal');
?>

<style>
/* Task Assignment Responsive Layout */
.task-admin-layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 960px) {
  .task-admin-layout { grid-template-columns: 1fr; }
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
  color: var(--muted);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.15s ease;
}
.sidebar-item:hover {
  background: var(--sunk);
  color: var(--fg);
}
.sidebar-item.active {
  background: var(--accent-soft);
  color: var(--fg);
  font-weight: 600;
  border-left: 3px solid var(--accent);
}
.sidebar-badge {
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 99px;
  background: var(--sunk);
  color: var(--muted);
  font-weight: 600;
}
.sidebar-item.active .sidebar-badge {
  background: var(--accent);
  color: var(--accent-fg);
}
.quick-action-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 0.75rem;
  padding: 9px;
  font-weight: 600;
  border-radius: 8px;
}
.assign-card-wrapper {
  background: var(--surface);
  border: 1.5px solid var(--accent);
  border-radius: 14px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  box-shadow: var(--shadow-sm);
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
}
.template-chip {
  display: inline-block;
  background: var(--sunk);
  border: 1px solid var(--line);
  border-radius: 99px;
  padding: 4px 11px;
  font-size: 12px;
  cursor: pointer;
  margin: 3px 2px;
  color: var(--fg);
  transition: all 0.12s ease;
}
.template-chip:hover {
  background: var(--accent-soft);
  border-color: var(--accent);
}
.modal-overlay {
  display: none;
  position: fixed;
  top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
}
.modal-content {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 14px;
  width: 92%;
  max-width: 600px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
  padding: 1.5rem;
}
</style>

<div class="task-admin-layout">
  <!-- SIDEBAR: Super Admin Navigation (Clearly Visible on Desktop & Mobile) -->
  <aside class="sidebar-panel">
    <div class="sidebar-nav-title">Super Admin Portal</div>
    <nav class="sidebar-menu">
      <a href="<?= h(url('admin/index.php')) ?>" class="sidebar-item">
        <span>📊 Dashboard</span>
      </a>
      <a href="<?= h(url('admin/task_assignment.php')) ?>" class="sidebar-item active">
        <span>📋 Task Assignment</span>
        <span class="sidebar-badge"><?= $totalAssigned ?></span>
      </a>
      <a href="<?= h(url('admin/task_monitor.php')) ?>" class="sidebar-item">
        <span>📑 Student Tasks</span>
      </a>
      <a href="<?= h(url('admin/students.php')) ?>" class="sidebar-item">
        <span>🎓 Students</span>
        <span class="sidebar-badge"><?= $totalStudents ?></span>
      </a>
      <a href="<?= h(url('admin/officers.php')) ?>" class="sidebar-item">
        <span>👔 Officers</span>
        <span class="sidebar-badge">5</span>
      </a>
      <a href="<?= h(url('admin/reports.php')) ?>" class="sidebar-item">
        <span>📈 Reports</span>
      </a>
      <a href="<?= h(url('admin/staff.php')) ?>" class="sidebar-item">
        <span>👥 Staff & Admins</span>
      </a>
      <a href="<?= h(url('admin/logins.php')) ?>" class="sidebar-item">
        <span>🔑 Student Logins</span>
      </a>
    </nav>

    <hr style="border:0; border-top:1px solid var(--line); margin:1.25rem 0;">

    <div class="sidebar-nav-title">Quick Actions</div>
    <a href="#assign-form-card" class="btn primary quick-action-btn" style="text-decoration:none;">
      + Assign New Task
    </a>
    <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn ghost quick-action-btn" style="text-decoration:none;">
      View All Tasks
    </a>

    <div style="margin-top:1.25rem; padding:0.75rem; background:var(--sunk); border-radius:8px; font-size:12px; color:var(--muted);">
      <div style="font-weight:600; color:var(--fg); margin-bottom:4px;">🔒 Biometric Guard</div>
      Students complete Android biometric verification before submitting gated tasks.
    </div>
  </aside>

  <!-- MAIN WORKSPACE -->
  <main class="task-workspace">
    <!-- Header with Action Buttons -->
    <div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
      <div>
        <h1 style="font-size:1.75rem; font-weight:700;">Task Assignment</h1>
        <p class="muted" style="margin-top:4px;">Assign processing tasks to students, trigger instant Firebase FCM push notifications, and monitor progress.</p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="#assign-form-card" class="btn primary" style="font-weight:600;">+ Assign New Task</a>
        <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn ghost">View All Tasks</a>
        <button type="button" class="btn" onclick="openTemplateModal()">+ New Template</button>
      </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="kpi-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #3b82f6;">
        <div class="small muted">Total Assigned</div>
        <div style="font-size:1.6rem; font-weight:700; color:var(--text);"><?= $totalAssigned ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #8b5cf6;">
        <div class="small muted">Pending Tasks</div>
        <div style="font-size:1.6rem; font-weight:700; color:#8b5cf6;"><?= $pending ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #06b6d4;">
        <div class="small muted">In Progress</div>
        <div style="font-size:1.6rem; font-weight:700; color:#06b6d4;"><?= $inProgress ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #f59e0b;">
        <div class="small muted">Under Review</div>
        <div style="font-size:1.6rem; font-weight:700; color:#f59e0b;"><?= $underReview ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #10b981;">
        <div class="small muted">Completed</div>
        <div style="font-size:1.6rem; font-weight:700; color:#10b981;"><?= $completed ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #14b8a6;">
        <div class="small muted">Biometric Verified</div>
        <div style="font-size:1.6rem; font-weight:700; color:#14b8a6;"><?= $bioVerified ?></div>
      </div>
      <div class="panel kpi" style="padding:1rem; border-left:4px solid #ef4444;">
        <div class="small muted">Overdue</div>
        <div style="font-size:1.6rem; font-weight:700; color:#ef4444;"><?= $overdue ?></div>
      </div>
    </div>

    <!-- ====================================================================== -->
    <!-- DEDICATED ASSIGN NEW TASK FORM (Requirements 3, 4, 5) -->
    <!-- ====================================================================== -->
    <section id="assign-form-card" class="assign-card-wrapper">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
        <div>
          <h2 style="font-size:1.35rem; font-weight:700; color:var(--fg); margin:0;">Assign New Task</h2>
          <p class="muted" style="font-size:13.5px; margin:3px 0 0;">Fill out the details below to assign a task directly to a student and deliver an FCM push notification.</p>
        </div>
        <span class="pill sm acc" style="font-weight:600;">Super Admin Action</span>
      </div>

      <form method="post" class="stack" id="mainAssignTaskForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_task">

        <!-- 1. Select Student (Search by Name, Student ID, and ID Card Number) -->
        <label class="f" style="margin-bottom:1rem;">
          <span style="font-weight:600; font-size:14px;">Select Student <span style="color:#ef4444;">*</span></span>
          <div style="margin-bottom:6px;">
            <input type="text" id="live_student_search" placeholder="🔍 Search student by name, student ID (#), or ID card number (CNIC)..." oninput="filterLiveStudents(this.value)" style="font-size:13.5px; padding:8px 12px; border-radius:8px;">
          </div>
          <select name="student_id" id="main_assign_student_id" required size="5" style="width:100%; height:130px; font-size:13px; border-radius:8px;">
            <?php foreach ($allStudents as $st): ?>
              <option value="<?= $st['id'] ?>" data-search="<?= strtolower($st['name'] . ' ' . $st['cnic'] . ' ' . $st['email'] . ' ' . $st['id']) ?>">
                #<?= $st['id'] ?> — <?= h($st['name']) ?> (ID Card: <?= h($st['cnic'] ?: 'N/A') ?> | <?= h($st['email'] ?: 'No Email') ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <span class="small muted" style="margin-top:3px;" id="live_student_count"><?= count($allStudents) ?> students available. Click a student from the list.</span>
        </label>

        <!-- Template Quick-Fill Chips -->
        <div style="margin-bottom:1rem;">
          <span class="small muted" style="font-weight:600;">Template Quick-Fill:</span>
          <div style="margin-top:4px;">
            <?php foreach ($templates as $tmpl): ?>
              <button type="button" class="template-chip" onclick='fillDirectTemplate(<?= json_encode($tmpl['title']) ?>, <?= json_encode($tmpl['description']) ?>, <?= json_encode($tmpl['priority']) ?>, <?= (int)$tmpl['requires_biometric'] ?>, <?= (int)$tmpl['default_deadline_days'] ?>)'>
                <?= h($tmpl['title']) ?><?= $tmpl['requires_biometric'] ? ' 🔒' : '' ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 2. Task Title -->
        <label class="f" style="margin-bottom:1rem;">
          <span style="font-weight:600; font-size:14px;">Task Title <span style="color:#ef4444;">*</span></span>
          <input type="text" name="title" id="main_task_title" required placeholder="e.g. Upload Bank Statement or Passport Copy" style="font-size:14px; padding:8px 12px; border-radius:8px;">
        </label>

        <!-- 3. Task Description -->
        <label class="f" style="margin-bottom:1rem;">
          <span style="font-weight:600; font-size:14px;">Task Description</span>
          <textarea name="description" id="main_task_desc" rows="3" placeholder="Provide clear instructions, accepted file formats, and submission requirements for the student..." style="font-size:13.5px; border-radius:8px;"></textarea>
        </label>

        <!-- 4. Priority, 5. Due Date, 6. Requires Biometric Verification -->
        <div class="form-grid" style="margin-bottom:1rem;">
          <label class="f">
            <span style="font-weight:600; font-size:14px;">Priority <span style="color:#ef4444;">*</span></span>
            <select name="priority" id="main_priority" required style="font-size:13.5px; border-radius:8px;">
              <option value="Low">Low</option>
              <option value="Normal" selected>Normal</option>
              <option value="High">High</option>
            </select>
          </label>

          <label class="f">
            <span style="font-weight:600; font-size:14px;">Due Date <span style="color:#ef4444;">*</span></span>
            <input type="date" name="due_date" id="main_due_date" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>" style="font-size:13.5px; border-radius:8px;">
          </label>
        </div>

        <div class="form-grid" style="margin-bottom:1rem;">
          <label class="f">
            <span style="font-weight:600; font-size:14px;">Requires Biometric Verification <span style="color:#ef4444;">*</span></span>
            <select name="requires_biometric" id="main_bio" required style="font-size:13.5px; border-radius:8px;">
              <option value="0">No (Standard Document Task)</option>
              <option value="1">Yes (Requires Android Biometric Verification)</option>
            </select>
          </label>

          <label class="f">
            <span style="font-weight:600; font-size:14px;">Optional Student Notes</span>
            <input type="text" name="student_notes" placeholder="Special note or deadline comment visible to student..." style="font-size:13.5px; border-radius:8px;">
          </label>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.25rem; padding-top:1rem; border-top:1px solid var(--line);">
          <button type="reset" class="btn ghost" onclick="document.getElementById('live_student_search').value=''; filterLiveStudents('');">Clear Form</button>
          <button type="submit" class="btn primary" style="min-width:160px; font-weight:600; font-size:14.5px;">Assign Task</button>
        </div>
      </form>
    </section>

    <!-- Search & Filter Bar (Requirement 4) -->
    <section class="panel" style="margin-bottom:1.5rem; padding:1.25rem;">
      <form method="get" class="filter-bar" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
        <label class="f" style="flex:2; min-width:220px;">
          <span style="font-weight:600; font-size:13px;">Search Student Name, ID (#) or ID Card #</span>
          <input name="q" value="<?= h($fq) ?>" placeholder="Search student name, CNIC, email, or task title...">
        </label>

        <label class="f" style="flex:1.5; min-width:180px;">
          <span style="font-weight:600; font-size:13px;">Filter by Student</span>
          <select name="student_id">
            <option value="">All Students (<?= $totalStudents ?>)</option>
            <?php foreach ($allStudents as $st): ?>
              <option value="<?= $st['id'] ?>" <?= $f_student === (int)$st['id'] ? 'selected' : '' ?>>
                #<?= $st['id'] ?> — <?= h($st['name']) ?> (<?= h($st['cnic'] ?: 'No CNIC') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="f" style="flex:1; min-width:130px;">
          <span style="font-weight:600; font-size:13px;">Filter by Status</span>
          <select name="status">
            <option value="all">All Statuses</option>
            <?php foreach (['Pending', 'In Progress', 'Submitted', 'Under Review', 'Correction Required', 'Completed', 'Rejected', 'Cancelled'] as $st): ?>
              <option value="<?= $st ?>" <?= $fs === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="f" style="flex:1; min-width:110px;">
          <span style="font-weight:600; font-size:13px;">Priority</span>
          <select name="priority">
            <option value="all">All</option>
            <option value="High" <?= $fp === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Normal" <?= $fp === 'Normal' ? 'selected' : '' ?>>Normal</option>
            <option value="Low" <?= $fp === 'Low' ? 'selected' : '' ?>>Low</option>
          </select>
        </label>

        <div style="display:flex; gap:0.4rem; padding-bottom:0.25rem;">
          <button type="submit" class="btn primary sm">Filter</button>
          <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn ghost sm" title="Clear all filters and view all tasks">View All Tasks</a>
        </div>
      </form>
    </section>

    <!-- Task Tracking Table (Requirement 10) -->
    <section class="panel">
      <div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <div>
          <h2 style="font-size:1.25rem; font-weight:700;">Assigned Tasks (<?= $totalRows ?>)</h2>
          <p class="muted" style="font-size:13px;">Complete list of assigned tasks, student identity, deadlines, and biometric compliance.</p>
        </div>
        <div>
          <a href="#assign-form-card" class="btn sm primary" style="font-weight:600;">+ Assign New Task</a>
        </div>
      </div>

      <?php if (empty($studentTasks)): ?>
        <div style="text-align:center; padding:3rem 1rem;">
          <div style="font-size:2rem; margin-bottom:0.5rem;">📋</div>
          <p class="muted" style="margin-bottom:1rem;">No tasks found matching your filter criteria.</p>
          <div style="display:flex; justify-content:center; gap:0.5rem;">
            <a href="#assign-form-card" class="btn primary sm">Assign New Task</a>
            <a href="<?= h(url('admin/task_assignment.php')) ?>" class="btn ghost sm">View All Tasks</a>
          </div>
        </div>
      <?php else: ?>
        <div class="table-wrap"><table class="data-table" style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="width:50px;">ID</th>
              <th>Student</th>
              <th>Task Title</th>
              <th>Priority</th>
              <th>Deadline</th>
              <th>Status</th>
              <th>Assignment Date</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($studentTasks as $t): 
              $isOverdue = (!empty($t['deadline']) && strtotime($t['deadline']) < time() && $t['status'] !== 'Completed' && $t['status'] !== 'Cancelled');
              $statusClass = match ($t['status']) {
                  'Completed' => 'ok',
                  'Submitted', 'Under Review' => 'warn',
                  'Correction Required', 'Rejected' => 'bad',
                  'In Progress' => 'acc',
                  'Pending' => 'warn',
                  default => 'muted',
              };
              $priorityClass = match ($t['priority']) {
                  'High' => 'bad',
                  'Normal' => 'acc',
                  'Low' => 'muted',
                  default => 'muted',
              };
              $bioClass = match ($t['biometric_status']) {
                  'Verified' => 'ok',
                  'Failed' => 'bad',
                  'Pending' => 'warn',
                  default => 'muted',
              };
            ?>
            <tr>
              <td><span class="mono muted">#<?= $t['id'] ?></span></td>
              <td>
                <div style="font-weight:600; color:var(--fg);"><?= h($t['student_name']) ?></div>
                <div class="small muted" style="font-size:12px;">
                  ID: #<?= $t['student_id'] ?> | ID Card: <b><?= h($t['student_cnic'] ?: 'N/A') ?></b>
                </div>
              </td>
              <td>
                <div style="font-weight:600; color:var(--fg);"><?= h($t['task_title']) ?></div>
                <?php if ((int)$t['requires_biometric'] === 1): ?>
                  <span class="pill sm <?= $bioClass ?>" style="font-size:11px;" title="Biometric requirement: <?= h($t['biometric_status']) ?>">
                    🔒 Biometric: <?= h($t['biometric_status']) ?>
                  </span>
                <?php else: ?>
                  <span class="small muted" style="font-size:11px;">Standard Task</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="pill sm <?= $priorityClass ?>"><?= h($t['priority']) ?></span>
              </td>
              <td>
                <?php if (!empty($t['deadline'])): ?>
                  <div style="font-size:13px; font-weight:500; <?= $isOverdue ? 'color:#ef4444; font-weight:700;' : '' ?>">
                    <?= date('d M Y', strtotime($t['deadline'])) ?>
                  </div>
                  <?php if ($isOverdue): ?>
                    <span class="pill sm bad" style="font-size:10px; padding:1px 5px;">Overdue</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="pill sm <?= $statusClass ?>"><?= h($t['status']) ?></span>
              </td>
              <td>
                <div style="font-size:12px; color:var(--muted);">
                  <?= date('d M Y, h:i A', strtotime($t['display_assigned_at'])) ?>
                </div>
                <div style="font-size:11px; color:var(--muted);">
                  By: <?= h($t['assigned_by_name'] ?: 'Admin') ?>
                </div>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:0.3rem;">
                  <button type="button" class="btn sm" onclick='showTaskDetail(<?= json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                    View Details
                  </button>
                  <?php if (in_array($t['status'], ['Submitted', 'Under Review'])): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this task as Completed?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="review_task">
                      <input type="hidden" name="student_task_id" value="<?= $t['id'] ?>">
                      <input type="hidden" name="decision" value="approve">
                      <button type="submit" class="btn sm ok" title="Approve">✓</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div class="pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--line);">
            <span class="small muted">Showing Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> total tasks)</span>
            <div style="display:flex; gap:0.4rem;">
              <?php if ($page > 1): ?>
                <a class="btn sm ghost" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">« Prev</a>
              <?php endif; ?>
              <?php if ($page < $totalPages): ?>
                <a class="btn sm ghost" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next »</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</div>

<!-- ====================================================================== -->
<!-- MODAL: View Task Details -->
<!-- ====================================================================== -->
<div id="modal-detail" class="modal-overlay">
  <div class="modal-content">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
      <div>
        <h2 id="detail-task-title" style="font-size:1.25rem; font-weight:700;">Task Details</h2>
        <span id="detail-task-id" class="small muted">#</span>
      </div>
      <button type="button" onclick="closeModals()" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--muted);">&times;</button>
    </div>

    <div class="stack" style="gap:1rem;">
      <div style="background:var(--sunk); padding:1rem; border-radius:8px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); font-weight:700;">Student Information</div>
        <div id="detail-student-name" style="font-size:15px; font-weight:700; margin-top:2px;"></div>
        <div id="detail-student-meta" class="small muted" style="margin-top:2px;"></div>
      </div>

      <div class="form-grid">
        <div>
          <div class="small muted">Status</div>
          <div id="detail-status" style="font-weight:600; margin-top:2px;"></div>
        </div>
        <div>
          <div class="small muted">Priority</div>
          <div id="detail-priority" style="font-weight:600; margin-top:2px;"></div>
        </div>
        <div>
          <div class="small muted">Deadline</div>
          <div id="detail-deadline" style="font-weight:600; margin-top:2px;"></div>
        </div>
        <div>
          <div class="small muted">Biometric Requirement</div>
          <div id="detail-bio" style="font-weight:600; margin-top:2px;"></div>
        </div>
      </div>

      <div class="form-grid">
        <div>
          <div class="small muted">Assigned Date & Time</div>
          <div id="detail-assigned-at" class="small" style="font-weight:600; margin-top:2px;"></div>
        </div>
        <div>
          <div class="small muted">Assigned By</div>
          <div id="detail-assigned-by" class="small" style="font-weight:600; margin-top:2px;"></div>
        </div>
      </div>

      <div>
        <div class="small muted" style="font-weight:600;">Description & Guidelines:</div>
        <div id="detail-desc" style="background:var(--input-bg); border:1px solid var(--line); border-radius:8px; padding:0.75rem; margin-top:4px; font-size:13px; white-space:pre-wrap;"></div>
      </div>

      <div id="detail-notes-box" style="display:none;">
        <div class="small muted" style="font-weight:600;">Student Submission Notes:</div>
        <div id="detail-notes" style="background:var(--sunk); border-radius:8px; padding:0.75rem; margin-top:4px; font-size:13px;"></div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; padding-top:1rem; border-top:1px solid var(--line);">
        <a id="detail-dossier-link" href="#" class="btn sm ghost">Full Student Dossier →</a>
        <button type="button" class="btn sm" onclick="closeModals()">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL: Create New Task Template -->
<!-- ====================================================================== -->
<div id="modal-template" class="modal-overlay">
  <div class="modal-content" style="max-width:500px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
      <h2 style="font-size:1.25rem; font-weight:700;">Create Task Template</h2>
      <button type="button" onclick="closeModals()" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--muted);">&times;</button>
    </div>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_template">

      <label class="f">
        <span style="font-weight:600;">Template Title</span>
        <input name="title" required placeholder="e.g. Upload Bank Statement">
      </label>

      <label class="f">
        <span style="font-weight:600;">Description / Guidelines</span>
        <textarea name="description" rows="3" placeholder="Provide instructions for students..."></textarea>
      </label>

      <div class="form-grid">
        <label class="f">
          <span style="font-weight:600;">Default Priority</span>
          <select name="priority">
            <option value="High">High</option>
            <option value="Normal" selected>Normal</option>
            <option value="Low">Low</option>
          </select>
        </label>
        <label class="f">
          <span style="font-weight:600;">Default Days</span>
          <input type="number" name="default_deadline_days" value="7" min="1" max="90">
        </label>
      </div>

      <label class="f" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; margin-top:0.5rem;">
        <input type="checkbox" name="requires_biometric" value="1" style="width:1.2rem; height:1.2rem;">
        <span style="font-weight:600;">Requires Android Biometric Verification</span>
      </label>

      <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--line);">
        <button type="button" class="btn ghost" onclick="closeModals()">Cancel</button>
        <button type="submit" class="btn primary">Save Template</button>
      </div>
    </form>
  </div>
</div>

<script>
function openTemplateModal() {
  document.getElementById('modal-template').style.display = 'flex';
}
function closeModals() {
  document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}

// Live student search filter (search by name, student ID, and ID card number)
function filterLiveStudents(query) {
  const q = query.trim().toLowerCase();
  const select = document.getElementById('main_assign_student_id');
  const options = select.options;
  let visible = 0;
  for (let i = 0; i < options.length; i++) {
    const text = options[i].getAttribute('data-search') || '';
    if (!q || text.includes(q)) {
      options[i].style.display = '';
      visible++;
    } else {
      options[i].style.display = 'none';
    }
  }
  document.getElementById('live_student_count').textContent = visible + ' student(s) match search.';
}

// Template quick-fill into form
function fillDirectTemplate(title, desc, priority, bio, days) {
  document.getElementById('main_task_title').value = title;
  document.getElementById('main_task_desc').value = desc || '';
  if (priority) document.getElementById('main_priority').value = priority;
  document.getElementById('main_bio').value = bio ? '1' : '0';
  
  const d = new Date();
  d.setDate(d.getDate() + (days || 7));
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  document.getElementById('main_due_date').value = yyyy + '-' + mm + '-' + dd;
}

// Show Task Detail Modal
function showTaskDetail(t) {
  document.getElementById('detail-task-title').textContent = t.task_title || 'Task #' + t.id;
  document.getElementById('detail-task-id').textContent = 'Task Assignment #' + t.id + ' (Template #' + t.task_id + ')';
  document.getElementById('detail-student-name').textContent = t.student_name || 'Student #' + t.student_id;
  document.getElementById('detail-student-meta').textContent = 'Student ID: #' + t.student_id + ' | ID Card (CNIC): ' + (t.student_cnic || 'N/A') + ' | ' + (t.student_email || 'No Email');
  document.getElementById('detail-status').textContent = t.status || 'Pending';
  document.getElementById('detail-priority').textContent = t.priority || 'Normal';
  document.getElementById('detail-deadline').textContent = t.deadline ? t.deadline.substring(0, 16) : 'Not set';
  document.getElementById('detail-bio').textContent = (parseInt(t.requires_biometric) === 1) ? '🔒 Yes (' + t.biometric_status + ')' : 'No (Standard Task)';
  document.getElementById('detail-assigned-at').textContent = t.display_assigned_at || t.assigned_date || 'N/A';
  document.getElementById('detail-assigned-by').textContent = t.assigned_by_name || 'Admin #' + t.assigned_by;
  document.getElementById('detail-desc').textContent = t.task_description || 'No specific description provided.';
  
  if (t.student_notes && t.student_notes.trim() !== '') {
    document.getElementById('detail-notes-box').style.display = 'block';
    document.getElementById('detail-notes').textContent = t.student_notes;
  } else {
    document.getElementById('detail-notes-box').style.display = 'none';
  }
  
  document.getElementById('detail-dossier-link').href = 'task_monitor.php?student_id=' + t.student_id + '&task_id=' + t.id;
  document.getElementById('modal-detail').style.display = 'flex';
}
</script>

<?php page_end();
