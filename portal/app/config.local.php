<?php
// Isolated Training Database Configuration (SQLite)
// Points exclusively to the Training Database in ../database/training_portal.sqlite
// NEVER points to the original Main Database.
$trainingDbPath = getenv('TRAINING_PORTAL_SQLITE') ?: (dirname(__DIR__, 2) . '/database/training_portal.sqlite');

return [
    'dsn' => 'sqlite:' . $trainingDbPath,
    'training_db_path' => $trainingDbPath,
    'debug' => true,
];
