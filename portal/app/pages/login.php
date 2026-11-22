<?php
declare(strict_types=1);
// Shared sign-in page. Expects $portal = 'admin' | 'officer' | 'student'.

$next = safe_next($portal, get('next'));
$u = current_user($portal);
if ($u && $u['role'] === $portal && get('switch') !== '1') redirect($next ?: PORTALS[$portal]['home']);

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login($portal, post('username'), $_POST['password'] ?? '');
    if ($error === null) redirect($next ?: PORTALS[$portal]['home']);
}

$hint = [
    'admin'   => 'For office administrators.',
    'officer' => 'For processing officers. You will see only the students assigned to you.',
    'student' => 'Sign in using your registered student email address and password.',
][$portal];

$userLabel = $portal === 'student' ? 'Email Address' : 'Username';
$userPlaceholder = $portal === 'student' ? 'e.g. student@example.com' : 'Your username';
$userType = $portal === 'student' ? 'email' : 'text';

page_start(PORTALS[$portal]['label']);
?>
<div class="login">
  <div class="brand"><div class="mark">P</div><div><div class="app"><?= h($CONFIG['app_name']) ?></div><small><?= h(PORTALS[$portal]['label']) ?></small></div></div>
  <section class="panel">
    <h1><?= $portal === 'student' ? 'Student Sign In' : 'Sign in' ?></h1>
    <p class="muted"><?= h($hint) ?></p>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="on">
      <?= csrf_field() ?>
      <label class="f">
        <span><?= h($userLabel) ?></span>
        <input name="username" id="username" type="<?= h($userType) ?>" required autocomplete="username" placeholder="<?= h($userPlaceholder) ?>" value="<?= h(post('username')) ?>" autofocus>
      </label>
      <label class="f">
        <span>Password</span>
        <input name="password" id="password" type="password" required autocomplete="current-password" placeholder="Enter your password">
      </label>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top: -0.25rem; margin-bottom: 0.5rem;">
        <span class="small muted"><?= $portal === 'student' ? 'First time? Use your temporary password.' : '' ?></span>
        <a href="<?= h(url('forgot_password.php?portal=' . $portal)) ?>" class="small" style="text-decoration:none; color:var(--primary, #0B72B9); font-weight:600;">Forgot password?</a>
      </div>
      <button class="btn primary" type="submit"><?= $portal === 'student' ? 'Sign in to Student Dashboard' : 'Sign in' ?></button>
    </form>
  </section>
  <p class="muted small"><a href="<?= h(url('index.php')) ?>">← Back to all portals</a></p>
</div>
<?php page_end();
