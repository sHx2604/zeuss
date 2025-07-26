<?php

namespace SmartRelay\Controllers;

use SmartRelay\Services\AuthService;
use SmartRelay\Services\DeviceService;
use SmartRelay\Services\BillingService;
use SmartRelay\Services\LoggingService;
use SmartRelay\Config\Database;

class AdminController
{
    private $authService;
    private $deviceService;
    private $billingService;
    private $loggingService;
    private $db;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->deviceService = new DeviceService();
        $this->billingService = new BillingService();
        $this->loggingService = new LoggingService();
        $this->db = Database::getInstance();
    }

    public function handleRequest(): void
    {
        // Set CORS headers
        $this->setCorsHeaders();

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        // Authenticate and verify admin role
        $user = $this->authenticateAdmin();

        // Parse the request
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Remove admin prefix
        $uri = preg_replace('/^\/admin/', '', $uri);

        try {
            $this->route($method, $uri, $user);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function route(string $method, string $uri, array $user): void
    {
        switch (true) {
            // Dashboard stats
            case $uri === '/dashboard' && $method === 'GET':
                $this->getDashboardStats($user);
                break;

            // User management
            case $uri === '/users' && $method === 'GET':
                $this->getAllUsers($user);
                break;

            case preg_match('/^\/users\/(\d+)$/', $uri, $matches) && $method === 'GET':
                $this->getUserDetails($user, (int)$matches[1]);
                break;

            case preg_match('/^\/users\/(\d+)$/', $uri, $matches) && $method === 'PUT':
                $this->updateUser($user, (int)$matches[1]);
                break;

            case preg_match('/^\/users\/(\d+)\/suspend$/', $uri, $matches) && $method === 'POST':
                $this->suspendUser($user, (int)$matches[1]);
                break;

            case preg_match('/^\/users\/(\d+)\/activate$/', $uri, $matches) && $method === 'POST':
                $this->activateUser($user, (int)$matches[1]);
                break;

            // Device management
            case $uri === '/devices' && $method === 'GET':
                $this->getAllDevices($user);
                break;

            case preg_match('/^\/devices\/(\d+)\/force-control$/', $uri, $matches) && $method === 'POST':
                $this->forceDeviceControl($user, (int)$matches[1]);
                break;

            // System management
            case $uri === '/system/settings' && $method === 'GET':
                $this->getSystemSettings($user);
                break;

            case $uri === '/system/settings' && $method === 'PUT':
                $this->updateSystemSettings($user);
                break;

            case $uri === '/system/maintenance' && $method === 'POST':
                $this->toggleMaintenanceMode($user);
                break;

            // Analytics and logs
            case $uri === '/analytics/users' && $method === 'GET':
                $this->getUserAnalytics($user);
                break;

            case $uri === '/analytics/devices' && $method === 'GET':
                $this->getDeviceAnalytics($user);
                break;

            case $uri === '/logs/api' && $method === 'GET':
                $this->getApiLogs($user);
                break;

            case $uri === '/logs/activity' && $method === 'GET':
                $this->getActivityLogs($user);
                break;

            case $uri === '/logs/billing' && $method === 'GET':
                $this->getBillingLogs($user);
                break;

            // Subscription management
            case $uri === '/subscriptions' && $method === 'GET':
                $this->getAllSubscriptions($user);
                break;

            case $uri === '/plans' && $method === 'GET':
                $this->getSubscriptionPlans($user);
                break;

            case $uri === '/plans' && $method === 'POST':
                $this->createSubscriptionPlan($user);
                break;

            case preg_match('/^\/plans\/(\d+)$/', $uri, $matches) && $method === 'PUT':
                $this->updateSubscriptionPlan($user, (int)$matches[1]);
                break;

            default:
                $this->sendError('Admin endpoint not found', 404);
        }
    }

    private function authenticateAdmin(): array
    {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $this->sendError('Authorization header required', 401);
        }

        $token = $matches[1];
        $result = $this->authService->verifyToken($token);

        if (!$result['valid']) {
            $this->sendError('Invalid token', 401);
        }

        $user = $result['user'];
        if ($user['role'] !== 'admin') {
            $this->sendError('Admin access required', 403);
        }

        return $user;
    }

    private function getDashboardStats(array $user): void
    {
        $stats = [
            'total_users' => $this->db->fetchOne("SELECT COUNT(*) as count FROM users")['count'],
            'active_users' => $this->db->fetchOne("SELECT COUNT(*) as count FROM users WHERE status = 'active'")['count'],
            'total_devices' => $this->db->fetchOne("SELECT COUNT(*) as count FROM devices")['count'],
            'online_devices' => $this->db->fetchOne("SELECT COUNT(*) as count FROM devices WHERE status = 'online'")['count'],
            'total_subscriptions' => $this->db->fetchOne("SELECT COUNT(*) as count FROM user_subscriptions WHERE status = 'active'")['count'],
            'monthly_revenue' => $this->getMonthlyRevenue(),
            'api_calls_today' => $this->getApiCallsToday(),
            'recent_activities' => $this->getRecentActivities()
        ];

        $this->sendSuccess($stats);
    }

    private function getAllUsers(array $user): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $limit = min((int)($_GET['limit'] ?? 25), 100);
        $offset = ($page - 1) * $limit;

        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $role = $_GET['role'] ?? '';

        $where = ['1 = 1'];
        $params = [];

        if ($search) {
            $where[] = '(username LIKE :search OR email LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        if ($status) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($role) {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }

        $sql = "SELECT id, username, email, role, status, created_at, last_login,
                       (SELECT COUNT(*) FROM devices WHERE user_id = users.id) as device_count,
                       (SELECT COUNT(*) FROM user_subscriptions WHERE user_id = users.id AND status = 'active') as has_subscription
                FROM users
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $users = $this->db->fetchAll($sql, $params);

        $totalSql = "SELECT COUNT(*) as count FROM users WHERE " . implode(' AND ', $where);
        $total = $this->db->fetchOne($totalSql, $params)['count'];

        $this->sendSuccess([
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    private function getUserDetails(array $user, int $userId): void
    {
        $userDetails = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = :user_id",
            ['user_id' => $userId]
        );

        if (!$userDetails) {
            $this->sendError('User not found', 404);
        }

        // Get user's devices
        $devices = $this->db->fetchAll(
            "SELECT * FROM devices WHERE user_id = :user_id ORDER BY created_at DESC",
            ['user_id' => $userId]
        );

        // Get user's subscriptions
        $subscriptions = $this->db->fetchAll(
            "SELECT us.*, sp.name as plan_name, sp.price
             FROM user_subscriptions us
             JOIN subscription_plans sp ON us.plan_id = sp.id
             WHERE us.user_id = :user_id
             ORDER BY us.created_at DESC",
            ['user_id' => $userId]
        );

        // Get recent activity
        $activities = $this->loggingService->getUserActivityLogs($userId, ['limit' => 10]);

        $this->sendSuccess([
            'user' => $userDetails,
            'devices' => $devices,
            'subscriptions' => $subscriptions,
            'recent_activities' => $activities
        ]);
    }

    private function updateUser(array $user, int $userId): void
    {
        $data = $this->getJsonInput();

        $allowedFields = ['username', 'email', 'role', 'status'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            $this->sendError('No valid fields to update', 400);
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('users', $updateData, ['id' => $userId]);

        $this->loggingService->logUserActivity($user['id'], 'admin_update_user', [
            'target_user_id' => $userId,
            'updated_fields' => array_keys($updateData)
        ]);

        $this->sendSuccess(['message' => 'User updated successfully']);
    }

    private function suspendUser(array $user, int $userId): void
    {
        $this->db->update('users', ['status' => 'suspended'], ['id' => $userId]);

        $this->loggingService->logUserActivity($user['id'], 'admin_suspend_user', [
            'target_user_id' => $userId
        ]);

        $this->sendSuccess(['message' => 'User suspended successfully']);
    }

    private function activateUser(array $user, int $userId): void
    {
        $this->db->update('users', ['status' => 'active'], ['id' => $userId]);

        $this->loggingService->logUserActivity($user['id'], 'admin_activate_user', [
            'target_user_id' => $userId
        ]);

        $this->sendSuccess(['message' => 'User activated successfully']);
    }

    private function getAllDevices(array $user): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $limit = min((int)($_GET['limit'] ?? 25), 100);
        $offset = ($page - 1) * $limit;

        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';

        $where = ['1 = 1'];
        $params = [];

        if ($search) {
            $where[] = '(d.name LIKE :search OR d.device_id LIKE :search OR u.username LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        if ($status) {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }

        $sql = "SELECT d.*, u.username, u.email
                FROM devices d
                JOIN users u ON d.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $devices = $this->db->fetchAll($sql, $params);

        $totalSql = "SELECT COUNT(*) as count
                     FROM devices d
                     JOIN users u ON d.user_id = u.id
                     WHERE " . implode(' AND ', $where);
        $total = $this->db->fetchOne($totalSql, $params)['count'];

        $this->sendSuccess([
            'devices' => $devices,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    private function getSystemSettings(array $user): void
    {
        $settings = $this->db->fetchAll("SELECT * FROM system_settings ORDER BY setting_key");

        $formattedSettings = [];
        foreach ($settings as $setting) {
            $value = $setting['setting_value'];

            switch ($setting['setting_type']) {
                case 'number':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = $value === 'true';
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }

            $formattedSettings[$setting['setting_key']] = [
                'value' => $value,
                'type' => $setting['setting_type'],
                'description' => $setting['description']
            ];
        }

        $this->sendSuccess(['settings' => $formattedSettings]);
    }

    private function updateSystemSettings(array $user): void
    {
        $data = $this->getJsonInput();

        foreach ($data as $key => $value) {
            $setting = $this->db->fetchOne(
                "SELECT * FROM system_settings WHERE setting_key = :key",
                ['key' => $key]
            );

            if ($setting) {
                $formattedValue = $value;

                switch ($setting['setting_type']) {
                    case 'boolean':
                        $formattedValue = $value ? 'true' : 'false';
                        break;
                    case 'json':
                        $formattedValue = json_encode($value);
                        break;
                    default:
                        $formattedValue = (string)$value;
                }

                $this->db->update('system_settings', [
                    'setting_value' => $formattedValue
                ], ['setting_key' => $key]);
            }
        }

        $this->loggingService->logUserActivity($user['id'], 'admin_update_system_settings', [
            'updated_settings' => array_keys($data)
        ]);

        $this->sendSuccess(['message' => 'System settings updated successfully']);
    }

    private function getMonthlyRevenue(): float
    {
        $result = $this->db->fetchOne(
            "SELECT SUM(sp.price) as revenue
             FROM user_subscriptions us
             JOIN subscription_plans sp ON us.plan_id = sp.id
             WHERE us.status = 'active' AND sp.billing_cycle = 'monthly'"
        );

        return (float)($result['revenue'] ?? 0);
    }

    private function getApiCallsToday(): int
    {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count
             FROM api_logs
             WHERE DATE(timestamp) = CURDATE()"
        );

        return (int)($result['count'] ?? 0);
    }

    private function getRecentActivities(): array
    {
        return $this->db->fetchAll(
            "SELECT ual.*, u.username
             FROM user_activity_logs ual
             JOIN users u ON ual.user_id = u.id
             ORDER BY ual.timestamp DESC
             LIMIT 10"
        );
    }

    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON input', 400);
        }

        return $data ?? [];
    }

    private function setCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
    }

    private function sendSuccess(array $data): void
    {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }

    private function sendError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }
}
