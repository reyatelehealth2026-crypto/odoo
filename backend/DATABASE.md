# Database Schema and Migration Setup

This document describes the database schema and migration setup for the Odoo Dashboard modernization project.

## Overview

The modernized system uses Prisma ORM with MySQL to provide type-safe database access while maintaining compatibility with the existing database schema. The setup includes:

- **Performance optimization tables** for caching and metrics
- **Enhanced audit logging** for compliance and security
- **Proper indexing** for optimal query performance
- **Migration validation** to ensure data integrity

## Schema Structure

### Core Tables

#### Users and Authentication
- `users` - User accounts with role-based access control
- `user_sessions` - JWT session management with refresh tokens
- `audit_logs` - Comprehensive audit trail for all operations

#### LINE Integration
- `line_accounts` - Multi-account LINE Official Account configuration
- `account_followers` - LINE follower tracking and analytics
- `account_events` - LINE webhook event logging
- `account_daily_stats` - Daily statistics aggregation

#### Odoo Integration
- `odoo_orders` - Cached order data from Odoo ERP
- `odoo_slip_uploads` - Payment slip processing
- `odoo_webhooks_log` - Webhook event tracking
- `odoo_bdos` - Bill delivery order management

#### Performance Optimization
- `dashboard_metrics_cache` - Aggregated dashboard metrics with TTL
- `api_cache` - General API response caching

## Key Features

### 1. Performance Optimization (Requirements: BR-1.4, NFR-1.4)

**Dashboard Metrics Cache:**
```sql
CREATE TABLE dashboard_metrics_cache (
  id VARCHAR(191) PRIMARY KEY,
  line_account_id VARCHAR(191) NOT NULL,
  metric_type ENUM('ORDERS', 'PAYMENTS', 'WEBHOOKS', 'CUSTOMERS'),
  date_key DATE NOT NULL,
  data JSON NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL,
  
  UNIQUE KEY (line_account_id, metric_type, date_key),
  INDEX idx_dashboard_metrics_lookup (line_account_id, metric_type, date_key),
  INDEX idx_expires (expires_at)
);
```

**API Response Cache:**
```sql
CREATE TABLE api_cache (
  cache_key VARCHAR(191) PRIMARY KEY,
  data JSON NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  
  INDEX idx_api_cache_expiry (expires_at)
);
```

### 2. Enhanced Audit Logging (Requirements: BR-5.4, NFR-3.4)

**Comprehensive Audit Trail:**
```sql
CREATE TABLE audit_logs (
  id VARCHAR(191) PRIMARY KEY,
  user_id VARCHAR(191) NOT NULL,
  action VARCHAR(191) NOT NULL,
  resource_type VARCHAR(191) NOT NULL,
  resource_id VARCHAR(191),
  old_values JSON,
  new_values JSON,
  ip_address VARCHAR(191),
  user_agent TEXT,
  created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  
  INDEX idx_audit_logs_user_action (user_id, action),
  INDEX idx_audit_logs_resource (resource_type, resource_id),
  INDEX idx_audit_logs_created (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

**JWT Session Management:**
```sql
CREATE TABLE user_sessions (
  id VARCHAR(191) PRIMARY KEY,
  user_id VARCHAR(191) NOT NULL,
  token_hash VARCHAR(191) UNIQUE NOT NULL,
  refresh_token_hash VARCHAR(191) UNIQUE NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  last_activity DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  ip_address VARCHAR(191),
  user_agent TEXT,
  is_active BOOLEAN DEFAULT true,
  created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  
  INDEX idx_user_sessions_user (user_id),
  INDEX idx_user_sessions_token (token_hash),
  INDEX idx_user_sessions_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 3. Optimized Indexing Strategy

**Performance Indexes:**
- `idx_dashboard_metrics_lookup` - Fast dashboard metric retrieval
- `idx_api_cache_expiry` - Efficient cache cleanup
- `idx_audit_logs_user_action` - Quick audit trail queries
- `idx_user_sessions_expires` - Session cleanup optimization

**Multi-column Indexes:**
- `(line_account_id, metric_type, date_key)` - Dashboard metrics lookup
- `(user_id, action)` - User action auditing
- `(resource_type, resource_id)` - Resource-based audit queries

## Migration Process

### 1. Initial Setup

```bash
# Install dependencies
npm install

# Generate Prisma client
npm run prisma:generate

# Run migrations (when database is available)
npm run prisma:migrate

# Seed initial data
npm run prisma:seed
```

### 2. Migration Validation

The system includes comprehensive migration validation:

```bash
# Run migration validation tests
npm run prisma:validate
```

**Validation Tests:**
- ✅ Data integrity verification
- ✅ Foreign key relationship testing
- ✅ Index performance validation
- ✅ Cache table structure verification

### 3. Development Workflow

```bash
# Reset database and reseed
npm run db:reset

# Open Prisma Studio for data inspection
npm run prisma:studio

# Generate client after schema changes
npm run prisma:generate
```

## Environment Configuration

Create a `.env` file in the backend directory:

```env
# Database Configuration
DATABASE_URL="mysql://username:password@localhost:3306/telepharmacy"

# JWT Configuration
JWT_SECRET="your-secure-jwt-secret-key"
JWT_EXPIRES_IN="15m"
JWT_REFRESH_EXPIRES_IN="7d"

# Redis Configuration
REDIS_URL="redis://localhost:6379"

# Application Configuration
NODE_ENV="development"
PORT=4000
```

## Data Migration Strategy

### Phase 1: Schema Preparation
- ✅ Add new performance optimization tables
- ✅ Create enhanced audit logging tables
- ✅ Set up proper indexes and constraints

### Phase 2: Data Transformation
- Migrate existing user data to new format
- Populate cache tables with historical data
- Validate data integrity and consistency

### Phase 3: Cleanup
- Remove deprecated columns after validation
- Optimize existing indexes based on new patterns
- Archive old data according to retention policies

## Performance Considerations

### Caching Strategy
- **Level 1:** Application cache (5-15 minutes TTL)
- **Level 2:** Redis distributed cache (30 minutes - 24 hours TTL)
- **Level 3:** Database query cache with automatic invalidation

### Query Optimization
- Use connection pooling for optimal performance
- Implement read replicas for query optimization
- Materialized views for complex dashboard aggregations

### Monitoring
- Track query performance with APM tools
- Monitor cache hit rates (target: >85%)
- Alert on slow queries (>300ms threshold)

## Security Features

### Data Protection
- Encrypted sensitive data at rest
- Parameterized queries prevent SQL injection
- Role-based access control (RBAC)
- Comprehensive audit logging

### Session Management
- JWT tokens with refresh rotation
- Session invalidation on logout
- IP address and user agent tracking
- Automatic session cleanup

## Maintenance

### Regular Tasks
- Clean up expired cache entries
- Archive old audit logs (retention: 1 year)
- Monitor and optimize slow queries
- Update indexes based on query patterns

### Backup Strategy
- Daily automated backups
- Point-in-time recovery capability
- Cross-region backup replication
- Regular restore testing

## Troubleshooting

### Common Issues

**Migration Fails:**
```bash
# Reset and retry
npm run db:reset
npm run prisma:migrate
```

**Performance Issues:**
```bash
# Check index usage
npm run prisma:validate
# Review slow query logs
# Consider adding new indexes
```

**Cache Issues:**
```bash
# Clear expired cache entries
# Check Redis connection
# Verify cache TTL settings
```

## Support

For database-related issues:
1. Check the migration validation results
2. Review the audit logs for data integrity
3. Monitor performance metrics
4. Contact the development team with specific error messages

---

**Requirements Satisfied:**
- ✅ TC-1.2: Preserve existing database schema during transition
- ✅ TC-3.2: Set up proper database migration system
- ✅ BR-1.4: Support performance optimization with caching
- ✅ NFR-1.4: Implement multi-layer caching strategy
- ✅ BR-5.4: Comprehensive audit logging for compliance
- ✅ NFR-3.4: Enhanced security with session management