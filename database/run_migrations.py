import sqlite3
import os
import glob

db_dir = r'C:\Users\UC\Downloads\training-portal-package\training-portal-package\database'
migration_file = os.path.join(db_dir, 'migrations', '001_create_task_and_biometric_tables.sql')

target_dbs = [
    os.path.join(db_dir, 'training_portal.sqlite'),
    os.path.join(db_dir, 'training_portal_reset_backup.sqlite')
]

print("=== ScholarshipHive PMS Database Migration Runner ===")
with open(migration_file, 'r', encoding='utf-8') as f:
    sql_script = f.read()

for db_path in target_dbs:
    if not os.path.exists(db_path):
        print(f"Skipping missing db: {db_path}")
        continue
    
    print(f"\nMigrating: {os.path.basename(db_path)}")
    conn = sqlite3.connect(db_path)
    c = conn.cursor()
    c.execute("PRAGMA foreign_keys = ON;")
    
    # Execute the migration script
    c.executescript(sql_script)
    conn.commit()
    print("  [SUCCESS] Applied 001_create_task_and_biometric_tables.sql")

    # Verify tables
    c.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [row[0] for row in c.fetchall()]
    print(f"  [INFO] Total tables in database: {len(tables)}")
    print(f"  [INFO] Tables: {', '.join(tables)}")

    # Verify seed tasks
    c.execute("SELECT id, title, priority, requires_biometric FROM tasks ORDER BY id")
    tasks = c.fetchall()
    print(f"  [INFO] Tasks templates ({len(tasks)} seeded):")
    for t in tasks:
        print(f"    - ID {t[0]}: {t[1]} [Priority: {t[2]}, Biometric Req: {t[3]}]")

    conn.close()

print("\nAll migrations completed successfully!")
