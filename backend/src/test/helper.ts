import { FastifyInstance } from 'fastify';
import Fastify from 'fastify';
import { registerPlugins } from '@/plugins';
import { registerRoutes } from '@/routes';
import { config } from '@/config/config';

export const build = async (): Promise<FastifyInstance> => {
  const fastify = Fastify({
    logger: false, // Disable logging in tests
    requestIdHeader: 'x-request-id',
    requestIdLogLabel: 'requestId',
    genReqId: () => {
      return `test_req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    },
  });

  // Register plugins
  await registerPlugins(fastify);

  // Register routes
  await registerRoutes(fastify);

  return fastify;
};