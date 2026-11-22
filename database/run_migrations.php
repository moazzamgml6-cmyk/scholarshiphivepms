<?php
declare(strict_types=1);

/**
 * ScholarshipHive PMS Database Migration Runner
 * Executes all pending SQL migration files in order.
 */

$rootDir = dirname(__DIR__);
$dbDir = __DIR__;
$migrationDir = __DIR__ . '/migrations';

$targetDbs = [
    $dbDir . '/training_portal.sqlite',
    $dbDir . '/training_portal_reset_backup.sqlite',
];

echo "=== ScholarshipHive PMS Database Migration Runner ===\n";

if (!is_dir($migrationDir)) {
    exit("Migration directory not found: $migrationDir\n");
}

$migrationFiles = glob($migrationDir . '/*.sql');
sort($migrationFiles);

if (empty($migrationFiles)) {
    exit("No migration files found in $migrationDir\n");
}

echo "Found " . count($migrationFiles) . " migration file(s):\n";
foreach ($migrationFiles as $mf) {
    echo "  - " . basename($mf) . "\n";
}
echo "\n";

foreach ($targetDbs as $dbPath) {
    if (!file_exists($dbPath)) {
        echo "Database file not found (skipping): $dbPath\n";
        continue;
    }

    echo "Applying migrations to: " . basename($dbPath) . "...\n";
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        foreach ($migrationFiles as $mf) {
            $sql = file_get_contents($mf);
            if ($sql === false || trim($sql) === '') continue;

            $pdo->exec($sql);
            echo "  [SUCCESS] Applied " . basename($mf) . "\n";
        }

        // Verify newly created tables
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo "  [INFO] Total tables in database: " . count($tables) . "\n";
        echo "  [INFO] Tables: " . implode(', ', $tables) . "\n";

        // Verify task seeds
        $taskCount = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
        echo "  [INFO] Pre-seeded tasks: $taskCount templates available.\n\n";

    } catch (Exception $e) {
        echo "  [ERROR] Migration failed on " . basename($dbPath) . ": " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations applied successfully!\n";
