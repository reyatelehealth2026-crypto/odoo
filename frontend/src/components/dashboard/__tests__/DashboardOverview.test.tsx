import React from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DashboardOverview } from '../DashboardOverview'
import { mockData, mockApiResponses, mockFetch } from '@/test/utils/testUtils'

// Mock the API
const mockApi = {
  dashboard: {
    getMetrics: vi.fn(),
    getChartData: vi.fn(),
  },
}

vi.mock('@/lib/api/dashboard', () => ({
  dashboardApi: mockApi,
}))

describe('DashboardOverview', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    })
    vi.clearAllMocks()
  })

  const renderWithProvider = (component: React.ReactElement) => {
    return render(
      <QueryClientProvider client={queryClient}>
        {component}
      </QueryClientProvider>
    )
  }

  it('renders dashboard metrics correctly', async () => {
    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(mockData.dashboardMetrics)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByText('Dashboard Overview')).toBeInTheDocument()
    })

    // Check KPI cards are rendered
    expect(screen.getByText('Total Orders Today')).toBeInTheDocument()
    expect(screen.getByText('25')).toBeInTheDocument()
    expect(screen.getByText('Today\'s Revenue')).toBeInTheDocument()
    expect(screen.getByText('฿125,000.50')).toBeInTheDocument()
  })

  it('shows loading state initially', () => {
    mockApi.dashboard.getMetrics.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    renderWithProvider(<DashboardOverview />)

    expect(screen.getAllByTestId('loading-skeleton')).toHaveLength(4)
  })

  it('handles API error gracefully', async () => {
    mockApi.dashboard.getMetrics.mockRejectedValue(
      new Error('Failed to fetch metrics')
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByTestId('error-message')).toBeInTheDocument()
    })

    expect(screen.getByText(/Failed to load dashboard data/)).toBeInTheDocument()
  })

  it('updates data when date range changes', async () => {
    const user = userEvent.setup()
    
    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(mockData.dashboardMetrics)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByText('Dashboard Overview')).toBeInTheDocument()
    })

    // Change date range
    const dateRangeButton = screen.getByTestId('date-range-selector')
    await user.click(dateRangeButton)

    const lastWeekOption = screen.getByText('Last 7 days')
    await user.click(lastWeekOption)

    // Should trigger new API call
    await waitFor(() => {
      expect(mockApi.dashboard.getMetrics).toHaveBeenCalledTimes(2)
    })
  })

  it('refreshes data automatically every 30 seconds', async () => {
    vi.useFakeTimers()
    
    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(mockData.dashboardMetrics)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(mockApi.dashboard.getMetrics).toHaveBeenCalledTimes(1)
    })

    // Fast-forward 30 seconds
    vi.advanceTimersByTime(30000)

    await waitFor(() => {
      expect(mockApi.dashboard.getMetrics).toHaveBeenCalledTimes(2)
    })

    vi.useRealTimers()
  })

  it('displays correct trend indicators', async () => {
    const metricsWithTrend = {
      ...mockData.dashboardMetrics,
      orders: {
        ...mockData.dashboardMetrics.orders,
        trend: {
          direction: 'up' as const,
          percentage: 15.5,
          period: 'vs yesterday',
        },
      },
    }

    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(metricsWithTrend)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByText('↗')).toBeInTheDocument()
      expect(screen.getByText('15.5%')).toBeInTheDocument()
      expect(screen.getByText('vs yesterday')).toBeInTheDocument()
    })
  })

  it('handles empty data gracefully', async () => {
    const emptyMetrics = {
      orders: {
        todayCount: 0,
        todayTotal: 0,
        pendingCount: 0,
        completedCount: 0,
        averageOrderValue: 0,
        topProducts: [],
      },
      payments: {
        pendingSlips: 0,
        processedToday: 0,
        matchingRate: 0,
        totalAmount: 0,
        averageProcessingTime: 0,
      },
      webhooks: {
        totalEvents: 0,
        successRate: 0,
        failedEvents: 0,
        averageResponseTime: 0,
      },
      customers: {
        totalActive: 0,
        newToday: 0,
        returningRate: 0,
      },
      updatedAt: new Date(),
    }

    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(emptyMetrics)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByText('0')).toBeInTheDocument()
      expect(screen.getByText('฿0.00')).toBeInTheDocument()
    })
  })

  it('shows last updated timestamp', async () => {
    const fixedDate = new Date('2024-01-01T10:30:00Z')
    const metricsWithTimestamp = {
      ...mockData.dashboardMetrics,
      updatedAt: fixedDate,
    }

    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(metricsWithTimestamp)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByText(/Last updated:/)).toBeInTheDocument()
    })
  })

  it('allows manual refresh', async () => {
    const user = userEvent.setup()
    
    mockApi.dashboard.getMetrics.mockResolvedValue(
      mockApiResponses.success(mockData.dashboardMetrics)
    )

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(mockApi.dashboard.getMetrics).toHaveBeenCalledTimes(1)
    })

    const refreshButton = screen.getByTestId('refresh-button')
    await user.click(refreshButton)

    await waitFor(() => {
      expect(mockApi.dashboard.getMetrics).toHaveBeenCalledTimes(2)
    })
  })

  it('handles network errors with retry option', async () => {
    const user = userEvent.setup()
    
    mockApi.dashboard.getMetrics
      .mockRejectedValueOnce(new Error('Network error'))
      .mockResolvedValueOnce(mockApiResponses.success(mockData.dashboardMetrics))

    renderWithProvider(<DashboardOverview />)

    await waitFor(() => {
      expect(screen.getByTestId('error-message')).toBeInTheDocument()
    })

    const retryButton = screen.getByText('Retry')
    await user.click(retryButton)

    await waitFor(() => {
      expect(screen.getByText('Dashboard Overview')).toBeInTheDocument()
      expect(screen.queryByTestId('error-message')).not.toBeInTheDocument()
    })
  })
})