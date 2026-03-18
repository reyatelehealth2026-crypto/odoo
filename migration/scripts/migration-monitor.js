#!/usr/bin/env node

/**
 * Migration Monitoring Dashboard
 * Purpose: Real-time monitoring of migration progress and system health
 * Requirements: TC-3.1, TC-3.3
 */

const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mysql = require('mysql2/promise');
const Redis = require('ioredis');
const axios = require('axios');
const fs = require('fs').promises;
const path = require('path');

// Configuration
const config = {
    port: process.env.MONITOR_PORT || 9090,
    mysql: {
        host: process.env.MYSQL_HOST || 'localhost',
        port: process.env.MYSQL_PORT || 3306,
        user: process.env.MYSQL_USER,
        password: process.env.MYSQL_PASSWORD,
        database: process.env.MYSQL_DATABASE
    },
    redis: {
        host: process.env.REDIS_HOST || 'localhost',
        port: process.env.REDIS_PORT || 6379
    },
    services: {
        legacy: process.env.LEGACY_API_URL || 'http://legacy-web:80',
        modern: process.env.MODERN_API_URL || 'http://modern-backend:4000',
        websocket: process.env.WEBSOCKET_URL || 'http://websocket:3001'
    },
    monitoring: {
        interval: 30000, // 30 seconds
        healthCheckTimeout: 5000,
        metricsRetention: 24 * 60 * 60 * 1000 // 24 hours
    }
};

class MigrationMonitor {
    constructor() {
        this.app = express();
        this.server = http.createServer(this.app);
        this.io = socketIo(this.server, {
            cors: {
                origin: "*",
                methods: ["GET", "POST"]
            }
        });
        
        this.db = null;
        this.redis = null;
        this.metrics = new Map();
        this.alerts = [];
        this.isMonitoring = false;
        
        this.setupMiddleware();
        this.setupRoutes();
        this.setupSocketHandlers();
    }

    async initialize() {
        try {
            // Initialize database connection
            this.db = await mysql.createConnection(config.mysql);
            console.log('✅ Database connected');

            // Initialize Redis connection
            this.redis = new Redis(config.redis);
            console.log('✅ Redis connected');

            // Start monitoring
            this.startMonitoring();
            console.log('✅ Monitoring started');

        } catch (error) {
            console.error('❌ Initialization failed:', error);
            process.exit(1);
        }
    }

    setupMiddleware() {
        this.app.use(express.json());
        this.app.use(express.static(path.join(__dirname, '../dashboard')));
        
        // CORS middleware
        this.app.use((req, res, next) => {
            res.header('Access-Control-Allow-Origin', '*');
            res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            res.header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE');
            next();
        });
    }

    setupRoutes() {
        // Health check
        this.app.get('/health', (req, res) => {
            res.json({
                status: 'healthy',
                timestamp: new Date().toISOString(),
                uptime: process.uptime()
            });
        });

        // Current metrics
        this.app.get('/api/metrics', (req, res) => {
            const currentMetrics = this.getCurrentMetrics();
            res.json(currentMetrics);
        });

        // Historical metrics
        this.app.get('/api/metrics/history', async (req, res) => {
            try {
                const hours = parseInt(req.query.hours) || 24;
                const history = await this.getMetricsHistory(hours);
                res.json(history);
            } catch (error) {
                res.status(500).json({ error: error.message });
            }
        });

        // Feature flags status
        this.app.get('/api/feature-flags', async (req, res) => {
            try {
                const flags = await this.getFeatureFlags();
                res.json(flags);
            } catch (error) {
                res.status(500).json({ error: error.message });
            }
        });

        // Migration progress
        this.app.get('/api/migration/progress', async (req, res) => {
            try {
                const progress = await this.getMigrationProgress();
                res.json(progress);
            } catch (error) {
                res.status(500).json({ error: error.message });
            }
        });

        // System alerts
        this.app.get('/api/alerts', (req, res) => {
            res.json({
                alerts: this.alerts,
                count: this.alerts.length
            });
        });

        // Acknowledge alert
        this.app.post('/api/alerts/:id/acknowledge', (req, res) => {
            const alertId = req.params.id;
            const alert = this.alerts.find(a => a.id === alertId);
            
            if (alert) {
                alert.acknowledged = true;
                alert.acknowledgedAt = new Date().toISOString();
                alert.acknowledgedBy = req.body.acknowledgedBy || 'system';
                
                res.json({ success: true });
            } else {
                res.status(404).json({ error: 'Alert not found' });
            }
        });

        // Emergency rollback trigger
        this.app.post('/api/emergency/rollback', async (req, res) => {
            try {
                const reason = req.body.reason || 'Manual emergency rollback';
                await this.triggerEmergencyRollback(reason);
                res.json({ success: true, message: 'Emergency rollback initiated' });
            } catch (error) {
                res.status(500).json({ error: error.message });
            }
        });

        // Dashboard
        this.app.get('/', (req, res) => {
            res.sendFile(path.join(__dirname, '../dashboard/index.html'));
        });
    }

    setupSocketHandlers() {
        this.io.on('connection', (socket) => {
            console.log('Client connected:', socket.id);

            // Send current metrics immediately
            socket.emit('metrics', this.getCurrentMetrics());
            socket.emit('alerts', this.alerts);

            socket.on('disconnect', () => {
                console.log('Client disconnected:', socket.id);
            });

            socket.on('subscribe', (channel) => {
                socket.join(channel);
                console.log(`Client ${socket.id} subscribed to ${channel}`);
            });
        });
    }

    startMonitoring() {
        if (this.isMonitoring) return;
        
        this.isMonitoring = true;
        this.monitoringInterval = setInterval(async () => {
            try {
                await this.collectMetrics();
                await this.checkAlerts();
                this.broadcastMetrics();
            } catch (error) {
                console.error('Monitoring error:', error);
            }
        }, config.monitoring.interval);

        console.log(`🔍 Monitoring started (interval: ${config.monitoring.interval}ms)`);
    }

    stopMonitoring() {
        if (this.monitoringInterval) {
            clearInterval(this.monitoringInterval);
            this.isMonitoring = false;
            console.log('🛑 Monitoring stopped');
        }
    }

    async collectMetrics() {
        const timestamp = new Date().toISOString();
        const metrics = {
            timestamp,
            system: await this.getSystemMetrics(),
            services: await this.getServiceMetrics(),
            database: await this.getDatabaseMetrics(),
            featureFlags: await this.getFeatureFlags(),
            routing: await this.getRoutingMetrics()
        };

        // Store in memory (with retention)
        this.metrics.set(timestamp, metrics);
        this.cleanupOldMetrics();

        // Store in Redis for persistence
        await this.redis.setex(
            `migration_metrics:${timestamp}`,
            config.monitoring.metricsRetention / 1000,
            JSON.stringify(metrics)
        );

        return metrics;
    }

    async getSystemMetrics() {
        try {
            const [loadavg] = await Promise.all([
                this.getSystemLoad()
            ]);

            return {
                loadAverage: loadavg,
                memory: process.memoryUsage(),
                uptime: process.uptime(),
                nodeVersion: process.version
            };
        } catch (error) {
            console.error('System metrics error:', error);
            return { error: error.message };
        }
    }

    async getServiceMetrics() {
        const services = {};
        
        for (const [name, url] of Object.entries(config.services)) {
            try {
                const startTime = Date.now();
                const response = await axios.get(`${url}/health`, {
                    timeout: config.monitoring.healthCheckTimeout
                });
                
                services[name] = {
                    status: 'healthy',
                    responseTime: Date.now() - startTime,
                    statusCode: response.status,
                    lastCheck: new Date().toISOString()
                };
            } catch (error) {
                services[name] = {
                    status: 'unhealthy',
                    error: error.message,
                    lastCheck: new Date().toISOString()
                };
            }
        }

        return services;
    }

    async getDatabaseMetrics() {
        try {
            const [connectionResult] = await this.db.execute('SELECT 1 as connected');
            const [processListResult] = await this.db.execute('SHOW PROCESSLIST');
            const [statusResult] = await this.db.execute("SHOW STATUS LIKE 'Threads_connected'");

            return {
                connected: connectionResult[0].connected === 1,
                activeConnections: processListResult.length,
                threadsConnected: parseInt(statusResult[0].Value),
                lastCheck: new Date().toISOString()
            };
        } catch (error) {
            return {
                connected: false,
                error: error.message,
                lastCheck: new Date().toISOString()
            };
        }
    }

    async getFeatureFlags() {
        try {
            const [rows] = await this.db.execute(`
                SELECT 
                    flag_name,
                    display_name,
                    enabled,
                    rollout_percentage,
                    created_at,
                    updated_at
                FROM feature_flags 
                WHERE enabled = TRUE
                ORDER BY flag_name
            `);

            return rows.map(row => ({
                name: row.flag_name,
                displayName: row.display_name,
                enabled: row.enabled,
                rolloutPercentage: row.rollout_percentage,
                createdAt: row.created_at,
                updatedAt: row.updated_at
            }));
        } catch (error) {
            console.error('Feature flags error:', error);
            return [];
        }
    }

    async getRoutingMetrics() {
        try {
            const today = new Date().toISOString().split('T')[0];
            const metricsKey = `routing_metrics:${today}`;
            const metrics = await this.redis.hgetall(metricsKey);

            const processed = {
                date: today,
                totalRequests: 0,
                newSystemRequests: 0,
                legacySystemRequests: 0,
                routeBreakdown: {}
            };

            for (const [key, value] of Object.entries(metrics)) {
                const count = parseInt(value);
                processed.totalRequests += count;

                const [route, system] = key.split(':');
                
                if (system === 'new') {
                    processed.newSystemRequests += count;
                } else {
                    processed.legacySystemRequests += count;
                }

                if (!processed.routeBreakdown[route]) {
                    processed.routeBreakdown[route] = { new: 0, legacy: 0, total: 0 };
                }
                
                processed.routeBreakdown[route][system] = count;
                processed.routeBreakdown[route].total += count;
            }

            // Calculate percentages
            if (processed.totalRequests > 0) {
                processed.newSystemPercentage = Math.round(
                    (processed.newSystemRequests / processed.totalRequests) * 100
                );
                processed.legacySystemPercentage = Math.round(
                    (processed.legacySystemRequests / processed.totalRequests) * 100
                );
            }

            return processed;
        } catch (error) {
            console.error('Routing metrics error:', error);
            return {};
        }
    }

    async getMigrationProgress() {
        try {
            const [migrationStats] = await this.db.execute(`
                SELECT 
                    migration_type,
                    total_records,
                    successful_records,
                    failed_records,
                    migration_date,
                    notes
                FROM migration_stats 
                ORDER BY migration_date DESC
                LIMIT 10
            `);

            const featureFlags = await this.getFeatureFlags();
            const routing = await this.getRoutingMetrics();

            // Calculate overall progress based on feature flag rollouts
            const totalFlags = featureFlags.length;
            const averageRollout = featureFlags.reduce((sum, flag) => 
                sum + flag.rolloutPercentage, 0) / totalFlags;

            return {
                overallProgress: Math.round(averageRollout),
                migrationStats,
                featureFlags,
                routing,
                phase: this.determineCurrentPhase(averageRollout)
            };
        } catch (error) {
            console.error('Migration progress error:', error);
            return { error: error.message };
        }
    }

    determineCurrentPhase(averageRollout) {
        if (averageRollout === 0) return { number: 1, name: 'Parallel System Monitoring' };
        if (averageRollout <= 25) return { number: 2, name: 'Dashboard Rollout' };
        if (averageRollout <= 40) return { number: 3, name: 'Order Management Enablement' };
        if (averageRollout <= 60) return { number: 4, name: 'Payment Processing Rollout' };
        if (averageRollout <= 80) return { number: 5, name: 'Full Feature Rollout' };
        return { number: 6, name: 'Complete Migration' };
    }

    async checkAlerts() {
        const currentMetrics = this.getCurrentMetrics();
        
        // Check service health
        for (const [serviceName, serviceMetrics] of Object.entries(currentMetrics.services || {})) {
            if (serviceMetrics.status === 'unhealthy') {
                this.createAlert('service_unhealthy', `Service ${serviceName} is unhealthy`, 'high', {
                    service: serviceName,
                    error: serviceMetrics.error
                });
            }
        }

        // Check database connectivity
        if (currentMetrics.database && !currentMetrics.database.connected) {
            this.createAlert('database_disconnected', 'Database connection lost', 'critical', {
                error: currentMetrics.database.error
            });
        }

        // Check error rates
        const routing = currentMetrics.routing || {};
        if (routing.totalRequests > 100) { // Only check if we have significant traffic
            const errorRate = this.calculateErrorRate(routing);
            if (errorRate > 5) { // 5% error rate threshold
                this.createAlert('high_error_rate', `Error rate is ${errorRate}%`, 'high', {
                    errorRate,
                    totalRequests: routing.totalRequests
                });
            }
        }

        // Check feature flag rollout anomalies
        const featureFlags = currentMetrics.featureFlags || [];
        for (const flag of featureFlags) {
            if (flag.rolloutPercentage > 0 && flag.rolloutPercentage < 100) {
                // Check if rollout is stuck (no updates in last hour)
                const lastUpdate = new Date(flag.updatedAt);
                const hourAgo = new Date(Date.now() - 60 * 60 * 1000);
                
                if (lastUpdate < hourAgo) {
                    this.createAlert('rollout_stalled', `Feature flag ${flag.name} rollout may be stalled`, 'medium', {
                        flagName: flag.name,
                        rolloutPercentage: flag.rolloutPercentage,
                        lastUpdate: flag.updatedAt
                    });
                }
            }
        }
    }

    createAlert(type, message, severity, metadata = {}) {
        const alertId = `${type}_${Date.now()}`;
        const alert = {
            id: alertId,
            type,
            message,
            severity,
            metadata,
            timestamp: new Date().toISOString(),
            acknowledged: false
        };

        // Check if similar alert already exists
        const existingAlert = this.alerts.find(a => 
            a.type === type && 
            !a.acknowledged && 
            JSON.stringify(a.metadata) === JSON.stringify(metadata)
        );

        if (!existingAlert) {
            this.alerts.unshift(alert);
            
            // Limit alerts to last 100
            if (this.alerts.length > 100) {
                this.alerts = this.alerts.slice(0, 100);
            }

            // Broadcast alert
            this.io.emit('alert', alert);
            
            console.log(`🚨 Alert created: ${severity.toUpperCase()} - ${message}`);
        }
    }

    calculateErrorRate(routing) {
        // This is a simplified calculation - in reality you'd track actual errors
        const { newSystemRequests, legacySystemRequests, totalRequests } = routing;
        
        // Assume some baseline error rate based on system health
        let errorRate = 0;
        
        // Add to error rate based on unhealthy services
        const currentMetrics = this.getCurrentMetrics();
        const unhealthyServices = Object.values(currentMetrics.services || {})
            .filter(service => service.status === 'unhealthy').length;
        
        errorRate += unhealthyServices * 2; // 2% per unhealthy service
        
        return Math.min(errorRate, 100); // Cap at 100%
    }

    async triggerEmergencyRollback(reason) {
        console.log(`🚨 EMERGENCY ROLLBACK TRIGGERED: ${reason}`);
        
        // Create critical alert
        this.createAlert('emergency_rollback', `Emergency rollback initiated: ${reason}`, 'critical', {
            reason,
            triggeredBy: 'monitoring_system'
        });

        // Set all feature flags to 0%
        try {
            await this.db.execute(`
                UPDATE feature_flags 
                SET rollout_percentage = 0, 
                    updated_at = NOW() 
                WHERE enabled = TRUE
            `);

            console.log('✅ Feature flags reset to 0%');
        } catch (error) {
            console.error('❌ Failed to reset feature flags:', error);
            throw error;
        }

        // Broadcast emergency status
        this.io.emit('emergency_rollback', {
            reason,
            timestamp: new Date().toISOString()
        });
    }

    getCurrentMetrics() {
        const timestamps = Array.from(this.metrics.keys()).sort().reverse();
        return timestamps.length > 0 ? this.metrics.get(timestamps[0]) : {};
    }

    async getMetricsHistory(hours) {
        const keys = await this.redis.keys('migration_metrics:*');
        const cutoff = new Date(Date.now() - hours * 60 * 60 * 1000);
        
        const history = [];
        for (const key of keys) {
            const timestamp = key.split(':')[1];
            if (new Date(timestamp) >= cutoff) {
                const data = await this.redis.get(key);
                if (data) {
                    history.push(JSON.parse(data));
                }
            }
        }

        return history.sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp));
    }

    cleanupOldMetrics() {
        const cutoff = Date.now() - config.monitoring.metricsRetention;
        for (const [timestamp] of this.metrics) {
            if (new Date(timestamp).getTime() < cutoff) {
                this.metrics.delete(timestamp);
            }
        }
    }

    broadcastMetrics() {
        const currentMetrics = this.getCurrentMetrics();
        this.io.emit('metrics', currentMetrics);
        
        if (this.alerts.length > 0) {
            this.io.emit('alerts', this.alerts);
        }
    }

    async getSystemLoad() {
        // Simplified system load - in production you'd use proper system monitoring
        return {
            1: Math.random() * 2,
            5: Math.random() * 2,
            15: Math.random() * 2
        };
    }

    async shutdown() {
        console.log('🛑 Shutting down migration monitor...');
        
        this.stopMonitoring();
        
        if (this.db) {
            await this.db.end();
        }
        
        if (this.redis) {
            this.redis.disconnect();
        }
        
        this.server.close();
        console.log('✅ Migration monitor shutdown complete');
    }

    start() {
        this.server.listen(config.port, () => {
            console.log(`🚀 Migration Monitor running on port ${config.port}`);
            console.log(`📊 Dashboard: http://localhost:${config.port}`);
            console.log(`🔌 WebSocket: ws://localhost:${config.port}`);
        });
    }
}

// Handle graceful shutdown
process.on('SIGINT', async () => {
    console.log('\n🛑 Received SIGINT, shutting down gracefully...');
    if (global.monitor) {
        await global.monitor.shutdown();
    }
    process.exit(0);
});

process.on('SIGTERM', async () => {
    console.log('\n🛑 Received SIGTERM, shutting down gracefully...');
    if (global.monitor) {
        await global.monitor.shutdown();
    }
    process.exit(0);
});

// Start the monitor
async function main() {
    try {
        const monitor = new MigrationMonitor();
        global.monitor = monitor;
        
        await monitor.initialize();
        monitor.start();
        
        console.log('✅ Migration Monitor initialized successfully');
    } catch (error) {
        console.error('❌ Failed to start Migration Monitor:', error);
        process.exit(1);
    }
}

if (require.main === module) {
    main();
}

module.exports = MigrationMonitor;