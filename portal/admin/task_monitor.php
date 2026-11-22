<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/task_service.php';
require APP . '/fcm_service.php';

$u = require_role('admin');

$searchQuery = trim((string)get('q'));
$selectedStudentId = (int)get('student_id');

// Search students
$searchResults = [];
if ($searchQuery !== '') {
    $like = "%$searchQuery%";
    $searchResults = q("SELECT id, name, email, phone, cnic, counselor, branch, intake
                        FROM students
                        WHERE name LIKE ? OR email LIKE ? OR cnic LIKE ? OR id = ?
                        ORDER BY name ASC LIMIT 20",
        [$like, $like, $like, is_numeric($searchQuery) ? (int)$searchQuery : 0]
    )->fetchAll();

    if (empty($selectedStudentId) && count($searchResults) === 1) {
        $selectedStudentId = (int)$searchResults[0]['id'];
    }
}

// If no student selected, default to first student or ID 1
if ($selectedStudentId <= 0 && empty($searchResults)) {
    $first = q("SELECT id FROM students ORDER BY id ASC LIMIT 1")->fetch();
    if ($first) $selectedStudentId = (int)$first['id'];
}

$student = null;
$progress = null;
$studentTasks = [];
$biometricLogs = [];
$activityLogs = [];

if ($selectedStudentId > 0) {
    $student = q("SELECT s.*, o.name AS officer_name, fo.name AS frontend_officer_name
                  FROM students s
                  LEFT JOIN users o ON o.id = s.officer_id
                  LEFT JOIN users fo ON fo.id = s.frontend_officer_id
                  WHERE s.id = ?", [$selectedStudentId])->fetch();

    if ($student) {
        $progress = PmsTaskService::getStudentProgress($selectedStudentId);
        $studentTasks = PmsTaskService::getStudentTasks($selectedStudentId);
        $biometricLogs = q("SELECT * FROM biometric_verification_logs WHERE student_id = ? ORDER BY timestamp DESC LIMIT 20", [$selectedStudentId])->fetchAll();
        $activityLogs = q("SELECT tal.*, t.title AS task_title, u.name AS actor_name
                           FROM task_activity_logs tal
                           JOIN student_tasks st ON st.id = tal.student_task_id
                           JOIN tasks t ON t.id = st.task_id
                           LEFT JOIN users u ON u.id = tal.actor_id
                           WHERE st.student_id = ?
                           ORDER BY tal.created_at DESC LIMIT 30", [$selectedStudentId])->fetchAll();
    }
}

page_start('Student Task Monitoring', $u, 'Admin Portal');
?>

<div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <h1>Student Task Monitoring & Dossier</h1>
    <p class="muted">Search student dossiers, inspect task submissions, review biometric verification events and audit logs.</p>
  </div>
  <div>
    <a href="<?= h(url('admin/tasks.php')) ?>" class="btn ghost">← Back to Task Dashboard</a>
  </div>
</div>

<!-- Search Bar -->
<section class="panel" style="margin-bottom:1.5rem;">
  <form method="get" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
    <label class="f" style="flex:1; min-width:260px;">
      <span>Find Student by Name, Email, ID or CNIC</span>
      <input name="q" value="<?= h($searchQuery) ?>" placeholder="Type name, email, student ID or CNIC..." autofocus>
    </label>
    <button type="submit" class="btn primary">Search Student</button>
    <?php if ($searchQuery !== ''): ?>
      <a href="<?= h(url('admin/task_monitor.php')) ?>" class="btn ghost">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!empty($searchResults)): ?>
    <div style="margin-top:1rem; border-top:1px solid var(--border); padding-top:0.75rem;">
      <div class="small muted" style="margin-bottom:0.5rem;">Search Results (<?= count($searchResults) ?>):</div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <?php foreach ($searchResults as $sr): ?>
          <a href="<?= h(url('admin/task_monitor.php?student_id=' . $sr['id'])) ?>" 
             class="btn sm <?= $selectedStudentId === (int)$sr['id'] ? 'primary' : 'ghost' ?>">
            #<?= $sr['id'] ?> <?= h($sr['name']) ?> (<?= h($sr['email'] ?: 'No email') ?>)
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php if (!$student): ?>
  <div class="panel" style="text-align:center; padding:3rem 1rem;">
    <p class="muted">Student record not found. Please search for a student above.</p>
  </div>
<?php else: ?>

  <!-- Progress Overview Card -->
  <div class="panel" style="margin-bottom:1.5rem; border-left:6px solid <?= $progress['completion_pct'] >= 100 ? '#10b981' : '#3b82f6' ?>;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2 style="margin:0; font-size:1.4rem;">
          <?= h($student['name']) ?> 
          <span class="pill sm"><?= h($student['branch'] ?: 'Lahore') ?></span>
          <?php if (!empty($progress['is_fully_completed'])): ?>
            <span class="pill sm ok">✓ All Tasks Completed</span>
          <?php endif; ?>
        </h2>
        <div class="small muted" style="margin-top:0.25rem;">
          Student ID: <b>#<?= $student['id'] ?></b> | Email: <b><?= h($student['email'] ?: 'Not set') ?></b> | CNIC: <b><?= h($student['cnic'] ?: 'Not set') ?></b> | Counselor: <b><?= h($student['counselor'] ?: 'Unassigned') ?></b>
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:2rem; font-weight:800; color:<?= $progress['completion_pct'] >= 100 ? '#10b981' : '#3b82f6' ?>;">
          <?= $progress['completion_pct'] ?>%
        </div>
        <div class="small muted"><?= $progress['completed_tasks'] ?> of <?= $progress['total_tasks'] ?> tasks completed</div>
      </div>
    </div>

    <!-- Visual Progress Bar -->
    <div style="background:var(--border, #e2e8f0); height:12px; border-radius:6px; margin:1rem 0; overflow:hidden;">
      <div style="background:<?= $progress['completion_pct'] >= 100 ? '#10b981' : '#3b82f6' ?>; width:<?= $progress['completion_pct'] ?>%; height:100%; transition:width 0.4s ease;"></div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); gap:0.75rem; text-align:center;">
      <div style="background:var(--surface, #f8fafc); padding:0.5rem; border-radius:6px;">
        <div class="small muted">Total</div>
        <b><?= $progress['total_tasks'] ?></b>
      </div>
      <div style="background:rgba(16,185,129,0.1); padding:0.5rem; border-radius:6px; color:#10b981;">
        <div class="small">Completed</div>
        <b><?= $progress['completed_tasks'] ?></b>
      </div>
      <div style="background:rgba(245,158,11,0.1); padding:0.5rem; border-radius:6px; color:#f59e0b;">
        <div class="small">Under Review</div>
        <b><?= $progress['under_review_tasks'] ?></b>
      </div>
      <div style="background:rgba(6,182,212,0.1); padding:0.5rem; border-radius:6px; color:#06b6d4;">
        <div class="small">In Progress</div>
        <b><?= $progress['in_progress_tasks'] ?></b>
      </div>
      <div style="background:rgba(139,92,246,0.1); padding:0.5rem; border-radius:6px; color:#8b5cf6;">
        <div class="small">Pending</div>
        <b><?= $progress['pending_tasks'] ?></b>
      </div>
      <div style="background:rgba(20,184,166,0.1); padding:0.5rem; border-radius:6px; color:#14b8a6;">
        <div class="small">Biometrics</div>
        <b><?= $progress['biometric_verified'] ?> Verified</b>
      </div>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start;">
    
    <!-- Left Column: Tasks List -->
    <div>
      <section class="panel">
        <div class="panel-h" style="display:flex; justify-content:space-between; align-items:center;">
          <h2>Assigned Tasks (<?= count($studentTasks) ?>)</h2>
          <a href="<?= h(url('admin/tasks.php#modal-assign')) ?>" class="btn sm primary">+ Assign Task</a>
        </div>

        <?php if (empty($studentTasks)): ?>
          <p class="muted" style="text-align:center; padding:2rem 0;">No tasks assigned to this student yet.</p>
        <?php else: ?>
          <div class="stack" style="gap:1rem;">
            <?php foreach ($studentTasks as $t): 
              $isOverdue = (!empty($t['deadline']) && strtotime($t['deadline']) < time() && $t['status'] !== 'Completed');
            ?>
              <div class="panel" style="background:var(--surface, #f8fafc); border:1px solid var(--border); padding:1rem; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:0.5rem;">
                  <div>
                    <h3 style="margin:0 0 0.25rem 0; font-size:1.1rem; color:var(--text);">
                      <?= h($t['title']) ?>
                      <span class="pill sm <?= $t['priority'] === 'High' ? 'bad' : ($t['priority'] === 'Normal' ? 'acc' : '') ?>">
                        <?= h($t['priority']) ?>
                      </span>
                      <span class="pill sm <?= $t['status'] === 'Completed' ? 'ok' : ($t['status'] === 'Submitted' ? 'acc' : '') ?>">
                        <?= h($t['status']) ?>
                      </span>
                    </h3>
                    <p class="small muted" style="margin:0;"><?= h($t['description']) ?></p>
                  </div>
                  <div style="text-align:right;">
                    <?php if ((int)$t['requires_biometric'] === 1): ?>
                      <span class="pill sm <?= $t['biometric_status'] === 'Verified' ? 'ok' : 'bad' ?>" style="display:inline-block; margin-bottom:0.25rem;">
                        🔒 Biometric: <?= h($t['biometric_status']) ?>
                      </span><br>
                    <?php endif; ?>
                    <span class="small muted">Deadline: <?= !empty($t['deadline']) ? date('d M Y', strtotime($t['deadline'])) : 'None' ?></span>
                  </div>
                </div>

                <?php if (!empty($t['student_notes'])): ?>
                  <div style="margin-top:0.75rem; background:rgba(59,130,246,0.06); padding:0.5rem 0.75rem; border-radius:6px; border-left:3px solid #3b82f6;">
                    <span class="small" style="font-weight:600; color:#3b82f6;">Student Submission Notes:</span>
                    <div class="small" style="color:var(--text);"><?= h($t['student_notes']) ?></div>
                  </div>
                <?php endif; ?>

                <?php if (!empty($t['admin_feedback'])): ?>
                  <div style="margin-top:0.5rem; background:rgba(245,158,11,0.08); padding:0.5rem 0.75rem; border-radius:6px; border-left:3px solid #f59e0b;">
                    <span class="small" style="font-weight:600; color:#f59e0b;">Admin Feedback:</span>
                    <div class="small" style="color:var(--text);"><?= h($t['admin_feedback']) ?></div>
                  </div>
                <?php endif; ?>

                <!-- Actions -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.75rem; padding-top:0.5rem; border-top:1px solid var(--border);">
                  <span class="small muted">Assigned by <?= h($t['assigned_by_name'] ?: 'Admin') ?> on <?= date('d M Y', strtotime($t['assigned_date'])) ?></span>
                  <div style="display:flex; gap:0.4rem;">
                    <?php if ($t['status'] !== 'Completed'): ?>
                      <form method="post" action="<?= h(url('admin/tasks.php')) ?>" style="display:inline;" onsubmit="return confirm('Mark task as Completed?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="review_task">
                        <input type="hidden" name="student_task_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="btn sm ok">✓ Approve</button>
                      </form>
                      <form method="post" action="<?= h(url('admin/tasks.php')) ?>" style="display:inline;" onsubmit="var f = prompt('Correction message for student:'); if (!f) return false; this.feedback.value = f; return true;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="review_task">
                        <input type="hidden" name="student_task_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="decision" value="request_correction">
                        <input type="hidden" name="feedback" value="">
                        <button type="submit" class="btn sm ghost">✎ Redo</button>
                      </form>
                    <?php else: ?>
                      <span class="small ok" style="font-weight:600;">✓ Completed on <?= date('d M Y', strtotime($t['completed_at'] ?: $t['updated_at'])) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <!-- Right Column: Biometrics & Activity Logs -->
    <div class="stack" style="gap:1.5rem;">

      <!-- Biometric Logs Card -->
      <section class="panel">
        <div class="panel-h">
          <h2>🔒 Biometric Audit Log</h2>
          <span class="small muted"><?= count($biometricLogs) ?> records</span>
        </div>
        <?php if (empty($biometricLogs)): ?>
          <p class="small muted">No biometric events recorded for this student yet.</p>
        <?php else: ?>
          <div class="stack" style="gap:0.5rem;">
            <?php foreach ($biometricLogs as $bl): ?>
              <div style="padding:0.5rem; background:var(--surface, #f8fafc); border-radius:6px; border-left:3px solid <?= $bl['verification_result'] === 'Verified' ? '#10b981' : '#ef4444' ?>;">
                <div style="display:flex; justify-content:space-between;">
                  <b class="small"><?= h($bl['verification_result']) ?></b>
                  <span class="small muted"><?= date('d M H:i', strtotime($bl['timestamp'])) ?></span>
                </div>
                <div class="small muted">Method: <?= h($bl['verification_method']) ?></div>
                <div class="small muted">Device: <?= h($bl['device_info'] ?: 'Mobile') ?> | IP: <?= h($bl['ip_address'] ?: '—') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Task Activity Logs Card -->
      <section class="panel">
        <div class="panel-h">
          <h2>Activity History</h2>
          <span class="small muted">Immutable Audit</span>
        </div>
        <?php if (empty($activityLogs)): ?>
          <p class="small muted">No activity logged yet.</p>
        <?php else: ?>
          <div class="stack" style="gap:0.5rem; max-height:400px; overflow-y:auto;">
            <?php foreach ($activityLogs as $al): ?>
              <div style="font-size:0.8rem; padding:0.4rem 0.5rem; background:var(--surface, #f8fafc); border-radius:4px; border-left:2px solid var(--border);">
                <div style="display:flex; justify-content:space-between;">
                  <b><?= h(ucfirst($al['action'])) ?></b>
                  <span class="muted"><?= date('d M H:i', strtotime($al['created_at'])) ?></span>
                </div>
                <div style="color:var(--text); margin-top:0.2rem;"><?= h($al['details']) ?></div>
                <div class="muted" style="font-size:0.75rem;">By: <?= h($al['actor_name'] ?: ucfirst($al['actor_role'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>

<?php endif; ?>

<?php page_end();
