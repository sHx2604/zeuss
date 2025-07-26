<?php

namespace SmartRelay\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use SmartRelay\Config\Database;

class NotificationService
{
    private $db;
    private $loggingService;
    private $mailer;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loggingService = new LoggingService();
        $this->setupMailer();
    }

    private function setupMailer(): void
    {
        $this->mailer = new PHPMailer(true);

        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = $_ENV['MAIL_USERNAME'] ?? '';
            $this->mailer->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
            $this->mailer->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);

            // Default sender
            $this->mailer->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@smartrelay.com',
                $_ENV['MAIL_FROM_NAME'] ?? 'Smart Relay System'
            );
        } catch (Exception $e) {
            $this->loggingService->error("Failed to setup mailer: " . $e->getMessage());
        }
    }

    public function sendDeviceAlert(int $deviceId, string $alertType, array $data): bool
    {
        try {
            // Get device and user information
            $device = $this->db->fetchOne(
                "SELECT d.*, u.email, u.username
                 FROM devices d
                 JOIN users u ON d.user_id = u.id
                 WHERE d.id = :device_id",
                ['device_id' => $deviceId]
            );

            if (!$device) {
                $this->loggingService->error("Device not found for alert", ['device_id' => $deviceId]);
                return false;
            }

            $subject = $this->getAlertSubject($alertType, $device['name']);
            $htmlBody = $this->getAlertEmailTemplate($alertType, $device, $data);
            $textBody = $this->getAlertTextTemplate($alertType, $device, $data);

            return $this->sendEmail($device['email'], $subject, $htmlBody, $textBody);

        } catch (\Exception $e) {
            $this->loggingService->error("Failed to send device alert", [
                'device_id' => $deviceId,
                'alert_type' => $alertType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendWelcomeEmail(array $user): bool
    {
        try {
            $subject = "Welcome to Smart Relay System";
            $htmlBody = $this->getWelcomeEmailTemplate($user);
            $textBody = "Welcome to Smart Relay System, {$user['username']}!\n\nYour account has been created successfully.";

            return $this->sendEmail($user['email'], $subject, $htmlBody, $textBody);

        } catch (\Exception $e) {
            $this->loggingService->error("Failed to send welcome email", [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendPasswordResetEmail(array $user, string $resetToken): bool
    {
        try {
            $subject = "Password Reset Request";
            $htmlBody = $this->getPasswordResetEmailTemplate($user, $resetToken);
            $textBody = "You requested a password reset. Use this token: {$resetToken}";

            return $this->sendEmail($user['email'], $subject, $htmlBody, $textBody);

        } catch (\Exception $e) {
            $this->loggingService->error("Failed to send password reset email", [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendEmailVerification(array $user, string $verificationToken): bool
    {
        try {
            $subject = "Verify Your Email Address";
            $htmlBody = $this->getEmailVerificationTemplate($user, $verificationToken);
            $textBody = "Please verify your email using this token: {$verificationToken}";

            return $this->sendEmail($user['email'], $subject, $htmlBody, $textBody);

        } catch (\Exception $e) {
            $this->loggingService->error("Failed to send email verification", [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendSubscriptionNotification(array $user, string $eventType, array $data): bool
    {
        try {
            $subject = $this->getSubscriptionEmailSubject($eventType);
            $htmlBody = $this->getSubscriptionEmailTemplate($user, $eventType, $data);
            $textBody = $this->getSubscriptionTextTemplate($user, $eventType, $data);

            return $this->sendEmail($user['email'], $subject, $htmlBody, $textBody);

        } catch (\Exception $e) {
            $this->loggingService->error("Failed to send subscription notification", [
                'user_id' => $user['id'],
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $htmlBody;
            $this->mailer->AltBody = $textBody ?: strip_tags($htmlBody);

            $result = $this->mailer->send();

            if ($result) {
                $this->loggingService->info("Email sent successfully", [
                    'to' => $to,
                    'subject' => $subject
                ]);
            }

            return $result;

        } catch (Exception $e) {
            $this->loggingService->error("Failed to send email", [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getAlertSubject(string $alertType, string $deviceName): string
    {
        $subjects = [
            'offline' => "Device Offline Alert: {$deviceName}",
            'error' => "Device Error Alert: {$deviceName}",
            'low_battery' => "Low Battery Alert: {$deviceName}",
            'sensor_threshold' => "Sensor Threshold Alert: {$deviceName}",
            'maintenance' => "Maintenance Required: {$deviceName}"
        ];

        return $subjects[$alertType] ?? "Device Alert: {$deviceName}";
    }

    private function getAlertEmailTemplate(string $alertType, array $device, array $data): string
    {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'https://smartrelay.app';
        $deviceUrl = "{$baseUrl}/devices/{$device['id']}";

        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; }
                .header { background-color: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                .content { padding: 20px; }
                .alert-info { background-color: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0; }
                .button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Device Alert</h1>
                </div>
                <div class='content'>
                    <h2>Alert: {$this->getAlertTitle($alertType)}</h2>
                    <p>Dear User,</p>
                    <p>We detected an issue with your device <strong>{$device['name']}</strong>.</p>

                    <div class='alert-info'>
                        <strong>Device Details:</strong><br>
                        Name: {$device['name']}<br>
                        Location: {$device['location']}<br>
                        Device ID: {$device['device_id']}<br>
                        Alert Type: {$this->getAlertTitle($alertType)}<br>
                        Time: " . date('Y-m-d H:i:s') . "
                    </div>

                    " . $this->getAlertDescription($alertType, $data) . "

                    <a href='{$deviceUrl}' class='button'>View Device</a>

                    <p>If you need assistance, please contact our support team.</p>
                </div>
                <div class='footer'>
                    <p>Smart Relay System - Automated Device Management</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";

        return $html;
    }

    private function getAlertTextTemplate(string $alertType, array $device, array $data): string
    {
        return "Device Alert: {$this->getAlertTitle($alertType)}\n\n" .
               "Device: {$device['name']}\n" .
               "Location: {$device['location']}\n" .
               "Device ID: {$device['device_id']}\n" .
               "Time: " . date('Y-m-d H:i:s') . "\n\n" .
               $this->getAlertDescription($alertType, $data, false);
    }

    private function getAlertTitle(string $alertType): string
    {
        $titles = [
            'offline' => 'Device Offline',
            'error' => 'Device Error',
            'low_battery' => 'Low Battery',
            'sensor_threshold' => 'Sensor Threshold Exceeded',
            'maintenance' => 'Maintenance Required'
        ];

        return $titles[$alertType] ?? 'Device Alert';
    }

    private function getAlertDescription(string $alertType, array $data, bool $html = true): string
    {
        $br = $html ? '<br>' : "\n";

        switch ($alertType) {
            case 'offline':
                return "Your device has gone offline and is no longer responding to commands.{$br}Please check the device connection and power supply.";

            case 'error':
                $error = $data['error'] ?? 'Unknown error';
                return "Your device reported an error: {$error}{$br}Please check the device status and configuration.";

            case 'low_battery':
                $level = $data['battery_level'] ?? 'unknown';
                return "Your device battery level is low: {$level}%{$br}Please replace or recharge the battery soon.";

            case 'sensor_threshold':
                $sensor = $data['sensor'] ?? 'unknown';
                $value = $data['value'] ?? 'unknown';
                $threshold = $data['threshold'] ?? 'unknown';
                return "Sensor '{$sensor}' reading ({$value}) has exceeded the threshold ({$threshold}).{$br}Please review the sensor data and take appropriate action.";

            default:
                return "Please check your device status and take appropriate action if needed.";
        }
    }

    private function getWelcomeEmailTemplate(array $user): string
    {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'https://smartrelay.app';
        $dashboardUrl = "{$baseUrl}/dashboard";

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; }
                .header { background-color: #28a745; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                .content { padding: 20px; }
                .button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Welcome to Smart Relay System!</h1>
                </div>
                <div class='content'>
                    <h2>Hello {$user['username']}!</h2>
                    <p>Thank you for joining Smart Relay System. Your account has been created successfully.</p>

                    <p>With Smart Relay System, you can:</p>
                    <ul>
                        <li>Monitor and control your IoT devices remotely</li>
                        <li>Receive real-time alerts and notifications</li>
                        <li>View detailed analytics and reports</li>
                        <li>Manage device schedules and automation</li>
                    </ul>

                    <a href='{$dashboardUrl}' class='button'>Go to Dashboard</a>

                    <p>If you have any questions or need help getting started, feel free to contact our support team.</p>
                </div>
                <div class='footer'>
                    <p>Smart Relay System - Your IoT Management Solution</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getPasswordResetEmailTemplate(array $user, string $resetToken): string
    {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'https://smartrelay.app';
        $resetUrl = "{$baseUrl}/reset-password?token={$resetToken}";

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; }
                .header { background-color: #ffc107; color: #212529; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                .content { padding: 20px; }
                .button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                <div class='content'>
                    <h2>Reset Your Password</h2>
                    <p>Hello {$user['username']},</p>
                    <p>We received a request to reset your password. Click the button below to set a new password:</p>

                    <a href='{$resetUrl}' class='button'>Reset Password</a>

                    <p>If you didn't request this password reset, please ignore this email. Your password will remain unchanged.</p>
                    <p>This link will expire in 1 hour for security reasons.</p>
                </div>
                <div class='footer'>
                    <p>Smart Relay System - Account Security</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getEmailVerificationTemplate(array $user, string $verificationToken): string
    {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'https://smartrelay.app';
        $verifyUrl = "{$baseUrl}/verify-email?token={$verificationToken}";

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; }
                .header { background-color: #17a2b8; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                .content { padding: 20px; }
                .button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Verify Your Email</h1>
                </div>
                <div class='content'>
                    <h2>Email Verification Required</h2>
                    <p>Hello {$user['username']},</p>
                    <p>Please verify your email address to complete your account setup:</p>

                    <a href='{$verifyUrl}' class='button'>Verify Email</a>

                    <p>If you didn't create this account, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>Smart Relay System - Account Verification</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getSubscriptionEmailSubject(string $eventType): string
    {
        $subjects = [
            'created' => 'Subscription Activated',
            'updated' => 'Subscription Updated',
            'canceled' => 'Subscription Canceled',
            'expired' => 'Subscription Expired',
            'payment_failed' => 'Payment Failed'
        ];

        return $subjects[$eventType] ?? 'Subscription Update';
    }

    private function getSubscriptionEmailTemplate(array $user, string $eventType, array $data): string
    {
        // Implementation for subscription email templates
        return "<p>Subscription {$eventType} for user {$user['username']}</p>";
    }

    private function getSubscriptionTextTemplate(array $user, string $eventType, array $data): string
    {
        return "Subscription {$eventType} for user {$user['username']}";
    }
}
