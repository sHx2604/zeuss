<?php

namespace SmartRelay\Controllers;

use SmartRelay\Services\AuthService;
use SmartRelay\Services\DeviceService;
use SmartRelay\Services\BillingService;
use SmartRelay\Services\MqttService;
use SmartRelay\Config\Database;

class ApiController
{
    private $authService;
    private $deviceService;
    private $billingService;
    private $mqttService;
    private $db;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->deviceService = new DeviceService();
        $this->billingService = new BillingService();
        $this->mqttService = new MqttService();
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

        // Parse the request
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Remove API version prefix if present
        $uri = preg_replace('/^\/api\/v\d+/', '', $uri);

        try {
            // Route the request
            $this->route($method, $uri);

        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function route(string $method, string $uri): void
    {
        // Public routes (no authentication required)
        if ($uri === '/auth/login' && $method === 'POST') {
            $this->login();
            return;
        }

        if ($uri === '/auth/register' && $method === 'POST') {
            $this->register();
            return;
        }

        if ($uri === '/plans' && $method === 'GET') {
            $this->getSubscriptionPlans();
            return;
        }

        // All other routes require authentication
        $user = $this->authenticateRequest();

        // Protected routes
        switch (true) {
            // Auth routes
            case $uri === '/auth/me' && $method === 'GET':
                $this->getProfile($user);
                break;

            // Device routes
            case $uri === '/devices' && $method === 'GET':
                $this->getDevices($user);
                break;

            case $uri === '/devices' && $method === 'POST':
                $this->createDevice($user);
                break;

            case preg_match('/^\/devices\/(\d+)\/control$/', $uri, $matches) && $method === 'POST':
                $this->controlDevice($user, (int)$matches[1]);
                break;

            // Billing routes
            case $uri === '/billing/subscription' && $method === 'GET':
                $this->getUserSubscription($user);
                break;

            case $uri === '/billing/subscription' && $method === 'POST':
                $this->createSubscription($user);
                break;

            default:
                $this->sendError('Endpoint not found', 404);
        }
    }

    private function authenticateRequest(): array
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

        return $result['user'];
    }

    // Auth endpoints
    private function login(): void
    {
        $data = $this->getJsonInput();

        if (empty($data['username']) || empty($data['password'])) {
            $this->sendError('Username and password required', 400);
        }

        try {
            $result = $this->authService->login($data['username'], $data['password']);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 401);
        }
    }

    private function register(): void
    {
        $data = $this->getJsonInput();

        try {
            $result = $this->authService->register($data);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function getProfile(array $user): void
    {
        $this->sendSuccess(['user' => $user]);
    }

    // Device endpoints
    private function getDevices(array $user): void
    {
        $filters = $_GET;
        $result = $this->deviceService->getDevices($user, $filters);
        $this->sendSuccess($result);
    }

    private function createDevice(array $user): void
    {
        $data = $this->getJsonInput();

        try {
            $result = $this->deviceService->createDevice($user, $data);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function controlDevice(array $user, int $deviceId): void
    {
        $data = $this->getJsonInput();

        if (empty($data['action'])) {
            $this->sendError('Action required', 400);
        }

        try {
            $result = $this->deviceService->controlDevice($user, $deviceId, $data['action'], $data['params'] ?? []);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    // Billing endpoints
    private function getSubscriptionPlans(): void
    {
        $result = $this->billingService->getSubscriptionPlans();
        $this->sendSuccess($result);
    }

    private function getUserSubscription(array $user): void
    {
        $result = $this->billingService->getUserSubscription($user);
        $this->sendSuccess($result);
    }

    private function createSubscription(array $user): void
    {
        $data = $this->getJsonInput();

        if (empty($data['plan_id']) || empty($data['payment_method_id'])) {
            $this->sendError('Plan ID and payment method required', 400);
        }

        try {
            $result = $this->billingService->createSubscription($user, $data['plan_id'], $data['payment_method_id']);
            $this->sendSuccess($result);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    // Helper methods
    private function setCorsHeaders(): void
    {
        $origin = $_ENV['CORS_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Access-Control-Max-Age: 86400");
    }

    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON', 400);
        }

        return $data ?? [];
    }

    private function sendSuccess(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function sendError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}
