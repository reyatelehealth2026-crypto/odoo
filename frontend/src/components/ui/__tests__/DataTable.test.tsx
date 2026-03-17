import React from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DataTable } from '../DataTable'
import { mockData, factories } from '@/test/utils/testUtils'

describe('DataTable', () => {
  const mockOrders = [
    factories.order({ id: '1', customerRef: 'CUST_001', status: 'pending', totalAmount: 1000 }),
    factories.order({ id: '2', customerRef: 'CUST_002', status: 'completed', totalAmount: 2000 }),
    factories.order({ id: '3', customerRef: 'CUST_003', status: 'processing', totalAmount: 1500 }),
  ]

  const columns = [
    {
      key: 'customerRef',
      title: 'Customer',
      sortable: true,
    },
    {
      key: 'status',
      title: 'Status',
      sortable: true,
      render: (value: string) => (
        <span className={`status-${value}`}>{value}</span>
      ),
    },
    {
      key: 'totalAmount',
      title: 'Amount',
      sortable: true,
      render: (value: number) => `฿${value.toLocaleString()}`,
    },
  ]

  const defaultProps = {
    data: mockOrders,
    columns,
    pagination: {
      page: 1,
      limit: 10,
      total: mockOrders.length,
      totalPages: 1,
    },
    onPageChange: vi.fn(),
    onSortChange: vi.fn(),
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders table with data correctly', () => {
    render(<DataTable {...defaultProps} />)

    // Check headers
    expect(screen.getByText('Customer')).toBeInTheDocument()
    expect(screen.getByText('Status')).toBeInTheDocument()
    expect(screen.getByText('Amount')).toBeInTheDocument()

    // Check data rows
    expect(screen.getByText('CUST_001')).toBeInTheDocument()
    expect(screen.getByText('CUST_002')).toBeInTheDocument()
    expect(screen.getByText('CUST_003')).toBeInTheDocument()

    // Check rendered values
    expect(screen.getByText('฿1,000')).toBeInTheDocument()
    expect(screen.getByText('฿2,000')).toBeInTheDocument()
    expect(screen.getByText('฿1,500')).toBeInTheDocument()
  })

  it('shows loading state correctly', () => {
    render(<DataTable {...defaultProps} loading={true} />)

    expect(screen.getByTestId('table-loading')).toBeInTheDocument()
    expect(screen.getAllByTestId('loading-row')).toHaveLength(5) // Default skeleton rows
  })

  it('shows empty state when no data', () => {
    render(<DataTable {...defaultProps} data={[]} />)

    expect(screen.getByTestId('empty-state')).toBeInTheDocument()
    expect(screen.getByText('No data available')).toBeInTheDocument()
  })

  it('handles sorting correctly', async () => {
    const user = userEvent.setup()
    
    render(<DataTable {...defaultProps} />)

    const customerHeader = screen.getByText('Customer')
    await user.click(customerHeader)

    expect(defaultProps.onSortChange).toHaveBeenCalledWith({
      field: 'customerRef',
      direction: 'asc',
    })

    // Click again for descending
    await user.click(customerHeader)

    expect(defaultProps.onSortChange).toHaveBeenCalledWith({
      field: 'customerRef',
      direction: 'desc',
    })
  })

  it('handles pagination correctly', async () => {
    const user = userEvent.setup()
    
    const propsWithPagination = {
      ...defaultProps,
      pagination: {
        page: 1,
        limit: 10,
        total: 25,
        totalPages: 3,
      },
    }

    render(<DataTable {...propsWithPagination} />)

    // Check pagination info
    expect(screen.getByText('Showing 1-10 of 25')).toBeInTheDocument()

    // Test next page
    const nextButton = screen.getByTestId('next-page')
    await user.click(nextButton)

    expect(defaultProps.onPageChange).toHaveBeenCalledWith(2)

    // Test specific page
    const page2Button = screen.getByText('2')
    await user.click(page2Button)

    expect(defaultProps.onPageChange).toHaveBeenCalledWith(2)
  })

  it('handles row selection correctly', async () => {
    const user = userEvent.setup()
    const onSelectionChange = vi.fn()
    
    render(
      <DataTable
        {...defaultProps}
        selectable={true}
        onSelectionChange={onSelectionChange}
      />
    )

    // Select first row
    const firstCheckbox = screen.getAllByRole('checkbox')[1] // Skip header checkbox
    await user.click(firstCheckbox)

    expect(onSelectionChange).toHaveBeenCalledWith(['1'])

    // Select all
    const selectAllCheckbox = screen.getAllByRole('checkbox')[0]
    await user.click(selectAllCheckbox)

    expect(onSelectionChange).toHaveBeenCalledWith(['1', '2', '3'])
  })

  it('handles row click events', async () => {
    const user = userEvent.setup()
    const onRowClick = vi.fn()
    
    render(<DataTable {...defaultProps} onRowClick={onRowClick} />)

    const firstRow = screen.getByTestId('table-row-1')
    await user.click(firstRow)

    expect(onRowClick).toHaveBeenCalledWith(mockOrders[0])
  })

  it('applies custom row classes correctly', () => {
    const getRowClassName = (row: any) => {
      return row.status === 'completed' ? 'row-completed' : 'row-default'
    }

    render(<DataTable {...defaultProps} getRowClassName={getRowClassName} />)

    const completedRow = screen.getByTestId('table-row-2')
    expect(completedRow).toHaveClass('row-completed')

    const pendingRow = screen.getByTestId('table-row-1')
    expect(pendingRow).toHaveClass('row-default')
  })

  it('handles search functionality', async () => {
    const user = userEvent.setup()
    const onSearch = vi.fn()
    
    render(
      <DataTable
        {...defaultProps}
        searchable={true}
        onSearch={onSearch}
        searchPlaceholder="Search orders..."
      />
    )

    const searchInput = screen.getByPlaceholderText('Search orders...')
    await user.type(searchInput, 'CUST_001')

    // Debounced search should be called
    await waitFor(() => {
      expect(onSearch).toHaveBeenCalledWith('CUST_001')
    }, { timeout: 1000 })
  })

  it('handles filters correctly', async () => {
    const user = userEvent.setup()
    const onFilterChange = vi.fn()
    
    const filters = [
      {
        key: 'status',
        label: 'Status',
        type: 'select' as const,
        options: [
          { value: 'pending', label: 'Pending' },
          { value: 'completed', label: 'Completed' },
          { value: 'processing', label: 'Processing' },
        ],
      },
    ]

    render(
      <DataTable
        {...defaultProps}
        filters={filters}
        onFilterChange={onFilterChange}
      />
    )

    const statusFilter = screen.getByTestId('filter-status')
    await user.selectOptions(statusFilter, 'completed')

    expect(onFilterChange).toHaveBeenCalledWith({
      status: 'completed',
    })
  })

  it('handles bulk actions correctly', async () => {
    const user = userEvent.setup()
    const onBulkAction = vi.fn()
    
    const bulkActions = [
      {
        key: 'delete',
        label: 'Delete Selected',
        icon: '🗑️',
        variant: 'danger' as const,
      },
      {
        key: 'export',
        label: 'Export Selected',
        icon: '📤',
        variant: 'primary' as const,
      },
    ]

    render(
      <DataTable
        {...defaultProps}
        selectable={true}
        bulkActions={bulkActions}
        onBulkAction={onBulkAction}
        selectedRows={['1', '2']}
      />
    )

    const bulkActionButton = screen.getByTestId('bulk-actions-trigger')
    await user.click(bulkActionButton)

    const deleteAction = screen.getByText('Delete Selected')
    await user.click(deleteAction)

    expect(onBulkAction).toHaveBeenCalledWith('delete', ['1', '2'])
  })

  it('handles column visibility correctly', async () => {
    const user = userEvent.setup()
    
    render(
      <DataTable
        {...defaultProps}
        columnVisibility={true}
        hiddenColumns={['status']}
      />
    )

    // Status column should be hidden
    expect(screen.queryByText('Status')).not.toBeInTheDocument()
    expect(screen.getByText('Customer')).toBeInTheDocument()
    expect(screen.getByText('Amount')).toBeInTheDocument()

    // Open column visibility menu
    const columnVisibilityButton = screen.getByTestId('column-visibility')
    await user.click(columnVisibilityButton)

    // Show status column
    const statusToggle = screen.getByTestId('toggle-status')
    await user.click(statusToggle)

    expect(screen.getByText('Status')).toBeInTheDocument()
  })

  it('handles responsive design correctly', () => {
    // Mock window.innerWidth
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 768, // Mobile width
    })

    render(<DataTable {...defaultProps} responsive={true} />)

    // Should render mobile-friendly layout
    expect(screen.getByTestId('mobile-table')).toBeInTheDocument()
  })

  it('handles export functionality', async () => {
    const user = userEvent.setup()
    const onExport = vi.fn()
    
    render(
      <DataTable
        {...defaultProps}
        exportable={true}
        onExport={onExport}
      />
    )

    const exportButton = screen.getByTestId('export-button')
    await user.click(exportButton)

    const csvExport = screen.getByText('Export as CSV')
    await user.click(csvExport)

    expect(onExport).toHaveBeenCalledWith('csv', mockOrders)
  })
})