import { FastifyRequest, FastifyReply } from 'fastify';

// DEMO ONLY — no-op rate limit stubs.
// Created 2026-04-28 to unblock backend startup under ENABLE_DEMO_DASHBOARD=true.
// Replace with real per-route rate limiting before enabling production auth.
// See backend/src/middleware/rateLimiting.ts for production-grade primitives.

const noopPreHandler = async (_request: FastifyRequest, _reply: FastifyReply): Promise<void> => {
  return;
};

export const loginRateLimit = noopPreHandler;
export const refreshRateLimit = noopPreHandler;
export const logoutRateLimit = noopPreHandler;
export const profileRateLimit = noopPreHandler;
