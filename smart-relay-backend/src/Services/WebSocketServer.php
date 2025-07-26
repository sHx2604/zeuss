<?php

namespace SmartRelay\Services;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use SmartRelay\Config\Database;

class WebSocketServer
{
    private $worker;
    private $db;
    private $connections = [];
    private $userConnections = [];

    public function __construct()
    {
        $this->db = Database::getInstance();

        // Create WebSocket worker
        $this->worker = new Worker("websocket://0.0.0.0:8080");
        $this->worker->name = 'SmartRelayWebSocket';

        // Set event handlers
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
    }

    public function onConnect(TcpConnection $connection): void
    {
        echo "New connection: {$connection->id}\n";
    }

    public function onMessage(TcpConnection $connection, string $data): void
    {
        $message = json_decode($data, true);

        if (!$message || !isset($message['type'])) {
            $this->sendError($connection, 'Invalid message format');
            return;
        }

        try {
            switch ($message['type']) {
                case 'auth':
                    $this->handleAuth($connection, $message);
                    break;

                case 'subscribe_device':
                    $this->handleDeviceSubscription($connection, $message);
                    break;

                case 'device_control':
                    $this->handleDeviceControl($connection, $message);
                    break;

                default:
                    $this->sendError($connection, 'Unknown message type');
            }
        } catch (\Exception $e) {
            $this->sendError($connection, $e->getMessage());
        }
    }

    public function onClose(TcpConnection $connection): void
    {
        // Remove connection from tracking
        unset($this->connections[$connection->id]);

        // Remove from user connections
        foreach ($this->userConnections as $userId => $userConns) {
            if (isset($userConns[$connection->id])) {
                unset($this->userConnections[$userId][$connection->id]);
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                }
                break;
            }
        }

        echo "Connection closed: {$connection->id}\n";
    }

    public function onError(TcpConnection $connection, $code, $msg): void
    {
        echo "Connection error: {$connection->id} - {$msg}\n";
    }

    private function handleAuth(TcpConnection $connection, array $message): void
    {
        if (!isset($message['token'])) {
            $this->sendError($connection, 'Token required');
            return;
        }

        $authService = new AuthService();
        $result = $authService->verifyToken($message['token']);

        if (!$result['valid']) {
            $this->sendError($connection, 'Invalid token');
            return;
        }

        $user = $result['user'];

        // Store authenticated connection
        $this->connections[$connection->id] = [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'connection' => $connection
        ];

        // Group by user
        if (!isset($this->userConnections[$user['id']])) {
            $this->userConnections[$user['id']] = [];
        }
        $this->userConnections[$user['id']][$connection->id] = $connection;

        $this->sendSuccess($connection, [
            'message' => 'Authenticated successfully',
            'user' => $user
        ]);
    }

    private function handleDeviceSubscription(TcpConnection $connection, array $message): void
    {
        if (!$this->isAuthenticated($connection)) {
            $this->sendError($connection, 'Authentication required');
            return;
        }

        $deviceId = $message['device_id'] ?? null;
        $userId = $this->connections[$connection->id]['user_id'];

        if (!$deviceId) {
            $this->sendError($connection, 'Device ID required');
            return;
        }

        // Verify user has access to device
        $device = $this->db->fetchOne(
            "SELECT * FROM devices WHERE id = :device_id AND user_id = :user_id",
            ['device_id' => $deviceId, 'user_id' => $userId]
        );

        if (!$device) {
            $this->sendError($connection, 'Device not found or access denied');
            return;
        }

        // Subscribe to device updates
        $this->subscribeToDevice($connection, $deviceId);

        $this->sendSuccess($connection, [
            'message' => 'Subscribed to device updates',
            'device_id' => $deviceId
        ]);
    }

    private function handleDeviceControl(TcpConnection $connection, array $message): void
    {
        if (!$this->isAuthenticated($connection)) {
            $this->sendError($connection, 'Authentication required');
            return;
        }

        $deviceId = $message['device_id'] ?? null;
        $action = $message['action'] ?? null;
        $params = $message['params'] ?? [];

        if (!$deviceId || !$action) {
            $this->sendError($connection, 'Device ID and action required');
            return;
        }

        $userId = $this->connections[$connection->id]['user_id'];
        $role = $this->connections[$connection->id]['role'];

        try {
            $deviceService = new DeviceService();
            $user = ['id' => $userId, 'role' => $role];

            $result = $deviceService->controlDevice($user, $deviceId, $action, $params);

            $this->sendSuccess($connection, $result);

            // Broadcast device update to all subscribers
            $this->broadcastDeviceUpdate($deviceId, [
                'type' => 'device_update',
                'device_id' => $deviceId,
                'action' => $action,
                'status' => $result['status'] ?? 'unknown',
                'timestamp' => date('c')
            ]);

        } catch (\Exception $e) {
            $this->sendError($connection, $e->getMessage());
        }
    }

    private function isAuthenticated(TcpConnection $connection): bool
    {
        return isset($this->connections[$connection->id]);
    }

    private function subscribeToDevice(TcpConnection $connection, int $deviceId): void
    {
        // Store device subscription
        if (!isset($this->connections[$connection->id]['subscriptions'])) {
            $this->connections[$connection->id]['subscriptions'] = [];
        }
        $this->connections[$connection->id]['subscriptions'][] = $deviceId;
    }

    private function broadcastDeviceUpdate(int $deviceId, array $data): void
    {
        foreach ($this->connections as $connectionData) {
            $subscriptions = $connectionData['subscriptions'] ?? [];
            if (in_array($deviceId, $subscriptions)) {
                $connectionData['connection']->send(json_encode($data));
            }
        }
    }

    public function broadcastToUser(int $userId, array $data): void
    {
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $connection) {
                $connection->send(json_encode($data));
            }
        }
    }

    public function broadcastToAll(array $data): void
    {
        foreach ($this->connections as $connectionData) {
            $connectionData['connection']->send(json_encode($data));
        }
    }

    private function sendSuccess(TcpConnection $connection, array $data): void
    {
        $response = [
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ];
        $connection->send(json_encode($response));
    }

    private function sendError(TcpConnection $connection, string $message): void
    {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ];
        $connection->send(json_encode($response));
    }

    public function run(): void
    {
        echo "Starting WebSocket server on port 8080...\n";
        Worker::runAll();
    }
}

// Run the server if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->load();

    $server = new WebSocketServer();
    $server->run();
}
