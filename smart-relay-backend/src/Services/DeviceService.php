<?php

namespace SmartRelay\Services;

use SmartRelay\Config\Database;

class DeviceService
{
    private $db;
    private $mqttService;
    private $authService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->mqttService = new MqttService();
        $this->authService = new AuthService();
    }

    public function createDevice(array $user, array $deviceData): array
    {
        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.create')) {
            throw new \Exception("Permission denied: Cannot create device");
        }

        // Validate required fields
        $required = ['device_id', 'name', 'location'];
        foreach ($required as $field) {
            if (empty($deviceData[$field])) {
                throw new \InvalidArgumentException("Field {$field} is required");
            }
        }

        // Check if device already exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM devices WHERE device_id = :device_id",
            ['device_id' => $deviceData['device_id']]
        );

        if ($existing) {
            throw new \Exception("Device with this ID already exists");
        }

        // Generate MQTT topic
        $mqttTopic = "smartrelay/{$deviceData['device_id']}";

        // Insert device
        $deviceId = $this->db->insert('devices', [
            'user_id' => $user['id'],
            'device_id' => $deviceData['device_id'],
            'name' => $deviceData['name'],
            'location' => $deviceData['location'],
            'device_type' => $deviceData['device_type'] ?? 'relay',
            'mqtt_topic' => $mqttTopic,
            'configuration' => json_encode($deviceData['configuration'] ?? []),
            'status' => 'offline'
        ]);

        // Log device creation
        $this->logDeviceEvent($deviceId, 'device_created', [
            'device_id' => $deviceData['device_id'],
            'created_by' => $user['username']
        ]);

        return [
            'success' => true,
            'message' => 'Device created successfully',
            'device_id' => $deviceId,
            'mqtt_topic' => $mqttTopic
        ];
    }

    public function getDevices(array $user, array $filters = []): array
    {
        $where = [];
        $params = [];

        // Role-based filtering
        if ($user['role'] !== 'admin') {
            $where[] = "d.user_id = :user_id";
            $params['user_id'] = $user['id'];
        }

        // Apply additional filters
        if (!empty($filters['status'])) {
            $where[] = "d.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['device_type'])) {
            $where[] = "d.device_type = :device_type";
            $params['device_type'] = $filters['device_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(d.name LIKE :search OR d.location LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT
                d.*,
                u.username as owner_username,
                (SELECT COUNT(*) FROM device_logs dl WHERE dl.device_id = d.id) as log_count,
                (SELECT timestamp FROM device_logs dl
                 WHERE dl.device_id = d.id AND dl.event_type = 'sensor_reading'
                 ORDER BY timestamp DESC LIMIT 1) as last_sensor_reading
            FROM devices d
            LEFT JOIN users u ON d.user_id = u.id
            {$whereClause}
            ORDER BY d.created_at DESC
        ";

        $devices = $this->db->fetchAll($sql, $params);

        // Get latest sensor data for each device
        foreach ($devices as &$device) {
            $device['configuration'] = json_decode($device['configuration'] ?? '{}', true);
            $device['latest_data'] = $this->getLatestSensorData($device['id']);
        }

        return [
            'success' => true,
            'devices' => $devices,
            'total' => count($devices)
        ];
    }

    public function getDevice(array $user, int $deviceId): array
    {
        $device = $this->db->fetchOne(
            "SELECT d.*, u.username as owner_username
             FROM devices d
             LEFT JOIN users u ON d.user_id = u.id
             WHERE d.id = :id",
            ['id' => $deviceId]
        );

        if (!$device) {
            throw new \Exception("Device not found");
        }

        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.read', ['device_user_id' => $device['user_id']])) {
            throw new \Exception("Permission denied: Cannot view this device");
        }

        $device['configuration'] = json_decode($device['configuration'] ?? '{}', true);
        $device['latest_data'] = $this->getLatestSensorData($device['id']);
        $device['recent_logs'] = $this->getRecentLogs($device['id'], 10);

        return [
            'success' => true,
            'device' => $device
        ];
    }

    public function updateDevice(array $user, int $deviceId, array $updateData): array
    {
        $device = $this->db->fetchOne("SELECT * FROM devices WHERE id = :id", ['id' => $deviceId]);

        if (!$device) {
            throw new \Exception("Device not found");
        }

        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.update', ['device_user_id' => $device['user_id']])) {
            throw new \Exception("Permission denied: Cannot update this device");
        }

        // Prepare update data
        $allowedFields = ['name', 'location', 'configuration'];
        $updateFields = [];

        foreach ($allowedFields as $field) {
            if (isset($updateData[$field])) {
                if ($field === 'configuration') {
                    $updateFields[$field] = json_encode($updateData[$field]);
                } else {
                    $updateFields[$field] = $updateData[$field];
                }
            }
        }

        if (empty($updateFields)) {
            throw new \InvalidArgumentException("No valid fields to update");
        }

        $updateFields['updated_at'] = date('Y-m-d H:i:s');

        // Update device
        $this->db->update('devices', $updateFields, 'id = :id', ['id' => $deviceId]);

        // Log device update
        $this->logDeviceEvent($deviceId, 'device_updated', [
            'updated_fields' => array_keys($updateFields),
            'updated_by' => $user['username']
        ]);

        // If configuration was updated, send to device via MQTT
        if (isset($updateData['configuration'])) {
            $this->mqttService->updateDeviceConfig($device['device_id'], $updateData['configuration']);
        }

        return [
            'success' => true,
            'message' => 'Device updated successfully'
        ];
    }

    public function deleteDevice(array $user, int $deviceId): array
    {
        $device = $this->db->fetchOne("SELECT * FROM devices WHERE id = :id", ['id' => $deviceId]);

        if (!$device) {
            throw new \Exception("Device not found");
        }

        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.delete', ['device_user_id' => $device['user_id']])) {
            throw new \Exception("Permission denied: Cannot delete this device");
        }

        try {
            $this->db->beginTransaction();

            // Delete related records (cascading delete should handle most)
            $this->db->delete('device_schedules', 'device_id = :device_id', ['device_id' => $deviceId]);
            $this->db->delete('device_commands', 'device_id = :device_id', ['device_id' => $deviceId]);
            $this->db->delete('device_logs', 'device_id = :device_id', ['device_id' => $deviceId]);

            // Delete device
            $this->db->delete('devices', 'id = :id', ['id' => $deviceId]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Device deleted successfully'
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function controlDevice(array $user, int $deviceId, string $action, array $params = []): array
    {
        $device = $this->db->fetchOne("SELECT * FROM devices WHERE id = :id", ['id' => $deviceId]);

        if (!$device) {
            throw new \Exception("Device not found");
        }

        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.control', ['device_user_id' => $device['user_id']])) {
            throw new \Exception("Permission denied: Cannot control this device");
        }

        $result = false;

        switch ($action) {
            case 'turn_on':
                $result = $this->mqttService->turnOnDevice($device['device_id']);
                break;

            case 'turn_off':
                $result = $this->mqttService->turnOffDevice($device['device_id']);
                break;

            case 'reset':
                $result = $this->mqttService->resetDevice($device['device_id']);
                break;

            case 'toggle':
                // Determine current state and toggle
                $currentStatus = $this->getCurrentDeviceStatus($device['id']);
                if ($currentStatus === 'on') {
                    $result = $this->mqttService->turnOffDevice($device['device_id']);
                } else {
                    $result = $this->mqttService->turnOnDevice($device['device_id']);
                }
                break;

            default:
                throw new \InvalidArgumentException("Invalid action: {$action}");
        }

        // Log the command
        $this->db->insert('device_commands', [
            'device_id' => $deviceId,
            'user_id' => $user['id'],
            'command_type' => $action,
            'command_data' => json_encode($params),
            'status' => $result ? 'sent' : 'failed'
        ]);

        return [
            'success' => $result,
            'message' => $result ? 'Command sent successfully' : 'Failed to send command',
            'action' => $action
        ];
    }

    public function getDeviceLogs(array $user, int $deviceId, array $filters = []): array
    {
        $device = $this->db->fetchOne("SELECT * FROM devices WHERE id = :id", ['id' => $deviceId]);

        if (!$device) {
            throw new \Exception("Device not found");
        }

        // Check permissions
        if (!$this->authService->hasPermission($user, 'device.read', ['device_user_id' => $device['user_id']])) {
            throw new \Exception("Permission denied: Cannot view device logs");
        }

        $where = ['device_id = :device_id'];
        $params = ['device_id' => $deviceId];

        // Apply filters
        if (!empty($filters['event_type'])) {
            $where[] = "event_type = :event_type";
            $params['event_type'] = $filters['event_type'];
        }

        if (!empty($filters['from_date'])) {
            $where[] = "timestamp >= :from_date";
            $params['from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $where[] = "timestamp <= :to_date";
            $params['to_date'] = $filters['to_date'];
        }

        $limit = (int)($filters['limit'] ?? 100);
        $offset = (int)($filters['offset'] ?? 0);

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT * FROM device_logs
            WHERE {$whereClause}
            ORDER BY timestamp DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $logs = $this->db->fetchAll($sql, $params);

        // Decode JSON data
        foreach ($logs as &$log) {
            $log['data'] = json_decode($log['data'], true);
        }

        // Get total count
        $totalSql = "SELECT COUNT(*) as total FROM device_logs WHERE {$whereClause}";
        $total = $this->db->fetchOne($totalSql, $params)['total'];

        return [
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    private function getLatestSensorData(int $deviceId): ?array
    {
        $log = $this->db->fetchOne(
            "SELECT data FROM device_logs
             WHERE device_id = :device_id AND event_type = 'sensor_reading'
             ORDER BY timestamp DESC LIMIT 1",
            ['device_id' => $deviceId]
        );

        return $log ? json_decode($log['data'], true) : null;
    }

    private function getRecentLogs(int $deviceId, int $limit = 10): array
    {
        $logs = $this->db->fetchAll(
            "SELECT * FROM device_logs
             WHERE device_id = :device_id
             ORDER BY timestamp DESC LIMIT :limit",
            ['device_id' => $deviceId, 'limit' => $limit]
        );

        foreach ($logs as &$log) {
            $log['data'] = json_decode($log['data'], true);
        }

        return $logs;
    }

    private function getCurrentDeviceStatus(int $deviceId): string
    {
        $log = $this->db->fetchOne(
            "SELECT data FROM device_logs
             WHERE device_id = :device_id AND event_type = 'status_change'
             ORDER BY timestamp DESC LIMIT 1",
            ['device_id' => $deviceId]
        );

        if ($log) {
            $data = json_decode($log['data'], true);
            return $data['status'] ?? 'unknown';
        }

        return 'unknown';
    }

    private function logDeviceEvent(int $deviceId, string $eventType, array $data): void
    {
        $this->db->insert('device_logs', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'data' => json_encode($data)
        ]);
    }

    public function getDeviceStatistics(array $user): array
    {
        $where = [];
        $params = [];

        // Role-based filtering
        if ($user['role'] !== 'admin') {
            $where[] = "user_id = :user_id";
            $params['user_id'] = $user['id'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get device counts by status
        $statusCounts = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count FROM devices {$whereClause} GROUP BY status",
            $params
        );

        // Get total device count
        $totalDevices = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM devices {$whereClause}",
            $params
        )['total'];

        // Get device activity in last 24 hours
        $activeDevices = $this->db->fetchOne(
            "SELECT COUNT(*) as active FROM devices
             {$whereClause}" . ($where ? ' AND' : 'WHERE') . " last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $params
        )['active'];

        return [
            'success' => true,
            'statistics' => [
                'total_devices' => $totalDevices,
                'active_devices' => $activeDevices,
                'status_breakdown' => $statusCounts
            ]
        ];
    }
}
