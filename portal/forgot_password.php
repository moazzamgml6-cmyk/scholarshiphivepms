<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require APP . '/layout.php';

$portal = get('portal') ?: 'student';
if (!in_array($portal, ['student', 'officer', 'admin'], true)) {
    $portal = 'student';
}

$submitted = false;
$found = false;
$error = null;

if (is_post()) {
    csrf_check();
    $identifier = strtolower(trim((string)post('identifier')));
    if ($identifier === '') {
        $error = 'Please enter your registered email address or username.';
    } else {
        $u = q('SELECT u.id, u.name, u.username, u.student_id, s.officer_id, o.name AS officer_name, s.counselor
                FROM users u
                LEFT JOIN students s ON s.id = u.student_id
                LEFT JOIN users o ON o.id = s.officer_id
                WHERE (LOWER(u.username) = ? OR (s.email IS NOT NULL AND LOWER(s.email) = ?))
                  AND u.role = ? AND u.active = 1
                LIMIT 1', [$identifier, $identifier, $portal])->fetch();

        $submitted = true;
        if ($u) {
            $found = true;
            log_action('Requested password reset for ' . $u['username'], (int)($u['student_id'] ?? 0));
        }
    }
}

page_start('Forgot password');
?>
<div class="login">
  <div class="brand"><div class="mark">P</div><div><div class="app"><?= h($CONFIG['app_name']) ?></div><small><?= h(PORTALS[$portal]['label']) ?></small></div></div>
  <section class="panel">
    <h1>Forgot password</h1>
    <p class="muted">Enter your registered email address or username to reset your access.</p>

    <?php if ($error): ?>
      <div class="flash bad" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($submitted): ?>
      <div class="flash ok" role="status" style="line-height:1.6;">
        <b>Request Recorded</b><br>
        If an active <?= h($portal) ?> account matches your details, your assigned processing officer has been notified.
        <div style="margin-top:0.6rem; font-size:0.88rem;">
          For immediate assistance, please reach out directly to your assigned counselor or Processing Management team with your Student ID Card Number.
        </div>
      </div>
      <p style="margin-top:1.25rem;">
        <a class="btn primary sm" href="<?= h(url($portal . '/login.php')) ?>">Return to Sign In</a>
      </p>
    <?php else: ?>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <label class="f">
          <span><?= $portal === 'student' ? 'Registered Student Email' : 'Username or Email' ?></span>
          <input name="identifier" required autocomplete="username" placeholder="<?= $portal === 'student' ? 'e.g. student@example.com' : 'Your username' ?>" value="<?= h(post('identifier')) ?>" autofocus>
        </label>
        <button class="btn primary" type="submit">Submit Reset Request</button>
      </form>
      <p class="muted small" style="margin-top:1rem;">
        <a href="<?= h(url($portal . '/login.php')) ?>">← Back to Sign in</a>
      </p>
    <?php endif; ?>
  </section>
  <p class="muted small"><a href="<?= h(url('index.php')) ?>">Back to all portals</a></p>
</div>
<?php page_end();
