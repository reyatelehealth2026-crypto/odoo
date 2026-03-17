import { PrismaClient } from '@prisma/client';

export abstract class BaseService {
  protected prisma: PrismaClient;

  constructor(prisma: PrismaClient) {
    this.prisma = prisma;
  }

  protected handleError(error: unknown, context: string): never {
    console.error(`Error in ${context}`, error);
    
    if (error instanceof Error) {
      throw error;
    }
    
    throw new Error(`Unknown error in ${context}`);
  }

  protected validateLineAccountAccess(
    userLineAccountId: string, 
    requestedLineAccountId?: string
  ): string {
    // If no specific line account requested, use user's default
    if (!requestedLineAccountId) {
      return userLineAccountId;
    }

    // For now, users can only access their own line account
    // TODO: Implement proper multi-account access control
    if (requestedLineAccountId !== userLineAccountId) {
      throw new Error('Access denied to requested line account');
    }

    return requestedLineAccountId;
  }
}