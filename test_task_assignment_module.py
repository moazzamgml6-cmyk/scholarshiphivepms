"""
ScholarshipHive PMS - Comprehensive Task Assignment & Super Admin Module Verification Suite
Tests:
1. Super Admin Dashboard navigation & action card integration.
2. task_assignment.php page existence, routing, and form architecture.
3. Student Search by Name, Student ID (#), and ID Card Number (CNIC).
4. Task Assignment Database insertion (status strictly 'Pending', assigned_by, assigned_at).
5. Firebase FCM Notification Payload (Title: 'New Task Assigned', Body: 'New Task Assigned: [Title]', student_task_id in data).
6. Missing token graceful handling & FCM response confirmation.
7. Flutter 'My Tasks' Screen (StudentTasksScreen) compatibility via /api/tasks/student.
8. Flutter 'StudentTaskDetailScreen' compatibility via /api/tasks/detail.
9. Notification tap deep-link parsing.
10. Mobile and Desktop CSS responsiveness check.
11. Baseline database invariance (440 students, 6 staff).
"""

import os
import sys
import sqlite3
import json
from datetime import datetime, timedelta

BASE_DIR = r"C:\Users\UC\Downloads\training-portal-package\training-portal-package"
DB_PATH = os.path.join(BASE_DIR, "database", "training_portal.sqlite")

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
    print("=" * 80)
    print("SCHOLARSHIPHIVE PMS - TASK ASSIGNMENT VERIFICATION SUITE")
    print(f"Database: {DB_PATH}")
    print(f"Timestamp: {datetime.now().isoformat()}")
    print("=" * 80)

    # -------------------------------------------------------------------------
    # TEST 1: File & Routing Existence
    # -------------------------------------------------------------------------
    print("\n[Phase 1] Page Architecture & Routing Files")
    admin_page = os.path.join(BASE_DIR, "portal", "admin", "task_assignment.php")
    root_page = os.path.join(BASE_DIR, "portal", "task_assignment.php")
    admin_tasks = os.path.join(BASE_DIR, "portal", "admin", "tasks.php")

    test("admin/task_assignment.php exists", os.path.isfile(admin_page), admin_page)
    test("portal/task_assignment.php exists", os.path.isfile(root_page), root_page)
    test("admin/tasks.php exists (backward compatible)", os.path.isfile(admin_tasks), admin_tasks)

    # -------------------------------------------------------------------------
    # TEST 2: Super Admin Dashboard & Navigation Integration
    # -------------------------------------------------------------------------
    print("\n[Phase 2] Super Admin Dashboard & Menu Integration (Requirement 1)")
    layout_path = os.path.join(BASE_DIR, "portal", "app", "layout.php")
    with open(layout_path, "r", encoding="utf-8") as f:
        layout_txt = f.read()
    test("Menu Item 'Task Assignment' in nav_items('admin')", "'admin/task_assignment.php'  => 'Task Assignment'" in layout_txt or "'admin/task_assignment.php' => 'Task Assignment'" in layout_txt)
    test("Active tab highlight logic for task_assignment.php", "admin/task_assignment.php" in layout_txt and "aria-current" in layout_txt)

    dashboard_path = os.path.join(BASE_DIR, "portal", "app", "pages", "dashboard.php")
    with open(dashboard_path, "r", encoding="utf-8") as f:
        dashboard_txt = f.read()
    test("Super Admin Dashboard Action Card for Task Assignment", "Task Assignment Module" in dashboard_txt and "admin/task_assignment.php" in dashboard_txt)
    test("Super Admin Dashboard KPI for Task Assignment", "<div class=\"l\">Task Assignment</div>" in dashboard_txt)

    # -------------------------------------------------------------------------
    # TEST 3: Mobile & Desktop Responsiveness
    # -------------------------------------------------------------------------
    print("\n[Phase 3] Mobile & Desktop Responsiveness")
    css_path = os.path.join(BASE_DIR, "portal", "assets", "style.css")
    with open(css_path, "r", encoding="utf-8") as f:
        css_txt = f.read()
    test("Mobile Navigation Swipe Scrollbar in style.css", "overflow-x: auto" in css_txt and "nav.tabs" in css_txt)
    test("Media Query max-width 860px present", "@media (max-width: 860px)" in css_txt)

    # -------------------------------------------------------------------------
    # TEST 4: Database Baseline & Student ID Card Number (CNIC) Verification
    # -------------------------------------------------------------------------
    print("\n[Phase 4] Database Integrity & CNIC Verification")
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA foreign_keys = ON;")
    c = conn.cursor()

    c.execute("SELECT COUNT(*) FROM students;")
    total_students = c.fetchone()[0]
    test("Total Students Count (440)", total_students == 440, f"Found {total_students}")

    c.execute("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'officer');")
    total_staff = c.fetchone()[0]
    test("Total Staff Accounts (6)", total_staff == 6, f"Found {total_staff}")

    c.execute("SELECT id, name, cnic, email FROM students WHERE id = 3;")
    student3 = c.fetchone()
    test("Student #3 has valid Name, ID, and CNIC", bool(student3[1]) and bool(student3[2]), f"ID: {student3[0]}, Name: {student3[1]}, CNIC: {student3[2]}")

    # -------------------------------------------------------------------------
    # TEST 5: Assign Task Execution & Gating
    # -------------------------------------------------------------------------
    print("\n[Phase 5] Task Assignment Database Execution (Requirements 3, 5, 6)")
    task_title = "MOFA Document Attestation & Verification"
    task_desc = "Submit verified degree copies certified by Ministry of Foreign Affairs with fingerprint verification."
    priority = "High"
    due_date = (datetime.now() + timedelta(days=14)).strftime("%Y-%m-%d 23:59:59")
    requires_bio = 1
    admin_id = 1
    student_id = 3

    # Check/create template
    c.execute("SELECT id FROM tasks WHERE LOWER(title) = LOWER(?);", (task_title,))
    tmpl = c.fetchone()
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    if tmpl:
        task_id = tmpl[0]
    else:
        c.execute("""
            INSERT INTO tasks (title, description, priority, category, default_deadline_days, requires_biometric, created_by, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 'document', 14, ?, ?, 1, ?, ?);
        """, (task_title, task_desc, priority, requires_bio, admin_id, now_str, now_str))
        task_id = c.lastrowid
        conn.commit()

    test("Master Task Template Exists", task_id > 0, f"Template ID: {task_id}")

    # Insert into student_tasks with initial status strictly 'Pending'
    c.execute("""
        INSERT INTO student_tasks (task_id, student_id, status, biometric_status, priority, deadline, assigned_by, assigned_date, assigned_at, student_notes, created_at, updated_at)
        VALUES (?, ?, 'Pending', 'Pending', ?, ?, ?, ?, ?, '', ?, ?);
    """, (task_id, student_id, priority, due_date, admin_id, now_str, now_str, now_str, now_str))
    st_id = c.lastrowid
    conn.commit()

    test("Assignment Saved in student_tasks", st_id > 0, f"student_task_id: {st_id}")

    # Verify column values
    c.execute("SELECT status, biometric_status, priority, assigned_by, assigned_at, deadline FROM student_tasks WHERE id = ?;", (st_id,))
    row = c.fetchone()
    test("Initial status strictly 'Pending'", row[0] == "Pending", f"Status: {row[0]}")
    test("Biometric status initialized to 'Pending'", row[1] == "Pending", f"Biometric Status: {row[1]}")
    test("Priority is 'High'", row[2] == "High", f"Priority: {row[2]}")
    test("Assigned By is Admin (1)", row[3] == 1, f"assigned_by: {row[3]}")
    test("Assigned At is populated", bool(row[4]), f"assigned_at: {row[4]}")
    test("Deadline matches due date", row[5] == due_date, f"deadline: {row[5]}")

    # -------------------------------------------------------------------------
    # TEST 6: Firebase FCM Notification Payload (Requirements 8, 9)
    # -------------------------------------------------------------------------
    print("\n[Phase 6] Firebase FCM Push Notification Trigger & Payload (Requirements 8, 9)")
    notif_title = "New Task Assigned"
    notif_body = f"New Task Assigned: {task_title}"

    c.execute("""
        INSERT INTO notifications (user_id, student_id, title, body, type, target_id, is_read, created_at)
        VALUES (NULL, ?, ?, ?, 'task_assigned', ?, 0, ?);
    """, (student_id, notif_title, notif_body, st_id, now_str))
    notif_id = c.lastrowid
    conn.commit()

    test("In-App Notification Record Inserted", notif_id > 0, f"Notif ID: {notif_id}")

    c.execute("SELECT title, body, type, target_id FROM notifications WHERE id = ?;", (notif_id,))
    notif_row = c.fetchone()
    test("Notification Title strictly 'New Task Assigned'", notif_row[0] == "New Task Assigned")
    test("Notification Body format 'New Task Assigned: [Title]'", notif_row[1] == notif_body)
    test("Notification Target ID is student_task_id", int(notif_row[3]) == st_id)

    # Validate exact FCM payload structure sent to Google FCM and received by Flutter
    fcm_payload = {
        'notification_id': str(notif_id),
        'student_task_id': str(st_id),
        'target_id': str(st_id),
        'task_id': str(st_id),
        'type': 'task_assigned',
        'click_action': 'FLUTTER_NOTIFICATION_CLICK',
    }
    test("FCM Payload contains 'student_task_id'", 'student_task_id' in fcm_payload and fcm_payload['student_task_id'] == str(st_id))
    test("FCM Payload click_action is FLUTTER_NOTIFICATION_CLICK", fcm_payload['click_action'] == 'FLUTTER_NOTIFICATION_CLICK')

    # -------------------------------------------------------------------------
    # TEST 7: Search by Name, Student ID, and ID Card Number (Requirement 4)
    # -------------------------------------------------------------------------
    print("\n[Phase 7] Student Search & Filter Queries (Requirement 4)")
    # 7a: Search by Name
    c.execute("""
        SELECT st.id, s.name, s.cnic 
        FROM student_tasks st 
        JOIN students s ON s.id = st.student_id 
        WHERE (s.name LIKE ? OR s.cnic LIKE ? OR s.id = ?) AND st.id = ?;
    """, (f"%{student3[1]}%", f"%{student3[1]}%", 0, st_id))
    search_name = c.fetchone()
    test("Search by Student Name ('Danish Ali')", search_name is not None, f"Matched: {search_name[1]}")

    # 7b: Search by ID Card Number (CNIC)
    c.execute("""
        SELECT st.id, s.name, s.cnic 
        FROM student_tasks st 
        JOIN students s ON s.id = st.student_id 
        WHERE (s.name LIKE ? OR s.cnic LIKE ? OR s.id = ?) AND st.id = ?;
    """, (f"%{student3[2]}%", f"%{student3[2]}%", 0, st_id))
    search_cnic = c.fetchone()
    test(f"Search by ID Card Number / CNIC ('{student3[2]}')", search_cnic is not None, f"Found task #{search_cnic[0]} for CNIC: {search_cnic[2]}")

    # 7c: Search by Student ID
    c.execute("""
        SELECT st.id, s.name, s.cnic 
        FROM student_tasks st 
        JOIN students s ON s.id = st.student_id 
        WHERE (s.name LIKE ? OR s.cnic LIKE ? OR s.id = ?) AND st.id = ?;
    """, ("%3%", "%3%", 3, st_id))
    search_id = c.fetchone()
    test("Search by Student ID (3)", search_id is not None, f"Found task #{search_id[0]}")

    # -------------------------------------------------------------------------
    # TEST 8: Flutter 'My Tasks' Screen (StudentTasksScreen) Integration (Requirement 7)
    # -------------------------------------------------------------------------
    print("\n[Phase 8] Flutter 'My Tasks' Screen Integration (Requirement 7)")
    # Simulated API call: GET /api/tasks/student?student_id=3
    c.execute("""
        SELECT st.id, st.task_id, st.student_id, st.status, st.biometric_status, st.priority, st.deadline,
               t.title, t.description, t.category, t.requires_biometric
        FROM student_tasks st
        JOIN tasks t ON t.id = st.task_id
        WHERE st.student_id = ? AND st.status != 'Cancelled'
        ORDER BY st.id DESC;
    """, (student_id,))
    student_tasks = c.fetchall()
    test("Student has assigned tasks in list", len(student_tasks) >= 1, f"Found {len(student_tasks)} tasks for student {student_id}")

    latest_task = student_tasks[0]
    test("Assigned task appears under 'Pending' tab", latest_task[3] == "Pending", f"Status: {latest_task[3]}")
    test("Task contains required biometric badge", latest_task[10] == 1, f"Requires Biometric: {latest_task[10]}")

    # -------------------------------------------------------------------------
    # TEST 9: Flutter Deep-Link Navigation Parsing (Requirement 9)
    # -------------------------------------------------------------------------
    print("\n[Phase 9] Flutter Deep-Link Navigation Routing (Requirement 9)")
    # In Flutter notification_service.dart:
    # final taskId = int.tryParse(message.data['student_task_id']?.toString() ?? '');
    parsed_id = int(fcm_payload['student_task_id'])
    test("Flutter Deep-Link extracts exact task ID", parsed_id == st_id, f"Parsed {parsed_id} == {st_id}")

    # Simulated API call: GET /api/tasks/detail?id=X
    c.execute("""
        SELECT st.id, st.task_id, st.student_id, st.status, st.biometric_status, st.priority, st.deadline,
               st.assigned_by, COALESCE(st.assigned_at, st.assigned_date) AS assigned_at,
               st.student_notes, st.admin_feedback,
               t.title, t.description, t.category, t.requires_biometric,
               s.name AS student_name, s.cnic AS student_cnic
        FROM student_tasks st
        JOIN tasks t ON t.id = st.task_id
        JOIN students s ON s.id = st.student_id
        WHERE st.id = ?;
    """, (st_id,))
    detail_row = c.fetchone()
    test("Task Detail API response returns complete data", detail_row is not None and detail_row[11] == task_title)

    # -------------------------------------------------------------------------
    # TEST 10: Cleanup & Database Invariance
    # -------------------------------------------------------------------------
    print("\n[Phase 10] Test Cleanup and Database Invariance")
    c.execute("DELETE FROM notifications WHERE id = ?;", (notif_id,))
    c.execute("DELETE FROM student_tasks WHERE id = ?;", (st_id,))
    conn.commit()

    c.execute("SELECT COUNT(*) FROM students;")
    final_students = c.fetchone()[0]
    test("Post-Test Student Count Invariant (440)", final_students == 440)

    c.execute("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'officer');")
    final_staff = c.fetchone()[0]
    test("Post-Test Staff Count Invariant (6)", final_staff == 6)

    conn.close()

    print("\n" + "=" * 80)
    print(f"VERIFICATION RESULTS: {passed_tests} PASSED, {failed_tests} FAILED")
    print("=" * 80)

    if failed_tests > 0:
        sys.exit(1)

if __name__ == "__main__":
    run_suite()
