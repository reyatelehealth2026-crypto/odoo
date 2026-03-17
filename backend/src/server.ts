import 'dotenv/config';
import Fastify from 'fastify';
import { createServer } from 'http';
import { config } from '@/config/config';
import { registerPlugins } from '@/plugins';
import { registerRoutes } from '@/routes';
import { logger } from '@/utils/logger';
import { prisma } from '@/utils/prisma';
import { DashboardWebSocketServer } from '@/websocket/server';
import { WebSocketService } from '@/services/WebSocketService';

const fastify = Fastify({
  logger: {
    level: config.LOG_LEVEL,
  },
  requestIdHeader: 'x-request-id',
  requestIdLogLabel: 'requestId',
  genReqId: () => {
    return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  },
  serverFactory: (handler) => {
    return createServer(handler);
  },
});

// Initialize WebSocket server and service
let webSocketServer: DashboardWebSocketServer;
let webSocketService: WebSocketService;

const start = async (): Promise<void> => {
  try {
    // Register plugins
    await registerPlugins(fastify);

    // Register routes
    await registerRoutes(fastify);

    // Initialize WebSocket server
    webSocketServer = new DashboardWebSocketServer(fastify.server, prisma);
    webSocketService = new WebSocketService(prisma);
    webSocketService.setWebSocketServer(webSocketServer);

    // Make WebSocket service available globally
    fastify.decorate('webSocketService', webSocketService);

    // Start periodic dashboard updates (every 30 seconds)
    const updateInterval = webSocketService.startPeriodicUpdates(30000);

    // Graceful shutdown
    const gracefulShutdown = async (signal: string): Promise<void> => {
      logger.info(`Received ${signal}, shutting down gracefully`);
      
      try {
        // Clear periodic updates
        clearInterval(updateInterval);
        
        // Shutdown WebSocket server
        if (webSocketServer) {
          await webSocketServer.shutdown();
        }
        
        await fastify.close();
        await prisma.$disconnect();
        logger.info('Server closed successfully');
        process.exit(0);
      } catch (error) {
        logger.error('Error during shutdown:', { error: String(error) });
        process.exit(1);
      }
    };

    process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
    process.on('SIGINT', () => gracefulShutdown('SIGINT'));

    // Handle uncaught exceptions
    process.on('uncaughtException', (error) => {
      logger.fatal('Uncaught exception', { error: String(error), stack: error.stack });
      process.exit(1);
    });

    process.on('unhandledRejection', (reason, promise) => {
      logger.fatal('Unhandled rejection', { reason: String(reason), promise });
      process.exit(1);
    });

    // Start server
    await fastify.listen({ 
      port: config.PORT, 
      host: '0.0.0.0' 
    });

    logger.info(`🚀 Server listening on port ${config.PORT}`);
    logger.info(`📊 Environment: ${config.NODE_ENV}`);
    logger.info(`🔗 API Prefix: ${config.API_PREFIX}`);
    logger.info(`📚 Documentation: http://localhost:${config.PORT}/docs`);
    logger.info(`❤️  Health Check: http://localhost:${config.PORT}/health`);
    logger.info(`🔌 WebSocket Server: Enabled with Redis scaling`);
    logger.info(`⚡ Real-time Updates: Every 30 seconds`);

  } catch (error) {
    logger.error('Error starting server:', { error: String(error) });
    process.exit(1);
  }
};

start();