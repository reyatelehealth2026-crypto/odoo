import { FastifyRequest, FastifyReply } from 'fastify';
import { JWTPayload } from '@/types';
import { logger } from '@/utils/logger';

export enum Permission {
  VIEW_DASHBOARD = 'view_dashboard',
  MANAGE_ORDERS = 'manage_orders',
  PROCESS_PAYMENTS = 'process_payments',
  MANAGE_WEBHOOKS = 'manage_webhooks',
  ADMIN_ACCESS = 'admin_access',
  MANAGE_USERS = 'manage_users',
  SYSTEM_SETTINGS = 'system_settings',
  PHARMACIST_ACCESS = 'pharmacist_access',
}

export enum UserRole {
  SUPER_ADMIN = 'SUPER_ADMIN',
  ADMIN = 'ADMIN',
  PHARMACIST = 'PHARMACIST',
  STAFF = 'STAFF',
}

// Role hierarchy: super_admin → admin → pharmacist/staff
const ROLE_HIERARCHY = {
  [UserRole.SUPER_ADMIN]: 4,
  [UserRole.ADMIN]: 3,
  [UserRole.PHARMACIST]: 2,
  [UserRole.STAFF]: 1,
};

// Permission mappings for each role
const ROLE_PERMISSIONS = {
  [UserRole.SUPER_ADMIN]: [
    Permission.VIEW_DASHBOARD,
    Permission.MANAGE_ORDERS,
    Permission.PROCESS_PAYMENTS,
    Permission.MANAGE_WEBHOOKS,
    Permission.ADMIN_ACCESS,
    Permission.MANAGE_USERS,
    Permission.SYSTEM_SETTINGS,
  ],
  [UserRole.ADMIN]: [
    Permission.VIEW_DASHBOARD,
    Permission.MANAGE_ORDERS,
    Permission.PROCESS_PAYMENTS,
    Permission.MANAGE_WEBHOOKS,
    Permission.ADMIN_ACCESS,
  ],
  [UserRole.PHARMACIST]: [
    Permission.VIEW_DASHBOARD,
    Permission.MANAGE_ORDERS,
    Permission.PROCESS_PAYMENTS,
    Permission.PHARMACIST_ACCESS,
  ],
  [UserRole.STAFF]: [
    Permission.VIEW_DASHBOARD,
    Permission.MANAGE_ORDERS,
    Permission.PROCESS_PAYMENTS,
  ],
};

/**
 * Check if user has required permission
 */
export const hasPermission = (userRole: string, userPermissions: string[], requiredPermission: Permission): boolean => {
  // Super admin has all permissions
  if (userRole === UserRole.SUPER_ADMIN) {
    return true;
  }

  // Check explicit permissions
  if (userPermissions.includes(requiredPermission) || userPermissions.includes('*')) {
    return true;
  }

  // Check role-based permissions
  const rolePermissions = ROLE_PERMISSIONS[userRole as UserRole] || [];
  return rolePermissions.includes(requiredPermission);
};
/**
 * Check if user has required role level or higher
 */
export const hasRoleLevel = (userRole: string, requiredRole: UserRole): boolean => {
  const userLevel = ROLE_HIERARCHY[userRole as UserRole] || 0;
  const requiredLevel = ROLE_HIERARCHY[requiredRole] || 0;
  return userLevel >= requiredLevel;
};

/**
 * Middleware to require specific permission
 */
export const requirePermission = (permission: Permission) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const user = (request as any).user as JWTPayload;
    
    if (!user) {
      return reply.status(401).send({
        success: false,
        error: {
          code: 'UNAUTHORIZED',
          message: 'Authentication required',
          timestamp: new Date().toISOString(),
        },
      });
    }

    if (!hasPermission(user.role, user.permissions, permission)) {
      logger.warn('Access denied - insufficient permissions', {
        userId: user.userId,
        role: user.role,
        requiredPermission: permission,
        userPermissions: user.permissions,
      });

      return reply.status(403).send({
        success: false,
        error: {
          code: 'INSUFFICIENT_PERMISSIONS',
          message: 'Access denied',
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};

/**
 * Middleware to require specific role level or higher
 */
export const requireRole = (role: UserRole) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const user = (request as any).user as JWTPayload;
    
    if (!user) {
      return reply.status(401).send({
        success: false,
        error: {
          code: 'UNAUTHORIZED',
          message: 'Authentication required',
          timestamp: new Date().toISOString(),
        },
      });
    }

    if (!hasRoleLevel(user.role, role)) {
      logger.warn('Access denied - insufficient role level', {
        userId: user.userId,
        userRole: user.role,
        requiredRole: role,
      });

      return reply.status(403).send({
        success: false,
        error: {
          code: 'INSUFFICIENT_ROLE',
          message: 'Access denied - insufficient role level',
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};

/**
 * Middleware to require admin access (admin or super_admin)
 */
export const requireAdmin = requireRole(UserRole.ADMIN);

/**
 * Middleware to require super admin access
 */
export const requireSuperAdmin = requireRole(UserRole.SUPER_ADMIN);

/**
 * Get all permissions for a role
 */
export const getRolePermissions = (role: UserRole): Permission[] => {
  return ROLE_PERMISSIONS[role] || [];
};

/**
 * Check if user can access specific line account
 */
export const canAccessLineAccount = (user: JWTPayload, requestedLineAccountId: string): boolean => {
  // Super admin can access all accounts
  if (user.role === UserRole.SUPER_ADMIN) {
    return true;
  }

  // Users can only access their own line account
  return user.lineAccountId === requestedLineAccountId;
};

/**
 * Middleware to validate line account access
 */
export const requireLineAccountAccess = (getLineAccountId: (request: FastifyRequest) => string) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const user = (request as any).user as JWTPayload;
    const requestedLineAccountId = getLineAccountId(request);

    if (!canAccessLineAccount(user, requestedLineAccountId)) {
      logger.warn('Access denied - line account access violation', {
        userId: user.userId,
        userLineAccountId: user.lineAccountId,
        requestedLineAccountId,
      });

      return reply.status(403).send({
        success: false,
        error: {
          code: 'LINE_ACCOUNT_ACCESS_DENIED',
          message: 'Access denied to requested line account',
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};