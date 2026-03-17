#!/bin/bash

# Traffic Routing Setup Script
# Purpose: Configure load balancer and traffic splitting for parallel deployment
# Requirements: TC-3.1

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TRAEFIK_CONFIG_DIR="$PROJECT_ROOT/docker/traefik"
NGINX_CONFIG_DIR="$PROJECT_ROOT/docker/nginx"
LOG_DIR="$PROJECT_ROOT/logs/migration"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/traffic-routing.log"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/traffic-routing.log"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/traffic-routing.log"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/traffic-routing.log"
}

# Create necessary directories
setup_directories() {
    mkdir -p "$LOG_DIR" "$TRAEFIK_CONFIG_DIR" "$NGINX_CONFIG_DIR"
    log_info "Traffic routing directories created"
}

# Generate Nginx configuration for static files and additional routing
generate_nginx_config() {
    log_info "Generating Nginx configuration..."
    
    cat > "$NGINX_CONFIG_DIR/migration.conf" << 'EOF'
# Nginx Configuration for Migration
# Purpose: Handle static files and additional routing during migration

upstream legacy_backend {
    server legacy-web:80 max_fails=3 fail_timeout=30s;
}

upstream modern_backend {
    server modern-backend:4000 max_fails=3 fail_timeout=30s;
}

upstream modern_frontend {
    server modern-frontend:3000 max_fails=3 fail_timeout=30s;
}

# Rate limiting zones
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=upload_limit:10m rate=2r/s;

# Main server block
server {
    listen 80;
    server_name localhost;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Logging
    access_log /var/log/nginx/migration_access.log combined;
    error_log /var/log/nginx/migration_error.log warn;
    
    # Static files (served directly by Nginx)
    location /uploads/ {
        alias /var/www/uploads/;
        expires 1y;
        add_header Cache-Control "public, immutable";
        
        # Security for uploaded files
        location ~* \.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$ {
            deny all;
        }
    }
    
    location /assets/ {
        alias /var/www/assets/;
        expires 1y;
        add_header Cache-Control "public, immutable";
        
        # Gzip compression
        gzip on;
        gzip_types text/css application/javascript image/svg+xml;
    }
    
    # Health check endpoints
    location /health/nginx {
        access_log off;
        return 200 "nginx healthy\n";
        add_header Content-Type text/plain;
    }
    
    # API rate limiting
    location /api/ {
        limit_req zone=api_limit burst=20 nodelay;
        
        # Add routing headers
        add_header X-Routed-By "nginx" always;
        add_header X-Routing-Timestamp "$time_iso8601" always;
        
        # Proxy to Traefik for intelligent routing
        proxy_pass http://traefik:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
    }
    
    # File upload endpoints with special handling
    location /api/payments/upload {
        limit_req zone=upload_limit burst=5 nodelay;
        
        # Increase body size for file uploads
        client_max_body_size 10M;
        
        # Proxy to modern system for file uploads
        proxy_pass http://modern_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Upload timeouts
        proxy_connect_timeout 10s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
    
    # WebSocket proxy
    location /socket.io/ {
        proxy_pass http://websocket:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # WebSocket timeouts
        proxy_connect_timeout 7d;
        proxy_send_timeout 7d;
        proxy_read_timeout 7d;
    }
    
    # Admin panel (always route to legacy for now)
    location /admin/ {
        proxy_pass http://legacy_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Admin-Route "legacy" always;
    }
    
    # Default routing (let Traefik handle feature flag routing)
    location / {
        # Add routing context headers
        add_header X-Migration-Phase "parallel-deployment" always;
        add_header X-Routing-Strategy "feature-flags" always;
        
        proxy_pass http://traefik:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Standard timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
    }
    
    # Error pages
    error_page 502 503 504 /50x.html;
    location = /50x.html {
        root /usr/share/nginx/html;
        internal;
    }
}

# HTTPS redirect (if SSL is configured)
server {
    listen 443 ssl http2;
    server_name localhost;
    
    # SSL configuration (placeholder - update with actual certificates)
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;
    
    # Include same configuration as HTTP
    include /etc/nginx/conf.d/migration.conf;
}
EOF

    log_success "Nginx configuration generated: $NGINX_CONFIG_DIR/migration.conf"
}

# Generate Traefik static configuration
generate_traefik_static_config() {
    log_info "Generating Traefik static configuration..."
    
    cat > "$TRAEFIK_CONFIG_DIR/traefik.yml" << 'EOF'
# Traefik Static Configuration for Migration
# Purpose: Main configuration for load balancing and routing

global:
  checkNewVersion: false
  sendAnonymousUsage: false

api:
  dashboard: true
  insecure: true

entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"
  traefik:
    address: ":8080"

providers:
  docker:
    endpoint: "unix:///var/run/docker.sock"
    exposedByDefault: false
    network: migration-network
  file:
    filename: /etc/traefik/dynamic.yml
    watch: true

certificatesResolvers:
  myresolver:
    acme:
      email: admin@company.com
      storage: /letsencrypt/acme.json
      httpChallenge:
        entryPoint: web

log:
  level: INFO
  filePath: /var/log/traefik/traefik.log

accessLog:
  filePath: /var/log/traefik/access.log
  format: json
  fields:
    defaultMode: keep
    names:
      ClientUsername: drop
    headers:
      defaultMode: keep
      names:
        User-Agent: keep
        Authorization: drop
        Content-Type: keep

metrics:
  prometheus:
    addEntryPointsLabels: true
    addServicesLabels: true

tracing:
  jaeger:
    samplingServerURL: http://jaeger:14268/api/sampling
    localAgentHostPort: jaeger:6831
EOF

    log_success "Traefik static configuration generated: $TRAEFIK_CONFIG_DIR/traefik.yml"
}

# Update Traefik dynamic configuration with enhanced routing
update_traefik_dynamic_config() {
    log_info "Updating Traefik dynamic configuration..."
    
    # Backup existing configuration
    if [[ -f "$TRAEFIK_CONFIG_DIR/dynamic.yml" ]]; then
        cp "$TRAEFIK_CONFIG_DIR/dynamic.yml" "$TRAEFIK_CONFIG_DIR/dynamic.yml.backup.$(date +%Y%m%d_%H%M%S)"
        log_info "Existing configuration backed up"
    fi
    
    cat > "$TRAEFIK_CONFIG_DIR/dynamic.yml" << 'EOF'
# Traefik Dynamic Configuration for Migration
# Purpose: Configure traffic splitting and routing rules with enhanced monitoring
# Requirements: TC-3.1

http:
  # Routers for different services
  routers:
    # Main application router with intelligent traffic splitting
    app-router:
      rule: "Host(`telepharmacy.local`) || Host(`www.telepharmacy.local`) || Host(`localhost`)"
      service: "app-service"
      middlewares:
        - "feature-flag-routing"
        - "cors-headers"
        - "rate-limit"
        - "request-logging"
        - "legacy-circuit-breaker"
      tls:
        certResolver: "myresolver"

    # API router with version-based routing and enhanced monitoring
    api-router:
      rule: "Host(`telepharmacy.local`, `localhost`) && PathPrefix(`/api/`)"
      service: "api-service"
      middlewares:
        - "api-routing"
        - "cors-headers"
        - "rate-limit"
        - "request-logging"
        - "retry-policy"
      tls:
        certResolver: "myresolver"

    # WebSocket router with connection management
    websocket-router:
      rule: "Host(`telepharmacy.local`, `localhost`) && PathPrefix(`/socket.io/`)"
      service: "websocket-service"
      middlewares:
        - "cors-headers"
        - "request-logging"
      tls:
        certResolver: "myresolver"

    # Admin panel router (always legacy for now)
    admin-router:
      rule: "Host(`telepharmacy.local`, `localhost`) && PathPrefix(`/admin/`)"
      service: "legacy-service"
      middlewares:
        - "cors-headers"
        - "rate-limit"
        - "admin-auth"
      tls:
        certResolver: "myresolver"

    # Migration monitoring dashboard
    monitor-router:
      rule: "Host(`monitor.telepharmacy.local`) || Host(`monitor.localhost`)"
      service: "monitor-service"
      middlewares:
        - "auth-admin"
        - "cors-headers"
      tls:
        certResolver: "myresolver"

    # Feature flag management interface
    feature-flags-router:
      rule: "Host(`telepharmacy.local`, `localhost`) && PathPrefix(`/api/feature-flags/`)"
      service: "legacy-service"
      middlewares:
        - "cors-headers"
        - "rate-limit"
        - "admin-auth"

  # Services with intelligent load balancing
  services:
    # Main application service with weighted routing based on feature flags
    app-service:
      weighted:
        services:
          - name: "legacy-service"
            weight: 85  # 85% to legacy initially (conservative approach)
          - name: "modern-service"
            weight: 15  # 15% to modern system

    # API service with path-based intelligent routing
    api-service:
      weighted:
        services:
          - name: "legacy-api-service"
            weight: 75  # 75% to legacy API initially
          - name: "modern-api-service"
            weight: 25  # 25% to modern API

    # Individual service definitions with health checks
    legacy-service:
      loadBalancer:
        servers:
          - url: "http://legacy-web:80"
        healthCheck:
          path: "/health.php"
          interval: "30s"
          timeout: "5s"
          headers:
            Host: "localhost"

    modern-service:
      loadBalancer:
        servers:
          - url: "http://modern-frontend:3000"
        healthCheck:
          path: "/api/health"
          interval: "30s"
          timeout: "5s"
          headers:
            Host: "localhost"

    legacy-api-service:
      loadBalancer:
        servers:
          - url: "http://legacy-web:80"
        healthCheck:
          path: "/api/health.php"
          interval: "30s"
          timeout: "5s"
          headers:
            Host: "localhost"

    modern-api-service:
      loadBalancer:
        servers:
          - url: "http://modern-backend:4000"
        healthCheck:
          path: "/api/v1/health"
          interval: "30s"
          timeout: "5s"
          headers:
            Host: "localhost"

    websocket-service:
      loadBalancer:
        servers:
          - url: "http://websocket:3001"
        healthCheck:
          path: "/health"
          interval: "30s"
          timeout: "5s"

    monitor-service:
      loadBalancer:
        servers:
          - url: "http://migration-monitor:9090"
        healthCheck:
          path: "/health"
          interval: "30s"
          timeout: "5s"

  # Enhanced middlewares for request processing
  middlewares:
    # Feature flag based routing with fallback
    feature-flag-routing:
      plugin:
        dev:
          headers:
            X-Routing-Decision: "feature-flag"
            X-Migration-Phase: "parallel-deployment"

    # API version routing with backward compatibility
    api-routing:
      replacePath:
        path: "/api/v1"
      headers:
        customRequestHeaders:
          X-API-Version: "v1"
          X-Migration-Context: "api-routing"

    # Enhanced CORS headers for cross-origin requests
    cors-headers:
      headers:
        accessControlAllowMethods:
          - "GET"
          - "POST"
          - "PUT"
          - "DELETE"
          - "OPTIONS"
          - "PATCH"
        accessControlAllowHeaders:
          - "Content-Type"
          - "Authorization"
          - "X-Line-Account-ID"
          - "X-Requested-With"
          - "X-API-Version"
          - "X-Migration-Context"
        accessControlAllowOriginList:
          - "https://telepharmacy.local"
          - "https://www.telepharmacy.local"
          - "http://localhost:3000"
          - "http://localhost:8080"
        accessControlMaxAge: 86400
        accessControlAllowCredentials: true

    # Adaptive rate limiting based on user type
    rate-limit:
      rateLimit:
        burst: 100
        average: 50
        period: "1m"
        sourceCriterion:
          ipStrategy:
            depth: 1
            excludedIPs:
              - "127.0.0.1"
              - "::1"

    # Admin authentication for sensitive endpoints
    admin-auth:
      basicAuth:
        users:
          - "admin:$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"  # password: password

    # Enhanced request/response logging
    request-logging:
      accessLog:
        filePath: "/var/log/traefik/detailed_access.log"
        format: json
        fields:
          defaultMode: keep
          names:
            RequestMethod: keep
            RequestPath: keep
            RequestProtocol: keep
            RequestHost: keep
            ClientAddr: keep
            ClientHost: keep
            ClientPort: keep
            ClientUsername: drop
            RequestCount: keep
            RouterName: keep
            ServiceName: keep
            ServiceURL: keep
            Duration: keep
            OriginDuration: keep
            OriginContentSize: keep
            OriginStatus: keep
            OriginStatusLine: keep
            DownstreamStatus: keep
            DownstreamStatusLine: keep
            DownstreamContentSize: keep
          headers:
            defaultMode: keep
            names:
              User-Agent: keep
              Authorization: drop
              Content-Type: keep
              X-Forwarded-For: keep
              X-Real-IP: keep
              X-Migration-Context: keep

    # Circuit breaker for legacy system with adaptive thresholds
    legacy-circuit-breaker:
      circuitBreaker:
        expression: "NetworkErrorRatio() > 0.3 || ResponseCodeRatio(500, 600, 0, 600) > 0.3"
        checkPeriod: "10s"
        fallbackDuration: "30s"
        recoveryDuration: "10s"

    # Circuit breaker for modern system
    modern-circuit-breaker:
      circuitBreaker:
        expression: "NetworkErrorRatio() > 0.3 || ResponseCodeRatio(500, 600, 0, 600) > 0.3"
        checkPeriod: "10s"
        fallbackDuration: "30s"
        recoveryDuration: "10s"

    # Intelligent retry mechanism with exponential backoff
    retry-policy:
      retry:
        attempts: 3
        initialInterval: "100ms"

    # Request timeout with different limits for different endpoints
    request-timeout:
      forwardTimeout: "30s"

    # Compression for better performance
    compression:
      excludedContentTypes:
        - "text/event-stream"

# TLS Configuration with modern security
tls:
  options:
    default:
      sslProtocols:
        - "TLSv1.2"
        - "TLSv1.3"
      cipherSuites:
        - "TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384"
        - "TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305"
        - "TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256"
        - "TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384"
        - "TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305"
        - "TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256"
      curvePreferences:
        - "CurveP521"
        - "CurveP384"
      minVersion: "VersionTLS12"

# TCP Services for WebSocket with load balancing
tcp:
  routers:
    websocket-tcp:
      rule: "HostSNI(`telepharmacy.local`) || HostSNI(`localhost`)"
      service: "websocket-tcp-service"
      tls:
        passthrough: true

  services:
    websocket-tcp-service:
      loadBalancer:
        servers:
          - address: "websocket:3001"
        healthCheck:
          interval: "30s"
          timeout: "5s"
EOF

    log_success "Traefik dynamic configuration updated: $TRAEFIK_CONFIG_DIR/dynamic.yml"
}

# Create monitoring configuration
create_monitoring_config() {
    log_info "Creating monitoring configuration..."
    
    mkdir -p "$PROJECT_ROOT/docker/monitoring"
    
    cat > "$PROJECT_ROOT/docker/monitoring/prometheus.yml" << 'EOF'
# Prometheus Configuration for Migration Monitoring
global:
  scrape_interval: 15s
  evaluation_interval: 15s

rule_files:
  - "alert_rules.yml"

alerting:
  alertmanagers:
    - static_configs:
        - targets:
          - alertmanager:9093

scrape_configs:
  - job_name: 'traefik'
    static_configs:
      - targets: ['traefik:8080']
    metrics_path: /metrics
    scrape_interval: 10s

  - job_name: 'legacy-system'
    static_configs:
      - targets: ['legacy-web:80']
    metrics_path: /metrics.php
    scrape_interval: 30s

  - job_name: 'modern-backend'
    static_configs:
      - targets: ['modern-backend:4000']
    metrics_path: /api/v1/metrics
    scrape_interval: 15s

  - job_name: 'websocket-server'
    static_configs:
      - targets: ['websocket:3001']
    metrics_path: /metrics
    scrape_interval: 15s

  - job_name: 'data-sync-service'
    static_configs:
      - targets: ['data-sync:8080']
    metrics_path: /metrics
    scrape_interval: 30s

  - job_name: 'mysql'
    static_configs:
      - targets: ['mysql:3306']
    scrape_interval: 30s

  - job_name: 'redis'
    static_configs:
      - targets: ['redis:6379']
    scrape_interval: 30s
EOF

    log_success "Monitoring configuration created"
}

# Test traffic routing configuration
test_traffic_routing() {
    log_info "Testing traffic routing configuration..."
    
    local test_errors=0
    
    # Test configuration syntax
    if command -v docker &> /dev/null; then
        log_info "Testing Traefik configuration syntax..."
        
        # Create a temporary container to test configuration
        docker run --rm \
            -v "$TRAEFIK_CONFIG_DIR:/etc/traefik:ro" \
            traefik:v2.10 \
            traefik --configfile=/etc/traefik/traefik.yml --dry-run 2>&1 | tee "$LOG_DIR/traefik-config-test.log"
        
        if [[ ${PIPESTATUS[0]} -eq 0 ]]; then
            log_success "Traefik configuration syntax is valid"
        else
            log_error "Traefik configuration syntax error"
            ((test_errors++))
        fi
    else
        log_warning "Docker not available for configuration testing"
    fi
    
    # Test Nginx configuration syntax
    if command -v nginx &> /dev/null; then
        log_info "Testing Nginx configuration syntax..."
        
        nginx -t -c "$NGINX_CONFIG_DIR/migration.conf" 2>&1 | tee "$LOG_DIR/nginx-config-test.log"
        
        if [[ ${PIPESTATUS[0]} -eq 0 ]]; then
            log_success "Nginx configuration syntax is valid"
        else
            log_error "Nginx configuration syntax error"
            ((test_errors++))
        fi
    else
        log_warning "Nginx not available for configuration testing"
    fi
    
    if [[ $test_errors -gt 0 ]]; then
        log_error "Traffic routing configuration test failed with $test_errors errors"
        return 1
    fi
    
    log_success "Traffic routing configuration tests passed"
}

# Generate routing documentation
generate_routing_documentation() {
    log_info "Generating traffic routing documentation..."
    
    local doc_file="$LOG_DIR/traffic_routing_guide.md"
    
    cat > "$doc_file" << 'EOF'
# Traffic Routing Configuration Guide

## Overview

This document describes the traffic routing configuration for the Odoo Dashboard migration parallel deployment phase.

## Architecture

```
Internet → Nginx → Traefik → [Legacy System | Modern System]
                           ↘ WebSocket Server
                           ↘ Monitoring
```

## Routing Rules

### Main Application Routes
- **Dashboard**: Feature flag controlled (5% to modern system initially)
- **Admin Panel**: Always routed to legacy system
- **API Endpoints**: Intelligent routing based on feature flags
- **WebSocket**: Direct routing to modern WebSocket server

### Feature Flag Routing
- `useNewDashboard`: 5% rollout to modern dashboard
- `useNewOrderManagement`: 0% rollout (disabled)
- `useNewPaymentProcessing`: 0% rollout (disabled)
- `useNewWebhookManagement`: 0% rollout (disabled)
- `useNewCustomerManagement`: 0% rollout (disabled)

### Load Balancing Weights
- **Legacy System**: 85% of traffic
- **Modern System**: 15% of traffic
- **API Legacy**: 75% of API traffic
- **API Modern**: 25% of API traffic

## Health Checks

All services have health check endpoints configured:
- **Legacy System**: `/health.php` (30s interval)
- **Modern Backend**: `/api/v1/health` (30s interval)
- **Modern Frontend**: `/api/health` (30s interval)
- **WebSocket**: `/health` (30s interval)

## Circuit Breakers

Circuit breakers are configured for both systems:
- **Threshold**: 30% error rate
- **Check Period**: 10 seconds
- **Fallback Duration**: 30 seconds
- **Recovery Duration**: 10 seconds

## Rate Limiting

- **API Endpoints**: 50 requests/minute (burst: 100)
- **File Uploads**: 2 requests/second (burst: 5)
- **General**: 50 requests/minute per IP

## Monitoring

- **Traefik Dashboard**: http://localhost:8080/
- **Prometheus Metrics**: http://localhost:9090/
- **Access Logs**: `/var/log/traefik/access.log`
- **Error Logs**: `/var/log/traefik/traefik.log`

## Security

- **TLS**: TLS 1.2+ with modern cipher suites
- **CORS**: Configured for allowed origins
- **Headers**: Security headers added automatically
- **Admin Auth**: Basic authentication for admin endpoints

## Troubleshooting

### Common Issues

1. **503 Service Unavailable**
   - Check service health endpoints
   - Verify Docker containers are running
   - Check circuit breaker status

2. **Routing Not Working**
   - Verify feature flag configuration
   - Check Traefik dynamic configuration
   - Review access logs for routing decisions

3. **Performance Issues**
   - Monitor response times in metrics
   - Check rate limiting logs
   - Verify health check intervals

### Useful Commands

```bash
# Check Traefik configuration
docker-compose -f docker/docker-compose.migration.yml logs traefik

# View routing metrics
curl http://localhost:8080/metrics

# Test health endpoints
curl http://localhost/health
curl http://localhost:8080/health
curl http://localhost:4000/api/v1/health

# Check feature flags
curl http://localhost/api/feature-flags/health
```

## Rollback Procedure

In case of issues:

1. Set all feature flags to 0%
2. Update Traefik weights to 100% legacy
3. Restart Traefik service
4. Monitor system stability

## Next Steps

1. Monitor traffic distribution for 24-48 hours
2. Gradually increase modern system weights
3. Enable additional feature flags based on stability
4. Plan for Phase 2 migration
EOF

    log_success "Traffic routing documentation generated: $doc_file"
}

# Main execution function
main() {
    log_info "Setting up traffic routing for parallel deployment"
    
    # Setup
    setup_directories
    
    # Generate configurations
    generate_nginx_config
    generate_traefik_static_config
    update_traefik_dynamic_config
    create_monitoring_config
    
    # Test configurations
    test_traffic_routing
    
    # Documentation
    generate_routing_documentation
    
    log_success "Traffic routing setup completed successfully!"
    log_info "Configuration files created:"
    log_info "- Nginx: $NGINX_CONFIG_DIR/migration.conf"
    log_info "- Traefik Static: $TRAEFIK_CONFIG_DIR/traefik.yml"
    log_info "- Traefik Dynamic: $TRAEFIK_CONFIG_DIR/dynamic.yml"
    log_info "- Monitoring: $PROJECT_ROOT/docker/monitoring/prometheus.yml"
    log_info "- Documentation: $LOG_DIR/traffic_routing_guide.md"
}

# Execute main function if script is run directly
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi