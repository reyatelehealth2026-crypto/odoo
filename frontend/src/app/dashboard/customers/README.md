# Customer Management Frontend

Complete customer management interface for the Odoo Dashboard modernization project.

## Overview

This module provides a comprehensive customer management system with search, filtering, profile viewing, and LINE account linking capabilities.

## Features

### 1. Customer Search & Filtering (FR-3.1)
- **Full-text search**: Search by name, phone, email, or member ID
- **Advanced filters**:
  - Membership tier (Bronze, Silver, Gold, Platinum)
  - LINE connection status
  - Date range (customer registration)
- **Real-time search**: Instant results as you type
- **Responsive design**: Works on mobile, tablet, and desktop

### 2. Customer Profile View (FR-3.2)
- **Comprehensive profile display**:
  - Basic information (name, contact, tier, points)
  - Address details
  - LINE connection status
  - Customer notes
- **Order history**:
  - Paginated order list
  - Order status and amounts
  - Order dates
- **Medical information**:
  - Blood type
  - Medical conditions
  - Drug allergies
  - Current medications
  - Emergency contact
- **Statistics cards**:
  - Total orders
  - Total spent
  - Available points

### 3. LINE Account Linking (FR-3.3)
- **Connection management**:
  - Link LINE User ID to customer
  - Disconnect LINE account
  - View connection status
- **User-friendly interface**:
  - Step-by-step instructions
  - Validation and error handling
  - Confirmation dialogs
- **Audit trail**: All changes are logged

### 4. Customer Statistics Dashboard
- **Key metrics**:
  - Total customers
  - New customers (30 days)
  - Active customers
  - LINE connected customers
  - Average order value
- **Real-time updates**: Statistics refresh automatically

## Components

### CustomerSearch
Location: `frontend/src/components/customers/CustomerSearch.tsx`

Search and filter interface for customers.

**Props:**
- `onSearch: (filters: CustomerFilters) => void` - Callback when search is performed
- `loading?: boolean` - Loading state

**Features:**
- Main search bar with placeholder text
- Advanced filters toggle
- Filter by tier, LINE connection, date range
- Reset filters button

### CustomerProfile
Location: `frontend/src/components/customers/CustomerProfile.tsx`

Modal component displaying full customer profile with tabs.

**Props:**
- `customer: Customer` - Customer data to display
- `onClose: () => void` - Callback when modal is closed
- `onLineConnectionUpdate?: (customerId: string, lineUserId: string | null) => void` - Callback when LINE connection is updated

**Features:**
- Three tabs: Profile, Orders, Medical
- Order history with pagination
- Formatted currency and dates
- Tier badges with colors
- Status indicators

### LineAccountLink
Location: `frontend/src/components/customers/LineAccountLink.tsx`

Modal component for managing LINE account connections.

**Props:**
- `customer: Customer` - Customer to link/unlink
- `onUpdate: (updatedCustomer: Customer) => void` - Callback when connection is updated
- `onCancel: () => void` - Callback when modal is cancelled

**Features:**
- Current connection status display
- LINE User ID input with validation
- Disconnect button for linked accounts
- Help text with instructions
- Error handling and loading states

### CustomersPage
Location: `frontend/src/app/dashboard/customers/page.tsx`

Main customer management page with list and modals.

**Features:**
- Statistics dashboard
- Customer search interface
- Paginated customer table
- Click to view profile
- Quick LINE management button
- Responsive table design
- Error handling

## API Integration

### API Client
Location: `frontend/src/lib/api/customers.ts`

**Methods:**
- `searchCustomers(params)` - Search customers with filters
- `getCustomerById(customerId)` - Get full customer profile
- `getCustomerOrders(customerId, page, limit)` - Get customer order history
- `updateLineConnection(customerId, update)` - Update LINE connection
- `getCustomerStatistics(dateFrom, dateTo)` - Get customer statistics
- `getRecentCustomers(limit)` - Get recent customers
- `getCustomersByTier(tier, page, limit)` - Get customers by tier
- `getLineConnectedCustomers(page, limit)` - Get LINE connected customers

### React Query Hooks
Location: `frontend/src/hooks/useCustomers.ts`

**Hooks:**
- `useCustomers(params)` - Query customers with filters
- `useCustomer(customerId)` - Query single customer
- `useCustomerOrders(customerId, page, limit)` - Query customer orders
- `useCustomerStatistics(dateFrom, dateTo)` - Query statistics
- `useUpdateLineConnection()` - Mutation for LINE connection
- `useRecentCustomers(limit)` - Query recent customers
- `useCustomersByTier(tier, page, limit)` - Query by tier
- `useLineConnectedCustomers(page, limit)` - Query LINE connected

**Features:**
- Automatic caching (30-60 seconds)
- Optimistic updates for LINE connection
- Automatic refetching
- Error handling
- Loading states

## Data Types

Location: `frontend/src/types/customers.ts`

**Main Types:**
- `Customer` - Full customer profile
- `CustomerListItem` - Customer in list view
- `CustomerFilters` - Search filter parameters
- `PaginatedCustomers` - Paginated customer results
- `CustomerStatistics` - Statistics data
- `CustomerOrder` - Order in customer history
- `PaginatedCustomerOrders` - Paginated orders
- `LineConnectionUpdate` - LINE connection update payload

## Usage Examples

### Basic Customer Search
```typescript
import { useCustomers } from '@/hooks/useCustomers';

const { data, isLoading, error } = useCustomers({
  search: 'john',
  page: 1,
  limit: 20,
});
```

### Get Customer Profile
```typescript
import { useCustomer } from '@/hooks/useCustomers';

const { data: customer, isLoading } = useCustomer(customerId);
```

### Update LINE Connection
```typescript
import { useUpdateLineConnection } from '@/hooks/useCustomers';

const mutation = useUpdateLineConnection();

mutation.mutate({
  customerId: '123',
  update: { lineUserId: 'U1234567890abcdef' },
});
```

### Filter by Tier
```typescript
const { data } = useCustomers({
  tier: 'gold',
  page: 1,
  limit: 20,
});
```

### Get Statistics
```typescript
import { useCustomerStatistics } from '@/hooks/useCustomers';

const { data: stats } = useCustomerStatistics();
```

## Styling

The components use Tailwind CSS with a consistent design system:

**Colors:**
- Primary: Blue (600, 700)
- Success: Green (600, 100)
- Warning: Yellow (100, 800)
- Error: Red (50, 200, 600, 800)
- Gray scale: 50-900

**Tier Colors:**
- Platinum: Purple (100, 800)
- Gold: Yellow (100, 800)
- Silver: Gray (100, 800)
- Bronze: Orange (100, 800)

**Components:**
- Rounded corners: `rounded-lg`
- Shadows: `shadow`, `shadow-xl`
- Transitions: `transition-colors`
- Hover states: `hover:bg-*`

## Responsive Design

**Breakpoints:**
- Mobile: Default (< 768px)
- Tablet: `md:` (≥ 768px)
- Desktop: `lg:` (≥ 1024px)

**Responsive Features:**
- Statistics grid: 1 column → 2 columns → 5 columns
- Search filters: Stacked → Grid layout
- Table: Horizontal scroll on mobile
- Modals: Full screen on mobile, centered on desktop

## Performance Optimizations

1. **React Query Caching**:
   - 30-60 second stale time
   - Automatic background refetching
   - Optimistic updates for mutations

2. **Pagination**:
   - Default 20 items per page
   - Server-side pagination
   - Efficient data loading

3. **Lazy Loading**:
   - Customer details loaded on demand
   - Order history loaded when tab is opened
   - Statistics loaded separately

4. **Optimistic Updates**:
   - LINE connection updates immediately
   - Rollback on error
   - Automatic refetch for consistency

## Error Handling

1. **API Errors**:
   - Display error messages to user
   - Retry failed requests
   - Graceful degradation

2. **Validation**:
   - Client-side validation
   - Server-side validation
   - Clear error messages

3. **Loading States**:
   - Skeleton screens
   - Loading spinners
   - Disabled buttons during operations

## Accessibility

1. **Keyboard Navigation**:
   - Tab through form fields
   - Enter to submit
   - Escape to close modals

2. **Screen Readers**:
   - Semantic HTML
   - ARIA labels
   - Alt text for icons

3. **Color Contrast**:
   - WCAG AA compliant
   - Clear text on backgrounds
   - Status indicators with icons

## Testing

### Unit Tests
- Component rendering
- User interactions
- API integration
- Error handling

### Integration Tests
- Search and filter flow
- Profile viewing
- LINE connection management
- Pagination

### E2E Tests
- Complete customer management workflow
- Search → View → Update flow
- Error scenarios

## Future Enhancements

1. **Bulk Operations**:
   - Select multiple customers
   - Bulk LINE connection
   - Bulk export

2. **Advanced Analytics**:
   - Customer lifetime value
   - Churn prediction
   - Segmentation

3. **Export Features**:
   - CSV export
   - PDF reports
   - Excel export

4. **Customer Communication**:
   - Send LINE messages
   - Email notifications
   - SMS alerts

## Related Documentation

- [API Reference](../../../backend/src/routes/customers.ts)
- [Service Layer](../../../backend/src/services/CustomerService.ts)
- [Database Schema](../../../database/migration_*.sql)
- [Requirements](../../../../.kiro/specs/odoo-dashboard-modernization/requirements.md)
- [Design Document](../../../../.kiro/specs/odoo-dashboard-modernization/design.md)
