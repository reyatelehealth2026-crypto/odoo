'use client';

import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';

interface PerformanceStats {
  cache: {
    hitRate: number;
    totalRequests: number;
    performance: 'good' | 'poor';
  };
  database: {
    activeConnections: number;
    totalConnections: number;
    utilization: number;
    averageQueryTime: number;
    slowQueries: number;
    performance: 'good' | 'poor';
  };
  overall: {
    status: 'good' | 'poor';
    timestamp: string;
  };
}

interface CacheStats {
  hits: number;
  misses: number;
  sets: number;
  deletes: number;
  hitRate: number;
}

interface DatabaseStats {
  pool: {
    totalConnections: number;
    activeConnections: number;
    idleConnections: number;
    queuedRequests: number;
    totalQueries: number;
    averageQueryTime: number;
    slowQueries: number;
  };
  slowQueries: Array<{
    query: string;
    executionTime: number;
    rowsAffected: number;
    timestamp: string;
  }>;
}

export default function PerformanceDashboard() {
  const [refreshInterval, setRefreshInterval] = useState(30000); // 30 seconds

  // Fetch performance statistics
  const { data: performanceStats, isLoading: statsLoading } = useQuery({
    queryKey: ['performance', 'stats'],
    queryFn: () => fetch('/api/v1/analytics/performance/stats').then(r => r.json()),
    refetchInterval: refreshInterval,
  });

  // Fetch cache statistics
  const { data: cacheStats, isLoading: cacheLoading } = useQuery({
    queryKey: ['performance', 'cache'],
    queryFn: () => fetch('/api/v1/analytics/cache/stats').then(r => r.json()),
    refetchInterval: refreshInterval,
  });

  // Fetch database statistics
  const { data: databaseStats, isLoading: dbLoading } = useQuery({
    queryKey: ['performance', 'database'],
    queryFn: () => fetch('/api/v1/analytics/database/stats').then(r => r.json()),
    refetchInterval: refreshInterval,
  });

  const stats: PerformanceStats | undefined = performanceStats?.data;
  const cache: CacheStats | undefined = cacheStats?.data;
  const database: DatabaseStats | undefined = databaseStats?.data;

  if (statsLoading || cacheLoading || dbLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        <span className="ml-2">Loading performance data...</span>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">Performance Dashboard</h1>
        <div className="flex items-center space-x-4">
          <select
            value={refreshInterval}
            onChange={(e) => setRefreshInterval(Number(e.target.value))}
            className="px-3 py-2 border border-gray-300 rounded-md text-sm"
          >
            <option value={10000}>10 seconds</option>
            <option value={30000}>30 seconds</option>
            <option value={60000}>1 minute</option>
            <option value={300000}>5 minutes</option>
          </select>
          <div className="flex items-center space-x-2">
            <div className={`w-3 h-3 rounded-full ${
              stats?.overall.status === 'good' ? 'bg-green-500' : 'bg-red-500'
            }`}></div>
            <span className="text-sm text-gray-600">
              {stats?.overall.status === 'good' ? 'Healthy' : 'Issues Detected'}
            </span>
          </div>
        </div>
      </div>

      {/* Overview Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Cache Performance */}
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900">Cache Performance</h3>
            <div className={`px-2 py-1 rounded-full text-xs font-medium ${
              stats?.cache.performance === 'good' 
                ? 'bg-green-100 text-green-800' 
                : 'bg-red-100 text-red-800'
            }`}>
              {stats?.cache.performance === 'good' ? 'Good' : 'Poor'}
            </div>
          </div>
          
          <div className="space-y-3">
            <div className="flex justify-between">
              <span className="text-sm text-gray-600">Hit Rate</span>
              <span className="text-sm font-medium">
                {stats?.cache.hitRate.toFixed(1)}%
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div 
                className={`h-2 rounded-full ${
                  (stats?.cache.hitRate || 0) >= 85 ? 'bg-green-500' : 'bg-red-500'
                }`}
                style={{ width: `${stats?.cache.hitRate || 0}%` }}
              ></div>
            </div>
            <div className="flex justify-between text-sm text-gray-600">
              <span>Total Requests</span>
              <span>{stats?.cache.totalRequests.toLocaleString()}</span>
            </div>
          </div>
        </div>

        {/* Database Performance */}
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900">Database Performance</h3>
            <div className={`px-2 py-1 rounded-full text-xs font-medium ${
              stats?.database.performance === 'good' 
                ? 'bg-green-100 text-green-800' 
                : 'bg-red-100 text-red-800'
            }`}>
              {stats?.database.performance === 'good' ? 'Good' : 'Poor'}
            </div>
          </div>
          
          <div className="space-y-3">
            <div className="flex justify-between">
              <span className="text-sm text-gray-600">Avg Query Time</span>
              <span className="text-sm font-medium">
                {stats?.database.averageQueryTime.toFixed(1)}ms
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-sm text-gray-600">Connection Utilization</span>
              <span className="text-sm font-medium">
                {stats?.database.utilization.toFixed(1)}%
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-sm text-gray-600">Slow Queries</span>
              <span className={`text-sm font-medium ${
                (stats?.database.slowQueries || 0) > 0 ? 'text-red-600' : 'text-green-600'
              }`}>
                {stats?.database.slowQueries || 0}
              </span>
            </div>
          </div>
        </div>

        {/* System Health */}
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900">System Health</h3>
            <div className={`px-2 py-1 rounded-full text-xs font-medium ${
              stats?.overall.status === 'good' 
                ? 'bg-green-100 text-green-800' 
                : 'bg-red-100 text-red-800'
            }`}>
              {stats?.overall.status === 'good' ? 'Healthy' : 'Issues'}
            </div>
          </div>
          
          <div className="space-y-3">
            <div className="flex items-center space-x-2">
              <div className={`w-2 h-2 rounded-full ${
                (stats?.cache.hitRate || 0) >= 85 ? 'bg-green-500' : 'bg-red-500'
              }`}></div>
              <span className="text-sm text-gray-600">Cache Hit Rate Target (85%)</span>
            </div>
            <div className="flex items-center space-x-2">
              <div className={`w-2 h-2 rounded-full ${
                (stats?.database.averageQueryTime || 0) < 300 ? 'bg-green-500' : 'bg-red-500'
              }`}></div>
              <span className="text-sm text-gray-600">Query Time Target (&lt;300ms)</span>
            </div>
            <div className="flex items-center space-x-2">
              <div className={`w-2 h-2 rounded-full ${
                (stats?.database.utilization || 0) < 80 ? 'bg-green-500' : 'bg-yellow-500'
              }`}></div>
              <span className="text-sm text-gray-600">Connection Utilization (&lt;80%)</span>
            </div>
          </div>
        </div>
      </div>

      {/* Detailed Statistics */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Cache Details */}
        <div className="bg-white rounded-lg shadow">
          <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Cache Statistics</h3>
          </div>
          <div className="p-6">
            {cache ? (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <div className="text-2xl font-bold text-green-600">
                      {cache.hits.toLocaleString()}
                    </div>
                    <div className="text-sm text-gray-600">Cache Hits</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-red-600">
                      {cache.misses.toLocaleString()}
                    </div>
                    <div className="text-sm text-gray-600">Cache Misses</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-blue-600">
                      {cache.sets.toLocaleString()}
                    </div>
                    <div className="text-sm text-gray-600">Cache Sets</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-gray-600">
                      {cache.deletes.toLocaleString()}
                    </div>
                    <div className="text-sm text-gray-600">Cache Deletes</div>
                  </div>
                </div>
              </div>
            ) : (
              <div className="text-center text-gray-500">No cache data available</div>
            )}
          </div>
        </div>

        {/* Database Connection Pool */}
        <div className="bg-white rounded-lg shadow">
          <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Connection Pool</h3>
          </div>
          <div className="p-6">
            {database?.pool ? (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <div className="text-2xl font-bold text-blue-600">
                      {database.pool.activeConnections}
                    </div>
                    <div className="text-sm text-gray-600">Active Connections</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-gray-600">
                      {database.pool.idleConnections}
                    </div>
                    <div className="text-sm text-gray-600">Idle Connections</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-yellow-600">
                      {database.pool.queuedRequests}
                    </div>
                    <div className="text-sm text-gray-600">Queued Requests</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-green-600">
                      {database.pool.totalQueries.toLocaleString()}
                    </div>
                    <div className="text-sm text-gray-600">Total Queries</div>
                  </div>
                </div>
              </div>
            ) : (
              <div className="text-center text-gray-500">No database data available</div>
            )}
          </div>
        </div>
      </div>

      {/* Slow Queries */}
      {database?.slowQueries && database.slowQueries.length > 0 && (
        <div className="bg-white rounded-lg shadow">
          <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Slow Queries</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Query
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Execution Time
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Rows Affected
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Timestamp
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {database.slowQueries.map((query, index) => (
                  <tr key={index}>
                    <td className="px-6 py-4 text-sm text-gray-900 max-w-md truncate">
                      {query.query}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                      {query.executionTime.toFixed(2)}ms
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {query.rowsAffected}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {new Date(query.timestamp).toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}