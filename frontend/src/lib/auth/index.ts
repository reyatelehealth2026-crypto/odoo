import { User, UserRole, Permission } from '@/types';

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
}

export interface LoginCredentials {
  username: string;
  password: string;
}

export class AuthManager {
  private static instance: AuthManager;
  private user: User | null = null;

  static getInstance(): AuthManager {
    if (!AuthManager.instance) {
      AuthManager.instance = new AuthManager();
    }
    return AuthManager.instance;
  }

  // Token management
  setTokens(tokens: AuthTokens): void {
    if (typeof window === 'undefined') return;

    localStorage.setItem('auth_token', tokens.accessToken);
    localStorage.setItem('refresh_token', tokens.refreshToken);
  }

  getAccessToken(): string | null {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem('auth_token');
  }

  getRefreshToken(): string | null {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem('refresh_token');
  }

  clearTokens(): void {
    if (typeof window === 'undefined') return;

    localStorage.removeItem('auth_token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('line_account_id');
  }

  // User management
  setUser(user: User): void {
    this.user = user;
    if (typeof window !== 'undefined') {
      localStorage.setItem('line_account_id', user.lineAccountId);
    }
  }

  getUser(): User | null {
    return this.user;
  }

  clearUser(): void {
    this.user = null;
  }

  // Authentication state
  isAuthenticated(): boolean {
    return this.getAccessToken() !== null && this.user !== null;
  }

  // Role-based access control
  hasRole(role: UserRole): boolean {
    if (!this.user) return false;

    const roleHierarchy: Record<UserRole, number> = {
      SUPER_ADMIN: 4,
      ADMIN: 3,
      PHARMACIST: 2,
      STAFF: 1,
    };

    return roleHierarchy[this.user.role] >= roleHierarchy[role];
  }

  hasPermission(permission: Permission): boolean {
    if (!this.user || !this.user.permissions) return false;
    return this.user.permissions.includes(permission);
  }

  // Role helpers (matching PHP backend)
  isSuperAdmin(): boolean {
    return this.user?.role === 'SUPER_ADMIN';
  }

  isAdmin(): boolean {
    return this.hasRole('ADMIN');
  }

  isStaff(): boolean {
    return this.hasRole('STAFF');
  }

  // Logout
  logout(): void {
    this.clearTokens();
    this.clearUser();
  }
}

export const authManager = AuthManager.getInstance();

// Utility functions for components
export function useAuth() {
  return {
    user: authManager.getUser(),
    isAuthenticated: authManager.isAuthenticated(),
    hasRole: (role: UserRole) => authManager.hasRole(role),
    hasPermission: (permission: Permission) =>
      authManager.hasPermission(permission),
    isSuperAdmin: () => authManager.isSuperAdmin(),
    isAdmin: () => authManager.isAdmin(),
    isStaff: () => authManager.isStaff(),
    logout: () => authManager.logout(),
  };
}
