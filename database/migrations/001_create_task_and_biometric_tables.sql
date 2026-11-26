-- Migration: 001_create_task_and_biometric_tables.sql
-- Description: Creates task management, real-time notification, device token, biometric verification, and audit tables.
-- Date: 2026-11-18

-- 1. Tasks catalog / templates table
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT DEFAULT '',
    priority TEXT NOT NULL DEFAULT 'Normal', -- 'High', 'Normal', 'Low'
    category TEXT DEFAULT 'general',         -- 'document', 'fee', 'biometric', 'academic', 'visa', 'general'
    default_deadline_days INTEGER DEFAULT 7,
    requires_biometric INTEGER NOT NULL DEFAULT 0, -- 0 = No, 1 = Yes
    created_by INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tasks_active ON tasks(is_active);
CREATE INDEX IF NOT EXISTS idx_tasks_priority ON tasks(priority);

-- 2. Student tasks assignments table
CREATE TABLE IF NOT EXISTS student_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'Assigned', -- 'Assigned', 'Pending', 'In Progress', 'Submitted', 'Under Review', 'Correction Required', 'Completed', 'Rejected', 'Cancelled'
    biometric_status TEXT NOT NULL DEFAULT 'Not Required', -- 'Not Required', 'Pending', 'Verified', 'Failed'
    priority TEXT NOT NULL DEFAULT 'Normal',
    deadline TEXT DEFAULT NULL,
    completed_at TEXT DEFAULT NULL,
    assigned_by INTEGER NOT NULL DEFAULT 1,
    assigned_date TEXT NOT NULL,
    assigned_at TEXT DEFAULT NULL,
    student_notes TEXT DEFAULT '',
    admin_feedback TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_student_tasks_student_id ON student_tasks(student_id);
CREATE INDEX IF NOT EXISTS idx_student_tasks_status ON student_tasks(status);
CREATE INDEX IF NOT EXISTS idx_student_tasks_task_id ON student_tasks(task_id);
CREATE INDEX IF NOT EXISTS idx_student_tasks_biometric ON student_tasks(biometric_status);

-- 3. Notifications table (in-app notifications for students and staff)
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    student_id INTEGER DEFAULT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'general', -- 'task_assigned', 'task_updated', 'deadline_near', 'correction_requested', 'task_completed', 'biometric_alert', 'all_completed', 'general'
    target_id INTEGER DEFAULT NULL,      -- references student_tasks(id) or relevant record
    is_read INTEGER NOT NULL DEFAULT 0,
    read_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notifications_student ON notifications(student_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at);

-- 4. Device tokens table (FCM push registration tokens)
CREATE TABLE IF NOT EXISTS device_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    student_id INTEGER DEFAULT NULL,
    device_token TEXT NOT NULL UNIQUE,
    platform TEXT NOT NULL DEFAULT 'android', -- 'android', 'ios', 'web'
    device_model TEXT DEFAULT '',
    app_version TEXT DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_device_tokens_student ON device_tokens(student_id);
CREATE INDEX IF NOT EXISTS idx_device_tokens_token ON device_tokens(device_token);

-- 5. Biometric verification event logs
CREATE TABLE IF NOT EXISTS biometric_verification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    student_task_id INTEGER DEFAULT NULL,
    verification_method TEXT NOT NULL, -- 'android_native_biometric', 'external_usb_scanner'
    verification_result TEXT NOT NULL, -- 'Verified', 'Failed', 'Cancelled', 'Error'
    device_info TEXT DEFAULT '',
    error_message TEXT DEFAULT '',
    ip_address TEXT DEFAULT '',
    timestamp TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (student_task_id) REFERENCES student_tasks(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_biometric_student ON biometric_verification_logs(student_id);
CREATE INDEX IF NOT EXISTS idx_biometric_task ON biometric_verification_logs(student_task_id);
CREATE INDEX IF NOT EXISTS idx_biometric_time ON biometric_verification_logs(timestamp);

-- 6. Immutable Task Activity Logs (Audit trail for every task event)
CREATE TABLE IF NOT EXISTS task_activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_task_id INTEGER NOT NULL,
    actor_id INTEGER DEFAULT NULL,
    actor_role TEXT NOT NULL, -- 'admin', 'officer', 'student', 'system'
    action TEXT NOT NULL,     -- 'assigned', 'viewed', 'started', 'submitted', 'biometric_attempt', 'review_started', 'approved', 'rejected', 'correction_requested', 'cancelled', 'deadline_changed'
    previous_status TEXT DEFAULT '',
    new_status TEXT DEFAULT '',
    details TEXT DEFAULT '',
    ip_address TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (student_task_id) REFERENCES student_tasks(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_task_activity_task ON task_activity_logs(student_task_id);
CREATE INDEX IF NOT EXISTS idx_task_activity_actor ON task_activity_logs(actor_id, actor_role);
CREATE INDEX IF NOT EXISTS idx_task_activity_time ON task_activity_logs(created_at);

-- 7. Real-time Notification Logs (Tracks sent, failed, retry status)
CREATE TABLE IF NOT EXISTS notification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER,
    user_id INTEGER,
    student_task_id INTEGER,
    notification_type TEXT NOT NULL DEFAULT 'task_assigned',
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    data_payload TEXT DEFAULT '{}',
    device_token TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT '',
    retry_count INTEGER DEFAULT 0,
    sent_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (student_task_id) REFERENCES student_tasks(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_notif_logs_student ON notification_logs(student_id);
CREATE INDEX IF NOT EXISTS idx_notif_logs_status ON notification_logs(status);
CREATE INDEX IF NOT EXISTS idx_notif_logs_type ON notification_logs(notification_type);
CREATE INDEX IF NOT EXISTS idx_notif_logs_task ON notification_logs(student_task_id);

-- 8. Seed Standard Initial Task Templates
INSERT INTO tasks (id, title, description, priority, category, default_deadline_days, requires_biometric, created_by, is_active, created_at, updated_at)
VALUES 
(1, 'Submit Passport Copy', 'Upload high-resolution color scan of all valid passport pages including photo page and exit/entry stamps.', 'High', 'document', 5, 0, 1, 1, datetime('now'), datetime('now')),
(2, 'Submit Educational Documents', 'Submit verified copies of Matric/O-Level, Inter/A-Level, Bachelor degree transcripts and certificates.', 'High', 'academic', 7, 0, 1, 1, datetime('now'), datetime('now')),
(3, 'Fee Verification', 'Submit the bank deposit receipt or transaction screenshot for registration and consultancy processing fee.', 'Normal', 'fee', 3, 0, 1, 1, datetime('now'), datetime('now')),
(4, 'Document Verification', 'Our processing team verifies your academic transcripts, IELTS/TOEFL scores, and recommendation letters.', 'High', 'document', 7, 0, 1, 1, datetime('now'), datetime('now')),
(5, 'Biometric Verification', 'Complete identity and thumbprint / biometric verification via your mobile device to validate your student dossier.', 'High', 'biometric', 3, 1, 1, 1, datetime('now'), datetime('now')),
(6, 'Application Processing', 'University portal account creation, course selection, and application submission to target universities.', 'Normal', 'academic', 14, 0, 1, 1, datetime('now'), datetime('now')),
(7, 'Final Approval', 'Final review by PMS Lead and Counselor for university admission confirmation and visa filing readiness.', 'High', 'visa', 5, 0, 1, 1, datetime('now'), datetime('now'))
ON CONFLICT(id) DO NOTHING;
