-- Migration Rollback: 001_rollback_task_and_biometric_tables.sql
-- Description: Reversibly drops the six task management and biometric verification tables in reverse dependency order.
-- Safety: Existing 'students', 'users', 'student_documents', and other core tables are completely untouched.

PRAGMA foreign_keys = OFF;

-- Drop activity logs and verification logs first
DROP TABLE IF EXISTS task_activity_logs;
DROP TABLE IF EXISTS biometric_verification_logs;
DROP TABLE IF EXISTS device_tokens;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS student_tasks;
DROP TABLE IF EXISTS tasks;

PRAGMA foreign_keys = ON;
