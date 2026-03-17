# Odoo Dashboard Migration System

Complete migration infrastructure for transitioning from legacy PHP/JavaScript system to modern Next.js + Node.js architecture with zero-downtime deployment.

## Overview

This migration system implements a comprehensive 7-phase gradual rollout strategy with:
- **Feature flag-controlled traffic routing**
- **Real-time data synchronization**
- **Parallel deployment infrastructure**
- **Comprehensive monitoring and alerting**
- **Emergency rollback capabilities**
- **Data integrity validation**

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Legacy PHP    │    │  Load Balancer  │    │  Modern Node.js │
│     System      │◄──►│   (Traefik)     │◄──►│     System      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         │              ┌─────────────────┐              │
         │              │ Feature Flags   │              │
         │              │   & Routing     │              │
         │              └─────────────────┘              │
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                    ┌─────────────────┐
                    │ Data Sync       │
                    │ Service         │
                    └─────────────────┘
                                 │
                    ┌─────────────────┐
                    │ MySQL Database  │
                    │ Redis Cache     │
                    └─────────────────┘
```

## Migration Phases

### Phase 1: Parallel System Monitoring (Week 1-2)
- Deploy modern system alongside legacy
- Initialize feature flags (5% dashboard rollout)
- 48-hour stability monitoring
- Data synchronization setup

### Phase 2: Dashboard Rollout (Week 3)
- Gradual increase: 5% → 10% → 15% → 20% → 25%
- 6-hour monitoring between increases
- Automatic rollback on instability

### Phase 3: Order Management Enablement (Week 4)
- Enable for admin users (5%)
- Increase to 15% after validation
- 24-hour monitoring periods

### Phase 4: Payment Processing Rollout (Week 5)
- Conservative rollout (2% → 10%)
- Extended monitoring for financial features
- 48-hour validation periods

### Phase 5: Full Feature Rollout (Week 6)
- Enable all remaining features
- Increase to 50% across all features
- 36-hour comprehensive monitoring

### Phase 6: Complete Migration (Week 7)
- Final rollout: 60% → 75% → 90% → 100%
- Legacy system decommission
- Production configuration update

## Directory Structure

```
migration/
├── scripts/                    # Migration execution scripts
│   ├── gradual-migration-plan.sh      # Master migration orchestrator
│   ├── phase-execution.sh             # Individual phase execution
│   ├── setup-traffic-routing.sh       # Load balancer configuration
│   ├── complete-migration.sh          # Final migration steps
│   ├── master-migration.sql           # Database migration orchestrator
│   ├── migrate-user-sessions.sql      # User session migration
│   ├── migrate-audit-logs.sql         # Audit log migration
│   ├── populate-performance-cache.sql # Cache population
│   ├── validate-data-integrity.sql    # Data validation
│   └── initialize-feature-flags.sql   # Feature flag setup
├── services/                   # Migration services
│   ├── DataSyncService.ts             # Real-time data synchronization
│   └── migration-monitor.js           # Monitoring dashboard
├── dashboard/                  # Monitoring web interface
│   └── index.html                     # Migration dashboard UI
└── README.md                   # This file
```

## Prerequisites

### System Requirements
- Docker & Docker Compose
- Node.js 18+
- MySQL 8.0+
- Redis 7+
- Nginx (for load balancing)

### Environment Variables
```bash
# Database Configuration
MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_USER=your_user
MYSQL_PASSWORD=your_password
MYSQL_DATABASE=telepharmacy

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379

# Application Configuration
JWT_SECRET=your_jwt_secret
ODOO_API_URL=https://your-odoo-instance.com

# Migration Configuration
LEGACY_SYSTEM_URL=http://legacy-web:80
MODERN_SYSTEM_URL=http://modern-backend:4000
```

## Quick Start

### 1. Initialize Migration Infrastructure

```bash
# Clone and setup
cd migration/scripts

# Make scripts executable (Linux/Mac)
chmod +x *.sh

# Initialize database and feature flags
mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE < initialize-feature-flags.sql

# Setup traffic routing
./setup-traffic-routing.sh
```

### 2. Execute Migration Plan

```bash
# Start complete migration process
./gradual-migration-plan.sh

# Or execute individual phases
./phase-execution.sh 1  # Phase 1: Parallel deployment
./phase-execution.sh 2  # Phase 2: Dashboard rollout
# ... continue through Phase 6
```

### 3. Monitor Progress

```bash
# Start monitoring dashboard
node migration-monitor.js

# Access dashboard
open http://localhost:9090
```

### 4. Complete Migration

```bash
# Final migration steps
./complete-migration.sh
```

## Feature Flag System

### Core Feature Flags

| Flag Name | Description | Initial % | Target % |
|-----------|-------------|-----------|----------|
| `useNewDashboard` | Modern dashboard system | 5% | 100% |
| `useNewOrderManagement` | Modern order management | 0% | 100% |
| `useNewPaymentProcessing` | Modern payment processing | 0% | 100% |
| `useNewWebhookManagement` | Modern webhook system | 0% | 100% |
| `useNewCustomerManagement` | Modern customer system | 0% | 100% |

### API Endpoints

```bash
# Get user feature flags
GET /api/feature-flags?userId=123&userRole=admin&lineAccountId=456

# Update rollout percentage
PUT /api/feature-flags/rollout
{
  "flagName": "useNewDashboard",
  "percentage": 25
}

# Get routing metrics
GET /api/feature-flags/metrics

# Health check
GET /api/feature-flags/health
```

## Data Migration

### User Sessions
- Migrates active sessions from legacy system
- Generates new JWT tokens with refresh capability
- Extends admin session duration to 30 days
- Validates session integrity

### Audit Logs
- Migrates last 12 months of audit data
- Transforms legacy format to new schema
- Validates JSON data integrity
- Creates performance indexes

### Performance Cache
- Pre-populates dashboard metrics for 90 days
- Creates order, payment, webhook, and customer metrics
- Optimizes query performance
- Validates cache effectiveness

## Monitoring & Alerting

### Real-time Monitoring
- System health checks every 30 seconds
- Service availability monitoring
- Error rate tracking
- Performance metrics collection

### Alert Types
- **Critical**: Database disconnection, emergency rollback
- **High**: Service unhealthy, high error rate
- **Medium**: Rollout stalled, performance degradation
- **Low**: Configuration changes, routine maintenance

### Monitoring Dashboard
Access the monitoring dashboard at `http://localhost:9090` for:
- Real-time system metrics
- Feature flag status
- Migration progress
- Alert management
- Emergency controls

## Emergency Procedures

### Automatic Rollback Triggers
- Error rate > 5%
- Service unavailability > 5 minutes
- Database connectivity issues
- Critical system failures

### Manual Emergency Rollback
```bash
# Via monitoring dashboard
POST /api/emergency/rollback
{
  "reason": "Critical issue description"
}

# Via command line
curl -X POST http://localhost:9090/api/emergency/rollback \
  -H "Content-Type: application/json" \
  -d '{"reason": "Manual rollback requested"}'
```

### Rollback Process
1. Set all feature flags to 0%
2. Route 100% traffic to legacy system
3. Restart legacy containers if needed
4. Validate legacy system health
5. Generate incident report

## Validation & Testing

### Data Integrity Checks
- Session count consistency (95% threshold)
- Audit log completeness
- Cache data validity
- Foreign key integrity
- Performance index existence

### System Health Validation
- Service endpoint accessibility
- Database connectivity
- Redis connectivity
- WebSocket functionality
- Load balancer routing

### Performance Validation
- Response time compliance (<300ms)
- Error rate maintenance (<3%)
- Cache effectiveness (>85% hit rate)
- Throughput capacity (100 concurrent users)

## Troubleshooting

### Common Issues

#### Migration Fails at Phase X
```bash
# Check system health
curl http://localhost:9090/api/metrics

# Review phase logs
tail -f logs/migration/phase-execution.log

# Check service status
docker-compose -f docker/docker-compose.migration.yml ps
```

#### Feature Flags Not Working
```bash
# Verify feature flag service
curl http://localhost/api/feature-flags/health

# Check database connection
mysql -u $MYSQL_USER -p$MYSQL_PASSWORD -e "SELECT * FROM feature_flags" $MYSQL_DATABASE

# Restart feature flag bridge
docker-compose restart legacy-web
```

#### Data Sync Issues
```bash
# Check sync service logs
docker-compose -f docker/docker-compose.migration.yml logs data-sync

# Verify sync status
curl http://localhost:9090/api/metrics | jq '.services.dataSync'

# Restart sync service
docker-compose -f docker/docker-compose.migration.yml restart data-sync
```

#### High Error Rates
```bash
# Check service health
curl http://localhost:4000/api/v1/health
curl http://localhost:8080/api/health.php

# Review error logs
docker-compose logs modern-backend
docker-compose logs legacy-web

# Check circuit breaker status
curl http://localhost:8080/metrics | grep circuit_breaker
```

### Log Locations
- **Migration Logs**: `logs/migration/`
- **Application Logs**: Docker container logs
- **Database Logs**: MySQL error logs
- **Web Server Logs**: Nginx access/error logs

## Performance Optimization

### Database Optimization
- Connection pooling configured
- Query optimization indexes
- Materialized views for complex queries
- Cache warming for frequently accessed data

### Caching Strategy
- **Level 1**: Application memory cache (5-15 min TTL)
- **Level 2**: Redis distributed cache (30 min - 24 hour TTL)
- **Level 3**: Database query cache (automatic invalidation)

### Load Balancing
- Weighted routing based on feature flags
- Health check-based failover
- Circuit breaker protection
- Rate limiting and DDoS protection

## Security Considerations

### Authentication & Authorization
- JWT tokens with refresh rotation
- Role-based access control (RBAC)
- Session management and blacklisting
- Multi-factor authentication support

### Data Protection
- Encryption at rest and in transit
- Input validation and sanitization
- SQL injection prevention
- XSS protection headers

### Network Security
- TLS 1.2+ with modern cipher suites
- CORS configuration
- Rate limiting per IP
- Security headers (HSTS, CSP, etc.)

## Backup & Recovery

### Automated Backups
- Daily database backups with compression
- Configuration file versioning
- Application code in version control
- Infrastructure as code

### Recovery Procedures
- Point-in-time database recovery
- Configuration rollback capability
- Blue-green deployment support
- Disaster recovery testing

## Support & Maintenance

### Team Contacts
- **Development Team**: dev-team@company.com
- **Operations Team**: ops-team@company.com
- **Emergency Contact**: +66-xxx-xxx-xxxx

### Documentation
- [API Documentation](../backend/README.md)
- [Frontend Guide](../frontend/README.md)
- [Database Schema](../database/README.md)
- [Deployment Guide](../docker/README.md)

### Regular Maintenance
- Weekly performance reviews
- Monthly security updates
- Quarterly disaster recovery tests
- Annual architecture reviews

## License

This migration system is part of the LINE Telepharmacy Platform and is proprietary software. All rights reserved.

---

**Migration System Version**: 1.0.0  
**Last Updated**: 2024-01-15  
**Compatibility**: PHP 8.0+, Node.js 18+, MySQL 8.0+