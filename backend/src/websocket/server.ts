/**
 * Enhanced WebSocket Server for Odoo Dashboard Real-time Updates
 * 
 * Provides real-time updates for dashboard metrics, order status changes,
 * and payment processing notifications with JWT authentication and Redis scaling.
 * 
 * Requirements: FR-1.4, TC-1.4, BR-3.3
 */

import { Server as HTTPServer } from 'http';
import { Server as SocketIOServer, Socket } from 'socket.io';
import { createAdapter } from '@socket.io/redis-adapter';
import { createClient } from 'redis';
import jwt from 'jsonwebtoken';
import { PrismaClient } from '@prisma/client';
import { config } from '../config/config';
import { logger } from '../utils/logger';
import { JWTPayload } from '../types';

export interface AuthenticatedSocket extends Socket {
  userId: string;
  username: string;
  lineAccountId: string;
  role: string;
  permissions: string[];
}

export interface DashboardUpdateEvent {
  type: 'metrics_updated' | 'order_status_changed' | 'payment_processed' | 'webhook_received';
  data: any;
  lineAccountId: string;
  timestamp: number;
}

export class DashboardWebSocketServer {
  private io: SocketIOServer;
  private prisma: PrismaClient;
  private redisClient: any;
  private redisSubscriber: any;
  private connections: Map<string, Set<string>> = new Map(); // lineAccountId -> Set<socketId>
  private heartbeatInterval: NodeJS.Timeout | null = null;

  constructor(httpServer: HTTPServer, prisma: PrismaClient) {
    this.prisma = prisma;
    
    // Initialize Socket.IO server with CORS configuration
    this.io = new SocketIOServer(httpServer, {
      cors: {
        origin: process.env.ALLOWED_ORIGINS?.split(',') || ['http://localhost:3000'],
        credentials: true,
        methods: ['GET', 'POST']
      },
      path: '/socket.io/',
      transports: ['websocket', 'polling'],
      pingTimeout: 60000,
      pingInterval: 25000,
      allowEIO3: true
    });

    this.setupRedisAdapter();
    this.setupEventHandlers();
    this.startHeartbeat();
    
    logger.info('Dashboard WebSocket server initialized');
  }

  /**
   * Set up Redis adapter for multi-instance scaling
   */
  private async setupRedisAdapter(): Promise<void> {
    try {
      // Create Redis clients for pub/sub
      this.redisClient = createClient({
        url: config.REDIS_URL,
        retry_strategy: (options) => {
          if (options.error && options.error.code === 'ECONNREFUSED') {
            logger.error('Redis connection refused');
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

      this.redisSubscriber = this.redisClient.duplicate();

      await this.redisClient.connect();
      await this.redisSubscriber.connect();

      // Set up Redis adapter for Socket.IO
      this.io.adapter(createAdapter(this.redisClient, this.redisSubscriber));

      // Subscribe to dashboard update events
      await this.redisSubscriber.subscribe('dashboard_updates', (message: string) => {
        this.handleDashboardUpdate(message);
      });

      logger.info('Redis adapter configured for WebSocket scaling');
    } catch (error) {
      logger.error('Failed to setup Redis adapter', { error: String(error) });
      throw error;
    }
  }

  /**
   * Set up Socket.IO event handlers
   */
  private setupEventHandlers(): void {
    this.io.use(this.authenticationMiddleware.bind(this));
    this.io.on('connection', this.handleConnection.bind(this));
  }

  /**
   * Authentication middleware for WebSocket connections
   */
  private async authenticationMiddleware(socket: Socket, next: (err?: Error) => void): Promise<void> {
    try {
      const token = socket.handshake.auth.token || socket.handshake.headers.authorization?.replace('Bearer ', '');
      
      if (!token) {
        return next(new Error('Authentication token required'));
      }

      // Verify JWT token
      const payload = jwt.verify(token, config.JWT_SECRET) as JWTPayload;

      // Check if user is still active
      const user = await this.prisma.user.findFirst({
        where: {
          id: payload.userId,
          isActive: true,
        },
      });

      if (!user) {
        return next(new Error('User not found or inactive'));
      }

      // Check if token is blacklisted (in production, check Redis)
      // For now, we'll skip this check

      // Attach user info to socket
      const authSocket = socket as AuthenticatedSocket;
      authSocket.userId = user.id;
      authSocket.username = user.username;
      authSocket.lineAccountId = user.lineAccountId;
      authSocket.role = user.role;
      authSocket.permissions = this.getUserPermissions(user.role);

      logger.info('WebSocket authentication successful', {
        userId: user.id,
        username: user.username,
        socketId: socket.id,
      });

      next();
    } catch (error) {
      logger.error('WebSocket authentication failed', { 
        error: String(error),
        socketId: socket.id,
      });
      next(new Error('Authentication failed'));
    }
  }

  /**
   * Handle new WebSocket connection
   */
  private handleConnection(socket: AuthenticatedSocket): void {
    logger.info('Dashboard WebSocket client connected', {
      userId: socket.userId,
      username: socket.username,
      lineAccountId: socket.lineAccountId,
      socketId: socket.id,
    });

    // Join room for this LINE account
    const room = `dashboard_${socket.lineAccountId}`;
    socket.join(room);

    // Track connection
    if (!this.connections.has(socket.lineAccountId)) {
      this.connections.set(socket.lineAccountId, new Set());
    }
    this.connections.get(socket.lineAccountId)!.add(socket.id);

    // Send connection confirmation
    socket.emit('connected', {
      userId: socket.userId,
      username: socket.username,
      lineAccountId: socket.lineAccountId,
      permissions: socket.permissions,
      timestamp: Date.now(),
    });

    // Set up event handlers for this socket
    this.setupSocketEventHandlers(socket);

    // Handle disconnection
    socket.on('disconnect', (reason) => {
      this.handleDisconnection(socket, reason);
    });
  }

  /**
   * Set up event handlers for individual socket
   */
  private setupSocketEventHandlers(socket: AuthenticatedSocket): void {
    // Handle dashboard subscription
    socket.on('subscribe_dashboard', (data: { metrics?: string[] }) => {
      const { metrics = ['all'] } = data;
      
      // Join specific metric rooms if needed
      metrics.forEach(metric => {
        if (metric === 'all' || ['orders', 'payments', 'webhooks', 'customers'].includes(metric)) {
          socket.join(`${socket.lineAccountId}_${metric}`);
        }
      });

      socket.emit('subscription_confirmed', {
        metrics,
        timestamp: Date.now(),
      });

      logger.info('Dashboard subscription confirmed', {
        userId: socket.userId,
        metrics,
      });
    });

    // Handle dashboard data request
    socket.on('request_dashboard_data', async (data: { dateRange?: { from: string; to: string } }) => {
      try {
        // This would typically fetch current dashboard data
        // For now, we'll emit a placeholder response
        socket.emit('dashboard_data', {
          metrics: {
            orders: { todayCount: 0, todayTotal: 0 },
            payments: { pendingSlips: 0, processedToday: 0 },
            webhooks: { todayCount: 0, successRate: 100 },
            customers: { totalActive: 0, newToday: 0 },
          },
          timestamp: Date.now(),
        });
      } catch (error) {
        logger.error('Failed to fetch dashboard data', {
          userId: socket.userId,
          error: String(error),
        });
        socket.emit('error', {
          message: 'Failed to fetch dashboard data',
          code: 'DASHBOARD_DATA_ERROR',
        });
      }
    });

    // Handle heartbeat/ping
    socket.on('ping', () => {
      socket.emit('pong', { timestamp: Date.now() });
    });

    // Handle errors
    socket.on('error', (error) => {
      logger.error('Socket error', {
        userId: socket.userId,
        socketId: socket.id,
        error: String(error),
      });
    });
  }

  /**
   * Handle socket disconnection
   */
  private handleDisconnection(socket: AuthenticatedSocket, reason: string): void {
    logger.info('Dashboard WebSocket client disconnected', {
      userId: socket.userId,
      socketId: socket.id,
      reason,
    });

    // Remove from connections tracking
    if (this.connections.has(socket.lineAccountId)) {
      this.connections.get(socket.lineAccountId)!.delete(socket.id);
      
      if (this.connections.get(socket.lineAccountId)!.size === 0) {
        this.connections.delete(socket.lineAccountId);
      }
    }
  }

  /**
   * Handle dashboard update events from Redis
   */
  private handleDashboardUpdate(message: string): void {
    try {
      const event: DashboardUpdateEvent = JSON.parse(message);
      const room = `dashboard_${event.lineAccountId}`;

      // Broadcast update to all clients in the room
      this.io.to(room).emit(event.type, {
        ...event.data,
        timestamp: event.timestamp,
      });

      logger.info('Dashboard update broadcasted', {
        type: event.type,
        lineAccountId: event.lineAccountId,
        room,
      });
    } catch (error) {
      logger.error('Failed to handle dashboard update', {
        error: String(error),
        message,
      });
    }
  }

  /**
   * Broadcast dashboard update to specific LINE account
   */
  public async broadcastDashboardUpdate(event: DashboardUpdateEvent): Promise<void> {
    try {
      // Publish to Redis for multi-instance scaling
      await this.redisClient.publish('dashboard_updates', JSON.stringify(event));
      
      logger.info('Dashboard update published to Redis', {
        type: event.type,
        lineAccountId: event.lineAccountId,
      });
    } catch (error) {
      logger.error('Failed to broadcast dashboard update', {
        error: String(error),
        event,
      });
    }
  }

  /**
   * Start heartbeat to keep connections alive and clean up stale connections
   */
  private startHeartbeat(): void {
    this.heartbeatInterval = setInterval(() => {
      // Emit heartbeat to all connected clients
      this.io.emit('heartbeat', { timestamp: Date.now() });
      
      // Log connection statistics
      const totalConnections = Array.from(this.connections.values())
        .reduce((sum, sockets) => sum + sockets.size, 0);
      
      logger.debug('WebSocket heartbeat', {
        totalConnections,
        accountsConnected: this.connections.size,
      });
    }, 30000); // Every 30 seconds
  }

  /**
   * Get user permissions based on role
   */
  private getUserPermissions(role: string): string[] {
    const rolePermissions: Record<string, string[]> = {
      SUPER_ADMIN: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'manage_webhooks',
        'admin_access',
        'manage_users',
        'system_settings',
      ],
      ADMIN: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'manage_webhooks',
        'admin_access',
      ],
      PHARMACIST: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'pharmacist_access',
      ],
      STAFF: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
      ],
    };

    return rolePermissions[role] || [];
  }

  /**
   * Get connection statistics
   */
  public getConnectionStats(): {
    totalConnections: number;
    accountsConnected: number;
    connectionsByAccount: Array<{ accountId: string; connections: number }>;
  } {
    const totalConnections = Array.from(this.connections.values())
      .reduce((sum, sockets) => sum + sockets.size, 0);

    const connectionsByAccount = Array.from(this.connections.entries())
      .map(([accountId, sockets]) => ({
        accountId,
        connections: sockets.size,
      }));

    return {
      totalConnections,
      accountsConnected: this.connections.size,
      connectionsByAccount,
    };
  }

  /**
   * Graceful shutdown
   */
  public async shutdown(): Promise<void> {
    logger.info('Shutting down Dashboard WebSocket server...');

    // Clear heartbeat interval
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
    }

    // Notify all clients about shutdown
    this.io.emit('server_shutdown', {
      message: 'Server is shutting down for maintenance',
      timestamp: Date.now(),
    });

    // Give clients time to receive the message
    await new Promise(resolve => setTimeout(resolve, 1000));

    // Close all socket connections
    const sockets = await this.io.fetchSockets();
    for (const socket of sockets) {
      socket.disconnect(true);
    }

    // Close Socket.IO server
    this.io.close();

    // Close Redis connections
    if (this.redisClient) {
      await this.redisClient.quit();
    }
    if (this.redisSubscriber) {
      await this.redisSubscriber.quit();
    }

    logger.info('Dashboard WebSocket server shutdown complete');
  }
}