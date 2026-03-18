import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import Fastify, { FastifyInstance } from 'fastify';
import { registerPlugins } from '@/plugins';
import { registerRoutes } from '@/routes';
import { config } from '@/config/config';

describe('Core API Infrastructure', () => {
  let app: FastifyInstance;

  beforeAll(async () => {
    app = Fastify({
      logger: false, // Disable logging in tests
    });

    await registerPlugins(app);
    await registerRoutes(app);
  });

  afterAll(async () => {
    await app.close();
  });

  describe('Server Setup', () => {
    it('should start server successfully', async () => {
      expect(app).toBeDefined();
      expect(app.server).toBeDefined();
    });

    it('should have proper error handling', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/non-existent-route',
      });

      expect(response.statusCode).toBe(404);
      const body = JSON.parse(response.body);
      expect(body.success).toBe(false);
      expect(body.error.code).toBe('NOT_FOUND');
    });

    it('should have standardized response format', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/health',
      });

      expect(response.statusCode).toBe(200);
      const body = JSON.parse(response.body);
      expect(body).toHaveProperty('status');
      expect(body).toHaveProperty('timestamp');
    });
  });

  describe('Middleware', () => {
    it('should have CORS enabled', async () => {
      const response = await app.inject({
        method: 'OPTIONS',
        url: '/health',
        headers: {
          'Origin': 'http://localhost:3000',
          'Access-Control-Request-Method': 'GET',
        },
      });

      expect(response.headers['access-control-allow-origin']).toBeDefined();
    });

    it('should have security headers', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/health',
      });

      expect(response.headers['x-frame-options']).toBeDefined();
      expect(response.headers['x-content-type-options']).toBeDefined();
    });

    it('should have rate limiting', async () => {
      // Make multiple requests to trigger rate limiting
      const promises = Array.from({ length: 150 }, () =>
        app.inject({
          method: 'GET',
          url: '/health',
        })
      );

      const responses = await Promise.all(promises);
      const rateLimitedResponses = responses.filter(r => r.statusCode === 429);
      
      // Should have some rate limited responses
      expect(rateLimitedResponses.length).toBeGreaterThan(0);
    });
  });

  describe('Health Checks', () => {
    it('should have health endpoint', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/health',
      });

      expect(response.statusCode).toBe(200);
      const body = JSON.parse(response.body);
      expect(body.status).toMatch(/healthy|degraded|unhealthy/);
      expect(body.checks).toBeDefined();
      expect(body.performance).toBeDefined();
    });

    it('should have readiness endpoint', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/ready',
      });

      expect([200, 503]).toContain(response.statusCode);
      const body = JSON.parse(response.body);
      expect(body.status).toMatch(/ready|not_ready/);
    });

    it('should have liveness endpoint', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/live',
      });

      expect(response.statusCode).toBe(200);
      const body = JSON.parse(response.body);
      expect(body.status).toBe('alive');
      expect(body.uptime).toBeGreaterThan(0);
    });

    it('should have metrics endpoint', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/metrics',
      });

      expect(response.statusCode).toBe(200);
      const body = JSON.parse(response.body);
      expect(body.memory).toBeDefined();
      expect(body.cache).toBeDefined();
      expect(body.process).toBeDefined();
    });
  });

  describe('API Routes', () => {
    it('should have API prefix configured', async () => {
      const response = await app.inject({
        method: 'GET',
        url: `${config.API_PREFIX}/auth/profile`,
      });

      // Should return 401 (unauthorized) not 404 (not found)
      expect(response.statusCode).toBe(401);
    });

    it('should have authentication routes', async () => {
      const response = await app.inject({
        method: 'POST',
        url: `${config.API_PREFIX}/auth/login`,
        payload: {},
      });

      // Should return 400 (validation error) not 404 (not found)
      expect(response.statusCode).toBe(400);
    });

    it('should have dashboard routes', async () => {
      const response = await app.inject({
        method: 'GET',
        url: `${config.API_PREFIX}/dashboard/overview`,
      });

      // Should return 401 (unauthorized) not 404 (not found)
      expect(response.statusCode).toBe(401);
    });
  });

  describe('Documentation', () => {
    it('should have Swagger documentation in development', async () => {
      if (config.NODE_ENV === 'development') {
        const response = await app.inject({
          method: 'GET',
          url: '/docs',
        });

        expect(response.statusCode).toBe(200);
        expect(response.headers['content-type']).toContain('text/html');
      }
    });

    it('should have OpenAPI spec', async () => {
      if (config.NODE_ENV === 'development') {
        const response = await app.inject({
          method: 'GET',
          url: '/docs/json',
        });

        expect(response.statusCode).toBe(200);
        const spec = JSON.parse(response.body);
        expect(spec.openapi).toBeDefined();
        expect(spec.info).toBeDefined();
      }
    });
  });

  describe('Error Handling', () => {
    it('should handle validation errors properly', async () => {
      const response = await app.inject({
        method: 'POST',
        url: `${config.API_PREFIX}/auth/login`,
        payload: {
          username: '', // Invalid empty username
        },
      });

      expect(response.statusCode).toBe(400);
      const body = JSON.parse(response.body);
      expect(body.success).toBe(false);
      expect(body.error.code).toBe('INVALID_REQUEST');
      expect(body.error.details).toBeDefined();
    });

    it('should handle internal server errors', async () => {
      // This would need a route that throws an error for testing
      // For now, just verify the error handler is registered
      expect(app.errorHandler).toBeDefined();
    });

    it('should include request ID in error responses', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/non-existent-route',
        headers: {
          'x-request-id': 'test-request-123',
        },
      });

      expect(response.statusCode).toBe(404);
      // The request ID should be available in the response context
      expect(response.headers['x-request-id']).toBeDefined();
    });
  });

  describe('Performance', () => {
    it('should respond to health checks quickly', async () => {
      const start = Date.now();
      
      const response = await app.inject({
        method: 'GET',
        url: '/health',
      });

      const duration = Date.now() - start;
      
      expect(response.statusCode).toBe(200);
      expect(duration).toBeLessThan(1000); // Should respond within 1 second
    });

    it('should handle concurrent requests', async () => {
      const concurrentRequests = 50;
      const promises = Array.from({ length: concurrentRequests }, () =>
        app.inject({
          method: 'GET',
          url: '/live',
        })
      );

      const responses = await Promise.all(promises);
      
      // All requests should succeed (ignoring rate limiting)
      const successfulResponses = responses.filter(r => r.statusCode === 200);
      expect(successfulResponses.length).toBeGreaterThan(concurrentRequests * 0.8);
    });
  });
});