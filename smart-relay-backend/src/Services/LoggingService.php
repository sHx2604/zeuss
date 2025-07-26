<?php

namespace SmartRelay\Services;

use SmartRelay\Config\Database;

class LoggingService
{
    private $db;
    private $logLevel;
    private $logPath;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logLevel = $_ENV['LOG_LEVEL'] ?? 'info';
        $this->logPath = $_ENV['LOG_PATH'] ?? 'logs/app.log';

        // Create logs directory if it doesn't exist
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public function logDeviceEvent(int $deviceId, string $eventType, array $data, ?int $userId = null): void
    {
        try {
            $this->db->insert('device_logs', [
                'device_id' => $deviceId,
                'user_id' => $userId,
                'event_type' => $eventType,
                'data' => json_encode($data),
                'timestamp' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to log device event: " . $e->getMessage());
        }
    }

    public function logApiRequest(array $request, ?array $user = null): void
    {
        try {
            $this->db->insert('api_logs', [
                'user_id' => $user['id'] ?? null,
                'method' => $request['method'] ?? '',
                'endpoint' => $request['endpoint'] ?? '',
                'ip_address' => $request['ip'] ?? '',
                'user_agent' => $request['user_agent'] ?? '',
                'response_code' => $request['response_code'] ?? 200,
                'response_time' => $request['response_time'] ?? 0,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to log API request: " . $e->getMessage());
        }
    }

    public function logUserActivity(int $userId, string $activity, array $data = []): void
    {
        try {
            $this->db->insert('user_activity_logs', [
                'user_id' => $userId,
                'activity' => $activity,
                'data' => json_encode($data),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to log user activity: " . $e->getMessage());
        }
    }

    public function logBillingEvent(int $userId, string $eventType, array $data): void
    {
        try {
            $this->db->insert('billing_logs', [
                'user_id' => $userId,
                'event_type' => $eventType,
                'data' => json_encode($data),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to log billing event: " . $e->getMessage());
        }
    }

    public function getDeviceLogs(int $deviceId, array $filters = []): array
    {
        $where = ['device_id = :device_id'];
        $params = ['device_id' => $deviceId];

        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'timestamp >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = 'timestamp <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        $limit = min((int)($filters['limit'] ?? 100), 1000);
        $offset = (int)($filters['offset'] ?? 0);

        $sql = "SELECT * FROM device_logs WHERE " . implode(' AND ', $where) .
               " ORDER BY timestamp DESC LIMIT {$limit} OFFSET {$offset}";

        $logs = $this->db->fetchAll($sql, $params);

        foreach ($logs as &$log) {
            $log['data'] = json_decode($log['data'], true);
        }

        return $logs;
    }

    public function getUserActivityLogs(int $userId, array $filters = []): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (!empty($filters['activity'])) {
            $where[] = 'activity = :activity';
            $params['activity'] = $filters['activity'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'timestamp >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = 'timestamp <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        $limit = min((int)($filters['limit'] ?? 100), 1000);
        $offset = (int)($filters['offset'] ?? 0);

        $sql = "SELECT * FROM user_activity_logs WHERE " . implode(' AND ', $where) .
               " ORDER BY timestamp DESC LIMIT {$limit} OFFSET {$offset}";

        $logs = $this->db->fetchAll($sql, $params);

        foreach ($logs as &$log) {
            $log['data'] = json_decode($log['data'], true);
        }

        return $logs;
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->shouldLog('debug')) {
            $this->writeLog('DEBUG', $message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        if ($this->shouldLog('info')) {
            $this->writeLog('INFO', $message, $context);
        }
    }

    public function warning(string $message, array $context = []): void
    {
        if ($this->shouldLog('warning')) {
            $this->writeLog('WARNING', $message, $context);
        }
    }

    public function error(string $message, array $context = []): void
    {
        if ($this->shouldLog('error')) {
            $this->writeLog('ERROR', $message, $context);
        }
    }

    public function critical(string $message, array $context = []): void
    {
        if ($this->shouldLog('critical')) {
            $this->writeLog('CRITICAL', $message, $context);
        }
    }

    private function shouldLog(string $level): bool
    {
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
        $currentLevel = $levels[$this->logLevel] ?? 1;
        $messageLevel = $levels[$level] ?? 1;

        return $messageLevel >= $currentLevel;
    }

    private function writeLog(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logLine = "[{$timestamp}] {$level}: {$message} {$contextStr}" . PHP_EOL;

        file_put_contents($this->logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    public function cleanOldLogs(int $daysToKeep = 30): void
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        try {
            // Clean device logs
            $this->db->execute(
                "DELETE FROM device_logs WHERE timestamp < :cutoff_date",
                ['cutoff_date' => $cutoffDate]
            );

            // Clean API logs
            $this->db->execute(
                "DELETE FROM api_logs WHERE timestamp < :cutoff_date",
                ['cutoff_date' => $cutoffDate]
            );

            // Clean user activity logs
            $this->db->execute(
                "DELETE FROM user_activity_logs WHERE timestamp < :cutoff_date",
                ['cutoff_date' => $cutoffDate]
            );

            $this->info("Cleaned old logs older than {$daysToKeep} days");
        } catch (\Exception $e) {
            $this->error("Failed to clean old logs: " . $e->getMessage());
        }
    }
}
