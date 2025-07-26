<?php

// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use SmartRelay\Controllers\ApiController;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', $_ENV['APP_DEBUG'] ?? '0');

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// Handle the API request
try {
    $api = new ApiController();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');

    $response = [
        'success' => false,
        'message' => 'Internal server error',
        'timestamp' => date('c')
    ];

    // Show detailed error in debug mode
    if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
        $response['debug'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }

    echo json_encode($response);
}
