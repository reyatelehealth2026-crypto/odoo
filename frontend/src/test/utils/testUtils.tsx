import React, { ReactElement } from 'react'
import { render, RenderOptions } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { vi } from 'vitest'

// Mock data generators
export const mockData = {
  user: {
    id: '123e4567-e89b-12d3-a456-426614174000',
    username: 'testuser',
    email: 'test@example.com',
    role: 'staff' as const,
    lineAccountId: '123e4567-e89b-12d3-a456-426614174001',
    permissions: ['view_dashboard', 'manage_orders'],
    createdAt: new Date('2024-01-01'),
    updatedAt: new Date('2024-01-01'),
  },
  
  dashboardMetrics: {
    orders: {
      todayCount: 25,
      todayTotal: 125000.50,
      pendingCount: 5,
      completedCount: 20,
      averageOrderValue: 5000.02,
      topProducts: [],
    },
    payments: {
      pendingSlips: 3,
      processedToday: 15,
      matchingRate: 0.95,
      totalAmount: 75000.00,
      averageProcessingTime: 120,
    },
    webhooks: {
      totalEvents: 1250,
      successRate: 0.98,
      failedEvents: 25,
      averageResponseTime: 150,
    },
    customers: {
      totalActive: 500,
      newToday: 5,
      returningRate: 0.75,
    },
    updatedAt: new Date('2024-01-01T10:00:00Z'),
  },
  
  order: {
    id: '123e4567-e89b-12d3-a456-426614174002',
    odooOrderId: 'ORD_001',
    customerRef: 'CUST_001',
    status: 'pending' as const,
    totalAmount: 5000.00,
    currency: 'THB',
    items: [],
    createdAt: new Date('2024-01-01'),
    updatedAt: new Date('2024-01-01'),
  },
  
  paymentSlip: {
    id: '123e4567-e89b-12d3-a456-426614174003',
    imageUrl: 'https://example.com/slip.jpg',
    amount: 5000.00,
    uploadedBy: '123e4567-e89b-12d3-a456-426614174000',
    status: 'pending' as const,
    createdAt: new Date('2024-01-01'),
  },
}

// Create a test query client
const createTestQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
      mutations: {
        retry: false,
      },
    },
  })

// Custom render function with providers
interface CustomRenderOptions extends Omit<RenderOptions, 'wrapper'> {
  queryClient?: QueryClient
}

export const renderWithProviders = (
  ui: ReactElement,
  options: CustomRenderOptions = {}
) => {
  const { queryClient = createTestQueryClient(), ...renderOptions } = options

  const Wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  )

  return {
    ...render(ui, { wrapper: Wrapper, ...renderOptions }),
    queryClient,
  }
}

// Mock API responses
export const mockApiResponses = {
  success: <T,>(data: T) => ({
    success: true,
    data,
    meta: {
      page: 1,
      limit: 20,
      total: 1,
      totalPages: 1,
    },
  }),
  
  error: (code: string, message: string) => ({
    success: false,
    error: {
      code,
      message,
      timestamp: new Date().toISOString(),
      requestId: 'test-request-id',
    },
  }),
  
  paginated: <T,>(data: T[], page = 1, limit = 20) => ({
    success: true,
    data,
    meta: {
      page,
      limit,
      total: data.length,
      totalPages: Math.ceil(data.length / limit),
    },
  }),
}

// Mock WebSocket
export const mockWebSocket = {
  connect: vi.fn(),
  disconnect: vi.fn(),
  emit: vi.fn(),
  on: vi.fn(),
  off: vi.fn(),
  connected: true,
}

// Mock fetch responses
export const mockFetch = (response: any, ok = true) => {
  global.fetch = vi.fn().mockResolvedValue({
    ok,
    status: ok ? 200 : 400,
    json: vi.fn().mockResolvedValue(response),
    text: vi.fn().mockResolvedValue(JSON.stringify(response)),
  })
}

// Test helpers for form interactions
export const formHelpers = {
  fillInput: async (input: HTMLElement, value: string) => {
    const { fireEvent } = await import('@testing-library/react')
    fireEvent.change(input, { target: { value } })
  },
  
  selectOption: async (select: HTMLElement, value: string) => {
    const { fireEvent } = await import('@testing-library/react')
    fireEvent.change(select, { target: { value } })
  },
  
  clickButton: async (button: HTMLElement) => {
    const { fireEvent } = await import('@testing-library/react')
    fireEvent.click(button)
  },
}

// Wait for async operations
export const waitForAsync = () => new Promise(resolve => setTimeout(resolve, 0))

// Mock localStorage
export const mockLocalStorage = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
}

// Mock sessionStorage
export const mockSessionStorage = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
}

// Test data factories with random values
export const factories = {
  user: (overrides = {}) => ({
    ...mockData.user,
    id: crypto.randomUUID(),
    username: `user_${Date.now()}`,
    email: `test_${Date.now()}@example.com`,
    ...overrides,
  }),
  
  order: (overrides = {}) => ({
    ...mockData.order,
    id: crypto.randomUUID(),
    odooOrderId: `ORD_${Date.now()}`,
    customerRef: `CUST_${Date.now()}`,
    ...overrides,
  }),
  
  paymentSlip: (overrides = {}) => ({
    ...mockData.paymentSlip,
    id: crypto.randomUUID(),
    imageUrl: `https://example.com/slip_${Date.now()}.jpg`,
    ...overrides,
  }),
}

// Component testing utilities
export const componentHelpers = {
  // Wait for component to be in loading state
  waitForLoading: async (container: HTMLElement) => {
    const { waitFor } = await import('@testing-library/react')
    await waitFor(() => {
      expect(container.querySelector('[data-testid="loading"]')).toBeInTheDocument()
    })
  },
  
  // Wait for component to finish loading
  waitForLoadingToFinish: async (container: HTMLElement) => {
    const { waitFor } = await import('@testing-library/react')
    await waitFor(() => {
      expect(container.querySelector('[data-testid="loading"]')).not.toBeInTheDocument()
    })
  },
  
  // Check if error message is displayed
  expectErrorMessage: (container: HTMLElement, message: string) => {
    const errorElement = container.querySelector('[data-testid="error"]')
    expect(errorElement).toBeInTheDocument()
    expect(errorElement).toHaveTextContent(message)
  },
}

// Re-export testing library utilities
export * from '@testing-library/react'
export { default as userEvent } from '@testing-library/user-event'