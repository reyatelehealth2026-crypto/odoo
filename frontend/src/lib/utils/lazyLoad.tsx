import { lazy, Suspense, ComponentType } from 'react';
import { ErrorBoundary } from 'react-error-boundary';

// Loading component for lazy-loaded components
const LoadingSpinner = () => (
  <div className="flex items-center justify-center p-8">
    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
    <span className="ml-2 text-gray-600">Loading...</span>
  </div>
);

// Error fallback component
const ErrorFallback = ({ error, resetErrorBoundary }: any) => (
  <div className="flex flex-col items-center justify-center p-8 text-center">
    <div className="text-red-600 mb-4">
      <svg className="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
      </svg>
    </div>
    <h3 className="text-lg font-semibold text-gray-900 mb-2">Something went wrong</h3>
    <p className="text-gray-600 mb-4">Failed to load component</p>
    <button
      onClick={resetErrorBoundary}
      className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"
    >
      Try again
    </button>
  </div>
);

// Enhanced lazy loading with error boundary and loading state
export function lazyLoad<T extends ComponentType<any>>(
  importFunc: () => Promise<any>,
  options: {
    fallback?: ComponentType;
    errorFallback?: ComponentType<any>;
    preload?: boolean;
  } = {}
) {
  const normalizedImport = async (): Promise<{ default: T }> => {
    const module = await importFunc();
    const namedExport = Object.keys(module).find((key) => /^[A-Z]/.test(key));
    return {
      default: module.default || (namedExport ? module[namedExport] : undefined),
    };
  };

  const LazyComponent = lazy(normalizedImport);
  
  // Preload the component if requested
  if (options.preload) {
    importFunc();
  }
  
  const WrappedComponent = (props: any) => (
    <ErrorBoundary
      FallbackComponent={options.errorFallback || ErrorFallback}
      onReset={() => window.location.reload()}
    >
      <Suspense fallback={options.fallback ? <options.fallback /> : <LoadingSpinner />}>
        <LazyComponent {...props} />
      </Suspense>
    </ErrorBoundary>
  );
  
  // Add preload method to the component
  (WrappedComponent as any).preload = importFunc;
  
  return WrappedComponent;
}

// Preload multiple components
export function preloadComponents(components: Array<() => Promise<any>>) {
  // Use requestIdleCallback if available, otherwise setTimeout
  const schedulePreload = (callback: () => void) => {
    if ('requestIdleCallback' in window) {
      requestIdleCallback(callback, { timeout: 2000 });
    } else {
      setTimeout(callback, 100);
    }
  };
  
  components.forEach((importFunc, index) => {
    schedulePreload(() => {
      // Stagger preloading to avoid blocking
      setTimeout(() => {
        importFunc().catch(error => {
          console.warn('Failed to preload component:', error);
        });
      }, index * 100);
    });
  });
}

// Route-based code splitting helper
export const routeComponents = {
  // Dashboard routes
  Dashboard: lazyLoad(() => import('@/app/dashboard/page'), { preload: true }),
  DashboardOverview: lazyLoad(() => import('@/components/dashboard/DashboardOverview')),
  
  // Order management routes
  OrderList: lazyLoad(() => import('@/components/orders/OrderList')),
  OrderDetail: lazyLoad(() => import('@/components/orders/OrderDetail')),
  OrderTimeline: lazyLoad(() => import('@/components/orders/OrderTimeline')),
  
  // Payment routes
  PaymentProcessingDashboard: lazyLoad(() => import('@/components/payments/PaymentProcessingDashboard')),
  PaymentSlipList: lazyLoad(() => import('@/components/payments/PaymentSlipList')),
  
  // Customer routes
  CustomerProfile: lazyLoad(() => import('@/components/customers/CustomerProfile')),
  CustomerOrderHistory: lazyLoad(() => import('@/components/customers/CustomerOrderHistory')),
};

// Component-based code splitting for heavy components
export const heavyComponents = {
  // Charts and visualizations
  ChartComponent: lazyLoad(() => import('@/components/dashboard/ChartComponent')),
  
  // Data tables
  DataTable: lazyLoad(() => import('@/components/ui/DataTable')),
  PerformanceDashboard: lazyLoad(() => import('@/components/performance/PerformanceDashboard')),
  
  // File upload components
  FileUploadZone: lazyLoad(() => import('@/components/payments/FileUploadZone')),
};

// Preload critical components on app start
export function preloadCriticalComponents() {
  preloadComponents([
    () => import('@/components/dashboard/DashboardOverview'),
    () => import('@/components/orders/OrderList'),
    () => import('@/components/ui/DataTable'),
  ]);
}

// Preload components based on route
export function preloadRouteComponents(route: string) {
  const preloadMap: Record<string, Array<() => Promise<any>>> = {
    '/dashboard': [
      () => import('@/components/dashboard/DashboardOverview'),
      () => import('@/components/dashboard/ChartComponent'),
      () => import('@/components/dashboard/KPICard'),
    ],
    '/orders': [
      () => import('@/components/orders/OrderList'),
      () => import('@/components/orders/OrderDetail'),
      () => import('@/components/ui/DataTable'),
    ],
    '/payments': [
      () => import('@/components/payments/PaymentProcessingDashboard'),
      () => import('@/components/payments/PaymentSlipList'),
      () => import('@/components/payments/FileUploadZone'),
    ],
    '/customers': [
      () => import('@/components/customers/CustomerProfile'),
      () => import('@/components/customers/CustomerOrderHistory'),
    ],
  };
  
  const componentsToPreload = preloadMap[route];
  if (componentsToPreload) {
    preloadComponents(componentsToPreload);
  }
}

// Hook for component-level lazy loading
export function useLazyLoad() {
  const preloadComponent = (importFunc: () => Promise<any>) => {
    importFunc().catch(error => {
      console.warn('Failed to preload component:', error);
    });
  };
  
  const preloadRoute = (route: string) => {
    preloadRouteComponents(route);
  };
  
  return {
    preloadComponent,
    preloadRoute,
  };
}