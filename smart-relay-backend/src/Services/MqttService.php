<?php

namespace SmartRelay\Services;

use Bluerhinos\phpMQTT;
use SmartRelay\Config\Database;

class MqttService
{
    private $mqtt;
    private $db;
    private $host;
    private $port;
    private $username;
    private $password;
    private $clientId;
    private $connected = false;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->host = $_ENV['MQTT_HOST'] ?? 'localhost';
        $this->port = (int)($_ENV['MQTT_PORT'] ?? 1883);
        $this->username = $_ENV['MQTT_USERNAME'] ?? '';
        $this->password = $_ENV['MQTT_PASSWORD'] ?? '';
        $this->clientId = $_ENV['MQTT_CLIENT_ID'] ?? 'smart_relay_' . uniqid();

        $this->mqtt = new phpMQTT($this->host, $this->port, $this->clientId);
    }

    public function connect(): bool
    {
        try {
            if ($this->username && $this->password) {
                $this->connected = $this->mqtt->connect(true, NULL, $this->username, $this->password);
            } else {
                $this->connected = $this->mqtt->connect();
            }

            if ($this->connected) {
                error_log("MQTT connected successfully");
                $this->subscribeToTopics();
                return true;
            }
        } catch (\Exception $e) {
            error_log("MQTT connection failed: " . $e->getMessage());
        }

        return false;
    }

    public function disconnect(): void
    {
        if ($this->connected) {
            $this->mqtt->close();
            $this->connected = false;
        }
    }

    private function subscribeToTopics(): void
    {
        // Subscribe to all device status topics
        $topics = [
            'smartrelay/+/status' => ['qos' => 0, 'function' => [$this, 'handleDeviceStatus']],
            'smartrelay/+/sensor' => ['qos' => 0, 'function' => [$this, 'handleSensorData']],
            'smartrelay/+/error' => ['qos' => 0, 'function' => [$this, 'handleDeviceError']],
            'smartrelay/+/heartbeat' => ['qos' => 0, 'function' => [$this, 'handleHeartbeat']]
        ];

        foreach ($topics as $topic => $config) {
            $this->mqtt->subscribe($topic, $config['qos']);
            error_log("Subscribed to topic: {$topic}");
        }
    }

    public function publishCommand(string $deviceId, string $command, array $data = []): bool
    {
        if (!$this->connected && !$this->connect()) {
            return false;
        }

        $topic = "smartrelay/{$deviceId}/command";
        $payload = json_encode([
            'command' => $command,
            'data' => $data,
            'timestamp' => time(),
            'id' => uniqid()
        ]);

        try {
            $result = $this->mqtt->publish($topic, $payload, 0);

            // Log the command
            $this->logDeviceCommand($deviceId, $command, $data, $result ? 'sent' : 'failed');

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to publish command: " . $e->getMessage());
            return false;
        }
    }

    public function handleDeviceStatus(string $topic, string $message): void
    {
        $deviceId = $this->extractDeviceIdFromTopic($topic);
        $data = json_decode($message, true);

        if (!$data) {
            error_log("Invalid JSON in device status message");
            return;
        }

        // Update device status in database
        $this->updateDeviceStatus($deviceId, $data);

        // Log the status change
        $this->logDeviceEvent($deviceId, 'status_change', $data);

        // Broadcast to WebSocket clients
        $this->broadcastToWebSocket('device_status', [
            'device_id' => $deviceId,
            'data' => $data
        ]);
    }

    public function handleSensorData(string $topic, string $message): void
    {
        $deviceId = $this->extractDeviceIdFromTopic($topic);
        $data = json_decode($message, true);

        if (!$data) {
            error_log("Invalid JSON in sensor data message");
            return;
        }

        // Log sensor reading
        $this->logDeviceEvent($deviceId, 'sensor_reading', $data);

        // Update device last seen
        $this->updateDeviceLastSeen($deviceId);

        // Broadcast to WebSocket clients
        $this->broadcastToWebSocket('sensor_data', [
            'device_id' => $deviceId,
            'data' => $data
        ]);
    }

    public function handleDeviceError(string $topic, string $message): void
    {
        $deviceId = $this->extractDeviceIdFromTopic($topic);
        $data = json_decode($message, true);

        if (!$data) {
            error_log("Invalid JSON in device error message");
            return;
        }

        // Log the error
        $this->logDeviceEvent($deviceId, 'error', $data);

        // Update device status to error
        $this->updateDeviceStatus($deviceId, ['status' => 'error']);

        // Send alert notifications
        $this->sendErrorAlert($deviceId, $data);

        // Broadcast to WebSocket clients
        $this->broadcastToWebSocket('device_error', [
            'device_id' => $deviceId,
            'data' => $data
        ]);
    }

    public function handleHeartbeat(string $topic, string $message): void
    {
        $deviceId = $this->extractDeviceIdFromTopic($topic);

        // Update device last seen
        $this->updateDeviceLastSeen($deviceId);

        // Update device status to online if it was offline
        $device = $this->getDeviceInfo($deviceId);
        if ($device && $device['status'] !== 'online') {
            $this->updateDeviceStatus($deviceId, ['status' => 'online']);
        }
    }

    private function extractDeviceIdFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);
        return $parts[1] ?? '';
    }

    private function updateDeviceStatus(string $deviceId, array $data): void
    {
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        $this->db->update('devices',
            $updateData,
            'device_id = :device_id',
            ['device_id' => $deviceId]
        );
    }

    private function updateDeviceLastSeen(string $deviceId): void
    {
        $this->db->update('devices',
            ['last_seen' => date('Y-m-d H:i:s')],
            'device_id = :device_id',
            ['device_id' => $deviceId]
        );
    }

    private function getDeviceInfo(string $deviceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM devices WHERE device_id = :device_id",
            ['device_id' => $deviceId]
        );
    }

    private function logDeviceEvent(string $deviceId, string $eventType, array $data): void
    {
        $device = $this->getDeviceInfo($deviceId);
        if (!$device) {
            return;
        }

        $this->db->insert('device_logs', [
            'device_id' => $device['id'],
            'event_type' => $eventType,
            'data' => json_encode($data)
        ]);
    }

    private function logDeviceCommand(string $deviceId, string $command, array $data, string $status): void
    {
        $device = $this->getDeviceInfo($deviceId);
        if (!$device) {
            return;
        }

        $this->db->insert('device_commands', [
            'device_id' => $device['id'],
            'user_id' => 1, // TODO: Get actual user ID from context
            'command_type' => $command,
            'command_data' => json_encode($data),
            'status' => $status,
            'executed_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function sendErrorAlert(string $deviceId, array $errorData): void
    {
        $device = $this->getDeviceInfo($deviceId);
        if (!$device) {
            return;
        }

        // Get device owner
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = :user_id",
            ['user_id' => $device['user_id']]
        );

        if ($user) {
            // TODO: Implement email alert service
            error_log("Device error alert for user {$user['email']}: Device {$device['name']} has error");
        }
    }

    private function broadcastToWebSocket(string $event, array $data): void
    {
        // TODO: Implement WebSocket broadcasting
        // For now, just log the event
        error_log("WebSocket broadcast: {$event} - " . json_encode($data));
    }

    public function processMessages(): void
    {
        if (!$this->connected && !$this->connect()) {
            return;
        }

        try {
            $this->mqtt->proc();
        } catch (\Exception $e) {
            error_log("Error processing MQTT messages: " . $e->getMessage());
            $this->connected = false;
        }
    }

    public function startListening(): void
    {
        echo "Starting MQTT listener...\n";

        while (true) {
            $this->processMessages();
            usleep(100000); // Sleep for 100ms
        }
    }

    // Device control methods
    public function turnOnDevice(string $deviceId): bool
    {
        return $this->publishCommand($deviceId, 'turn_on');
    }

    public function turnOffDevice(string $deviceId): bool
    {
        return $this->publishCommand($deviceId, 'turn_off');
    }

    public function resetDevice(string $deviceId): bool
    {
        return $this->publishCommand($deviceId, 'reset');
    }

    public function updateDeviceConfig(string $deviceId, array $config): bool
    {
        return $this->publishCommand($deviceId, 'config_update', $config);
    }

    public function getDeviceStatus(string $deviceId): ?array
    {
        $device = $this->getDeviceInfo($deviceId);
        if (!$device) {
            return null;
        }

        // Get latest sensor data
        $latestLog = $this->db->fetchOne(
            "SELECT * FROM device_logs
             WHERE device_id = :device_id AND event_type = 'sensor_reading'
             ORDER BY timestamp DESC LIMIT 1",
            ['device_id' => $device['id']]
        );

        return [
            'device' => $device,
            'latest_data' => $latestLog ? json_decode($latestLog['data'], true) : null
        ];
    }
}

// CLI script to run MQTT listener
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'MqttService.php') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    // Load environment variables
    if (file_exists(__DIR__ . '/../../.env')) {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
        $dotenv->load();
    }

    $mqtt = new MqttService();
    $mqtt->startListening();
}
