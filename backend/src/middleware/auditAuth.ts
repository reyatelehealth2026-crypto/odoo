import { FastifyRequest, FastifyReply } from 'fastify';

// DEMO ONLY — no-op audit/anomaly stubs.
// Created 2026-04-28 to unblock backend startup under ENABLE_DEMO_DASHBOARD=true.
// Replace with real auth audit logging + anomaly detection before enabling production auth.

const noopPreHandler = async (_request: FastifyRequest, _reply: FastifyReply): Promise<void> => {
  return;
};

export const auditLogin = noopPreHandler;
export const auditTokenRefresh = noopPreHandler;
export const auditLogout = noopPreHandler;
export const auditProfileAccess = noopPreHandler;
export const detectSuspiciousActivity = noopPreHandler;
