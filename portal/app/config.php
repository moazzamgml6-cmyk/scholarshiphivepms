<?php
// ------------------------------------------------------------------
// Processing Team Portal (TRAINING ENVIRONMENT) — settings
// Isolated configuration for training & practice only.
// ------------------------------------------------------------------

return [
    'db_host' => 'localhost',
    'db_name' => 'training_portal_db',
    'db_user' => 'training_user',
    'db_pass' => 'CHANGE_ME_training_password',

    // Distinct header name so trainees clearly see they are in Training Mode
    'app_name' => 'Processing Team Portal [TRAINING MODE]',

    'base_url' => '',
    'idle_minutes' => 60,
    'max_attempts' => 20,
    'timezone' => 'Asia/Karachi',
    'debug' => true,
];
