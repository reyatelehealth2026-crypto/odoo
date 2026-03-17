# Testing Framework Documentation

## Overview

This testing framework provides comprehensive testing capabilities for the Odoo Dashboard Modernization project, including unit tests, integration tests, and property-based testing.

## Framework Components

### 1. Unit Testing (Vitest)
- **Location**: `src/test/**/*.test.ts`
- **Purpose**: Test individual functions and classes in isolation
- **Coverage**: Business logic, utilities, and service classes

### 2. Property-Based Testing (fast-check)
- **Location**: `src/test/utils/propertyTesting.ts`
- **Purpose**: Test universal properties with generated test cases
- **Coverage**: Algorithm correctness, edge cases, and invariants

### 3. Integration Testing
- **Location**: `src/test/integration/**/*.test.ts`
- **Purpose**: Test component interactions and API endpoints
- **Coverage**: Database operations, external service integrations

## Running Tests

### Basic Commands

```bash
# Run all tests
npm test

# Run tests in watch mode
npm run test:watch

# Run tests with coverage
npm run test:coverage

# Run specific test file
npm test -- DashboardMetricsService.test.ts

# Run tests matching pattern
npm test -- --grep "authentication"
```

### Advanced Commands

```bash
# Run only property-based tests
npm test -- --grep "Property:"

# Run performance tests
npm test -- --grep "Performance:"

# Run tests with verbose output
npm test -- --reporter=verbose

# Run tests in CI mode
npm run test:ci
```

## Test Structure

### Unit Test Example

```typescript
import { describe, it, expect, beforeEach } from 'vitest'
import { ServiceClass } from '@/services/ServiceClass'

describe('ServiceClass', () => {
  let service: ServiceClass

  beforeEach(() => {
    service = new ServiceClass()
  })

  it('should perform basic operation', () => {
    const result = service.basicOperation('input')
    expect(result).toBe('expected_output')
  })
})
```

### Property-Based Test Example

```typescript
import * as fc from 'fast-check'
import { arbitraries, propertyTestConfig } from '@test/utils/propertyTesting'

it('should maintain invariant for any input', async () => {
  await fc.assert(
    fc.property(
      arbitraries.amount(),
      (amount) => {
        const result = service.processAmount(amount)
        return result >= 0 // Invariant: result is always non-negative
      }
    ),
    propertyTestConfig
  )
})
```

## Test Categories

### 1. Business Logic Tests
- Dashboard metrics calculation
- Payment matching algorithms
- Authentication and authorization
- Data validation and transformation

### 2. Performance Tests
- Response time validation
- Memory usage monitoring
- Database query optimization
- Cache effectiveness

### 3. Security Tests
- Input validation
- Authentication token security
- Authorization checks
- Audit trail completeness

### 4. Integration Tests
- Database operations
- External API calls
- WebSocket connections
- File upload processing

## Test Data Management

### Factories
Use test data factories for consistent test data:

```typescript
import { createTestUser, createTestOrder } from '@test/setup'

const user = createTestUser({ role: 'admin' })
const order = createTestOrder({ status: 'pending', amount: 1000 })
```

### Arbitraries
Use arbitraries for property-based testing:

```typescript
import { arbitraries } from '@test/utils/propertyTesting'

fc.property(
  arbitraries.amount(),
  arbitraries.userRole(),
  (amount, role) => {
    // Test logic here
  }
)
```

## Database Testing

### Setup
Each test gets a clean database:

```typescript
beforeEach(async () => {
  // Database is automatically cleaned before each test
  // Create test data as needed
  await prisma.user.create({ data: createTestUser() })
})
```

### Transactions
Use transactions for complex test scenarios:

```typescript
it('should handle transaction rollback', async () => {
  await prisma.$transaction(async (tx) => {
    // Test operations within transaction
    await tx.order.create({ data: testOrder })
    // Transaction will rollback after test
  })
})
```

## Mocking

### External Services
Mock external services consistently:

```typescript
import { mockOdooService, mockLineAPI } from '@test/setup'

beforeEach(() => {
  mockOdooService.getOrders.mockResolvedValue([])
  mockLineAPI.sendMessage.mockResolvedValue({ success: true })
})
```

### Redis
Use the mock Redis client:

```typescript
import { mockRedis } from '@test/setup'

mockRedis.get.mockResolvedValue('cached_value')
mockRedis.set.mockResolvedValue('OK')
```

## Performance Testing

### Response Time Testing
```typescript
import { performanceHelpers } from '@test/config/testConfig'

it('should respond within performance threshold', async () => {
  const { duration } = await performanceHelpers.measureExecutionTime(
    () => service.expensiveOperation()
  )
  
  performanceHelpers.assertPerformance(
    duration, 
    300, // 300ms threshold
    'expensive operation'
  )
})
```

### Memory Usage Testing
```typescript
it('should not exceed memory threshold', () => {
  const { memoryDelta } = performanceHelpers.measureMemoryUsage(
    () => service.memoryIntensiveOperation()
  )
  
  expect(memoryDelta).toBeLessThan(50 * 1024 * 1024) // 50MB
})
```

## Coverage Requirements

### Minimum Coverage Thresholds
- **Lines**: 80%
- **Functions**: 80%
- **Branches**: 80%
- **Statements**: 80%

### Coverage Exclusions
- Test files themselves
- Configuration files
- Database migrations
- Generated code (Prisma client)

## Best Practices

### 1. Test Naming
- Use descriptive test names
- Follow pattern: "should [expected behavior] when [condition]"
- Group related tests in describe blocks

### 2. Test Independence
- Each test should be independent
- Use beforeEach for setup
- Don't rely on test execution order

### 3. Property-Based Testing
- Use for testing universal properties
- Generate 100+ test cases per property
- Focus on invariants and edge cases

### 4. Performance Testing
- Set realistic thresholds
- Test under various load conditions
- Monitor memory usage and response times

### 5. Error Testing
- Test both success and failure paths
- Verify error messages and codes
- Test edge cases and boundary conditions

## Debugging Tests

### Debug Single Test
```bash
# Run with debugger
npm test -- --inspect-brk DashboardMetricsService.test.ts

# Run with verbose output
npm test -- --reporter=verbose --grep "specific test"
```

### Debug Property-Based Tests
```bash
# Run with seed for reproducible results
npm test -- --grep "Property:" --seed=42
```

### Debug Performance Issues
```bash
# Run with performance profiling
npm test -- --reporter=verbose --grep "Performance:"
```

## Continuous Integration

### GitHub Actions
Tests run automatically on:
- Pull requests
- Pushes to main branch
- Scheduled daily runs

### Test Reports
- Coverage reports uploaded to Codecov
- Test results available in GitHub Actions
- Performance metrics tracked over time

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Ensure MySQL is running
   - Check test database configuration
   - Verify migration status

2. **Property Test Failures**
   - Check for non-deterministic behavior
   - Verify test assumptions
   - Use fixed seeds for debugging

3. **Performance Test Failures**
   - Check system load during tests
   - Adjust thresholds if needed
   - Profile slow operations

4. **Mock Issues**
   - Ensure mocks are reset between tests
   - Verify mock implementations
   - Check mock call expectations

### Getting Help

- Check test logs for detailed error messages
- Use `--verbose` flag for more information
- Review test setup and teardown code
- Consult team documentation or ask for help