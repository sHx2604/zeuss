#!/bin/bash

# Smart Relay System - Service Starter Script
# This script starts all required backend services

echo "🚀 Starting Smart Relay System Backend Services..."

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed. Please install PHP 8.1+ first."
    exit 1
fi

# Check if Composer is installed
if ! command -v composer &> /dev/null; then
    echo "❌ Composer is not installed. Please install Composer first."
    exit 1
fi

# Install dependencies if not already installed
if [ ! -d "vendor" ]; then
    echo "📦 Installing PHP dependencies..."
    composer install
fi

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "⚙️ Creating .env file from template..."
    cp .env.example .env
    echo "📝 Please edit .env file with your configuration before starting services."
    exit 1
fi

# Create logs directory if it doesn't exist
mkdir -p logs

echo "🌐 Starting PHP Development Server on port 8000..."
php -S localhost:8000 -t public > logs/php-server.log 2>&1 &
PHP_PID=$!

echo "📡 Starting WebSocket Server on port 8080..."
php src/Services/WebSocketServer.php > logs/websocket.log 2>&1 &
WEBSOCKET_PID=$!

echo "🔌 Starting MQTT Service..."
php src/Services/MqttService.php > logs/mqtt.log 2>&1 &
MQTT_PID=$!

# Save PIDs for cleanup
echo $PHP_PID > .pids/php.pid
echo $WEBSOCKET_PID > .pids/websocket.pid
echo $MQTT_PID > .pids/mqtt.pid

echo "✅ All services started successfully!"
echo ""
echo "📋 Service Status:"
echo "   🌐 API Server: http://localhost:8000"
echo "   📡 WebSocket: ws://localhost:8080"
echo "   🔌 MQTT Service: Running"
echo ""
echo "📝 Logs:"
echo "   📄 API Server: logs/php-server.log"
echo "   📄 WebSocket: logs/websocket.log"
echo "   📄 MQTT: logs/mqtt.log"
echo "   📄 Application: logs/app.log"
echo ""
echo "🛑 To stop all services, run: ./stop-services.sh"
echo ""
echo "📚 API Documentation: http://localhost:8000/api/v1"
echo "🔧 Admin Panel: Access via your frontend application"

# Wait for user input to keep services running
echo ""
echo "Press Ctrl+C to stop all services..."
trap 'echo "🛑 Stopping services..."; kill $PHP_PID $WEBSOCKET_PID $MQTT_PID; rm -rf .pids; exit' INT
wait
