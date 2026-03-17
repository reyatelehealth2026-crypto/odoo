import React from 'react'
import { render, screen } from '@testing-library/react'
import { KPICard } from '../KPICard'

describe('KPICard', () => {
  const defaultProps = {
    title: 'Total Orders',
    value: 1250,
    format: 'number' as const,
  }

  it('renders title and value correctly', () => {
    render(<KPICard {...defaultProps} />)
    
    expect(screen.getByText('Total Orders')).toBeInTheDocument()
    expect(screen.getByText('1,250')).toBeInTheDocument()
  })

  it('formats currency values correctly', () => {
    render(
      <KPICard
        title="Total Revenue"
        value={125000.50}
        format="currency"
      />
    )
    
    expect(screen.getByText('Total Revenue')).toBeInTheDocument()
    expect(screen.getByText('฿125,000.50')).toBeInTheDocument()
  })

  it('formats percentage values correctly', () => {
    render(
      <KPICard
        title="Success Rate"
        value={0.95}
        format="percentage"
      />
    )
    
    expect(screen.getByText('Success Rate')).toBeInTheDocument()
    expect(screen.getByText('95%')).toBeInTheDocument()
  })

  it('displays trend indicator when provided', () => {
    const trend = {
      direction: 'up' as const,
      percentage: 12.5,
      period: 'vs last month',
    }

    render(
      <KPICard
        {...defaultProps}
        trend={trend}
      />
    )
    
    expect(screen.getByText('↗')).toBeInTheDocument()
    expect(screen.getByText('12.5%')).toBeInTheDocument()
    expect(screen.getByText('vs last month')).toBeInTheDocument()
  })

  it('shows loading state correctly', () => {
    render(
      <KPICard
        {...defaultProps}
        loading={true}
      />
    )
    
    expect(screen.getByTestId('loading-skeleton')).toBeInTheDocument()
    expect(screen.queryByText('Total Orders')).not.toBeInTheDocument()
  })

  it('handles zero values correctly', () => {
    render(
      <KPICard
        title="Pending Orders"
        value={0}
        format="number"
      />
    )
    
    expect(screen.getByText('Pending Orders')).toBeInTheDocument()
    expect(screen.getByText('0')).toBeInTheDocument()
  })

  it('handles negative values correctly', () => {
    render(
      <KPICard
        title="Net Change"
        value={-500}
        format="currency"
      />
    )
    
    expect(screen.getByText('Net Change')).toBeInTheDocument()
    expect(screen.getByText('-฿500.00')).toBeInTheDocument()
  })

  it('applies correct CSS classes for trend direction', () => {
    const { rerender } = render(
      <KPICard
        {...defaultProps}
        trend={{ direction: 'up', percentage: 10, period: 'test' }}
      />
    )
    
    expect(screen.getByTestId('trend-indicator')).toHaveClass('text-green-600')
    
    rerender(
      <KPICard
        {...defaultProps}
        trend={{ direction: 'down', percentage: 10, period: 'test' }}
      />
    )
    
    expect(screen.getByTestId('trend-indicator')).toHaveClass('text-red-600')
    
    rerender(
      <KPICard
        {...defaultProps}
        trend={{ direction: 'neutral', percentage: 0, period: 'test' }}
      />
    )
    
    expect(screen.getByTestId('trend-indicator')).toHaveClass('text-gray-600')
  })

  it('handles large numbers with proper formatting', () => {
    render(
      <KPICard
        title="Large Number"
        value={1234567890}
        format="number"
      />
    )
    
    expect(screen.getByText('1,234,567,890')).toBeInTheDocument()
  })

  it('handles decimal numbers correctly', () => {
    render(
      <KPICard
        title="Average Value"
        value={1234.56789}
        format="currency"
      />
    )
    
    expect(screen.getByText('฿1,234.57')).toBeInTheDocument()
  })
})