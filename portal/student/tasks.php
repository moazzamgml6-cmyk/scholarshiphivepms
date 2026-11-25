<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/task_service.php';

$u = require_role('student');
$sid = (int)($u['student_id'] ?? 0);

$tasks = PmsTaskService::getStudentTasks($sid);
$progress = PmsTaskService::getStudentProgress($sid);

page_start('My Tasks', $u, 'Student Portal');
?>

<div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <h1>My Processing Tasks</h1>
    <p class="muted">View your required tasks, deadlines, and biometric verification status.</p>
  </div>
  <div>
    <span class="pill <?= $progress['completion_pct'] >= 100 ? 'ok' : 'acc' ?>" style="font-size:1.1rem; padding:0.4rem 0.8rem;">
      <?= $progress['completion_pct'] ?>% Completed
    </span>
  </div>
</div>

<!-- Progress Bar Card -->
<section class="panel" style="margin-bottom:1.5rem;">
  <div style="background:var(--border, #e2e8f0); height:12px; border-radius:6px; overflow:hidden;">
    <div style="background:<?= $progress['completion_pct'] >= 100 ? '#10b981' : 'var(--primary, #0B72B9)' ?>; width:<?= $progress['completion_pct'] ?>%; height:100%; transition:width 0.4s ease;"></div>
  </div>
  <div style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:0.85rem;" class="muted">
    <span><b><?= $progress['completed_tasks'] ?></b> completed</span>
    <span><b><?= $progress['under_review_tasks'] ?></b> under review</span>
    <span><b><?= $progress['pending_tasks'] ?></b> pending</span>
    <span><b><?= $progress['biometric_verified'] ?></b> biometric verified</span>
  </div>
</section>

<!-- Tasks List -->
<section class="panel">
  <h2>Assigned Tasks (<?= count($tasks) ?>)</h2>
  <?php if (empty($tasks)): ?>
    <p class="muted" style="text-align:center; padding:2rem 0;">No active tasks assigned yet. Your counselor and processing officer will assign tasks as needed.</p>
  <?php else: ?>
    <div class="stack" style="gap:1rem; margin-top:1rem;">
      <?php foreach ($tasks as $t): 
        $isOverdue = (!empty($t['deadline']) && strtotime($t['deadline']) < time() && $t['status'] !== 'Completed');
        $statusClass = match ($t['status']) {
            'Completed' => 'ok',
            'Submitted', 'Under Review' => 'acc',
            'Correction Required', 'Rejected' => 'bad',
            'In Progress' => 'primary',
            default => 'muted',
        };
      ?>
        <div style="padding:1.25rem; background:var(--surface, #f8fafc); border-radius:10px; border:1px solid var(--border);">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:0.5rem;">
            <div>
              <h3 style="margin:0 0 0.35rem 0; font-size:1.15rem; color:var(--text);">
                <?= h($t['title']) ?>
                <span class="pill sm <?= $t['priority'] === 'High' ? 'bad' : ($t['priority'] === 'Normal' ? 'acc' : '') ?>"><?= h($t['priority']) ?> Priority</span>
                <span class="pill sm <?= $statusClass ?>"><?= h($t['status']) ?></span>
              </h3>
              <p class="small muted" style="margin:0;"><?= h($t['description']) ?></p>
            </div>
            <div style="text-align:right;">
              <?php if ((int)$t['requires_biometric'] === 1): ?>
                <span class="pill sm <?= $t['biometric_status'] === 'Verified' ? 'ok' : 'bad' ?>">
                  🔒 Biometric: <?= h($t['biometric_status']) ?>
                </span><br>
              <?php endif; ?>
              <span class="small <?= $isOverdue ? 'badge-bad' : 'muted' ?>">
                Deadline: <?= !empty($t['deadline']) ? date('d M Y', strtotime($t['deadline'])) : 'None' ?>
              </span>
            </div>
          </div>

          <?php if (!empty($t['admin_feedback'])): ?>
            <div style="margin-top:0.75rem; background:rgba(245,158,11,0.08); padding:0.5rem 0.75rem; border-radius:6px; border-left:3px solid #f59e0b;">
              <span class="small" style="font-weight:600; color:#f59e0b;">Officer / Admin Feedback:</span>
              <div class="small" style="color:var(--text);"><?= h($t['admin_feedback']) ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($t['student_notes'])): ?>
            <div style="margin-top:0.5rem; background:rgba(59,130,246,0.06); padding:0.5rem 0.75rem; border-radius:6px;">
              <span class="small" style="font-weight:600; color:#3b82f6;">Your Submission:</span>
              <div class="small" style="color:var(--text);"><?= h($t['student_notes']) ?></div>
            </div>
          <?php endif; ?>

          <div style="margin-top:1rem; padding-top:0.75rem; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <span class="small muted">To complete this task with native fingerprint authentication, open the <b>ScholarshipHive Mobile App</b>.</span>
            <?php if ($t['status'] === 'Completed'): ?>
              <span class="pill sm ok">✓ Verified & Approved</span>
            <?php else: ?>
              <span class="small" style="color:var(--primary, #0B72B9); font-weight:600;">Action Required in Mobile App</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php page_end();
