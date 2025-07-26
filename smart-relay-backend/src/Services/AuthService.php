<?php

namespace SmartRelay\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SmartRelay\Config\Database;

class AuthService
{
    private $db;
    private $jwtSecret;
    private $jwtExpire;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? 'default-secret-change-this';
        $this->jwtExpire = (int)($_ENV['JWT_EXPIRE'] ?? 3600);
    }

    public function register(array $userData): array
    {
        // Validate required fields
        $required = ['username', 'email', 'password'];
        foreach ($required as $field) {
            if (empty($userData[$field])) {
                throw new \InvalidArgumentException("Field {$field} is required");
            }
        }

        // Check if user already exists
        $existingUser = $this->db->fetchOne(
            "SELECT id FROM users WHERE username = :username OR email = :email",
            ['username' => $userData['username'], 'email' => $userData['email']]
        );

        if ($existingUser) {
            throw new \Exception("User with this username or email already exists");
        }

        // Hash password
        $passwordHash = password_hash($userData['password'], PASSWORD_DEFAULT);

        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));

        // Insert user
        $userId = $this->db->insert('users', [
            'username' => $userData['username'],
            'email' => $userData['email'],
            'password_hash' => $passwordHash,
            'role' => $userData['role'] ?? 'user',
            'verification_token' => $verificationToken,
            'status' => 'inactive' // Require email verification
        ]);

        // Send verification email (implement email service)
        $this->sendVerificationEmail($userData['email'], $verificationToken);

        return [
            'success' => true,
            'message' => 'User registered successfully. Please check your email for verification.',
            'user_id' => $userId
        ];
    }

    public function login(string $username, string $password): array
    {
        // Find user
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE (username = :username OR email = :username) AND status = 'active'",
            ['username' => $username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new \Exception("Invalid credentials");
        }

        if (!$user['email_verified']) {
            throw new \Exception("Please verify your email address before logging in");
        }

        // Update last login
        $this->db->update('users',
            ['last_login' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $user['id']]
        );

        // Generate JWT token
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + $this->jwtExpire
        ];

        $token = JWT::encode($payload, $this->jwtSecret, 'HS256');

        return [
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ];
    }

    public function verifyToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));

            // Verify user still exists and is active
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE id = :id AND status = 'active'",
                ['id' => $decoded->user_id]
            );

            if (!$user) {
                throw new \Exception("User not found or inactive");
            }

            return [
                'valid' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'Invalid token'
            ];
        }
    }

    public function hasPermission(array $user, string $permission, array $context = []): bool
    {
        $role = $user['role'];
        $userId = $user['id'];

        switch ($permission) {
            case 'device.create':
                // Check device limit based on subscription
                if ($role === 'admin') return true;
                return $this->checkDeviceLimit($userId);

            case 'device.read':
                if ($role === 'admin') return true;
                if ($role === 'viewer' && isset($context['device_user_id'])) {
                    return $context['device_user_id'] == $userId;
                }
                return $role === 'user' && isset($context['device_user_id'])
                    && $context['device_user_id'] == $userId;

            case 'device.update':
            case 'device.delete':
                if ($role === 'admin') return true;
                return $role === 'user' && isset($context['device_user_id'])
                    && $context['device_user_id'] == $userId;

            case 'device.control':
                if ($role === 'admin') return true;
                return $role === 'user' && isset($context['device_user_id'])
                    && $context['device_user_id'] == $userId;

            case 'admin.users':
            case 'admin.billing':
            case 'admin.system':
                return $role === 'admin';

            default:
                return false;
        }
    }

    private function checkDeviceLimit(int $userId): bool
    {
        // Get user's subscription
        $subscription = $this->db->fetchOne(
            "SELECT sp.max_devices
             FROM user_subscriptions us
             JOIN subscription_plans sp ON us.plan_id = sp.id
             WHERE us.user_id = :user_id AND us.status = 'active'",
            ['user_id' => $userId]
        );

        $maxDevices = $subscription['max_devices'] ?? (int)($_ENV['DEFAULT_MAX_DEVICES'] ?? 5);

        // Count current devices
        $currentDevices = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM devices WHERE user_id = :user_id",
            ['user_id' => $userId]
        )['count'];

        return $currentDevices < $maxDevices;
    }

    public function verifyEmail(string $token): array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE verification_token = :token",
            ['token' => $token]
        );

        if (!$user) {
            throw new \Exception("Invalid verification token");
        }

        $this->db->update('users',
            [
                'email_verified' => 1,
                'status' => 'active',
                'verification_token' => null
            ],
            'id = :id',
            ['id' => $user['id']]
        );

        return [
            'success' => true,
            'message' => 'Email verified successfully'
        ];
    }

    public function requestPasswordReset(string $email): array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => $email]
        );

        if (!$user) {
            // Don't reveal if email exists for security
            return [
                'success' => true,
                'message' => 'If the email exists, a reset link has been sent.'
            ];
        }

        $resetToken = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $this->db->update('users',
            [
                'reset_token' => $resetToken,
                'reset_token_expires' => $expires
            ],
            'id = :id',
            ['id' => $user['id']]
        );

        // Send password reset email
        $this->sendPasswordResetEmail($email, $resetToken);

        return [
            'success' => true,
            'message' => 'If the email exists, a reset link has been sent.'
        ];
    }

    private function sendVerificationEmail(string $email, string $token): void
    {
        // Implement email sending logic here
        // For now, just log the verification URL
        $verifyUrl = $_ENV['FRONTEND_URL'] . "/verify-email?token=" . $token;
        error_log("Verification email for {$email}: {$verifyUrl}");
    }

    private function sendPasswordResetEmail(string $email, string $token): void
    {
        // Implement email sending logic here
        $resetUrl = $_ENV['FRONTEND_URL'] . "/reset-password?token=" . $token;
        error_log("Password reset email for {$email}: {$resetUrl}");
    }
}
