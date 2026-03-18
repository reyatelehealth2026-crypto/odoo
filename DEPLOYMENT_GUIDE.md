# Odoo Dashboard Modernization - Deployment Guide

## Overview

This guide covers the complete deployment process for the modernized Odoo Dashboard system, including production Docker setup, blue-green deployment strategy, and comprehensive monitoring.

## Prerequisites

### System Requirements
- Docker 20.10+ and Docker Compose 2.0+
- Linux server with at least 4GB RAM and 20GB storage
- SSL certificates for HTTPS
- Domain name configured with DNS

### Environment Setup
- Production server access with sudo privileges
- Environment variables configured
- Database and Redis credentials
- SMTP settings for alerts

## Deployment Architecture

### Production Stack
- **Frontend**: Next.js 14 with SSR optimization
- **Backend**: Node.js + Fastify API server
- **WebSocket**: Real-time updates server
- **Database**: MySQL 8.0 with performance tuning
- **Cache**: Redis with persistence
- **Load Balancer**: Nginx with SSL termination
- **Monitoring**: Prometheus + Grafana + Alertmanager

### Blue-Green Deployment
- Zero-downtime deployments
- Automatic rollback capability
- Health checks and smoke tests
- Traffic switching with validation

## Quick Start

### 1. Initial Setup

```bash
# Clone repository
git clone <repository-url>
cd odoo-dashboard-modernization

# Set up environment
cp .env.prod.example .env.prod
# Edit .env.prod with your configuration

# Initialize Docker networks
docker network create odoo-dashboard-network

# Set up monitoring
bash docker/scripts/monitoring-setup.sh setup
```

### 2. First Deployment

```bash
# Build and deploy
make prod-deploy

# Or using deployment script
bash docker/scripts/blue-green-deploy.sh deploy latest
```

### 3. Start Monitoring

```bash
# Start monitoring stack
bash docker/scripts/monitoring-setup.sh start

# Access monitoring dashboards
# Grafana: http://your-domain:3000
# Prometheus: http://your-domain:9090
# Alertmanager: http://your-domain:9093
```

## Detailed Deployment Process

### Environment Configuration

Create and configure your production environment file:

```bash
# Copy template
cp .env.prod.example .env.prod

# Required variables
DB_HOST=mysql
DB_USER=odoo_dashboard
DB_PASSWORD=secure_password
DB_NAME=odoo_dashboard

REDIS_URL=redis://redis:6379
REDIS_PASSWORD=redis_password

JWT_SECRET=your_jwt_secret_key
API_URL=https://api.yourdomain.com
FRONTEND_URL=https://yourdomain.com
WS_URL=wss://yourdomain.com

DOMAIN_NAME=yourdomain.com
```

### SSL Certificate Setup

```bash
# Create SSL directory
mkdir -p docker/nginx/ssl

# Copy your SSL certificates
cp your-cert.pem docker/nginx/ssl/cert.pem
cp your-key.pem docker/nginx/ssl/key.pem

# Or use Let's Encrypt
certbot certonly --standalone -d yourdomain.com
cp /etc/letsencrypt/live/yourdomain.com/fullchain.pem docker/nginx/ssl/cert.pem
cp /etc/letsencrypt/live/yourdomain.com/privkey.pem docker/nginx/ssl/key.pem
```

### Database Migration

```bash
# Run initial migration
docker-compose -f docker-compose.prod.yml exec backend npm run prisma:migrate

# Seed initial data
docker-compose -f docker-compose.prod.yml exec backend npm run prisma:seed

# Verify migration
docker-compose -f docker-compose.prod.yml exec backend npm run prisma:studio
```

## Blue-Green Deployment

### Deployment Process

The blue-green deployment provides zero-downtime updates:

```bash
# Deploy new version
bash docker/scripts/blue-green-deploy.sh deploy v1.2.3

# Check deployment status
bash docker/scripts/blue-green-deploy.sh status

# Rollback if needed
bash docker/scripts/rollback.sh standard
```

### Deployment Flow

1. **Build Phase**: New version built in inactive environment
2. **Health Checks**: Comprehensive service validation
3. **Smoke Tests**: Critical functionality verification
4. **Traffic Switch**: Nginx configuration update
5. **Validation**: Post-deployment verification
6. **Cleanup**: Old environment cleanup

### Rollback Procedures

#### Standard Rollback
```bash
# Standard rollback with health checks
bash docker/scripts/rollback.sh standard

# Rollback to specific environment
bash docker/scripts/rollback.sh standard blue
```

#### Emergency Rollback
```bash
# Fastest rollback (skips health checks)
bash docker/scripts/rollback.sh emergency
```

## Monitoring and Alerting

### Monitoring Stack Components

- **Prometheus**: Metrics collection and storage
- **Grafana**: Visualization and dashboards
- **Alertmanager**: Alert routing and notifications
- **Node Exporter**: System metrics
- **MySQL Exporter**: Database metrics
- **Redis Exporter**: Cache metrics
- **cAdvisor**: Container metrics
- **Loki**: Log aggregation
- **Promtail**: Log shipping

### Key Metrics Monitored

#### Performance Metrics
- Response time (95th percentile < 300ms for API)
- Error rate (< 3% threshold)
- Request rate and throughput
- Cache hit rate (> 85% target)

#### System Metrics
- CPU usage (< 80% threshold)
- Memory usage (< 85% threshold)
- Disk space (< 85% threshold)
- Network I/O

#### Business Metrics
- Order processing time
- Payment processing success rate
- Webhook processing failures
- Dashboard load time

### Alert Configuration

Alerts are configured for:
- **Critical**: Service down, high error rate, API response time > 300ms
- **Warning**: High resource usage, low cache hit rate, slow response times
- **Business**: Order delays, payment failures, webhook issues

### Accessing Monitoring

```bash
# Grafana Dashboard
https://yourdomain.com:3000
# Default: admin / admin123

# Prometheus Metrics
https://yourdomain.com:9090

# Alertmanager
https://yourdomain.com:9093
```

## Maintenance Operations

### Regular Maintenance

#### Daily Tasks
```bash
# Check system health
make health

# View logs
make prod-logs

# Monitor resource usage
docker stats
```

#### Weekly Tasks
```bash
# Update monitoring dashboards
bash docker/scripts/monitoring-setup.sh restart

# Clean up old Docker images
docker system prune -f

# Backup database
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u root -p odoo_dashboard > backup_$(date +%Y%m%d).sql
```

#### Monthly Tasks
```bash
# Update SSL certificates (if using Let's Encrypt)
certbot renew

# Review and rotate logs
find logs/ -name "*.log" -mtime +30 -delete

# Performance review
# Check Grafana dashboards for trends
```

### Scaling Operations

#### Horizontal Scaling
```bash
# Scale backend services
docker-compose -f docker-compose.prod.yml up -d --scale backend=3

# Scale WebSocket servers
docker-compose -f docker-compose.prod.yml up -d --scale websocket=2
```

#### Database Scaling
```bash
# Add read replica
# Update docker-compose.prod.yml with read replica configuration
# Configure application to use read/write splitting
```

## Troubleshooting

### Common Issues

#### Deployment Failures
```bash
# Check deployment logs
docker-compose -f docker-compose.prod.yml logs

# Verify health checks
curl -f http://localhost/health

# Check service status
docker-compose -f docker-compose.prod.yml ps
```

#### Performance Issues
```bash
# Check resource usage
docker stats

# Analyze slow queries
docker-compose -f docker-compose.prod.yml exec mysql mysql -e "SHOW PROCESSLIST;"

# Review cache performance
docker-compose -f docker-compose.prod.yml exec redis redis-cli info stats
```

#### Network Issues
```bash
# Test connectivity
docker-compose -f docker-compose.prod.yml exec backend curl -f http://mysql:3306

# Check DNS resolution
docker-compose -f docker-compose.prod.yml exec backend nslookup mysql

# Verify port bindings
netstat -tlnp | grep :80
```

### Log Analysis

#### Application Logs
```bash
# Backend logs
docker-compose -f docker-compose.prod.yml logs backend

# Frontend logs
docker-compose -f docker-compose.prod.yml logs frontend

# WebSocket logs
docker-compose -f docker-compose.prod.yml logs websocket
```

#### System Logs
```bash
# Nginx access logs
docker-compose -f docker-compose.prod.yml exec nginx tail -f /var/log/nginx/access.log

# MySQL error logs
docker-compose -f docker-compose.prod.yml exec mysql tail -f /var/log/mysql/error.log

# Redis logs
docker-compose -f docker-compose.prod.yml logs redis
```

## Security Considerations

### SSL/TLS Configuration
- TLS 1.2+ only
- Strong cipher suites
- HSTS headers
- Certificate auto-renewal

### Container Security
- Non-root users in containers
- Read-only filesystems where possible
- Resource limits
- Security scanning

### Network Security
- Internal Docker networks
- Firewall rules
- Rate limiting
- DDoS protection

### Data Protection
- Encrypted data at rest
- Secure backup procedures
- Access logging
- Regular security updates

## Performance Optimization

### Database Optimization
- Connection pooling
- Query optimization
- Index tuning
- Materialized views

### Caching Strategy
- Multi-layer caching
- Cache warming
- Intelligent invalidation
- Redis clustering

### Frontend Optimization
- Code splitting
- Image optimization
- CDN integration
- Service worker caching

## Backup and Recovery

### Automated Backups
```bash
# Database backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u root -p${DB_ROOT_PASSWORD} odoo_dashboard > backup_${DATE}.sql
aws s3 cp backup_${DATE}.sql s3://your-backup-bucket/
```

### Recovery Procedures
```bash
# Restore from backup
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p${DB_ROOT_PASSWORD} odoo_dashboard < backup_20240315_120000.sql

# Verify data integrity
docker-compose -f docker-compose.prod.yml exec backend npm run prisma:validate
```

## Support and Documentation

### Getting Help
- Check monitoring dashboards first
- Review application logs
- Consult troubleshooting section
- Contact development team

### Documentation Updates
- Keep deployment guide current
- Document configuration changes
- Update runbooks
- Maintain change log

## Conclusion

This deployment guide provides comprehensive instructions for deploying and maintaining the modernized Odoo Dashboard system. The blue-green deployment strategy ensures zero-downtime updates, while comprehensive monitoring provides visibility into system performance and health.

For additional support or questions, refer to the project documentation or contact the development team.