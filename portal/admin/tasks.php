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

    // 1. Create task template
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
        redirect('admin/tasks.php');
    }

    // 2. Assign task
    if ($action === 'assign_task') {
        $taskId = (int)post('task_id');
        $mode = post('assign_mode'); // 'all', 'single', 'multiple'
        $priority = post('priority') ?: null;
        $deadline = post('deadline') ?: null;
        $notes = trim((string)post('notes'));

        try {
            if ($mode === 'all') {
                $count = PmsTaskService::assignToAllStudents($taskId, (int)$u['id'], $deadline, $priority, $notes);
                flash("Task successfully assigned to all $count students.", 'ok');
            } elseif ($mode === 'multiple') {
                $rawIds = post('student_ids');
                $sids = is_array($rawIds) ? $rawIds : array_map('trim', explode(',', (string)$rawIds));
                $res = PmsTaskService::bulkAssignTask($taskId, $sids, (int)$u['id'], $deadline, $priority, $notes);
                flash("Task successfully assigned to " . count($res) . " selected students.", 'ok');
            } else {
                $sid = (int)post('student_id');
                if ($sid <= 0) throw new InvalidArgumentException('Please select a student.');
                $stId = PmsTaskService::assignTask($taskId, $sid, (int)$u['id'], $deadline, $priority, $notes);
                flash("Task successfully assigned to student #$sid.", 'ok');
            }
        } catch (Exception $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('admin/tasks.php');
    }

    // 3. Review task (Approve / Reject / Request Correction)
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
        redirect('admin/tasks.php');
    }

    // 4. Cancel task
    if ($action === 'cancel_task') {
        $stId = (int)post('student_task_id');
        $reason = trim((string)post('reason'));
        try {
            PmsTaskService::cancelTask($stId, (int)$u['id'], $reason, client_ip());
            flash("Task #$stId cancelled.", 'ok');
        } catch (Exception $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('admin/tasks.php');
    }
}

// ---------- QUERY STATS & DATA ----------
$totalStudents = (int)q("SELECT COUNT(*) FROM students")->fetchColumn();
$totalAssigned = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status != 'Cancelled'")->fetchColumn();
$completed = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'Completed'")->fetchColumn();
$underReview = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status IN ('Submitted', 'Under Review')")->fetchColumn();
$pending = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status IN ('Assigned', 'Pending')")->fetchColumn();
$inProgress = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'In Progress'")->fetchColumn();
$bioVerified = (int)q("SELECT COUNT(*) FROM student_tasks WHERE biometric_status = 'Verified'")->fetchColumn();
$overdue = (int)q("SELECT COUNT(*) FROM student_tasks WHERE status != 'Completed' AND status != 'Cancelled' AND deadline IS NOT NULL AND deadline < datetime('now')")->fetchColumn();

// Filter parameters
$fq = trim((string)get('q'));
$fs = trim((string)get('status'));
$fp = trim((string)get('priority'));
$fb = trim((string)get('bio'));

$where = ["1=1"];
$params = [];

if ($fq !== '') {
    $where[] = "(s.name LIKE ? OR s.email LIKE ? OR s.cnic LIKE ? OR s.id = ? OR t.title LIKE ?)";
    $like = "%$fq%";
    array_push($params, $like, $like, $like, is_numeric($fq) ? (int)$fq : 0, $like);
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

$studentTasks = q("SELECT st.*, t.title AS task_title, t.requires_biometric, t.category,
                          s.name AS student_name, s.email AS student_email, s.cnic AS student_cnic,
                          u.name AS assigned_by_name
                   FROM student_tasks st
                   JOIN tasks t ON t.id = st.task_id
                   JOIN students s ON s.id = st.student_id
                   LEFT JOIN users u ON u.id = st.assigned_by
                   WHERE " . implode(' AND ', $where) . "
                   ORDER BY st.id DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

$templates = q("SELECT * FROM tasks WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$allStudents = q("SELECT id, name, email FROM students ORDER BY name ASC")->fetchAll();

page_start('Task Management', $u, 'Admin Portal');
?>

<div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <h1>Task Management Dashboard</h1>
    <p class="muted">Assign tasks, track student completion, verify Android biometrics, and approve dossiers.</p>
  </div>
  <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
    <a href="#modal-assign" class="btn primary">+ Assign Task</a>
    <a href="#modal-template" class="btn ghost">+ New Template</a>
    <a href="<?= h(url('admin/task_monitor.php')) ?>" class="btn">Student Task Dossiers →</a>
  </div>
</div>

<!-- KPI Summary Cards -->
<div class="kpi-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #3b82f6;">
    <div class="small muted">Total Students</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--text);"><?= $totalStudents ?></div>
  </div>
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #6366f1;">
    <div class="small muted">Assigned Tasks</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--text);"><?= $totalAssigned ?></div>
  </div>
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #10b981;">
    <div class="small muted">Completed</div>
    <div style="font-size:1.6rem; font-weight:700; color:#10b981;"><?= $completed ?></div>
  </div>
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #f59e0b;">
    <div class="small muted">Under Review</div>
    <div style="font-size:1.6rem; font-weight:700; color:#f59e0b;"><?= $underReview ?></div>
  </div>
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #06b6d4;">
    <div class="small muted">In Progress</div>
    <div style="font-size:1.6rem; font-weight:700; color:#06b6d4;"><?= $inProgress ?></div>
  </div>
  <div class="panel kpi" style="padding:1rem; border-left:4px solid #8b5cf6;">
    <div class="small muted">Pending</div>
    <div style="font-size:1.6rem; font-weight:700; color:#8b5cf6;"><?= $pending ?></div>
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

<!-- Filters Bar -->
<section class="panel" style="margin-bottom:1.5rem;">
  <form method="get" class="filter-bar" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
    <label class="f" style="flex:2; min-width:200px;">
      <span>Search Student / Task / CNIC</span>
      <input name="q" value="<?= h($fq) ?>" placeholder="Name, Email, Student ID or Task...">
    </label>
    <label class="f" style="flex:1; min-width:140px;">
      <span>Status</span>
      <select name="status">
        <option value="all">All Statuses</option>
        <?php foreach (['Assigned', 'Pending', 'In Progress', 'Submitted', 'Under Review', 'Correction Required', 'Completed', 'Rejected', 'Cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $fs === $st ? 'selected' : '' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="f" style="flex:1; min-width:130px;">
      <span>Priority</span>
      <select name="priority">
        <option value="all">All Priorities</option>
        <option value="High" <?= $fp === 'High' ? 'selected' : '' ?>>High</option>
        <option value="Normal" <?= $fp === 'Normal' ? 'selected' : '' ?>>Normal</option>
        <option value="Low" <?= $fp === 'Low' ? 'selected' : '' ?>>Low</option>
      </select>
    </label>
    <label class="f" style="flex:1; min-width:140px;">
      <span>Biometrics</span>
      <select name="bio">
        <option value="all">All</option>
        <option value="verified" <?= $fb === 'verified' ? 'selected' : '' ?>>Verified</option>
        <option value="pending" <?= $fb === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
      </select>
    </label>
    <div style="display:flex; gap:0.4rem; padding-bottom:0.25rem;">
      <button type="submit" class="btn primary sm">Filter</button>
      <a href="<?= h(url('admin/tasks.php')) ?>" class="btn sm ghost">Reset</a>
    </div>
  </form>
</section>

<!-- Student Tasks Table -->
<section class="panel">
  <div class="panel-h">
    <div>
      <h2>Assigned Student Tasks (<?= $totalRows ?>)</h2>
      <p class="muted">Track student submissions, review notes, verify biometric logs, and grant approval.</p>
    </div>
  </div>

  <?php if (empty($studentTasks)): ?>
    <p class="muted" style="text-align:center; padding:2rem 0;">No tasks found matching your filter criteria. Click <b>+ Assign Task</b> above to assign tasks to students.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Student</th>
          <th>Task</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Biometric</th>
          <th>Deadline</th>
          <th>Assigned By</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($studentTasks as $t): 
          $isOverdue = (!empty($t['deadline']) && strtotime($t['deadline']) < time() && $t['status'] !== 'Completed' && $t['status'] !== 'Cancelled');
          $statusClass = match ($t['status']) {
              'Completed' => 'ok',
              'Submitted', 'Under Review' => 'acc',
              'Correction Required', 'Rejected' => 'bad',
              'In Progress' => 'primary',
              default => 'muted',
          };
          $bioClass = match ($t['biometric_status']) {
              'Verified' => 'ok',
              'Failed' => 'bad',
              'Pending' => 'acc',
              default => 'muted',
          };
        ?>
        <tr>
          <td><span class="muted">#<?= $t['id'] ?></span></td>
          <td>
            <a href="<?= h(url('admin/task_monitor.php?student_id=' . $t['student_id'])) ?>" style="font-weight:600; text-decoration:none; color:var(--text);">
              <?= h($t['student_name']) ?>
            </a>
            <div class="small muted">ID: <?= $t['student_id'] ?> | <?= h($t['student_email'] ?: 'No Email') ?></div>
          </td>
          <td>
            <b><?= h($t['task_title']) ?></b>
            <?php if (!empty($t['student_notes'])): ?>
              <div class="small muted" style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($t['student_notes']) ?>">
                💬 <?= h($t['student_notes']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <span class="pill sm <?= $t['priority'] === 'High' ? 'bad' : ($t['priority'] === 'Normal' ? 'acc' : '') ?>">
              <?= h($t['priority']) ?>
            </span>
          </td>
          <td>
            <span class="pill sm <?= $statusClass ?>">
              <?= h($t['status']) ?>
            </span>
          </td>
          <td>
            <?php if ((int)$t['requires_biometric'] === 1): ?>
              <span class="pill sm <?= $bioClass ?>" title="Biometric requirement: <?= h($t['biometric_status']) ?>">
                🔒 <?= h($t['biometric_status']) ?>
              </span>
            <?php else: ?>
              <span class="small muted">Not required</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($t['deadline'])): ?>
              <span class="<?= $isOverdue ? 'badge-bad' : '' ?>" style="font-size:0.85rem;">
                <?= date('d M Y', strtotime($t['deadline'])) ?>
              </span>
              <?php if ($isOverdue): ?><div class="small" style="color:#ef4444; font-weight:600;">Overdue</div><?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="small"><?= h($t['assigned_by_name'] ?: 'Admin') ?></span></td>
          <td>
            <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
              <?php if (in_array($t['status'], ['Submitted', 'Under Review', 'In Progress', 'Assigned', 'Pending'])): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Approve this task as Completed?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review_task">
                  <input type="hidden" name="student_task_id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="decision" value="approve">
                  <button type="submit" class="btn sm ok" title="Approve Task">✓ Approve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="var f = prompt('Correction instructions for student:'); if (!f) return false; this.feedback.value = f; return true;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review_task">
                  <input type="hidden" name="student_task_id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="decision" value="request_correction">
                  <input type="hidden" name="feedback" value="">
                  <button type="submit" class="btn sm ghost" title="Request Corrections">✎ Redo</button>
                </form>
              <?php endif; ?>
              <a href="<?= h(url('admin/task_monitor.php?student_id=' . $t['student_id'] . '&task_id=' . $t['id'])) ?>" class="btn sm" title="View details and audit logs">Dossier →</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; padding:0.5rem 0;">
        <span class="small muted">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> total)</span>
        <div style="display:flex; gap:0.3rem;">
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

<!-- MODAL: Assign Task -->
<div id="modal-assign" class="modal-dialog" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
  <div class="panel" style="max-width:540px; width:90%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 40px rgba(0,0,0,0.4); border-radius:12px;">
    <div class="panel-h">
      <h2>Assign Processing Task</h2>
      <a href="#" style="text-decoration:none; font-size:1.4rem; color:var(--muted);">&times;</a>
    </div>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_task">

      <label class="f">
        <span>Select Task Template</span>
        <select name="task_id" required>
          <?php foreach ($templates as $tmpl): ?>
            <option value="<?= $tmpl['id'] ?>">
              <?= h($tmpl['title']) ?> (Priority: <?= h($tmpl['priority']) ?><?= $tmpl['requires_biometric'] ? ', 🔒 Biometric Required' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="f">
        <span>Assignment Scope</span>
        <select name="assign_mode" id="assign_mode" onchange="toggleScopeInputs(this.value);" required>
          <option value="single">Single Student</option>
          <option value="multiple">Multiple Students</option>
          <option value="all">ALL Active Students (<?= $totalStudents ?> Students)</option>
        </select>
      </label>

      <div id="box-single-student">
        <label class="f">
          <span>Choose Student</span>
          <select name="student_id" id="select-student">
            <option value="">-- Choose a student --</option>
            <?php foreach ($allStudents as $st): ?>
              <option value="<?= $st['id'] ?>">ID #<?= $st['id'] ?> — <?= h($st['name']) ?> (<?= h($st['email'] ?: 'No Email') ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div id="box-multiple-students" style="display:none;">
        <label class="f">
          <span>Enter Student IDs (comma separated)</span>
          <input name="student_ids" placeholder="e.g. 1, 2, 5, 12, 45">
        </label>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
        <label class="f">
          <span>Priority Override</span>
          <select name="priority">
            <option value="">Keep Default</option>
            <option value="High">High</option>
            <option value="Normal">Normal</option>
            <option value="Low">Low</option>
          </select>
        </label>
        <label class="f">
          <span>Deadline</span>
          <input type="date" name="deadline" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
        </label>
      </div>

      <label class="f">
        <span>Task Notes / Special Instructions</span>
        <textarea name="notes" rows="2" placeholder="Specific notes for the student..."></textarea>
      </label>

      <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
        <a href="#" class="btn ghost">Cancel</a>
        <button type="submit" class="btn primary">Assign Task Now</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: New Task Template -->
<div id="modal-template" class="modal-dialog" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
  <div class="panel" style="max-width:500px; width:90%; max-height:90vh; overflow-y:auto; border-radius:12px;">
    <div class="panel-h">
      <h2>Create New Task Template</h2>
      <a href="#" style="text-decoration:none; font-size:1.4rem; color:var(--muted);">&times;</a>
    </div>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_template">

      <label class="f">
        <span>Task Title</span>
        <input name="title" required placeholder="e.g. Upload Bank Statement">
      </label>

      <label class="f">
        <span>Description / Guidelines</span>
        <textarea name="description" rows="3" placeholder="Provide clear instructions for students..."></textarea>
      </label>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
        <label class="f">
          <span>Category</span>
          <select name="category">
            <option value="document">Document</option>
            <option value="academic">Academic</option>
            <option value="fee">Fee / Payment</option>
            <option value="biometric">Biometric</option>
            <option value="visa">Visa Filing</option>
            <option value="general">General</option>
          </select>
        </label>
        <label class="f">
          <span>Default Priority</span>
          <select name="priority">
            <option value="High">High</option>
            <option value="Normal" selected>Normal</option>
            <option value="Low">Low</option>
          </select>
        </label>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
        <label class="f">
          <span>Default Days Allowed</span>
          <input type="number" name="default_deadline_days" value="7" min="1" max="90">
        </label>
        <label class="f" style="display:flex; align-items:center; gap:0.5rem; margin-top:1.5rem; cursor:pointer;">
          <input type="checkbox" name="requires_biometric" value="1" style="width:1.2rem; height:1.2rem;">
          <span>Requires Biometrics</span>
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
        <a href="#" class="btn ghost">Cancel</a>
        <button type="submit" class="btn primary">Save Template</button>
      </div>
    </form>
  </div>
</div>

<script>
// Hash-based modal display
function checkModals() {
  document.querySelectorAll('.modal-dialog').forEach(m => m.style.display = 'none');
  const hash = window.location.hash;
  if (hash === '#modal-assign') {
    document.getElementById('modal-assign').style.display = 'flex';
  } else if (hash === '#modal-template') {
    document.getElementById('modal-template').style.display = 'flex';
  }
}
window.addEventListener('hashchange', checkModals);
window.addEventListener('DOMContentLoaded', checkModals);

function toggleScopeInputs(mode) {
  document.getElementById('box-single-student').style.display = (mode === 'single') ? 'block' : 'none';
  document.getElementById('box-multiple-students').style.display = (mode === 'multiple') ? 'block' : 'none';
}
</script>

<?php page_end();
