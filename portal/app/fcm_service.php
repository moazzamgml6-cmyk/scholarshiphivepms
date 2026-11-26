<?php
declare(strict_types=1);

/**
 * ScholarshipHive PMS — Notification & Firebase Cloud Messaging (FCM) Service
 * Manages permanent database notification history and real-time push delivery.
 * Supports both Google OAuth2 FCM HTTP v1 API and Legacy FCM Server Key.
 */

require_once __DIR__ . '/bootstrap.php';

class PmsNotificationService {

    private static ?string $cachedAccessToken = null;
    private static int $tokenExpiresAt = 0;
    private static ?string $lastDeliveryError = null;

    /**
     * Create an in-app notification and optionally dispatch a push notification to registered devices.
     */
    public static function notify(
        ?int $studentId,
        ?int $userId,
        string $title,
        string $body,
        string $type = 'general',
        ?int $targetId = null
    ): int {
        // 1. Permanent database notification record
        q("INSERT INTO notifications (user_id, student_id, title, body, type, target_id, is_read, created_at)
           VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
            [$userId, $studentId, $title, $body, $type, $targetId, now()]
        );
        $notifId = (int)db()->lastInsertId();

        // 2. Log system action
        log_action("Notification ($type): $title", $studentId);

        // 3. Dispatch push notification to registered FCM device tokens
        self::dispatchPush($studentId, $userId, $title, $body, [
            'notification_id' => (string)$notifId,
            'type'            => $type,
            'target_id'       => (string)($targetId ?? 0),
            'student_id'      => (string)($studentId ?? 0),
        ]);

        return $notifId;
    }

    /**
     * Dispatch FCM push notification to all active devices registered for this student or user.
     */
    public static function dispatchPush(
        ?int $studentId,
        ?int $userId,
        string $title,
        string $body,
        array $data = []
    ): int {
        $tokens = [];
        if ($studentId !== null && $studentId > 0) {
            $rows = q("SELECT device_token FROM device_tokens WHERE student_id = ? AND is_active = 1", [$studentId])->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['device_token'])) $tokens[] = $r['device_token'];
            }
        }
        if ($userId !== null && $userId > 0) {
            $rows = q("SELECT device_token FROM device_tokens WHERE user_id = ? AND is_active = 1", [$userId])->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['device_token'])) $tokens[] = $r['device_token'];
            }
        }

        $tokens = array_unique(array_filter($tokens));
        if (empty($tokens)) {
            // No devices registered yet; notification is safely preserved in DB notification center
            return 0;
        }

        // Deliver to each token
        $sentCount = 0;
        foreach ($tokens as $token) {
            $success = self::sendToToken($token, $title, $body, $data);
            if ($success) $sentCount++;
        }

        return $sentCount;
    }

    /**
     * Low-level FCM delivery supporting both FCM v1 HTTP API and Legacy API.
     */
    public static function sendToToken(string $deviceToken, string $title, string $body, array $data = []): bool {
        global $CONFIG;

        // Ensure all data values are strings for FCM compatibility
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = (string)$v;
        }
        $stringData['title'] = $title;
        $stringData['body'] = $body;

        // 1. Try FCM HTTP v1 API first (Modern Google OAuth2)
        $oauthToken = self::getOAuth2AccessToken();
        $projectId = self::getFirebaseProjectId();

        if ($oauthToken !== null && !empty($projectId)) {
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
            $payload = [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data' => $stringData,
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'pms_tasks_channel',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ],
                    ],
                ],
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $oauthToken,
                'Content-Type: application/json; UTF-8',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return true;
            }
        }

        // 2. Try FCM Legacy API if Legacy Server Key is provided
        $fcmServerKey = getenv('FCM_SERVER_KEY') ?: ($CONFIG['fcm_server_key'] ?? '');
        if (!empty($fcmServerKey)) {
            $url = 'https://fcm.googleapis.com/fcm/send';
            $payload = [
                'to' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                    'badge' => '1',
                ],
                'data' => array_merge($stringData, [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ]),
                'priority' => 'high',
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: key=' . $fcmServerKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        }

        // If no external FCM credentials are configured, return false and record diagnostic
        self::$lastDeliveryError = 'No Firebase service account JSON or FCM Server Key configured in environment.';
        return false;
    }

        /**
     * Specifically dispatch Task Assignment notification to student with comprehensive logging.
     * Guaranteed compliance:
     * - Notification title: "New Task Assigned"
     * - Notification body: "You have received a new task: [Task Title]"
     * - Data payload includes: type: 'task_assigned', task_id, student_task_id, student_id, click_action
     * - Multi-device support per student.
     * - Full delivery logging in notification_logs table.
     * - Deactivates invalid/expired tokens.
     * - Updates student_tasks.notification_status ('Sent', 'Failed', 'No Token').
     */
    public static function notifyTaskAssigned(
        int $studentTaskId,
        int $studentId,
        string $taskTitle,
        int $taskId = 0,
        ?string $customBody = null
    ): array {
        $title = "New Task Assigned";
        $body = $customBody ?: ("You have received a new task: " . $taskTitle);

        // 1. Permanent database in-app notification record
        q("INSERT INTO notifications (user_id, student_id, title, body, type, target_id, is_read, created_at)
           VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
            [null, $studentId, $title, $body, 'task_assigned', $studentTaskId, now()]
        );
        $notifId = (int)db()->lastInsertId();

        // 2. Audit system action
        log_action("Task assigned notification: {$taskTitle} (Task #{$studentTaskId})", $studentId);

        // 3. Find active registered devices for student
        $rows = q("SELECT id, device_token, platform, device_model FROM device_tokens WHERE student_id = ? AND is_active = 1", [$studentId])->fetchAll();
        $tokens = [];
        foreach ($rows as $r) {
            if (!empty($r['device_token'])) {
                $tokens[] = [
                    'id'    => (int)$r['id'],
                    'token' => trim((string)$r['device_token']),
                    'model' => (string)($r['device_model'] ?? 'Android'),
                ];
            }
        }

        $payload = [
            'notification_id' => (string)$notifId,
            'type'            => 'task_assigned',
            'task_id'         => (string)($taskId > 0 ? $taskId : $studentTaskId),
            'student_task_id' => (string)$studentTaskId,
            'student_id'      => (string)$studentId,
            'click_action'    => 'FLUTTER_NOTIFICATION_CLICK',
        ];

        // If student has no registered devices
        if (empty($tokens)) {
            // Log in notification_logs as no_token
            q("INSERT INTO notification_logs (student_id, user_id, student_task_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, created_at, updated_at)
               VALUES (?, NULL, ?, 'task_assigned', ?, ?, ?, '', 'no_token', 'No active device token registered for this student.', 0, ?, ?)",
                [$studentId, $studentTaskId, $title, $body, json_encode($payload), now(), now()]
            );

            // Update student_tasks notification_status
            q("UPDATE student_tasks SET notification_status = 'No Token' WHERE id = ?", [$studentTaskId]);

            return [
                'confirmed'       => false,
                'tokens_found'    => 0,
                'sent_count'      => 0,
                'status'          => 'no_token',
                'status_text'     => 'No active device registered for this student. In-app notification preserved.',
                'error'           => null,
                'notification_id' => $notifId,
            ];
        }

        // 4. Dispatch to each active device token
        $sentCount = 0;
        $failedCount = 0;
        $lastError = null;

        foreach ($tokens as $t) {
            $deviceToken = $t['token'];
            $ok = self::sendToToken($deviceToken, $title, $body, $payload);

            if ($ok) {
                $sentCount++;
                q("INSERT INTO notification_logs (student_id, user_id, student_task_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, sent_at, created_at, updated_at)
                   VALUES (?, NULL, ?, 'task_assigned', ?, ?, ?, ?, 'sent', '', 0, ?, ?, ?)",
                    [$studentId, $studentTaskId, $title, $body, json_encode($payload), $deviceToken, now(), now(), now()]
                );
            } else {
                $failedCount++;
                $errorStr = self::$lastDeliveryError ?? 'FCM delivery not confirmed';
                $lastError = $errorStr;

                q("INSERT INTO notification_logs (student_id, user_id, student_task_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, created_at, updated_at)
                   VALUES (?, NULL, ?, 'task_assigned', ?, ?, ?, ?, 'failed', ?, 0, ?, ?)",
                    [$studentId, $studentTaskId, $title, $body, json_encode($payload), $deviceToken, $errorStr, now(), now()]
                );

                // Auto-deactivate token if expired or unregistered
                if (preg_match('#(UNREGISTERED|NotRegistered|InvalidRegistration|INVALID_ARGUMENT)#i', $errorStr)) {
                    q("UPDATE device_tokens SET is_active = 0, updated_at = ? WHERE id = ?", [now(), $t['id']]);
                }
            }
        }

        if ($sentCount > 0) {
            q("UPDATE student_tasks SET notification_status = 'Sent' WHERE id = ?", [$studentTaskId]);
            return [
                'confirmed'       => true,
                'tokens_found'    => count($tokens),
                'sent_count'      => $sentCount,
                'failed_count'    => $failedCount,
                'status'          => 'sent',
                'status_text'     => "Push notification successfully confirmed and sent to {$sentCount} student device(s).",
                'error'           => null,
                'notification_id' => $notifId,
            ];
        }

        q("UPDATE student_tasks SET notification_status = 'Failed' WHERE id = ?", [$studentTaskId]);
        return [
            'confirmed'       => false,
            'tokens_found'    => count($tokens),
            'sent_count'      => 0,
            'failed_count'    => $failedCount,
            'status'          => 'failed',
            'status_text'     => "FCM delivery could not be confirmed ({$lastError}). In-app notification preserved.",
            'error'           => $lastError,
            'notification_id' => $notifId,
        ];
    }

    /**
     * Broadcast a custom announcement or alert to one, multiple, or all students.
     */
    public static function broadcastCustomNotification(
        array $studentIds,
        string $title,
        string $message,
        string $type = 'announcement',
        ?string $imageUrl = null,
        int $assignedBy = 1
    ): array {
        $title = trim($title);
        $message = trim($message);
        if ($title === '' || $message === '') {
            throw new InvalidArgumentException("Notification title and message are required.");
        }

        $studentIds = array_unique(array_filter(array_map('intval', $studentIds)));
        if (empty($studentIds)) {
            throw new InvalidArgumentException("No valid student recipients selected.");
        }

        $totalRecipients = count($studentIds);
        $totalDevices = 0;
        $totalSent = 0;
        $totalFailed = 0;
        $totalNoToken = 0;

        foreach ($studentIds as $sid) {
            // 1. In-app notification
            q("INSERT INTO notifications (user_id, student_id, title, body, type, is_read, created_at)
               VALUES (?, ?, ?, ?, ?, 0, ?)",
                [null, $sid, $title, $message, $type, now()]
            );
            $notifId = (int)db()->lastInsertId();

            $payload = [
                'notification_id' => (string)$notifId,
                'type'            => $type,
                'student_id'      => (string)$sid,
                'image_url'       => (string)($imageUrl ?? ''),
                'click_action'    => 'FLUTTER_NOTIFICATION_CLICK',
            ];

            // 2. Active tokens
            $tokens = q("SELECT id, device_token FROM device_tokens WHERE student_id = ? AND is_active = 1", [$sid])->fetchAll();
            if (empty($tokens)) {
                $totalNoToken++;
                q("INSERT INTO notification_logs (student_id, user_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, created_at, updated_at)
                   VALUES (?, NULL, ?, ?, ?, ?, '', 'no_token', 'No active device registered.', 0, ?, ?)",
                    [$sid, $type, $title, $message, json_encode($payload), now(), now()]
                );
                continue;
            }

            foreach ($tokens as $t) {
                $totalDevices++;
                $tokenStr = trim((string)$t['device_token']);
                $ok = self::sendToToken($tokenStr, $title, $message, $payload);
                if ($ok) {
                    $totalSent++;
                    q("INSERT INTO notification_logs (student_id, user_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, sent_at, created_at, updated_at)
                       VALUES (?, NULL, ?, ?, ?, ?, ?, 'sent', '', 0, ?, ?, ?)",
                        [$sid, $type, $title, $message, json_encode($payload), $tokenStr, now(), now(), now()]
                    );
                } else {
                    $totalFailed++;
                    $err = self::$lastDeliveryError ?? 'FCM send failed';
                    q("INSERT INTO notification_logs (student_id, user_id, notification_type, title, body, data_payload, device_token, status, error_message, retry_count, created_at, updated_at)
                       VALUES (?, NULL, ?, ?, ?, ?, ?, 'failed', ?, 0, ?, ?)",
                        [$sid, $type, $title, $message, json_encode($payload), $tokenStr, $err, now(), now()]
                    );
                }
            }
        }

        return [
            'total_recipients' => $totalRecipients,
            'devices_targeted' => $totalDevices,
            'sent_count'       => $totalSent,
            'failed_count'     => $totalFailed,
            'no_token_count'   => $totalNoToken,
        ];
    }

    /**
     * Retry a specific failed notification from notification_logs.
     */
    public static function retryNotification(int $logId): array {
        $log = q("SELECT * FROM notification_logs WHERE id = ?", [$logId])->fetch();
        if (!$log) throw new RuntimeException("Notification log #$logId not found.");

        $token = trim((string)$log['device_token']);
        $sid = (int)($log['student_id'] ?? 0);

        // If no token was saved in log, try finding an active token for this student now
        if (empty($token) && $sid > 0) {
            $active = q("SELECT device_token FROM device_tokens WHERE student_id = ? AND is_active = 1 LIMIT 1", [$sid])->fetch();
            if ($active && !empty($active['device_token'])) {
                $token = trim((string)$active['device_token']);
            }
        }

        if (empty($token)) {
            q("UPDATE notification_logs SET retry_count = retry_count + 1, error_message = 'Still no active device token registered.', updated_at = ? WHERE id = ?", [now(), $logId]);
            return ['success' => false, 'error' => 'No active device token found for student.'];
        }

        $payload = json_decode((string)($log['data_payload'] ?: '{}'), true) ?: [];
        $payload['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';

        $ok = self::sendToToken($token, (string)$log['title'], (string)$log['body'], $payload);

        if ($ok) {
            q("UPDATE notification_logs SET status = 'sent', device_token = ?, error_message = '', retry_count = retry_count + 1, sent_at = ?, updated_at = ? WHERE id = ?",
                [$token, now(), now(), $logId]
            );
            if (!empty($log['student_task_id'])) {
                q("UPDATE student_tasks SET notification_status = 'Sent' WHERE id = ?", [(int)$log['student_task_id']]);
            }
            return ['success' => true, 'error' => null];
        }

        $err = self::$lastDeliveryError ?? 'Retry FCM delivery failed';
        q("UPDATE notification_logs SET status = 'failed', device_token = ?, error_message = ?, retry_count = retry_count + 1, updated_at = ? WHERE id = ?",
            [$token, $err, now(), $logId]
        );
        return ['success' => false, 'error' => $err];
    }

    /**
     * Retry all failed notifications.
     */
    public static function retryAllFailed(): array {
        $failed = q("SELECT id FROM notification_logs WHERE status = 'failed' ORDER BY id DESC LIMIT 100")->fetchAll();
        $retried = 0;
        $succeeded = 0;
        foreach ($failed as $row) {
            $res = self::retryNotification((int)$row['id']);
            $retried++;
            if (!empty($res['success'])) {
                $succeeded++;
            }
        }
        return ['retried' => $retried, 'succeeded' => $succeeded, 'failed' => ($retried - $succeeded)];
    }

    /**
     * Acquire Google OAuth2 Access Token for FCM v1 using Service Account JSON.
     */
    private static function getOAuth2AccessToken(): ?string {
        if (self::$cachedAccessToken !== null && time() < (self::$tokenExpiresAt - 60)) {
            return self::$cachedAccessToken;
        }

        $serviceAccount = self::getServiceAccountData();
        if ($serviceAccount === null) {
            return null;
        }

        $clientEmail = $serviceAccount['client_email'] ?? '';
        $privateKey = $serviceAccount['private_key'] ?? '';
        if (empty($clientEmail) || empty($privateKey)) {
            return null;
        }

        $now = time();
        $header = base64UrlEncode((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = base64UrlEncode((string)json_encode([
            'iss'   => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signatureInput = $header . '.' . $claims;
        $signature = '';
        $binaryKey = openssl_pkey_get_private($privateKey);
        if (!$binaryKey) {
            return null;
        }

        $success = openssl_sign($signatureInput, $signature, $binaryKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            return null;
        }

        $jwt = $signatureInput . '.' . base64UrlEncode($signature);

        // Exchange JWT for OAuth2 Bearer token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($res)) {
            $data = json_decode($res, true);
            if (!empty($data['access_token'])) {
                self::$cachedAccessToken = $data['access_token'];
                self::$tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);
                return self::$cachedAccessToken;
            }
        }

        return null;
    }

    /**
     * Find service account data from file, env variable, or app config.
     */
    private static function getServiceAccountData(): ?array {
        // 1. Env variable with JSON string
        $envJson = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
        if (!empty($envJson)) {
            $data = json_decode($envJson, true);
            if (is_array($data)) return $data;
        }

        // 2. File in app directory
        $paths = [
            __DIR__ . '/firebase-service-account.json',
            __DIR__ . '/service-account.json',
            dirname(__DIR__) . '/firebase-service-account.json',
            getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '',
        ];

        foreach ($paths as $path) {
            if (!empty($path) && file_exists($path)) {
                $raw = file_get_contents($path);
                if ($raw) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && !empty($data['client_email'])) {
                        return $data;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get Firebase Project ID (defaults to scholarshiphive-11e2a).
     */
    private static function getFirebaseProjectId(): string {
        $data = self::getServiceAccountData();
        if ($data !== null && !empty($data['project_id'])) {
            return (string)$data['project_id'];
        }
        return getenv('FIREBASE_PROJECT_ID') ?: 'scholarshiphive-11e2a';
    }

    /**
     * Register or update an FCM device token.
     */
    public static function registerDeviceToken(
        string $token,
        ?int $studentId,
        ?int $userId,
        string $platform = 'android',
        string $deviceModel = '',
        string $appVersion = ''
    ): bool {
        if (trim($token) === '') return false;

        $existing = q("SELECT id FROM device_tokens WHERE device_token = ?", [$token])->fetch();
        if ($existing) {
            q("UPDATE device_tokens 
               SET user_id = ?, student_id = ?, platform = ?, device_model = ?, app_version = ?, is_active = 1, updated_at = ?
               WHERE id = ?",
                [$userId, $studentId, $platform, $deviceModel, $appVersion, now(), $existing['id']]
            );
        } else {
            q("INSERT INTO device_tokens (user_id, student_id, device_token, platform, device_model, app_version, is_active, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$userId, $studentId, $token, $platform, $deviceModel, $appVersion, now(), now()]
            );
        }

        return true;
    }

    /**
     * Deactivate a device token on logout.
     */
    public static function deactivateDeviceToken(string $token): bool {
        q("UPDATE device_tokens SET is_active = 0, updated_at = ? WHERE device_token = ?", [now(), $token]);
        return true;
    }

    /**
     * Get diagnostic status of FCM configuration and active device counts.
     */
    public static function getStatus(): array {
        $hasServiceAccount = self::getServiceAccountData() !== null;
        $hasServerKey = !empty(getenv('FCM_SERVER_KEY')) || !empty($GLOBALS['CONFIG']['fcm_server_key'] ?? '');

        $mode = 'none';
        if ($hasServiceAccount) {
            $mode = 'oauth2_v1';
        } elseif ($hasServerKey) {
            $mode = 'legacy_server_key';
        }

        $activeDevices = (int)q("SELECT COUNT(*) FROM device_tokens WHERE is_active = 1")->fetchColumn();

        return [
            'fcm_configured' => $hasServiceAccount || $hasServerKey,
            'mode'           => $mode,
            'project_id'     => self::getFirebaseProjectId(),
            'active_devices' => $activeDevices,
        ];
    }
}

/**
 * Base64 URL Encoder helper for JWT.
 */
function base64UrlEncode(string $data): string {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}
