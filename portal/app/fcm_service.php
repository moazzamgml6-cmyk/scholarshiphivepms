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
     * Specifically dispatch Task Assignment notification to student.
     * Guaranteed compliance:
     * - Notification title: "New Task Assigned"
     * - Notification body: "New Task Assigned: [Task Title]"
     * - Data payload includes: student_task_id, target_id, type: 'task_assigned', click_action: 'FLUTTER_NOTIFICATION_CLICK'
     * - Does NOT report success unless Firebase explicitly confirms the send request (HTTP 200).
     */
    public static function notifyTaskAssigned(int $studentTaskId, int $studentId, string $taskTitle): array {
        $title = "New Task Assigned";
        $body = "New Task Assigned: " . $taskTitle;

        // 1. Permanent database notification record
        q("INSERT INTO notifications (user_id, student_id, title, body, type, target_id, is_read, created_at)
           VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
            [null, $studentId, $title, $body, 'task_assigned', $studentTaskId, now()]
        );
        $notifId = (int)db()->lastInsertId();

        // 2. Log system action
        log_action("Task assigned notification: {$taskTitle} (Task #{$studentTaskId})", $studentId);

        // 3. Find active registered devices for student
        $rows = q("SELECT device_token FROM device_tokens WHERE student_id = ? AND is_active = 1", [$studentId])->fetchAll();
        $tokens = [];
        foreach ($rows as $r) {
            if (!empty($r['device_token'])) {
                $tokens[] = trim((string)$r['device_token']);
            }
        }
        $tokens = array_unique(array_filter($tokens));

        if (empty($tokens)) {
            return [
                'confirmed'       => false,
                'tokens_found'    => 0,
                'sent_count'      => 0,
                'status_text'     => 'No active device registered for this student. In-app notification saved.',
                'error'           => null,
                'notification_id' => $notifId,
            ];
        }

        // 4. Dispatch FCM push notification
        $data = [
            'notification_id' => (string)$notifId,
            'student_task_id' => (string)$studentTaskId,
            'target_id'       => (string)$studentTaskId,
            'task_id'         => (string)$studentTaskId,
            'type'            => 'task_assigned',
            'click_action'    => 'FLUTTER_NOTIFICATION_CLICK',
        ];

        $sentCount = 0;
        $lastError = null;
        foreach ($tokens as $token) {
            $ok = self::sendToToken($token, $title, $body, $data);
            if ($ok) {
                $sentCount++;
            } else {
                $lastError = self::$lastDeliveryError ?? 'FCM endpoint did not confirm delivery';
            }
        }

        if ($sentCount > 0) {
            return [
                'confirmed'       => true,
                'tokens_found'    => count($tokens),
                'sent_count'      => $sentCount,
                'status_text'     => "Push notification successfully confirmed and sent to {$sentCount} student device(s).",
                'error'           => null,
                'notification_id' => $notifId,
            ];
        }

        return [
            'confirmed'       => false,
            'tokens_found'    => count($tokens),
            'sent_count'      => 0,
            'status_text'     => "FCM delivery could not be confirmed ({$lastError}). In-app notification preserved.",
            'error'           => $lastError,
            'notification_id' => $notifId,
        ];
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
