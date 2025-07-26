#!/bin/bash

# Smart Relay System - Service Stopper Script
# This script stops all running backend services

echo "🛑 Stopping Smart Relay System Backend Services..."

# Create .pids directory if it doesn't exist
mkdir -p .pids

# Stop PHP Development Server
if [ -f ".pids/php.pid" ]; then
    PHP_PID=$(cat .pids/php.pid)
    if ps -p $PHP_PID > /dev/null; then
        echo "🌐 Stopping PHP Development Server (PID: $PHP_PID)..."
        kill $PHP_PID
        rm .pids/php.pid
    else
        echo "🌐 PHP Development Server is not running"
        rm -f .pids/php.pid
    fi
else
    echo "🌐 PHP Development Server PID file not found"
fi

# Stop WebSocket Server
if [ -f ".pids/websocket.pid" ]; then
    WEBSOCKET_PID=$(cat .pids/websocket.pid)
    if ps -p $WEBSOCKET_PID > /dev/null; then
        echo "📡 Stopping WebSocket Server (PID: $WEBSOCKET_PID)..."
        kill $WEBSOCKET_PID
        rm .pids/websocket.pid
    else
        echo "📡 WebSocket Server is not running"
        rm -f .pids/websocket.pid
    fi
else
    echo "📡 WebSocket Server PID file not found"
fi

# Stop MQTT Service
if [ -f ".pids/mqtt.pid" ]; then
    MQTT_PID=$(cat .pids/mqtt.pid)
    if ps -p $MQTT_PID > /dev/null; then
        echo "🔌 Stopping MQTT Service (PID: $MQTT_PID)..."
        kill $MQTT_PID
        rm .pids/mqtt.pid
    else
        echo "🔌 MQTT Service is not running"
        rm -f .pids/mqtt.pid
    fi
else
    echo "🔌 MQTT Service PID file not found"
fi

# Clean up any remaining processes
echo "🧹 Cleaning up any remaining processes..."
pkill -f "php -S localhost:8000"
pkill -f "WebSocketServer.php"
pkill -f "MqttService.php"

# Remove .pids directory if empty
rmdir .pids 2>/dev/null

echo "✅ All services stopped successfully!"

# Show running PHP processes for verification
REMAINING=$(pgrep -f php | wc -l)
if [ $REMAINING -gt 0 ]; then
    echo "⚠️  Warning: $REMAINING PHP processes still running"
    echo "   Run 'ps aux | grep php' to check"
else
    echo "✅ No PHP processes running"
fi
