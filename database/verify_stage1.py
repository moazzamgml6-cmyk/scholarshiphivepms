import sqlite3

backup_path = r'C:\Users\UC\Downloads\training-portal-package\training-portal-package\database\backups\training_portal_pre_migration_440_students.sqlite'
active_path = r'C:\Users\UC\Downloads\training-portal-package\training-portal-package\database\training_portal.sqlite'

cb = sqlite3.connect(backup_path).cursor()
ca = sqlite3.connect(active_path).cursor()

print("=== STAGE 1 BEFORE AND AFTER AUDIT ===")
cb.execute("SELECT count(*) FROM students")
st_before = cb.fetchone()[0]
ca.execute("SELECT count(*) FROM students")
st_after = ca.fetchone()[0]
print(f"Students before migration : {st_before}")
print(f"Students after migration  : {st_after}")
print(f"Student data preserved    : {st_before == st_after == 440}")

cb.execute("SELECT count(*) FROM users WHERE role = 'officer'")
off_before = cb.fetchone()[0]
ca.execute("SELECT count(*) FROM users WHERE role = 'officer'")
off_after = ca.fetchone()[0]
print(f"Officers before migration : {off_before}")
print(f"Officers after migration  : {off_after}")
print(f"Officer data preserved    : {off_before == off_after}")

print("\n=== NEW TABLES VERIFICATION ===")
for tbl in ['tasks', 'student_tasks', 'notifications', 'device_tokens', 'biometric_verification_logs', 'task_activity_logs']:
    ca.execute(f"SELECT count(*) FROM {tbl}")
    cnt = ca.fetchone()[0]
    print(f"Table '{tbl:28}': {cnt} rows")

print("\n=== FOREIGN KEY & SCHEMA INTEGRITY ===")
ca.execute("PRAGMA foreign_key_check")
fk_errors = ca.fetchall()
print(f"Foreign key integrity check: {'PASSED (0 violations)' if not fk_errors else f'FAILED ({len(fk_errors)} violations)'}")

ca.execute("PRAGMA integrity_check")
integrity = ca.fetchone()[0]
print(f"SQLite database integrity check: {integrity}")
