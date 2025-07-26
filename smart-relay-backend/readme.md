# Smart Relay System - Backend API

Smart Relay System adalah platform manajemen IoT yang memungkinkan pengguna untuk mengendalikan dan memantau perangkat relay secara remote melalui MQTT protocol dengan dukungan real-time monitoring, sistem billing, dan notifikasi.

## 🚀 Fitur Utama

- **Authentication & Authorization**: JWT-based authentication dengan role-based access control
- **Device Management**: CRUD operations untuk device dengan kontrol MQTT real-time
- **Real-time Monitoring**: WebSocket untuk monitoring device status secara real-time
- **Billing System**: Integrasi dengan Stripe untuk subscription management
- **MQTT Integration**: Koneksi dengan MQTT broker untuk device communication
- **Notification System**: Email notifications untuk alerts dan system events
- **Logging & Analytics**: Comprehensive logging untuk API calls, user activities, dan device events
- **Admin Panel**: Dashboard admin untuk user dan system management

## 📋 Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- Composer
- MQTT Broker (Mosquitto recommended)
- Stripe Account (untuk payment processing)
- SMTP Server (untuk email notifications)

## 🛠️ Installation

### 1. Clone Repository & Install Dependencies

```bash
git clone <repository-url>
cd smart-relay-backend
composer install
```

### 2. Environment Configuration

```bash
cp .env.example .env
```

Edit file `.env` dengan konfigurasi yang sesuai:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_NAME=smart_relay_db
DB_USER=your_db_user
DB_PASS=your_db_password

# JWT Configuration
JWT_SECRET=your-super-secret-jwt-key
JWT_EXPIRE=3600

# MQTT Configuration
MQTT_HOST=your-mqtt-broker-host
MQTT_PORT=1883
MQTT_USERNAME=your-mqtt-username
MQTT_PASSWORD=your-mqtt-password

# Stripe Configuration
STRIPE_SECRET_KEY=sk_test_your_stripe_secret_key
STRIPE_PUBLIC_KEY=pk_test_your_stripe_public_key

# Email Configuration
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
```

### 3. Database Setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE smart_relay_db;"

# Import database schema
mysql -u root -p smart_relay_db < database.sql
```

### 4. Start Services

#### Start PHP Development Server
```bash
composer start
# atau
php -S localhost:8000 -t public
```

#### Start MQTT Service (Terminal terpisah)
```bash
composer mqtt
# atau
php src/Services/MqttService.php
```

#### Start WebSocket Server (Terminal terpisah)
```bash
composer websocket
# atau
php src/Services/WebSocketServer.php
```

## 📚 API Documentation

### Base URL
```
http://localhost:8000/api/v1
```

### Authentication

Semua endpoint yang memerlukan autentikasi harus menyertakan header:
```
Authorization: Bearer <jwt_token>
```

### User Endpoints

#### Register User
```http
POST /auth/register
Content-Type: application/json

{
    "username": "johndoe",
    "email": "john@example.com",
    "password": "password123"
}
```

#### Login
```http
POST /auth/login
Content-Type: application/json

{
    "username": "johndoe",
    "password": "password123"
}
```

#### Get Profile
```http
GET /auth/profile
Authorization: Bearer <token>
```

### Device Endpoints

#### Get Devices
```http
GET /devices
Authorization: Bearer <token>
```

#### Create Device
```http
POST /devices
Authorization: Bearer <token>
Content-Type: application/json

{
    "device_id": "relay001",
    "name": "Living Room Relay",
    "location": "Living Room",
    "device_type": "relay"
}
```

#### Control Device
```http
POST /devices/{id}/control
Authorization: Bearer <token>
Content-Type: application/json

{
    "action": "turn_on",
    "params": {
        "relay_channel": 1
    }
}
```

#### Get Device Status
```http
GET /devices/{id}/status
Authorization: Bearer <token>
```

#### Get Device Logs
```http
GET /devices/{id}/logs
Authorization: Bearer <token>
```

### Billing Endpoints

#### Get Subscription Plans
```http
GET /billing/plans
```

#### Get User Subscription
```http
GET /billing/subscription
Authorization: Bearer <token>
```

#### Create Subscription
```http
POST /billing/subscription
Authorization: Bearer <token>
Content-Type: application/json

{
    "plan_id": 1,
    "payment_method_id": "pm_stripe_payment_method_id"
}
```

### Admin Endpoints

#### Get Dashboard Stats
```http
GET /admin/dashboard
Authorization: Bearer <admin_token>
```

#### Get All Users
```http
GET /admin/users?page=1&limit=25&search=john
Authorization: Bearer <admin_token>
```

#### Update User
```http
PUT /admin/users/{id}
Authorization: Bearer <admin_token>
Content-Type: application/json

{
    "role": "user",
    "status": "active"
}
```

## 🔌 WebSocket API

### Connection
```javascript
const ws = new WebSocket('ws://localhost:8080');
```

### Authentication
```javascript
ws.send(JSON.stringify({
    type: 'auth',
    token: 'your_jwt_token'
}));
```

### Subscribe to Device Updates
```javascript
ws.send(JSON.stringify({
    type: 'subscribe_device',
    device_id: 123
}));
```

### Device Control via WebSocket
```javascript
ws.send(JSON.stringify({
    type: 'device_control',
    device_id: 123,
    action: 'turn_on',
    params: { relay_channel: 1 }
}));
```

## 🔧 MQTT Topics

### Device Topics Structure
```
smartrelay/{device_id}/command     # Commands to device
smartrelay/{device_id}/status      # Device status updates
smartrelay/{device_id}/sensor      # Sensor data from device
smartrelay/{device_id}/error       # Error messages from device
smartrelay/{device_id}/heartbeat   # Device heartbeat/ping
```

### Message Formats

#### Command Message
```json
{
    "action": "turn_on",
    "relay_channel": 1,
    "timestamp": "2024-01-15T10:30:00Z"
}
```

#### Status Message
```json
{
    "status": "online",
    "relay_states": [true, false, true, false],
    "timestamp": "2024-01-15T10:30:00Z"
}
```

#### Sensor Data Message
```json
{
    "temperature": 25.5,
    "humidity": 60.2,
    "voltage": 12.1,
    "current": 0.5,
    "timestamp": "2024-01-15T10:30:00Z"
}
```

## 🛡️ Security

### Rate Limiting
- API: 100 requests per minute per user
- WebSocket: 50 messages per minute per connection

### Data Validation
- Semua input divalidasi dan di-sanitize
- SQL injection protection dengan prepared statements
- XSS protection dengan output encoding

### Authentication
- JWT tokens dengan expiration time
- Password hashing menggunakan bcrypt
- Role-based access control (admin, user, viewer)

## 📊 Monitoring & Logging

### Log Files
```
logs/app.log          # Application logs
logs/mqtt.log         # MQTT communication logs
logs/websocket.log    # WebSocket connection logs
```

### Database Logs
- `api_logs`: API request/response logs
- `user_activity_logs`: User activity tracking
- `device_logs`: Device events dan status changes
- `billing_logs`: Payment dan subscription events

## 🔄 Background Services

### MQTT Service
Menangani komunikasi dengan MQTT broker dan device management.

### WebSocket Server
Real-time communication dengan frontend applications.

### Notification Queue
Background processing untuk email notifications.

## 🧪 Testing

```bash
# Install dev dependencies
composer install --dev

# Run tests
./vendor/bin/phpunit
```

## 📈 Performance Optimization

### Database
- Proper indexing pada frequently queried columns
- Connection pooling untuk high-traffic scenarios
- Regular cleanup of old logs

### Caching
- JWT token caching
- Device status caching
- API response caching untuk static data

## 🚀 Deployment

### Production Configuration
1. Update `.env` dengan production values
2. Set `APP_DEBUG=0`
3. Configure web server (Apache/Nginx)
4. Set up SSL certificates
5. Configure firewall rules
6. Set up monitoring dan alerting

### Docker Deployment
```bash
docker build -t smart-relay-backend .
docker run -d --name smart-relay-api \
  -p 8000:8000 \
  -p 8080:8080 \
  --env-file .env \
  smart-relay-backend
```

## 🤝 Contributing

1. Fork repository
2. Create feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

## 📄 License

MIT License - see LICENSE file for details.

## 📞 Support

Untuk pertanyaan atau dukungan teknis, silakan hubungi tim development atau buat issue di repository ini.

---

## 🔧 Troubleshooting

### Common Issues

#### Database Connection Error
```bash
# Check MySQL service
sudo systemctl status mysql

# Check database credentials in .env
cat .env | grep DB_
```

#### MQTT Connection Failed
```bash
# Test MQTT broker connectivity
mosquitto_pub -h your-mqtt-host -p 1883 -t test -m "hello"
```

#### WebSocket Connection Issues
```bash
# Check if WebSocket server is running
netstat -an | grep 8080

# Check firewall settings
sudo ufw status
```

#### Stripe Integration Issues
- Verify Stripe keys in `.env`
- Check webhook endpoint configuration
- Review Stripe logs in dashboard

### Performance Issues
- Monitor database slow query log
- Check MQTT broker performance
- Review WebSocket connection limits
- Analyze API response times

### Email Delivery Issues
- Verify SMTP credentials
- Check spam/junk folders
- Review email server logs
- Test with different email providers
