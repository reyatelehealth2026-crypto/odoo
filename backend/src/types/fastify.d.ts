import { FastifyRequest, FastifyReply } from 'fastify';
import { Permission } from '@/middleware/rbac';
import { WebSocketService } from '@/services/WebSocketService';

declare module 'fastify' {
  interface FastifyInstance {
    authenticate: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    requirePermission: (permission: Permission) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    webSocketService: WebSocketService;
  }
}