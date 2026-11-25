<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';

$u = require_role('student');
$sid = (int)($u['student_id'] ?? 0);

// Handle mark all read
if (is_post() && post('action') === 'mark_all_read') {
    csrf_check();
    q("UPDATE notifications SET is_read = 1, read_at = ? WHERE student_id = ? AND is_read = 0", [now(), $sid]);
    flash('All notifications marked as read.', 'ok');
    redirect('student/notifications.php');
}

// Handle mark single read
if (is_post() && post('action') === 'mark_read') {
    csrf_check();
    $nid = (int)post('notification_id');
    q("UPDATE notifications SET is_read = 1, read_at = ? WHERE id = ? AND student_id = ?", [now(), $nid, $sid]);
    flash('Notification marked as read.', 'ok');
    redirect('student/notifications.php');
}

$notifications = q("SELECT * FROM notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 100", [$sid])->fetchAll();
$unreadCount = (int)q("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND is_read = 0", [$sid])->fetchColumn();

page_start('Notification Center', $u, 'Student Portal');
?>

<div class="panel-h" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <h1>Notification Center</h1>
    <p class="muted">Real-time alerts, task assignment notices, and officer instructions.</p>
  </div>
  <?php if ($unreadCount > 0): ?>
    <form method="post" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button type="submit" class="btn sm ghost">Mark All as Read (<?= $unreadCount ?>)</button>
    </form>
  <?php endif; ?>
</div>

<section class="panel">
  <?php if (empty($notifications)): ?>
    <p class="muted" style="text-align:center; padding:2rem 0;">No notifications yet. You will be notified here whenever tasks are assigned or updated.</p>
  <?php else: ?>
    <div class="stack" style="gap:0.75rem;">
      <?php foreach ($notifications as $n): 
        $isUnread = ((int)$n['is_read'] === 0);
      ?>
        <div style="padding:1rem; background:<?= $isUnread ? 'rgba(11,114,185,0.06)' : 'var(--surface, #f8fafc)' ?>; border-left:4px solid <?= $isUnread ? 'var(--primary, #0B72B9)' : 'var(--border)' ?>; border-radius:6px; display:flex; justify-content:space-between; align-items:flex-start; gap:1rem;">
          <div>
            <div style="display:flex; align-items:center; gap:0.5rem;">
              <b style="color:var(--text);"><?= h($n['title']) ?></b>
              <?php if ($isUnread): ?><span class="pill sm acc">New</span><?php endif; ?>
            </div>
            <p class="small" style="margin:0.35rem 0 0 0; color:var(--text);"><?= h($n['body']) ?></p>
            <span class="small muted" style="display:block; margin-top:0.35rem;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
          </div>
          <?php if ($isUnread): ?>
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
              <button type="submit" class="btn sm" title="Mark as Read">✓ Read</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php page_end();
