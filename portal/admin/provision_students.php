<?php
declare(strict_types=1);

// CLI execution support
$isCli = (php_sapi_name() === 'cli');

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';

// Web security: require Admin role
if (!$isCli) {
    $u = require_role('admin');
}

$run = $isCli || (is_post() && post('action') === 'provision');
$report = null;

if ($run) {
    if (!$isCli) {
        csrf_check();
    }

    $defaultPassword = 'password123!';
    $defaultHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

    $students = q('SELECT id, name, email FROM students ORDER BY id ASC')->fetchAll();
    $totalStudents = count($students);

    // Map existing student users
    $existingUsers = q("SELECT id, username, student_id FROM users WHERE role = 'student'")->fetchAll();
    $existingByStudentId = [];
    $existingByUsernames = [];
    foreach ($existingUsers as $eu) {
        if (!empty($eu['student_id'])) {
            $existingByStudentId[(int)$eu['student_id']] = $eu;
        }
        if (!empty($eu['username'])) {
            $existingByUsernames[strtolower(trim($eu['username']))] = $eu;
        }
    }

    $created = 0;
    $alreadyExisting = 0;
    $missingEmail = 0;
    $invalidEmail = 0;
    $failed = 0;

    $createdList = [];
    $missingList = [];
    $invalidList = [];
    $failedList  = [];

    db()->beginTransaction();
    try {
        foreach ($students as $s) {
            $sid = (int)$s['id'];
            $sname = trim((string)$s['name']);
            $email = trim((string)($s['email'] ?? ''));

            // Check if student already has a linked account
            if (isset($existingByStudentId[$sid])) {
                $alreadyExisting++;
                continue;
            }

            // Check if email is missing
            if ($email === '') {
                $missingEmail++;
                $missingList[] = ['id' => $sid, 'name' => $sname, 'reason' => 'Empty email address'];
                continue;
            }

            // Validate email format
            $cleanEmail = strtolower($email);
            if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                $invalidEmail++;
                $invalidList[] = ['id' => $sid, 'name' => $sname, 'email' => $email, 'reason' => 'Invalid email syntax'];
                continue;
            }

            // Check username uniqueness
            if (isset($existingByUsernames[$cleanEmail])) {
                $failed++;
                $failedList[] = ['id' => $sid, 'name' => $sname, 'email' => $cleanEmail, 'reason' => 'Email already assigned to another user account'];
                continue;
            }

            // Create new student account
            try {
                q("INSERT INTO users (name, username, password_hash, role, student_id, active, must_change_password, created_at, department)
                   VALUES (?, ?, ?, 'student', ?, 1, 1, ?, 'student')",
                  [$sname, $cleanEmail, $defaultHash, $sid, now()]);

                $newUserId = (int)db()->lastInsertId();
                $existingByStudentId[$sid] = ['id' => $newUserId, 'username' => $cleanEmail];
                $existingByUsernames[$cleanEmail] = ['id' => $newUserId, 'student_id' => $sid];

                $created++;
                if (count($createdList) < 10) {
                    $createdList[] = ['id' => $sid, 'user_id' => $newUserId, 'name' => $sname, 'email' => $cleanEmail];
                }
            } catch (Throwable $e) {
                $failed++;
                $failedList[] = ['id' => $sid, 'name' => $sname, 'email' => $cleanEmail, 'reason' => $e->getMessage()];
            }
        }
        db()->commit();
        if (!$isCli) {
            log_action("Bulk provisioned $created student accounts");
        }
    } catch (Throwable $e) {
        db()->rollBack();
        if ($isCli) {
            fwrite(STDERR, "FATAL ERROR: " . $e->getMessage() . "\n");
            exit(1);
        }
        flash('Database error during provisioning: ' . $e->getMessage(), 'bad');
    }

    $report = [
        'total'            => $totalStudents,
        'created'          => $created,
        'already_existing' => $alreadyExisting,
        'missing_email'    => $missingEmail,
        'invalid_email'    => $invalidEmail,
        'failed'           => $failed,
        'created_samples'  => $createdList,
        'missing_samples'  => $missingList,
        'invalid_samples'  => $invalidList,
        'failed_samples'   => $failedList,
    ];

    if ($isCli) {
        echo "====================================================\n";
        echo "STUDENT BULK ACCOUNT PROVISIONING REPORT\n";
        echo "====================================================\n";
        echo "Total Student Records:         " . $report['total'] . "\n";
        echo "Accounts Successfully Created: " . $report['created'] . "\n";
        echo "Accounts Already Existing:     " . $report['already_existing'] . "\n";
        echo "Missing Email Addresses:       " . $report['missing_email'] . "\n";
        echo "Invalid Email Addresses:       " . $report['invalid_email'] . "\n";
        echo "Failed Account Creations:      " . $report['failed'] . "\n";
        echo "====================================================\n";
        exit(0);
    }
}

// Compute current overview stats before running
$totalInDb = (int)q('SELECT COUNT(*) FROM students')->fetchColumn();
$studentsWithAccount = (int)q("SELECT COUNT(DISTINCT student_id) FROM users WHERE role = 'student' AND student_id IS NOT NULL")->fetchColumn();
$studentsWithEmail = (int)q("SELECT COUNT(*) FROM students WHERE email IS NOT NULL AND TRIM(email) != ''")->fetchColumn();
$pendingAccounts = max(0, $totalInDb - $studentsWithAccount);

page_start('Bulk Student Account Provisioning', $u, PORTALS['admin']['label']);
?>
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <h1>⚡ Bulk Student Account Provisioning</h1>
    <p class="muted">Provision secure individual login accounts for all existing student records using their registered email addresses.</p>
  </div>
  <div>
    <a class="btn sm ghost" href="<?= h(url('admin/logins.php')) ?>">← Back to Student Logins</a>
  </div>
</div>

<!-- Current Overview Cards -->
<div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:1.5rem;">
  <div class="panel kpi">
    <div class="eyebrow">Total Students</div>
    <div class="v"><?= $totalInDb ?></div>
    <div class="muted small">Records in database</div>
  </div>
  <div class="panel kpi">
    <div class="eyebrow">Existing Accounts</div>
    <div class="v" style="color:var(--ok, #10b981);"><?= $studentsWithAccount ?></div>
    <div class="muted small">Active student logins</div>
  </div>
  <div class="panel kpi">
    <div class="eyebrow">Valid Emails</div>
    <div class="v"><?= $studentsWithEmail ?></div>
    <div class="muted small">Ready for provisioning</div>
  </div>
  <div class="panel kpi">
    <div class="eyebrow">Unprovisioned</div>
    <div class="v" style="color:<?= $pendingAccounts > 0 ? '#f59e0b' : 'var(--ok, #10b981)' ?>;"><?= $pendingAccounts ?></div>
    <div class="muted small"><?= $pendingAccounts > 0 ? 'Accounts to create' : 'All accounts linked' ?></div>
  </div>
</div>

<?php if ($report): ?>
<!-- Provisioning Execution Report -->
<section class="panel" style="margin-bottom:1.5rem; border:2px solid var(--primary, #0B72B9);">
  <div class="panel-h">
    <div>
      <h2 style="color:var(--primary, #0B72B9);">📋 Provisioning Execution Report</h2>
      <p class="muted">Summary of the bulk provisioning run completed at <?= date('Y-m-d H:i:s') ?></p>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.25rem;">
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:1rem; text-align:center;">
      <div class="small muted">Total Students</div>
      <div style="font-size:1.6rem; font-weight:800;"><?= $report['total'] ?></div>
    </div>
    <div style="background:rgba(16, 185, 129, 0.1); border:1px solid #10b981; border-radius:8px; padding:1rem; text-align:center;">
      <div class="small" style="color:#047857; font-weight:600;">Successfully Created</div>
      <div style="font-size:1.6rem; font-weight:800; color:#047857;"><?= $report['created'] ?></div>
    </div>
    <div style="background:rgba(11, 114, 185, 0.08); border:1px solid #0B72B9; border-radius:8px; padding:1rem; text-align:center;">
      <div class="small" style="color:#0B72B9; font-weight:600;">Already Existing</div>
      <div style="font-size:1.6rem; font-weight:800; color:#0B72B9;"><?= $report['already_existing'] ?></div>
    </div>
    <div style="background:rgba(245, 158, 11, 0.1); border:1px solid #f59e0b; border-radius:8px; padding:1rem; text-align:center;">
      <div class="small" style="color:#b45309; font-weight:600;">Missing Email</div>
      <div style="font-size:1.6rem; font-weight:800; color:#b45309;"><?= $report['missing_email'] ?></div>
    </div>
    <div style="background:rgba(239, 68, 68, 0.1); border:1px solid #ef4444; border-radius:8px; padding:1rem; text-align:center;">
      <div class="small" style="color:#b91c1c; font-weight:600;">Invalid / Failed</div>
      <div style="font-size:1.6rem; font-weight:800; color:#b91c1c;"><?= $report['invalid_email'] + $report['failed'] ?></div>
    </div>
  </div>

  <?php if (!empty($report['created_samples'])): ?>
    <div style="margin-top:1rem;">
      <h3>Sample Newly Created Accounts</h3>
      <div class="tbl"><table>
        <thead><tr><th>Student ID</th><th>User ID</th><th>Student Name</th><th>Email / Username</th><th>Initial Password</th></tr></thead>
        <tbody>
          <?php foreach ($report['created_samples'] as $cs): ?>
            <tr>
              <td>#<?= $cs['id'] ?></td>
              <td>#<?= $cs['user_id'] ?></td>
              <td><b><?= h($cs['name']) ?></b></td>
              <td class="mono"><?= h($cs['email']) ?></td>
              <td><code>password123!</code> <span class="pill warn">Change required on 1st login</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($report['missing_samples'])): ?>
    <div style="margin-top:1rem;">
      <h3 style="color:#b45309;">Students with Missing Emails (<?= count($report['missing_samples']) ?>)</h3>
      <p class="small muted">These students do not have an email address recorded in their student profile. Please add their email to provision accounts.</p>
      <div class="tbl"><table>
        <thead><tr><th>Student ID</th><th>Student Name</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($report['missing_samples'], 0, 15) as $ms): ?>
            <tr>
              <td>#<?= $ms['id'] ?></td>
              <td><?= h($ms['name']) ?></td>
              <td><a class="btn sm" href="<?= h(url('admin/student.php?id=' . $ms['id'])) ?>">Edit Student Profile</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- Provisioning Action Form -->
<section class="panel">
  <div class="panel-h">
    <div>
      <h2>Execute Bulk Account Provisioning</h2>
      <p class="muted">This operation is 100% idempotent and safe. Existing accounts and passwords will be preserved untouched.</p>
    </div>
  </div>

  <div style="background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:1.25rem; margin-bottom:1.25rem;">
    <h3 style="margin-top:0;">Security & Policy Specifications:</h3>
    <ul style="margin:0; padding-left:1.25rem; line-height:1.7; font-size:0.9rem;">
      <li><b>Username:</b> Exactly matches each student's registered email address (lowercased).</li>
      <li><b>Initial Password:</b> Set to temporary default <code>password123!</code>.</li>
      <li><b>Password Hashing:</b> Secured using <code>password_hash()</code> with <code>PASSWORD_DEFAULT</code> (bcrypt). Plain-text passwords are never stored.</li>
      <li><b>Mandatory First Login Change:</b> <code>must_change_password = 1</code> is set for all new accounts. Students are forced to set their own private password before accessing the dashboard.</li>
      <li><b>Data Preservation:</b> Existing accounts, custom passwords, and student profiles are never duplicated or overwritten.</li>
    </ul>
  </div>

  <form method="post" onsubmit="return confirm('Start bulk student account provisioning now?');">
    <?= csrf_field() ?>
    <button class="btn primary" name="action" value="provision" type="submit" style="padding:0.75rem 1.75rem; font-size:1rem;">
      ⚡ Start Bulk Student Account Provisioning
    </button>
  </form>
</section>

<?php page_end();
