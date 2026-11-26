import sqlite3

db_path = r"C:\Users\UC\Downloads\training-portal-package\training-portal-package\database\training_portal.sqlite"
conn = sqlite3.connect(db_path)
cur = conn.cursor()

print("--- NOTIFICATION_LOGS COLUMNS ---")
cur.execute("PRAGMA table_info(notification_logs)")
for row in cur.fetchall():
    print(row)

print("\n--- TASK_ACTIVITY_LOGS COLUMNS ---")
cur.execute("PRAGMA table_info(task_activity_logs)")
for row in cur.fetchall():
    print(row)

print("\n--- STUDENT_TASKS COLUMNS ---")
cur.execute("PRAGMA table_info(student_tasks)")
for row in cur.fetchall():
    print(row)

print("\n--- TASKS COLUMNS ---")
cur.execute("PRAGMA table_info(tasks)")
for row in cur.fetchall():
    print(row)

print("\n--- STUDENTS SAMPLE COLUMNS ---")
cur.execute("PRAGMA table_info(students)")
for row in cur.fetchall():
    print(row)
