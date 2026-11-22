<?php
declare(strict_types=1);

/**
 * ScholarshipHive PMS — Task Management Core Service
 * Handles task lifecycle, status transitions, biometric gating, and audit logging.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fcm_service.php';

class PmsTaskService {

    // Valid Task Status Constants
    public const STATUS_ASSIGNED            = 'Assigned';
    public const STATUS_PENDING             = 'Pending';
    public const STATUS_IN_PROGRESS         = 'In Progress';
    public const STATUS_SUBMITTED           = 'Submitted';
    public const STATUS_UNDER_REVIEW        = 'Under Review';
    public const STATUS_CORRECTION_REQUIRED = 'Correction Required';
    public const STATUS_COMPLETED           = 'Completed';
    public const STATUS_REJECTED            = 'Rejected';
    public const STATUS_CANCELLED           = 'Cancelled';

    // Valid Biometric Status Constants
    public const BIO_NOT_REQUIRED = 'Not Required';
    public const BIO_PENDING      = 'Pending';
    public const BIO_VERIFIED     = 'Verified';
    public const BIO_FAILED       = 'Failed';

    /**
     * Create a new task catalog template.
     */
    public static function createTask(
        string $title,
        string $description,
        string $priority = 'Normal',
        string $category = 'general',
        int $defaultDeadlineDays = 7,
        bool $requiresBiometric = false,
        int $createdBy = 1
    ): int {
        $title = trim($title);
        if ($title === '') throw new InvalidArgumentException('Task title cannot be empty.');

        q("INSERT INTO tasks (title, description, priority, category, default_deadline_days, requires_biometric, created_by, is_active, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
            [$title, $description, $priority, $category, $defaultDeadlineDays, $requiresBiometric ? 1 : 0, $createdBy, now(), now()]
        );

        $taskId = (int)db()->lastInsertId();
        log_action("Created task template: $title", null);
        return $taskId;
    }

    /**
     * Assign a task to an individual student.
     */
    public static function assignTask(
        int $taskId,
        int $studentId,
        int $assignedBy = 1,
        ?string $customDeadline = null,
        ?string $customPriority = null,
        string $studentNotes = ''
    ): int {
        $task = q("SELECT * FROM tasks WHERE id = ? AND is_active = 1", [$taskId])->fetch();
        if (!$task) throw new RuntimeException("Task template #$taskId not found or inactive.");

        $student = q("SELECT id, name, email FROM students WHERE id = ?", [$studentId])->fetch();
        if (!$student) throw new RuntimeException("Student #$studentId not found.");

        $priority = $customPriority ?: ($task['priority'] ?? 'Normal');
        $days = (int)($task['default_deadline_days'] ?? 7);
        $deadline = $customDeadline ?: date('Y-m-d H:i:s', strtotime("+$days days"));
        $bioStatus = ((int)$task['requires_biometric'] === 1) ? self::BIO_PENDING : self::BIO_NOT_REQUIRED;

        q("INSERT INTO student_tasks (task_id, student_id, status, biometric_status, priority, deadline, assigned_by, assigned_date, student_notes, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$taskId, $studentId, self::STATUS_ASSIGNED, $bioStatus, $priority, $deadline, $assignedBy, now(), $studentNotes, now(), now()]
        );

        $studentTaskId = (int)db()->lastInsertId();

        // 1. Audit trail
        self::logTaskActivity(
            $studentTaskId,
            $assignedBy,
            'admin',
            'assigned',
            '',
            self::STATUS_ASSIGNED,
            "Assigned '{$task['title']}' to {$student['name']} (Deadline: $deadline)"
        );

        // 2. Dispatch real-time push and in-app notification
        PmsNotificationService::notify(
            $studentId,
            null,
            "New Task: " . $task['title'],
            "You have been assigned a new task with deadline " . date('M d, Y', strtotime($deadline)) . ". Please review the instructions.",
            'task_assigned',
            $studentTaskId
        );

        return $studentTaskId;
    }

    /**
     * Assign a task to multiple students.
     */
    public static function bulkAssignTask(
        int $taskId,
        array $studentIds,
        int $assignedBy = 1,
        ?string $customDeadline = null,
        ?string $customPriority = null,
        string $studentNotes = ''
    ): array {
        $assigned = [];
        foreach ($studentIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;
            try {
                $stId = self::assignTask($taskId, $sid, $assignedBy, $customDeadline, $customPriority, $studentNotes);
                $assigned[] = ['student_id' => $sid, 'student_task_id' => $stId];
            } catch (Exception $e) {
                // skip or record error
            }
        }
        return $assigned;
    }

    /**
     * Assign a task to ALL existing students.
     */
    public static function assignToAllStudents(
        int $taskId,
        int $assignedBy = 1,
        ?string $customDeadline = null,
        ?string $customPriority = null,
        string $studentNotes = ''
    ): int {
        $rows = q("SELECT id FROM students ORDER BY id ASC")->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            try {
                self::assignTask($taskId, (int)$r['id'], $assignedBy, $customDeadline, $customPriority, $studentNotes);
                $count++;
            } catch (Exception $e) {}
        }
        return $count;
    }

    /**
     * Fetch all tasks assigned to a specific student.
     */
    public static function getStudentTasks(int $studentId, ?string $statusFilter = null): array {
        $sql = "SELECT st.*, t.title, t.description, t.category, t.requires_biometric,
                       u.name AS assigned_by_name
                FROM student_tasks st
                JOIN tasks t ON t.id = st.task_id
                LEFT JOIN users u ON u.id = st.assigned_by
                WHERE st.student_id = ?";
        $params = [$studentId];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $sql .= " AND st.status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY CASE 
                    WHEN st.status = 'Assigned' THEN 1
                    WHEN st.status = 'Pending' THEN 2
                    WHEN st.status = 'Correction Required' THEN 3
                    WHEN st.status = 'In Progress' THEN 4
                    WHEN st.status = 'Submitted' THEN 5
                    WHEN st.status = 'Under Review' THEN 6
                    WHEN st.status = 'Completed' THEN 7
                    ELSE 8 END, st.deadline ASC, st.id DESC";

        return q($sql, $params)->fetchAll();
    }

    /**
     * Get specific student task detail with authorization.
     */
    public static function getTaskDetail(int $studentTaskId, array $currentUser): ?array {
        $row = q("SELECT st.*, t.title, t.description, t.category, t.requires_biometric,
                         s.name AS student_name, s.email AS student_email, s.cnic AS student_cnic,
                         u.name AS assigned_by_name
                  FROM student_tasks st
                  JOIN tasks t ON t.id = st.task_id
                  JOIN students s ON s.id = st.student_id
                  LEFT JOIN users u ON u.id = st.assigned_by
                  WHERE st.id = ?", [$studentTaskId])->fetch();

        if (!$row) return null;

        // Authorization check: student can only view their own task
        if ($currentUser['role'] === 'student' && (int)$row['student_id'] !== (int)($currentUser['student_id'] ?? 0)) {
            throw new RuntimeException("Access denied: You are not authorized to view this task.");
        }

        // Fetch activity logs
        $logs = q("SELECT * FROM task_activity_logs WHERE student_task_id = ? ORDER BY created_at ASC", [$studentTaskId])->fetchAll();
        $row['activity_logs'] = $logs;

        // Fetch biometric logs if any
        $bioLogs = q("SELECT * FROM biometric_verification_logs WHERE student_task_id = ? ORDER BY timestamp DESC", [$studentTaskId])->fetchAll();
        $row['biometric_logs'] = $bioLogs;

        return $row;
    }

    /**
     * Student submits completed task for review.
     */
    public static function submitTask(int $studentTaskId, int $studentId, string $notes = '', string $clientIp = ''): bool {
        $st = q("SELECT st.*, t.title, t.requires_biometric, s.name AS student_name
                 FROM student_tasks st
                 JOIN tasks t ON t.id = st.task_id
                 JOIN students s ON s.id = st.student_id
                 WHERE st.id = ? AND st.student_id = ?", [$studentTaskId, $studentId])->fetch();

        if (!$st) throw new RuntimeException("Task assignment not found or unauthorized.");

        if (in_array($st['status'], [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new RuntimeException("This task is already {$st['status']} and cannot be resubmitted.");
        }

        // Check biometric requirement: If biometric is required, verify it has been completed
        if ((int)$st['requires_biometric'] === 1 && $st['biometric_status'] !== self::BIO_VERIFIED) {
            throw new RuntimeException("Fingerprint/Biometric verification is required before submitting this task.");
        }

        $prevStatus = $st['status'];
        $newStatus = self::STATUS_UNDER_REVIEW;

        q("UPDATE student_tasks 
           SET status = ?, student_notes = ?, updated_at = ?
           WHERE id = ?",
            [$newStatus, $notes, now(), $studentTaskId]
        );

        // Audit log
        self::logTaskActivity(
            $studentTaskId,
            $studentId,
            'student',
            'submitted',
            $prevStatus,
            $newStatus,
            "Student submitted task for review. Notes: $notes",
            $clientIp
        );

        // Notify admins and assigned officer
        PmsNotificationService::notify(
            null,
            $st['assigned_by'],
            "Task Submitted: {$st['student_name']}",
            "{$st['student_name']} has submitted '{$st['title']}' for review.",
            'task_submitted',
            $studentTaskId
        );

        return true;
    }

    /**
     * Record a biometric verification event.
     */
    public static function recordBiometricVerification(
        int $studentTaskId,
        int $studentId,
        string $method,
        string $result,
        string $deviceInfo = '',
        string $errorMessage = '',
        string $clientIp = ''
    ): bool {
        $isSuccess = ($result === 'Verified');
        $bioStatus = $isSuccess ? self::BIO_VERIFIED : self::BIO_FAILED;

        // 1. Insert biometric audit log
        q("INSERT INTO biometric_verification_logs (student_id, student_task_id, verification_method, verification_result, device_info, error_message, ip_address, timestamp)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$studentId, $studentTaskId, $method, $result, $deviceInfo, $errorMessage, $clientIp, now()]
        );
        $bioLogId = (int)db()->lastInsertId();

        // 2. Update student_task biometric status
        q("UPDATE student_tasks SET biometric_status = ?, updated_at = ? WHERE id = ? AND student_id = ?",
            [$bioStatus, now(), $studentTaskId, $studentId]
        );

        // 3. Task audit activity log
        self::logTaskActivity(
            $studentTaskId,
            $studentId,
            'student',
            'biometric_attempt',
            '',
            $bioStatus,
            "Biometric verification attempt via $method ($deviceInfo) -> $result. Event ID: #$bioLogId",
            $clientIp
        );

        // 4. Notify admin if biometric failed or succeeded
        if (!$isSuccess) {
            $st = q("SELECT t.title, s.name FROM student_tasks st JOIN tasks t ON t.id = st.task_id JOIN students s ON s.id = st.student_id WHERE st.id = ?", [$studentTaskId])->fetch();
            PmsNotificationService::notify(
                null,
                1,
                "Biometric Verification Alert",
                "Biometric verification failed for {$st['name']} on task '{$st['title']}'. Reason: $errorMessage",
                'biometric_alert',
                $studentTaskId
            );
        }

        return $isSuccess;
    }

    /**
     * Admin review: Approve, Reject, or Request Correction.
     */
    public static function reviewTask(
        int $studentTaskId,
        int $adminId,
        string $action,
        string $feedback = '',
        string $clientIp = ''
    ): array {
        $st = q("SELECT st.*, t.title, s.name AS student_name, s.id AS student_id, u.name AS admin_name
                 FROM student_tasks st
                 JOIN tasks t ON t.id = st.task_id
                 JOIN students s ON s.id = st.student_id
                 LEFT JOIN users u ON u.id = ?
                 WHERE st.id = ?", [$adminId, $studentTaskId])->fetch();

        if (!$st) throw new RuntimeException("Task #$studentTaskId not found.");

        $prevStatus = $st['status'];
        $action = strtolower(trim($action));

        if ($action === 'approve') {
            $newStatus = self::STATUS_COMPLETED;
            $completedAt = now();
        } elseif ($action === 'reject') {
            $newStatus = self::STATUS_REJECTED;
            $completedAt = null;
        } elseif ($action === 'request_correction') {
            $newStatus = self::STATUS_CORRECTION_REQUIRED;
            $completedAt = null;
        } else {
            throw new InvalidArgumentException("Invalid review action '$action'. Must be approve, reject, or request_correction.");
        }

        q("UPDATE student_tasks 
           SET status = ?, completed_at = ?, admin_feedback = ?, updated_at = ?
           WHERE id = ?",
            [$newStatus, $completedAt, $feedback, now(), $studentTaskId]
        );

        // Audit log
        self::logTaskActivity(
            $studentTaskId,
            $adminId,
            'admin',
            $action,
            $prevStatus,
            $newStatus,
            "Admin reviewed task. Decision: $newStatus. Feedback: $feedback",
            $clientIp
        );

        // Notify student of decision
        $notifTitle = ($action === 'approve')
            ? "Task Approved: {$st['title']}"
            : (($action === 'reject') ? "Task Rejected: {$st['title']}" : "Correction Requested: {$st['title']}");
        $notifBody = ($action === 'approve')
            ? "Congratulations! Your task '{$st['title']}' has been approved and marked Completed."
            : "Admin review update: " . ($feedback ?: "Please review task remarks.");

        PmsNotificationService::notify(
            (int)$st['student_id'],
            null,
            $notifTitle,
            $notifBody,
            ($action === 'approve') ? 'task_completed' : 'correction_requested',
            $studentTaskId
        );

        // Check if all assigned tasks for this student are now completed!
        $allTasksCount = (int)q("SELECT COUNT(*) FROM student_tasks WHERE student_id = ? AND status != 'Cancelled'", [$st['student_id']])->fetchColumn();
        $completedTasksCount = (int)q("SELECT COUNT(*) FROM student_tasks WHERE student_id = ? AND status = 'Completed'", [$st['student_id']])->fetchColumn();

        $allDone = ($allTasksCount > 0 && $allTasksCount === $completedTasksCount);
        if ($allDone) {
            // Milestone: Dispatch the Final Completion Notification!
            PmsNotificationService::notify(
                (int)$st['student_id'],
                null,
                "Congratulations! All Processing Tasks Completed!",
                "Congratulations {$st['student_name']}! All your assigned processing tasks ($completedTasksCount of $allTasksCount) have been completed successfully.",
                'all_completed',
                $studentTaskId
            );
            PmsNotificationService::notify(
                null,
                $adminId,
                "Milestone: All Tasks Complete for {$st['student_name']}",
                "Student {$st['student_name']} (ID: {$st['student_id']}) has completed all $allTasksCount assigned processing tasks.",
                'all_completed',
                $studentTaskId
            );
        }

        return [
            'status'     => $newStatus,
            'all_done'   => $allDone,
            'completed'  => $completedTasksCount,
            'total'      => $allTasksCount,
        ];
    }

    /**
     * Cancel an assigned task with audit history.
     */
    public static function cancelTask(int $studentTaskId, int $adminId, string $reason = '', string $clientIp = ''): bool {
        $st = q("SELECT st.*, t.title, s.id AS student_id, s.name AS student_name FROM student_tasks st JOIN tasks t ON t.id = st.task_id JOIN students s ON s.id = st.student_id WHERE st.id = ?", [$studentTaskId])->fetch();
        if (!$st) throw new RuntimeException("Task #$studentTaskId not found.");

        $prevStatus = $st['status'];
        q("UPDATE student_tasks SET status = 'Cancelled', admin_feedback = ?, updated_at = ? WHERE id = ?",
            ["Cancelled: $reason", now(), $studentTaskId]
        );

        self::logTaskActivity(
            $studentTaskId,
            $adminId,
            'admin',
            'cancelled',
            $prevStatus,
            'Cancelled',
            "Task cancelled by admin. Reason: $reason",
            $clientIp
        );

        PmsNotificationService::notify(
            (int)$st['student_id'],
            null,
            "Task Cancelled: {$st['title']}",
            "The task '{$st['title']}' has been cancelled by administration.",
            'task_cancelled',
            $studentTaskId
        );

        return true;
    }

    /**
     * Compute comprehensive progress statistics for a student.
     */
    public static function getStudentProgress(int $studentId): array {
        $student = q("SELECT id, name, email FROM students WHERE id = ?", [$studentId])->fetch();
        if (!$student) throw new RuntimeException("Student #$studentId not found.");

        $tasks = q("SELECT status, biometric_status, deadline FROM student_tasks WHERE student_id = ? AND status != 'Cancelled'", [$studentId])->fetchAll();
        $total = count($tasks);

        $completed = 0;
        $pending = 0;
        $inProgress = 0;
        $underReview = 0;
        $correctionRequired = 0;
        $rejected = 0;
        $biometricVerified = 0;
        $overdue = 0;

        $now = time();
        foreach ($tasks as $t) {
            $st = $t['status'];
            if ($st === self::STATUS_COMPLETED) $completed++;
            elseif ($st === self::STATUS_ASSIGNED || $st === self::STATUS_PENDING) $pending++;
            elseif ($st === self::STATUS_IN_PROGRESS) $inProgress++;
            elseif ($st === self::STATUS_SUBMITTED || $st === self::STATUS_UNDER_REVIEW) $underReview++;
            elseif ($st === self::STATUS_CORRECTION_REQUIRED) $correctionRequired++;
            elseif ($st === self::STATUS_REJECTED) $rejected++;

            if ($t['biometric_status'] === self::BIO_VERIFIED) $biometricVerified++;

            if ($st !== self::STATUS_COMPLETED && !empty($t['deadline']) && strtotime($t['deadline']) < $now) {
                $overdue++;
            }
        }

        $percentage = ($total > 0) ? (int)round(($completed / $total) * 100) : 0;

        return [
            'student_id'          => $studentId,
            'student_name'        => $student['name'],
            'student_email'       => $student['email'],
            'total_tasks'         => $total,
            'completed_tasks'     => $completed,
            'pending_tasks'       => $pending,
            'in_progress_tasks'   => $inProgress,
            'under_review_tasks'  => $underReview,
            'correction_tasks'    => $correctionRequired,
            'rejected_tasks'      => $rejected,
            'biometric_verified'  => $biometricVerified,
            'overdue_tasks'       => $overdue,
            'completion_pct'      => $percentage,
            'is_fully_completed'  => ($total > 0 && $completed === $total),
        ];
    }

    /**
     * Record an event in the immutable task_activity_logs.
     */
    public static function logTaskActivity(
        int $studentTaskId,
        ?int $actorId,
        string $actorRole,
        string $action,
        string $previousStatus,
        string $newStatus,
        string $details = '',
        string $ip = ''
    ): void {
        q("INSERT INTO task_activity_logs (student_task_id, actor_id, actor_role, action, previous_status, new_status, details, ip_address, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$studentTaskId, $actorId, $actorRole, $action, $previousStatus, $newStatus, $details, $ip ?: client_ip(), now()]
        );
    }
}
