'use client';

import { useEffect, useRef, useState, useCallback } from 'react';
import { io, Socket } from 'socket.io-client';

export interface WebSocketConfig {
  url: string;
  path?: string;
  autoConnect?: boolean;
  reconnection?: boolean;
  reconnectionAttempts?: number;
  reconnectionDelay?: number;
  timeout?: number;
}

export interface ConnectionStatus {
  connected: boolean;
  connecting: boolean;
  error: string | null;
  reconnectAttempt: number;
}

export interface WebSocketHookReturn {
  socket: Socket | null;
  status: ConnectionStatus;
  connect: () => void;
  disconnect: () => void;
  emit: (event: string, data?: any) => void;
  on: (event: string, handler: (...args: any[]) => void) => void;
  off: (event: string, handler?: (...args: any[]) => void) => void;
}

const DEFAULT_CONFIG: WebSocketConfig = {
  url: process.env.NEXT_PUBLIC_WEBSOCKET_URL || 'http://localhost:3001',
  path: '/dashboard-socket.io/',
  autoConnect: true,
  reconnection: true,
  reconnectionAttempts: 5,
  reconnectionDelay: 1000,
  timeout: 20000,
};

export function useWebSocket(
  config: Partial<WebSocketConfig> = {},
  token?: string
): WebSocketHookReturn {
  const finalConfig = { ...DEFAULT_CONFIG, ...config };
  const socketRef = useRef<Socket | null>(null);
  const [status, setStatus] = useState<ConnectionStatus>({
    connected: false,
    connecting: false,
    error: null,
    reconnectAttempt: 0,
  });

  const connect = useCallback(() => {
    if (socketRef.current?.connected) {
      return;
    }

    if (!token) {
      setStatus(prev => ({
        ...prev,
        error: 'Authentication token required',
        connected: false,
        connecting: false,
      }));
      return;
    }

    setStatus(prev => ({
      ...prev,
      connecting: true,
      error: null,
    }));

    try {
      const socket = io(finalConfig.url, {
        ...(finalConfig.path && { path: finalConfig.path }),
        auth: {
          token,
        },
        autoConnect: false,
        ...(finalConfig.reconnection !== undefined && { reconnection: finalConfig.reconnection }),
        ...(finalConfig.reconnectionAttempts && { reconnectionAttempts: finalConfig.reconnectionAttempts }),
        ...(finalConfig.reconnectionDelay && { reconnectionDelay: finalConfig.reconnectionDelay }),
        ...(finalConfig.timeout && { timeout: finalConfig.timeout }),
        transports: ['websocket', 'polling'],
      });

      // Connection event handlers
      socket.on('connect', () => {
        console.log('Dashboard WebSocket connected:', socket.id);
        setStatus({
          connected: true,
          connecting: false,
          error: null,
          reconnectAttempt: 0,
        });
      });

      socket.on('disconnect', (reason) => {
        console.log('Dashboard WebSocket disconnected:', reason);
        setStatus(prev => ({
          ...prev,
          connected: false,
          connecting: false,
        }));
      });

      socket.on('connect_error', (error) => {
        console.error('Dashboard WebSocket connection error:', error);
        setStatus(prev => ({
          ...prev,
          connected: false,
          connecting: false,
          error: error.message || 'Connection failed',
        }));
      });

      socket.on('reconnect', (attemptNumber) => {
        console.log('Dashboard WebSocket reconnected after', attemptNumber, 'attempts');
        setStatus(prev => ({
          ...prev,
          connected: true,
          connecting: false,
          error: null,
          reconnectAttempt: 0,
        }));
      });

      socket.on('reconnect_attempt', (attemptNumber) => {
        console.log('Dashboard WebSocket reconnection attempt:', attemptNumber);
        setStatus(prev => ({
          ...prev,
          reconnectAttempt: attemptNumber,
          connecting: true,
        }));
      });

      socket.on('reconnect_error', (error) => {
        console.error('Dashboard WebSocket reconnection error:', error);
        setStatus(prev => ({
          ...prev,
          error: error.message || 'Reconnection failed',
        }));
      });

      socket.on('reconnect_failed', () => {
        console.error('Dashboard WebSocket reconnection failed');
        setStatus(prev => ({
          ...prev,
          connected: false,
          connecting: false,
          error: 'Failed to reconnect after maximum attempts',
        }));
      });

      // Server-specific events
      socket.on('connected', (data) => {
        console.log('Dashboard WebSocket authentication successful:', data);
      });

      socket.on('error', (error) => {
        console.error('Dashboard WebSocket server error:', error);
        setStatus(prev => ({
          ...prev,
          error: error.message || 'Server error',
        }));
      });

      socket.on('server_shutdown', (data) => {
        console.warn('Dashboard WebSocket server shutting down:', data);
        setStatus(prev => ({
          ...prev,
          error: 'Server is shutting down',
        }));
      });

      socketRef.current = socket;
      socket.connect();
    } catch (error) {
      console.error('Failed to create WebSocket connection:', error);
      setStatus(prev => ({
        ...prev,
        connected: false,
        connecting: false,
        error: error instanceof Error ? error.message : 'Failed to create connection',
      }));
    }
  }, [finalConfig, token]);

  const disconnect = useCallback(() => {
    if (socketRef.current) {
      socketRef.current.disconnect();
      socketRef.current = null;
    }
    setStatus({
      connected: false,
      connecting: false,
      error: null,
      reconnectAttempt: 0,
    });
  }, []);

  const emit = useCallback((event: string, data?: any) => {
    if (socketRef.current?.connected) {
      socketRef.current.emit(event, data);
    } else {
      console.warn('Cannot emit event: WebSocket not connected');
    }
  }, []);

  const on = useCallback((event: string, handler: (...args: any[]) => void) => {
    if (socketRef.current) {
      socketRef.current.on(event, handler);
    }
  }, []);

  const off = useCallback((event: string, handler?: (...args: any[]) => void) => {
    if (socketRef.current) {
      if (handler) {
        socketRef.current.off(event, handler);
      } else {
        socketRef.current.off(event);
      }
    }
  }, []);

  // Auto-connect on mount if enabled
  useEffect(() => {
    if (finalConfig.autoConnect && token) {
      connect();
    }

    return () => {
      disconnect();
    };
  }, [finalConfig.autoConnect, token, connect, disconnect]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (socketRef.current) {
        socketRef.current.disconnect();
      }
    };
  }, []);

  return {
    socket: socketRef.current,
    status,
    connect,
    disconnect,
    emit,
    on,
    off,
  };
}