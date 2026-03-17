import { FastifyRequest, FastifyReply } from 'fastify';
import { APIResponse } from '@/types';

declare module 'fastify' {
  interface FastifyReply {
    success<T>(data: T, meta?: any): FastifyReply;
    error(code: string, message: string, details?: any, statusCode?: number): FastifyReply;
  }
}

export const responseFormatter = async (
  _request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  // Add success response helper
  (reply as any).success = function<T>(data: T, meta?: any) {
    const response: APIResponse<T> = {
      success: true,
      data,
      meta,
    };
    return this.send(response);
  };

  // Add error response helper
  (reply as any).error = function(
    code: string, 
    message: string, 
    details?: any, 
    statusCode: number = 400
  ) {
    const response: APIResponse = {
      success: false,
      error: {
        code,
        message,
        details,
        timestamp: new Date().toISOString(),
      },
    };
    return this.status(statusCode).send(response);
  };
};