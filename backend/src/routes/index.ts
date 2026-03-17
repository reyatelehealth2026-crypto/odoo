import { FastifyInstance } from 'fastify';
import { config } from '@/config/config';
import authRoutes from '@/routes/auth';
import dashboardRoutes from '@/routes/dashboard';
import orderRoutes from '@/routes/orders';
import paymentRoutes from '@/routes/payments';
import customerRoutes from '@/routes/customers';
import healthRoutes from '@/routes/health';
import auditRoutes from '@/routes/audit';
import securityRoutes from '@/routes/security';

export const registerRoutes = async (fastify: FastifyInstance): Promise<void> => {
  // Health check routes (no prefix for load balancer compatibility)
  await fastify.register(healthRoutes);

  // Register API routes with prefix
  await fastify.register(async (fastify) => {
    // Authentication routes
    await fastify.register(authRoutes, { prefix: '/auth' });
    
    // Dashboard routes
    await fastify.register(dashboardRoutes, { prefix: '/dashboard' });
    
    // Order management routes
    await fastify.register(orderRoutes, { prefix: '/orders' });
    
    // Payment processing routes
    await fastify.register(paymentRoutes, { prefix: '/payments' });
    
    // Customer management routes
    await fastify.register(customerRoutes, { prefix: '/customers' });
    
    // Audit logging routes
    await fastify.register(auditRoutes, { prefix: '/audit' });
    
    // Security monitoring routes
    await fastify.register(securityRoutes, { prefix: '/security' });
    
  }, { prefix: config.API_PREFIX });
};