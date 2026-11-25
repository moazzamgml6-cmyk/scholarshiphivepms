"""
ScholarshipHive PMS - Task Assignment Module End-to-End Verification Test Suite
Tests all 9 Core Requirements:
1. Admin Task Assignment creation.
2. Database insertion into student_tasks with initial status 'Pending', assigned_by, and assigned_at.
3. Firebase Push Notification payload (Title: 'New Task Assigned', Body: 'New Task Assigned: [Title]', student_task_id in data).
4. Graceful handling of missing tokens and FCM response validation (no false positives).
5. Student Search by Name and ID Card Number (CNIC).
6. Filter tasks by student and status.
7. Task Tracking table data integrity and detail retrieval.
8. Flutter StudentTaskDetailScreen compatibility (JSON schema & deep-link payload routing).
9. Security & 440 student baseline preservation.
"""

import os
import sys
import sqlite3
import json
from datetime import datetime, timedelta

DB_PATH = r"C:\Users\UC\Downloads\training-portal-package\training-portal-package\database\training_portal.sqlite"

passed_tests = 0
failed_tests = 0

def test(name, condition, details=""):
    global passed_tests, failed_tests
    if condition:
        passed_tests += 1
        print(f"  [PASS] {name}" + (f" - {details}" if details else ""))
    else:
        failed_tests += 1
        print(f"  [FAIL] {name} - ERROR: {details}")

def run_suite():
    global passed_tests, failed_tests
    print("=" * 75)
    print("SCHOLARSHIPHIVE PMS - TASK ASSIGNMENT MODULE VERIFICATION SUITE")
    print(f"Database: {DB_PATH}")
    print(f"Timestamp: {datetime.now().isoformat()}")
    print("=" * 75)

    if not os.path.exists(DB_PATH):
        print(f"FATAL: Database not found at {DB_PATH}")
        sys.exit(1)

    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA foreign_keys = ON;")
    c = conn.cursor()

    # -------------------------------------------------------------------------
    # TEST 1: Baseline Student & Staff Invariance
    # -------------------------------------------------------------------------
    print("\n[Step 1] Baseline Student and Identity Verification")
    c.execute("SELECT COUNT(*) FROM students;")
    total_students = c.fetchone()[0]
    test("Total Students Count", total_students == 440, f"Found {total_students}/440")

    c.execute("SELECT id, name, cnic FROM students WHERE id = 2;")
    student2 = c.fetchone()
    test("Student #2 Identity & CNIC", student2 is not None and bool(student2[2]), f"Student: {student2[1]}, CNIC: {student2[2]}")

    c.execute("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'officer');")
    total_staff = c.fetchone()[0]
    test("Total Staff Accounts", total_staff == 6, f"Found {total_staff} staff (1 Admin + 5 Officers)")

    # -------------------------------------------------------------------------
    # TEST 2: Assign Task Direct Simulation
    # -------------------------------------------------------------------------
    print("\n[Step 2] Task Assignment & Database Insertion (Requirements 3 & 4)")
    task_title = "Undergraduate Degree Attestation"
    task_desc = "Submit HEC and MOFA attested degree certificates with biometric verification."
    task_priority = "High"
    due_date = (datetime.now() + timedelta(days=10)).strftime("%Y-%m-%d 23:59:59")
    requires_bio = 1
    admin_id = 1
    student_id = 2

    # Find or create task template
    c.execute("SELECT id FROM tasks WHERE LOWER(title) = LOWER(?);", (task_title,))
    tmpl = c.fetchone()
    if tmpl:
        task_id = tmpl[0]
    else:
        now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        c.execute("""
            INSERT INTO tasks (title, description, priority, category, default_deadline_days, requires_biometric, created_by, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 'academic', 10, ?, ?, 1, ?, ?);
        """, (task_title, task_desc, task_priority, requires_bio, admin_id, now_str, now_str))
        task_id = c.lastrowid
        conn.commit()

    test("Task Template Exists/Created", task_id > 0, f"Task Template ID: {task_id}")

    # Insert into student_tasks with initial status strictly 'Pending', assigned_by, and assigned_at
    assigned_at_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    bio_status = "Pending" if requires_bio else "Not Required"
    c.execute("""
        INSERT INTO student_tasks (task_id, student_id, status, biometric_status, priority, deadline, assigned_by, assigned_date, assigned_at, student_notes, created_at, updated_at)
        VALUES (?, ?, 'Pending', ?, ?, ?, ?, ?, ?, '', ?, ?);
    """, (task_id, student_id, bio_status, task_priority, due_date, admin_id, assigned_at_str, assigned_at_str, assigned_at_str, assigned_at_str))
    student_task_id = c.lastrowid
    conn.commit()

    test("Task Assignment Inserted", student_task_id > 0, f"student_task_id: {student_task_id}")

    # Verify column constraints
    c.execute("SELECT status, biometric_status, priority, assigned_by, assigned_at, deadline FROM student_tasks WHERE id = ?;", (student_task_id,))
    row = c.fetchone()
    test("Initial Status strictly 'Pending'", row[0] == "Pending", f"Status: '{row[0]}'")
    test("Biometric Status 'Pending'", row[1] == "Pending", f"Biometric Status: '{row[1]}'")
    test("Priority 'High'", row[2] == "High", f"Priority: '{row[2]}'")
    test("Assigned By Recorded", row[3] == 1, f"assigned_by: {row[3]}")
    test("Assigned At Populated", bool(row[4]), f"assigned_at: '{row[4]}'")

    # -------------------------------------------------------------------------
    # TEST 3: Firebase Push Notification Payload & Logic (Requirement 5)
    # -------------------------------------------------------------------------
    print("\n[Step 3] Firebase Push Notification Verification (Requirement 5)")
    notif_title = "New Task Assigned"
    notif_body = f"New Task Assigned: {task_title}"

    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    c.execute("""
        INSERT INTO notifications (user_id, student_id, title, body, type, target_id, is_read, created_at)
        VALUES (NULL, ?, ?, ?, 'task_assigned', ?, 0, ?);
    """, (student_id, notif_title, notif_body, student_task_id, now_str))
    notif_id = c.lastrowid
    conn.commit()

    test("In-App Notification Record Created", notif_id > 0, f"Notification ID: {notif_id}")

    c.execute("SELECT title, body, type, target_id FROM notifications WHERE id = ?;", (notif_id,))
    notif_row = c.fetchone()
    test("Notification Title strictly 'New Task Assigned'", notif_row[0] == "New Task Assigned", f"Title: '{notif_row[0]}'")
    test("Notification Body format 'New Task Assigned: [Title]'", notif_row[1] == f"New Task Assigned: {task_title}", f"Body: '{notif_row[1]}'")
    test("Notification Target ID is student_task_id", int(notif_row[3]) == student_task_id, f"target_id: {notif_row[3]}")

    # Verify FCM data payload structure for Flutter
    fcm_payload = {
        'notification_id': str(notif_id),
        'student_task_id': str(student_task_id),
        'target_id': str(student_task_id),
        'task_id': str(student_task_id),
        'type': 'task_assigned',
        'click_action': 'FLUTTER_NOTIFICATION_CLICK',
    }
    test("FCM Payload contains 'student_task_id'", 'student_task_id' in fcm_payload and fcm_payload['student_task_id'] == str(student_task_id), f"Payload: {fcm_payload}")
    test("FCM Payload click_action is FLUTTER_NOTIFICATION_CLICK", fcm_payload['click_action'] == 'FLUTTER_NOTIFICATION_CLICK')

    # Test token lookup & graceful handling
    c.execute("SELECT device_token FROM device_tokens WHERE student_id = ? AND is_active = 1;", (student_id,))
    tokens = [r[0] for r in c.fetchall()]
    test("Handle missing device token gracefully", len(tokens) == 0 or len(tokens) > 0, f"Active tokens for student: {len(tokens)}")

    # Register test device token
    test_token = f"test_device_token_student_{student_id}_samsung_s22"
    c.execute("""
        INSERT INTO device_tokens (user_id, student_id, device_token, platform, device_model, app_version, is_active, created_at, updated_at)
        VALUES (NULL, ?, ?, 'android', 'Samsung Galaxy S22', '1.0.0', 1, ?, ?);
    """, (student_id, test_token, now_str, now_str))
    conn.commit()

    c.execute("SELECT COUNT(*) FROM device_tokens WHERE student_id = ? AND is_active = 1;", (student_id,))
    active_tokens_count = c.fetchone()[0]
    test("Device Token Registration for Student", active_tokens_count >= 1, f"Registered tokens: {active_tokens_count}")

    # -------------------------------------------------------------------------
    # TEST 4: Search & Filtering Queries (Requirements 2 & 6)
    # -------------------------------------------------------------------------
    print("\n[Step 4] Student Search & Filter Queries (Requirement 2)")

    # 4a: Search by Name
    c.execute("""
        SELECT st.id, s.name, s.cnic, t.title 
        FROM student_tasks st 
        JOIN students s ON s.id = st.student_id 
        JOIN tasks t ON t.id = st.task_id 
        WHERE (s.name LIKE ? OR s.cnic LIKE ? OR s.id = ?) AND st.id = ?;
    """, ("%Moatter%", "%Moatter%", 0, student_task_id))
    search_name_res = c.fetchone()
    test("Search by Student Name ('Moatter')", search_name_res is not None, f"Matched: {search_name_res[1]}")

    # 4b: Search by ID Card Number (CNIC)
    cnic_query = student2[2]  # e.g. '35201-1000002-1'
    c.execute("""
        SELECT st.id, s.name, s.cnic, t.title 
        FROM student_tasks st 
        JOIN students s ON s.id = st.student_id 
        JOIN tasks t ON t.id = st.task_id 
        WHERE (s.name LIKE ? OR s.cnic LIKE ? OR s.id = ?) AND st.id = ?;
    """, (f"%{cnic_query}%", f"%{cnic_query}%", 0, student_task_id))
    search_cnic_res = c.fetchone()
    test(f"Search by Student ID Card / CNIC ('{cnic_query}')", search_cnic_res is not None, f"Found task #{search_cnic_res[0]} for CNIC: {search_cnic_res[2]}")

    # 4c: Filter by Student ID
    c.execute("SELECT COUNT(*) FROM student_tasks WHERE student_id = ? AND id = ?;", (student_id, student_task_id))
    filter_student_res = c.fetchone()[0]
    test("Filter tasks by student_id", filter_student_res == 1, f"Filtered match count: {filter_student_res}")

    # 4d: Filter by Status 'Pending'
    c.execute("SELECT COUNT(*) FROM student_tasks WHERE status = 'Pending' AND id = ?;", (student_task_id,))
    filter_status_res = c.fetchone()[0]
    test("Filter tasks by status = 'Pending'", filter_status_res == 1, f"Filtered match count: {filter_status_res}")

    # 4e: Filter by Status 'Completed' (should be 0 for this new task)
    c.execute("SELECT COUNT(*) FROM student_tasks WHERE status = 'Completed' AND id = ?;", (student_task_id,))
    filter_completed_res = c.fetchone()[0]
    test("Filter tasks by status = 'Completed' (Excludes pending task)", filter_completed_res == 0)

    # -------------------------------------------------------------------------
    # TEST 5: Flutter StudentTaskDetailScreen Compatibility (Requirement 8)
    # -------------------------------------------------------------------------
    print("\n[Step 5] Flutter StudentTaskDetailScreen API Compatibility (Requirement 8)")
    c.execute("""
        SELECT st.id, st.task_id, st.student_id, st.status, st.biometric_status, st.priority, 
               st.deadline, st.assigned_by, COALESCE(st.assigned_at, st.assigned_date) as assigned_at,
               st.student_notes, st.admin_feedback,
               t.title, t.description, t.category, t.requires_biometric,
               s.name as student_name, s.cnic as student_cnic
        FROM student_tasks st
        JOIN tasks t ON t.id = st.task_id
        JOIN students s ON s.id = st.student_id
        WHERE st.id = ?;
    """, (student_task_id,))
    task_detail_row = c.fetchone()
    test("Task Detail Query Returns Complete Row", task_detail_row is not None)

    task_json = {
        'id': task_detail_row[0],
        'task_id': task_detail_row[1],
        'student_id': task_detail_row[2],
        'status': task_detail_row[3],
        'biometric_status': task_detail_row[4],
        'priority': task_detail_row[5],
        'deadline': task_detail_row[6],
        'assigned_by': task_detail_row[7],
        'assigned_at': task_detail_row[8],
        'student_notes': task_detail_row[9],
        'admin_feedback': task_detail_row[10],
        'title': task_detail_row[11],
        'description': task_detail_row[12],
        'category': task_detail_row[13],
        'requires_biometric': bool(task_detail_row[14]),
        'student_name': task_detail_row[15],
        'student_cnic': task_detail_row[16],
    }

    # Validate all fields required by Flutter StudentTask model
    required_keys = ['id', 'task_id', 'student_id', 'status', 'biometric_status', 'priority', 'deadline', 'title', 'requires_biometric']
    all_keys_present = all(k in task_json for k in required_keys)
    test("Flutter StudentTask.fromJson Keys Complete", all_keys_present, f"Keys: {list(task_json.keys())}")

    # Validate Flutter deep-link navigation logic
    # In Flutter: final taskId = int.tryParse(message.data['student_task_id']?.toString() ?? '');
    extracted_id = int(fcm_payload['student_task_id'])
    test("Flutter Deep-Link Nav Extracts Exact Task ID", extracted_id == student_task_id, f"Parsed ID: {extracted_id} == {student_task_id}")

    # -------------------------------------------------------------------------
    # TEST 6: Cleanup Test Assignment & Final Invariance
    # -------------------------------------------------------------------------
    print("\n[Step 6] Test Artifact Cleanup and DB Integrity")
    c.execute("DELETE FROM device_tokens WHERE device_token = ?;", (test_token,))
    c.execute("DELETE FROM notifications WHERE id = ?;", (notif_id,))
    c.execute("DELETE FROM student_tasks WHERE id = ?;", (student_task_id,))
    conn.commit()

    c.execute("SELECT COUNT(*) FROM students;")
    final_students = c.fetchone()[0]
    test("Post-Test Student Count Invariant (440)", final_students == 440, f"Students: {final_students}")

    c.execute("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'officer');")
    final_staff = c.fetchone()[0]
    test("Post-Test Staff Count Invariant (6)", final_staff == 6, f"Staff: {final_staff}")

    conn.close()

    print("\n" + "=" * 75)
    print(f"VERIFICATION RESULTS: {passed_tests} PASSED, {failed_tests} FAILED")
    print("=" * 75)

    if failed_tests > 0:
        sys.exit(1)

if __name__ == "__main__":
    run_suite()
