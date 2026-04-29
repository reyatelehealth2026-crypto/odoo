interface PerformanceMetric {
  name: string;
  value: number;
  timestamp: number;
  url?: string;
  userAgent?: string;
}

interface WebVitalsMetric {
  name: 'CLS' | 'FID' | 'FCP' | 'LCP' | 'TTFB';
  value: number;
  delta: number;
  id: string;
  navigationType: string;
}

class PerformanceMonitor {
  private metrics: PerformanceMetric[] = [];
  private isEnabled: boolean = true;
  private batchSize: number = 10;
  private flushInterval: number = 30000; // 30 seconds
  private apiEndpoint: string = '/api/v1/analytics/performance';

  constructor() {
    if (typeof window !== 'undefined') {
      this.initializeMonitoring();
    }
  }

  /**
   * Initialize performance monitoring
   */
  private initializeMonitoring(): void {
    // Monitor Web Vitals
    this.initializeWebVitals();
    
    // Monitor navigation timing
    this.monitorNavigationTiming();
    
    // Monitor resource loading
    this.monitorResourceTiming();
    
    // Monitor long tasks
    this.monitorLongTasks();
    
    // Start periodic flushing
    this.startPeriodicFlush();
    
    // Flush on page unload
    window.addEventListener('beforeunload', () => {
      this.flush();
    });
  }

  /**
   * Initialize Web Vitals monitoring
   */
  private initializeWebVitals(): void {
    // Dynamic import to avoid SSR issues
    import('web-vitals').then(({ getCLS, getFID, getFCP, getLCP, getTTFB }) => {
      getCLS(this.onWebVital.bind(this));
      getFID(this.onWebVital.bind(this));
      getFCP(this.onWebVital.bind(this));
      getLCP(this.onWebVital.bind(this));
      getTTFB(this.onWebVital.bind(this));
    }).catch(error => {
      console.warn('Failed to load web-vitals:', error);
    });
  }

  /**
   * Handle Web Vitals metrics
   */
  private onWebVital(metric: WebVitalsMetric): void {
    this.recordMetric({
      name: `web_vital_${metric.name.toLowerCase()}`,
      value: metric.value,
      timestamp: Date.now(),
      url: window.location.href,
      userAgent: navigator.userAgent,
    });

    // Log poor performance
    if (this.isPoorPerformance(metric)) {
      console.warn(`Poor ${metric.name} performance:`, metric.value);
    }
  }

  /**
   * Monitor navigation timing
   */
  private monitorNavigationTiming(): void {
    window.addEventListener('load', () => {
      setTimeout(() => {
        const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
        
        if (navigation) {
          // DNS lookup time
          this.recordMetric({
            name: 'dns_lookup_time',
            value: navigation.domainLookupEnd - navigation.domainLookupStart,
            timestamp: Date.now(),
            url: window.location.href,
          });

          // TCP connection time
          this.recordMetric({
            name: 'tcp_connection_time',
            value: navigation.connectEnd - navigation.connectStart,
            timestamp: Date.now(),
            url: window.location.href,
          });

          // Server response time
          this.recordMetric({
            name: 'server_response_time',
            value: navigation.responseStart - navigation.requestStart,
            timestamp: Date.now(),
            url: window.location.href,
          });

          // DOM content loaded time
          this.recordMetric({
            name: 'dom_content_loaded_time',
            value: navigation.domContentLoadedEventEnd - navigation.startTime,
            timestamp: Date.now(),
            url: window.location.href,
          });

          // Page load time
          this.recordMetric({
            name: 'page_load_time',
            value: navigation.loadEventEnd - navigation.startTime,
            timestamp: Date.now(),
            url: window.location.href,
          });
        }
      }, 0);
    });
  }

  /**
   * Monitor resource loading performance
   */
  private monitorResourceTiming(): void {
    // Monitor resource loading
    const observer = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (entry.entryType === 'resource') {
          const resource = entry as PerformanceResourceTiming;
          
          // Track slow resources
          if (resource.duration > 1000) { // > 1 second
            this.recordMetric({
              name: 'slow_resource',
              value: resource.duration,
              timestamp: Date.now(),
              url: resource.name,
            });
          }

          // Track large resources
          if (resource.transferSize > 1024 * 1024) { // > 1MB
            this.recordMetric({
              name: 'large_resource',
              value: resource.transferSize,
              timestamp: Date.now(),
              url: resource.name,
            });
          }
        }
      }
    });

    observer.observe({ entryTypes: ['resource'] });
  }

  /**
   * Monitor long tasks that block the main thread
   */
  private monitorLongTasks(): void {
    if ('PerformanceObserver' in window) {
      try {
        const observer = new PerformanceObserver((list) => {
          for (const entry of list.getEntries()) {
            this.recordMetric({
              name: 'long_task',
              value: entry.duration,
              timestamp: Date.now(),
              url: window.location.href,
            });
          }
        });

        observer.observe({ entryTypes: ['longtask'] });
      } catch (error) {
        // Long task API not supported
        console.warn('Long task monitoring not supported:', error);
      }
    }
  }

  /**
   * Record a custom performance metric
   */
  recordMetric(metric: Omit<PerformanceMetric, 'timestamp'> & { timestamp?: number }): void {
    if (!this.isEnabled) return;

    this.metrics.push({
      ...metric,
      timestamp: metric.timestamp || Date.now(),
    });

    // Auto-flush if batch size reached
    if (this.metrics.length >= this.batchSize) {
      this.flush();
    }
  }

  /**
   * Record API call performance
   */
  recordAPICall(url: string, method: string, duration: number, status: number): void {
    this.recordMetric({
      name: 'api_call',
      value: duration,
      url: `${method} ${url}`,
      userAgent: `status_${status}`,
    });
  }

  /**
   * Record React component render time
   */
  recordComponentRender(componentName: string, renderTime: number): void {
    this.recordMetric({
      name: 'component_render',
      value: renderTime,
      url: componentName,
    });
  }

  /**
   * Flush metrics to the server
   */
  private async flush(): Promise<void> {
    if (this.metrics.length === 0) return;

    const metricsToSend = [...this.metrics];
    this.metrics = [];

    try {
      await fetch(this.apiEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          metrics: metricsToSend,
          session: this.getSessionId(),
          timestamp: Date.now(),
        }),
      });
    } catch (error) {
      console.warn('Failed to send performance metrics:', error);
      // Re-add metrics to queue for retry
      this.metrics.unshift(...metricsToSend);
    }
  }

  /**
   * Start periodic flushing
   */
  private startPeriodicFlush(): void {
    setInterval(() => {
      this.flush();
    }, this.flushInterval);
  }

  /**
   * Check if performance is poor based on Web Vitals thresholds
   */
  private isPoorPerformance(metric: WebVitalsMetric): boolean {
    const thresholds = {
      CLS: 0.25,
      FID: 300,
      FCP: 3000,
      LCP: 4000,
      TTFB: 800,
    };

    return metric.value > thresholds[metric.name];
  }

  /**
   * Get or create session ID
   */
  private getSessionId(): string {
    let sessionId = sessionStorage.getItem('performance_session_id');
    if (!sessionId) {
      sessionId = Math.random().toString(36).substring(2, 15);
      sessionStorage.setItem('performance_session_id', sessionId);
    }
    return sessionId;
  }

  /**
   * Enable/disable monitoring
   */
  setEnabled(enabled: boolean): void {
    this.isEnabled = enabled;
  }

  /**
   * Get current metrics
   */
  getMetrics(): PerformanceMetric[] {
    return [...this.metrics];
  }

  /**
   * Clear all metrics
   */
  clearMetrics(): void {
    this.metrics = [];
  }
}

// Singleton instance
const performanceMonitor = new PerformanceMonitor();

export default performanceMonitor;

// React hook for component performance monitoring
export function usePerformanceMonitor() {
  const recordRender = (componentName: string, renderTime: number) => {
    performanceMonitor.recordComponentRender(componentName, renderTime);
  };

  const recordCustomMetric = (name: string, value: number) => {
    performanceMonitor.recordMetric({ name, value });
  };

  return {
    recordRender,
    recordCustomMetric,
  };
}