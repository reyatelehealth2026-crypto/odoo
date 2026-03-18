/**
 * Enhanced WebSocket Server for Odoo Dashboard Real-time Updates
 * 
 * Extends the existing websocket-server.js to support dashboard-specific
 * real-time updates with JWT authentication and Redis scaling.
 * 
 * Requirements: FR-1.4, TC-1.4, BR-3.3
 */

const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const mysql = require('mysql2/promise');
const redis = require('redis');
const jwt = require('jsonwebtoken');
require('dotenv').config();

const app = express();
const server = http.createServer(app);

// Socket.IO server with CORS configuration
const io = socketIO(server, {
    cors: {
        origin: process.env.ALLOWED_ORIGINS?.split(',') || ['http://localhost:3000'],
        credentials: true,
        methods: ['GET', 'POST']
    },
    path: '/dashboard-socket.io/',
    transports: ['websocket', 'polling'],
    pingTimeout: 60000,
    pingInterval: 25000
});

// Redis clients for pub/sub and adapter
const redisClient = redis.createClient({
    host: process.env.REDIS_HOST || 'localhost',
    port: parseInt(process.env.REDIS_PORT || '6379'),
    password: process.env.REDIS_PASSWORD || undefined,
    retry_strategy: (options) => {
        if (options.error && options.error.code === 'ECONNREFUSED') {
            console.error('Redis connection refused');
            return new Error('Redis server connection refused');
        }
        if (options.total_retry_time > 1000 * 60 * 60) {
            return new Error('Redis retry time exhausted');
        }
        if (options.attempt > 10) {
            return undefined;
        }
        return Math.min(options.attempt * 100, 3000);
    }
});

const redisSubscriber = redisClient.duplicate();
const redisAdapter = require('@socket.io/redis-adapter');

// MySQL connection pool
const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'telepharmacy',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    enableKeepAlive: true,
    keepAliveInitialDelay: 0,
    timezone: '+07:00'
});

// Store active dashboard connections by LINE account ID
const dashboardConnections = new Map();

// Dashboard update interval (30 seconds)
let dashboardUpdateInterval = null;

/**
 * Authenticate socket connection using JWT token
 */
async function authenticateToken(token) {
    if (!token) {
        console.log('No token provided');
        return null;
    }

    try {
        // For JWT tokens
        if (token.startsWith('eyJ')) {
            const payload = jwt.verify(token, process.env.JWT_SECRET);
            
            // Check if user is still active
            const [rows] = await pool.query(
                `SELECT id, username, line_account_id, role 
                 FROM admin_users 
                 WHERE id = ? AND is_active = 1`,
                [payload.userId]
            );

            if (rows.length === 0) {
                console.log('User not found or inactive');
                return null;
            }

            return {
                id: rows[0].id,
                username: rows[0].username,
                line_account_id: rows[0].line_account_id,
                role: rows[0].role
            };
        }
        
        // For session tokens (fallback to existing auth)
        const [rows] = await pool.query(
            `SELECT id, username, line_account_id, role 
             FROM admin_users 
             WHERE session_token = ? 
             AND session_expires > NOW()`,
            [token]
        );

        if (rows.length === 0) {
            console.log('Invalid or expired session token');
            return null;
        }

        return rows[0];
    } catch (error) {
        console.error('Authentication error:', error);
        return null;
    }
}

/**
 * Get dashboard metrics for a LINE account
 */
async function getDashboardMetrics(lineAccountId) {
    try {
        const today = new Date().toISOString().split('T')[0];
        
        // Get order metrics from cache tables
        const [orderMetrics] = await pool.query(`
            SELECT 
                COUNT(*) as today_count,
                COALESCE(SUM(amount_total), 0) as today_total,
                COUNT(CASE WHEN state IN ('draft', 'sent') THEN 1 END) as pending_count,
                COUNT(CASE WHEN state = 'sale' THEN 1 END) as completed_count
            FROM odoo_orders 
            WHERE line_account_id = ? 
            AND DATE(date_order) = ?
        `, [lineAccountId, today]);

        // Get payment metrics
        const [paymentMetrics] = await pool.query(`
            SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_slips,
                COUNT(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN 1 END) as processed_today,
                COALESCE(SUM(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN amount END), 0) as total_amount
            FROM odoo_slip_uploads 
            WHERE line_account_id = ?
        `, [today, today, lineAccountId]);

        // Get webhook metrics
        const [webhookMetrics] = await pool.query(`
            SELECT 
                COUNT(*) as today_count,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                AVG(CASE WHEN response_time IS NOT NULL THEN response_time END) as avg_response_time
            FROM odoo_webhooks_log 
            WHERE line_account_id = ? 
            AND DATE(created_at) = ?
        `, [lineAccountId, today]);

        // Get customer metrics
        const [customerMetrics] = await pool.query(`
            SELECT 
                COUNT(DISTINCT partner_id) as total_active,
                COUNT(DISTINCT CASE WHEN DATE(create_date) = ? THEN partner_id END) as new_today,
                COUNT(DISTINCT CASE WHEN line_user_id IS NOT NULL THEN partner_id END) as line_connected
            FROM odoo_orders 
            WHERE line_account_id = ?
        `, [today, lineAccountId]);

        const orders = orderMetrics[0] || {};
        const payments = paymentMetrics[0] || {};
        const webhooks = webhookMetrics[0] || {};
        const customers = customerMetrics[0] || {};

        return {
            orders: {
                todayCount: parseInt(orders.today_count) || 0,
                todayTotal: parseFloat(orders.today_total) || 0,
                pendingCount: parseInt(orders.pending_count) || 0,
                completedCount: parseInt(orders.completed_count) || 0,
                averageOrderValue: orders.today_count > 0 ? (orders.today_total / orders.today_count) : 0
            },
            payments: {
                pendingSlips: parseInt(payments.pending_slips) || 0,
                processedToday: parseInt(payments.processed_today) || 0,
                matchingRate: 95, // This would be calculated based on actual matching logic
                totalAmount: parseFloat(payments.total_amount) || 0,
                averageProcessingTime: 15 // This would be calculated from actual processing times
            },
            webhooks: {
                todayCount: parseInt(webhooks.today_count) || 0,
                successRate: webhooks.today_count > 0 ? 
                    Math.round((webhooks.success_count / webhooks.today_count) * 100) : 100,
                failedCount: parseInt(webhooks.failed_count) || 0,
                averageResponseTime: Math.round(webhooks.avg_response_time) || 0
            },
            customers: {
                totalActive: parseInt(customers.total_active) || 0,
                newToday: parseInt(customers.new_today) || 0,
                lineConnected: parseInt(customers.line_connected) || 0,
                averageOrdersPerCustomer: customers.total_active > 0 ? 
                    Math.round(orders.today_count / customers.total_active * 10) / 10 : 0
            },
            updatedAt: new Date().toISOString()
        };
    } catch (error) {
        console.error('Error fetching dashboard metrics:', error);
        return null;
    }
}

// Set up Redis adapter for Socket.IO
redisClient.on('connect', () => {
    console.log('Redis client connected');
    io.adapter(redisAdapter.createAdapter(redisClient, redisSubscriber));
});

redisClient.on('error', (err) => {
    console.error('Redis client error:', err);
});

// Subscribe to dashboard update events
redisSubscriber.subscribe('dashboard_updates', (err) => {
    if (err) {
        console.error('Failed to subscribe to dashboard_updates:', err);
    } else {
        console.log('Subscribed to dashboard_updates channel');
    }
});

redisSubscriber.on('message', (channel, message) => {
    if (channel === 'dashboard_updates') {
        try {
            const data = JSON.parse(message);
            const { line_account_id, type, payload } = data;

            // Broadcast to dashboard clients for this LINE account
            const room = `dashboard_${line_account_id}`;
            
            io.to(room).emit(type, {
                ...payload,
                timestamp: Date.now()
            });

            console.log(`Broadcasted ${type} to dashboard room ${room}`);
        } catch (error) {
            console.error('Error processing dashboard update:', error);
        }
    }
});

// Socket.IO connection handler for dashboard
io.on('connection', async (socket) => {
    console.log('Dashboard client attempting connection:', socket.id);

    // Authenticate socket
    const token = socket.handshake.auth.token || socket.handshake.headers.authorization?.replace('Bearer ', '');
    const user = await authenticateToken(token);

    if (!user) {
        console.log('Dashboard authentication failed for socket:', socket.id);
        socket.emit('error', { message: 'Authentication failed' });
        socket.disconnect();
        return;
    }

    // Store user info on socket
    socket.userId = user.id;
    socket.username = user.username;
    socket.lineAccountId = user.line_account_id;
    socket.role = user.role;

    console.log(`Dashboard user ${user.username} (${user.id}) connected from account ${user.line_account_id}`);

    // Join dashboard room for this LINE account
    const dashboardRoom = `dashboard_${user.line_account_id}`;
    socket.join(dashboardRoom);

    // Track dashboard connection
    if (!dashboardConnections.has(user.line_account_id)) {
        dashboardConnections.set(user.line_account_id, new Set());
    }
    dashboardConnections.get(user.line_account_id).add(socket.id);

    console.log(`Dashboard socket ${socket.id} joined room ${dashboardRoom}`);

    // Send connection confirmation with initial data
    const initialMetrics = await getDashboardMetrics(user.line_account_id);
    socket.emit('connected', {
        userId: user.id,
        username: user.username,
        lineAccountId: user.line_account_id,
        role: user.role,
        timestamp: Date.now(),
        initialData: initialMetrics
    });

    // Handle dashboard subscription
    socket.on('subscribe_dashboard', (data) => {
        const { metrics = ['all'] } = data || {};
        
        // Join specific metric rooms if needed
        metrics.forEach(metric => {
            if (metric === 'all' || ['orders', 'payments', 'webhooks', 'customers'].includes(metric)) {
                socket.join(`${user.line_account_id}_${metric}`);
            }
        });

        socket.emit('subscription_confirmed', {
            metrics,
            timestamp: Date.now()
        });

        console.log(`Dashboard subscription confirmed for ${user.username}:`, metrics);
    });

    // Handle dashboard data request
    socket.on('request_dashboard_data', async (data) => {
        try {
            const metrics = await getDashboardMetrics(user.line_account_id);
            
            socket.emit('dashboard_data', {
                metrics,
                timestamp: Date.now()
            });
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
            socket.emit('error', {
                message: 'Failed to fetch dashboard data',
                code: 'DASHBOARD_DATA_ERROR'
            });
        }
    });

    // Handle heartbeat/ping
    socket.on('ping', () => {
        socket.emit('pong', { timestamp: Date.now() });
    });

    // Handle disconnection
    socket.on('disconnect', (reason) => {
        console.log(`Dashboard client disconnected: ${socket.id}, reason: ${reason}`);

        // Remove from dashboard connections tracking
        if (dashboardConnections.has(socket.lineAccountId)) {
            dashboardConnections.get(socket.lineAccountId).delete(socket.id);
            
            if (dashboardConnections.get(socket.lineAccountId).size === 0) {
                dashboardConnections.delete(socket.lineAccountId);
            }
        }
    });

    // Handle errors
    socket.on('error', (error) => {
        console.error('Dashboard socket error:', error);
    });
});

/**
 * Broadcast dashboard update to all connected clients
 */
async function broadcastDashboardUpdate(lineAccountId, type, data) {
    try {
        const updateMessage = JSON.stringify({
            line_account_id: lineAccountId,
            type: type,
            payload: data,
            timestamp: Date.now()
        });

        // Publish to Redis for multi-instance scaling
        await redisClient.publish('dashboard_updates', updateMessage);
        
        console.log(`Dashboard update published: ${type} for account ${lineAccountId}`);
    } catch (error) {
        console.error('Failed to broadcast dashboard update:', error);
    }
}

/**
 * Start periodic dashboard updates (every 30 seconds)
 */
function startPeriodicDashboardUpdates() {
    dashboardUpdateInterval = setInterval(async () => {
        try {
            // Get all active LINE accounts with dashboard connections
            const activeAccounts = Array.from(dashboardConnections.keys());
            
            for (const lineAccountId of activeAccounts) {
                const metrics = await getDashboardMetrics(lineAccountId);
                
                if (metrics) {
                    await broadcastDashboardUpdate(lineAccountId, 'metrics_updated', metrics);
                }
            }

            console.log(`Periodic dashboard updates sent to ${activeAccounts.length} accounts`);
        } catch (error) {
            console.error('Error in periodic dashboard updates:', error);
        }
    }, 30000); // Every 30 seconds

    console.log('Started periodic dashboard updates (every 30 seconds)');
}

// Health check endpoint
app.get('/health', (req, res) => {
    const health = {
        status: 'ok',
        timestamp: Date.now(),
        uptime: process.uptime(),
        connections: {
            total: io.engine.clientsCount,
            dashboard: Array.from(dashboardConnections.values())
                .reduce((sum, sockets) => sum + sockets.size, 0),
            byAccount: Array.from(dashboardConnections.entries()).map(([accountId, sockets]) => ({
                accountId,
                count: sockets.size
            }))
        },
        redis: redisClient.connected ? 'connected' : 'disconnected',
        database: pool ? 'connected' : 'disconnected'
    };

    res.json(health);
});

// Status endpoint
app.get('/status', (req, res) => {
    res.json({
        status: 'running',
        version: '1.0.0',
        timestamp: Date.now(),
        clients: io.engine.clientsCount,
        dashboardClients: Array.from(dashboardConnections.values())
            .reduce((sum, sockets) => sum + sockets.size, 0),
        rooms: io.sockets.adapter.rooms.size
    });
});

// Start server
const PORT = process.env.DASHBOARD_WEBSOCKET_PORT || 3001;
const HOST = process.env.WEBSOCKET_HOST || '0.0.0.0';

server.listen(PORT, HOST, () => {
    console.log(`Dashboard WebSocket server running on ${HOST}:${PORT}`);
    console.log(`Environment: ${process.env.NODE_ENV || 'development'}`);
    console.log(`Allowed origins: ${process.env.ALLOWED_ORIGINS || 'http://localhost:3000'}`);
    
    // Start periodic updates
    startPeriodicDashboardUpdates();
});

// Graceful shutdown handling
let isShuttingDown = false;

async function gracefulShutdown(signal) {
    if (isShuttingDown) {
        console.log('Shutdown already in progress...');
        return;
    }

    isShuttingDown = true;
    console.log(`\n${signal} received, starting graceful shutdown...`);

    // Clear periodic update interval
    if (dashboardUpdateInterval) {
        clearInterval(dashboardUpdateInterval);
        console.log('Stopped periodic dashboard updates');
    }

    // Stop accepting new connections
    server.close(() => {
        console.log('HTTP server closed');
    });

    // Notify all connected clients
    io.emit('server_shutdown', {
        message: 'Dashboard server is shutting down for maintenance',
        timestamp: Date.now()
    });

    // Give clients time to receive the message
    await new Promise(resolve => setTimeout(resolve, 1000));

    // Close all socket connections
    const sockets = await io.fetchSockets();
    console.log(`Closing ${sockets.length} dashboard socket connections...`);
    
    for (const socket of sockets) {
        socket.disconnect(true);
    }

    // Close Socket.IO
    io.close(() => {
        console.log('Dashboard Socket.IO server closed');
    });

    // Close database pool
    try {
        await pool.end();
        console.log('Database pool closed');
    } catch (error) {
        console.error('Error closing database pool:', error);
    }

    // Close Redis connections
    try {
        await redisClient.quit();
        await redisSubscriber.quit();
        console.log('Redis connections closed');
    } catch (error) {
        console.error('Error closing Redis connections:', error);
    }

    console.log('Dashboard WebSocket server shutdown complete');
    process.exit(0);
}

// Handle shutdown signals
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));

// Handle uncaught errors
process.on('uncaughtException', (error) => {
    console.error('Uncaught exception:', error);
    gracefulShutdown('UNCAUGHT_EXCEPTION');
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled rejection at:', promise, 'reason:', reason);
});

// Export functions for external use
module.exports = {
    broadcastDashboardUpdate,
    getDashboardMetrics
};

// Log startup info
console.log('='.repeat(60));
console.log('Dashboard WebSocket Server for Odoo Dashboard Modernization');
console.log('='.repeat(60));
console.log(`Node version: ${process.version}`);
console.log(`Platform: ${process.platform}`);
console.log(`Memory: ${Math.round(process.memoryUsage().heapUsed / 1024 / 1024)}MB`);
console.log('='.repeat(60));