# Dashboard Real-time Updates System Implementation

## Overview

This document describes the implementation of Task 6: Real-time Updates System for the Odoo Dashboard modernization. The system provides real-time dashboard updates every 30 seconds with WebSocket authentication, Redis scaling, and seamless integration with the existing PHP-based LINE Telepharmacy Platform.

## Architecture

### Components

1. **Dashboard WebSocket Server** (`websocket-dashboard-server.js`)
   - Node.js + Socket.IO server for real-time communication
   - JWT authentication for secure connections
   - Redis adapter for multi-instance scaling
   - Automatic dashboard updates every 30 seconds

2. **PHP Integration** (`classes/DashboardWebSocketNotifier.php`)
   - PHP class for broadcasting updates from existing codebase
   - Redis pub/sub integration
   - Support for metrics, orders, payments, and webhook updates

3. **Frontend Hooks** (`frontend/src/hooks/`)
   - React hooks for WebSocket connection management
   - Optimistic updates with React Query integration
   - Connection status monitoring and error handling

4. **Cron Job** (`cron/dashboard_realtime_updates.php`)
   - Periodic dashboard metrics updates from PHP side
   - Complements WebSocket server's built-in updates

## Requirements Fulfilled

- **FR-1.4**: Real-time dashboard updates every 30 seconds ✅
- **TC-1.4**: WebSocket authentication using JWT tokens ✅
- **BR-3.3**: Redis adapter for scaling across multiple instances ✅
- **Client reconnection and error handling** ✅
- **Integration with React Query for optimistic updates** ✅

## Installation & Setup

### 1. Install Dependencies

```bash
# Install dashboard WebSocket server dependencies
npm install express@^4.18.2 socket.io@^4.6.1 @socket.io/redis-adapter@^8.2.1 mysql2@^3.6.5 redis@^4.6.5 jsonwebtoken@^9.0.2 dotenv@^16.3.1

# Or use the deployment script
chmod +x deploy-dashboard-websocket.sh
./deploy-dashboard-websocket.sh
```

### 2. Environment Configuration

Create or update your `.env` file:

```env
# Database Configuration
DB_HOST=localhost
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=telepharmacy

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password

# WebSocket Configuration
DASHBOARD_WEBSOCKET_PORT=3001
WEBSOCKET_HOST=0.0.0.0
ALLOWED_ORIGINS=http://localhost:3000,https://yourdomain.com

# JWT Configuration
JWT_SECRET=your_jwt_secret

# Node.js Environment
NODE_ENV=production
```

### 3. Start Services

```bash
# Start dashboard WebSocket server
node websocket-dashboard-server.js

# Or with PM2 for production
pm2 start websocket-dashboard-server.js --name dashboard-websocket

# Start existing WebSocket server (if not already running)
node websocket-server.js
```

### 4. Configure Cron Job

Add to your crontab for periodic updates:

```bash
# Dashboard real-time updates every 30 seconds
* * * * * cd /path/to/your/project && php cron/dashboard_realtime_updates.php
* * * * * cd /path/to/your/project && sleep 30 && php cron/dashboard_realtime_updates.php
```

## Usage

### Frontend Integration

```typescript
// In your React component
import { useDashboardRealtime } from '@/hooks/useDashboardRealtime';

function DashboardPage() {
  const authToken = 'your-jwt-token';
  
  const realtimeStatus = useDashboardRealtime(authToken, true);
  
  return (
    <div>
      <ConnectionStatus
        connected={realtimeStatus.connected}
        connecting={realtimeStatus.connecting}
        error={realtimeStatus.error}
        reconnectAttempt={realtimeStatus.reconnectAttempt}
        lastUpdate={realtimeStatus.lastUpdate}
      />
      
      <DashboardOverview
        realTimeEnabled={true}
        authToken={authToken}
        // ... other props
      />
    </div>
  );
}
```

### PHP Integration

```php
// In your existing PHP code
require_once 'classes/DashboardWebSocketNotifier.php';

$notifier = new DashboardWebSocketNotifier($lineAccountId);

// Broadcast metrics update
$metrics = [
  'orders' => ['todayCount' => 25, 'todayTotal' => 15750],
  'payments' => ['pendingSlips' => 3, 'processedToday' => 22],
  // ... more metrics
];
$notifier->broadcastMetricsUpdate($metrics);

// Broadcast order status change
$notifier->broadcastOrderStatusChange(
  'ORDER123', 
  'draft', 
  'sale', 
  'admin_user'
);

// Broadcast payment processed
$notifier->broadcastPaymentProcessed(
  'PAY123', 
  'ORDER123', 
  1500, 
  'matched', 
  'admin_user'
);
```

## API Endpoints

### WebSocket Server Endpoints

- `GET /health` - Health check with connection statistics
- `GET /status` - Server status and metrics

### Test API Endpoints

- `GET /api/dashboard-realtime-demo.php?action=test` - Test WebSocket connection
- `GET /api/dashboard-realtime-demo.php?action=trigger_metrics_update` - Trigger metrics update
- `GET /api/dashboard-realtime-demo.php?action=trigger_order_update` - Trigger order status change
- `GET /api/dashboard-realtime-demo.php?action=trigger_payment_update` - Trigger payment update
- `GET /api/dashboard-realtime-demo.php?action=trigger_webhook_update` - Trigger webhook update

## WebSocket Events

### Client → Server

- `subscribe_dashboard` - Subscribe to dashboard updates
- `request_dashboard_data` - Request current dashboard data
- `ping` - Heartbeat ping

### Server → Client

- `connected` - Connection confirmation with initial data
- `metrics_updated` - Dashboard metrics update
- `order_status_changed` - Order status change notification
- `payment_processed` - Payment processing notification
- `webhook_received` - Webhook received notification
- `heartbeat` - Server heartbeat
- `server_shutdown` - Server shutdown notification

## Testing

### 1. Test WebSocket Connection

```bash
# Check server health
curl http://localhost:3001/health

# Check server status
curl http://localhost:3001/status
```

### 2. Test Real-time Updates

Visit the test page: `http://localhost:3000/dashboard/realtime-test`

Or use the API endpoints:

```bash
# Test metrics update
curl "http://localhost/api/dashboard-realtime-demo.php?action=trigger_metrics_update"

# Test order status change
curl "http://localhost/api/dashboard-realtime-demo.php?action=trigger_order_update&order_id=TEST123"
```

### 3. Monitor Logs

```bash
# PM2 logs
pm2 logs dashboard-websocket

# Or direct logs
tail -f websocket-dashboard.log
```

## Performance Considerations

### Scaling

- **Redis Adapter**: Enables horizontal scaling across multiple server instances
- **Connection Pooling**: MySQL connection pool with 10 connections max
- **Memory Management**: PM2 auto-restart at 500MB memory usage

### Optimization

- **Periodic Updates**: 30-second intervals balance real-time feel with performance
- **Selective Broadcasting**: Only broadcast to accounts with active connections
- **Efficient Queries**: Use cache tables (`odoo_orders`, `odoo_slip_uploads`, etc.)
- **Connection Cleanup**: Automatic cleanup of stale connections

## Security

### Authentication

- **JWT Tokens**: Secure WebSocket authentication
- **Session Validation**: Fallback to session token authentication
- **User Verification**: Active user status checking

### Data Protection

- **CORS Configuration**: Restricted origins for WebSocket connections
- **Input Validation**: All WebSocket events validated
- **Error Handling**: Secure error messages without sensitive data exposure

## Monitoring & Maintenance

### Health Checks

```bash
# WebSocket server health
curl http://localhost:3001/health

# Connection statistics
curl http://localhost:3001/status
```

### Log Monitoring

- **Application Logs**: PM2 logs with timestamps
- **Database Logs**: `dev_logs` table for cron job execution
- **Error Tracking**: Comprehensive error logging with context

### Maintenance Commands

```bash
# Restart WebSocket server
pm2 restart dashboard-websocket

# View real-time logs
pm2 logs dashboard-websocket --lines 100

# Monitor server resources
pm2 monit

# Update and restart
git pull && pm2 restart dashboard-websocket
```

## Troubleshooting

### Common Issues

1. **Connection Failed**
   - Check Redis server status
   - Verify database connection
   - Check JWT secret configuration

2. **No Real-time Updates**
   - Verify cron job is running
   - Check Redis pub/sub channels
   - Ensure WebSocket server is receiving data

3. **High Memory Usage**
   - Monitor connection count
   - Check for memory leaks in event handlers
   - Adjust PM2 memory restart threshold

### Debug Commands

```bash
# Test Redis connection
redis-cli ping

# Check MySQL connection
mysql -h localhost -u user -p database -e "SELECT 1"

# Test WebSocket manually
wscat -c ws://localhost:3001/dashboard-socket.io/

# Check PM2 process status
pm2 status
pm2 show dashboard-websocket
```

## Integration with Existing System

This real-time updates system is designed to work alongside the existing PHP-based LINE Telepharmacy Platform:

- **Non-intrusive**: Runs on separate port (3001) from main WebSocket server (3000)
- **Optional**: Can be disabled without affecting core functionality
- **Compatible**: Uses existing database tables and authentication system
- **Scalable**: Redis adapter allows multiple instances

The system enhances the existing dashboard without requiring changes to core platform functionality.

## Future Enhancements

- **Push Notifications**: Browser push notifications for critical updates
- **Custom Alerts**: User-configurable alert thresholds
- **Historical Data**: Real-time charts with historical data overlay
- **Mobile Support**: React Native integration for mobile dashboard
- **Advanced Filtering**: Real-time filtering and search capabilities