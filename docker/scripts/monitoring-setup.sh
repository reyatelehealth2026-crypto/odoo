#!/bin/bash

# Monitoring Setup Script for Odoo Dashboard
# Sets up Prometheus, Grafana, Alertmanager, and related monitoring tools

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MONITORING_DIR="$PROJECT_ROOT/docker/monitoring"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[MONITORING]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if required tools are installed
check_dependencies() {
    log_info "Checking dependencies..."
    
    local deps=("docker" "docker-compose" "curl")
    for dep in "${deps[@]}"; do
        if ! command -v "$dep" &> /dev/null; then
            log_error "$dep is not installed or not in PATH"
            exit 1
        fi
    done
    
    log_success "All dependencies are available"
}

# Create monitoring directories
create_directories() {
    log_info "Creating monitoring directories..."
    
    local dirs=(
        "$MONITORING_DIR/grafana/provisioning/datasources"
        "$MONITORING_DIR/grafana/provisioning/dashboards"
        "$MONITORING_DIR/grafana/dashboards"
        "$MONITORING_DIR/alertmanager/templates"
        "$MONITORING_DIR/loki"
        "$MONITORING_DIR/promtail"
        "$PROJECT_ROOT/logs/monitoring"
    )
    
    for dir in "${dirs[@]}"; do
        mkdir -p "$dir"
        log_info "Created directory: $dir"
    done
    
    log_success "Monitoring directories created"
}

# Generate Grafana provisioning configuration
setup_grafana_provisioning() {
    log_info "Setting up Grafana provisioning..."
    
    # Datasources configuration
    cat > "$MONITORING_DIR/grafana/provisioning/datasources/prometheus.yml" << 'EOF'
apiVersion: 1

datasources:
  - name: Prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
    editable: true
    
  - name: Loki
    type: loki
    access: proxy
    url: http://loki:3100
    editable: true
EOF

    # Dashboards configuration
    cat > "$MONITORING_DIR/grafana/provisioning/dashboards/dashboards.yml" << 'EOF'
apiVersion: 1

providers:
  - name: 'default'
    orgId: 1
    folder: ''
    type: file
    disableDeletion: false
    updateIntervalSeconds: 10
    allowUiUpdates: true
    options:
      path: /var/lib/grafana/dashboards
EOF

    log_success "Grafana provisioning configured"
}

# Setup Loki configuration
setup_loki_config() {
    log_info "Setting up Loki configuration..."
    
    cat > "$MONITORING_DIR/loki/loki-config.yml" << 'EOF'
auth_enabled: false

server:
  http_listen_port: 3100
  grpc_listen_port: 9096

common:
  path_prefix: /loki
  storage:
    filesystem:
      chunks_directory: /loki/chunks
      rules_directory: /loki/rules
  replication_factor: 1
  ring:
    instance_addr: 127.0.0.1
    kvstore:
      store: inmemory

query_range:
  results_cache:
    cache:
      embedded_cache:
        enabled: true
        max_size_mb: 100

schema_config:
  configs:
    - from: 2020-10-24
      store: boltdb-shipper
      object_store: filesystem
      schema: v11
      index:
        prefix: index_
        period: 24h

ruler:
  alertmanager_url: http://alertmanager:9093

limits_config:
  reject_old_samples: true
  reject_old_samples_max_age: 168h

chunk_store_config:
  max_look_back_period: 0s

table_manager:
  retention_deletes_enabled: false
  retention_period: 0s

compactor:
  working_directory: /loki/boltdb-shipper-compactor
  shared_store: filesystem

ingester:
  max_chunk_age: 1h
  chunk_idle_period: 3m
  chunk_block_size: 262144
  chunk_target_size: 1048576
  chunk_retain_period: 1m
  max_transfer_retries: 0
  wal:
    enabled: true
    dir: /loki/wal
EOF

    log_success "Loki configuration created"
}

# Setup Promtail configuration
setup_promtail_config() {
    log_info "Setting up Promtail configuration..."
    
    cat > "$MONITORING_DIR/promtail/promtail-config.yml" << 'EOF'
server:
  http_listen_port: 9080
  grpc_listen_port: 0

positions:
  filename: /tmp/positions.yaml

clients:
  - url: http://loki:3100/loki/api/v1/push

scrape_configs:
  - job_name: system
    static_configs:
      - targets:
          - localhost
        labels:
          job: varlogs
          __path__: /var/log/*log

  - job_name: containers
    static_configs:
      - targets:
          - localhost
        labels:
          job: containerlogs
          __path__: /var/lib/docker/containers/*/*log

  - job_name: odoo-dashboard-logs
    static_configs:
      - targets:
          - localhost
        labels:
          job: odoo-dashboard
          __path__: /app/logs/**/*log
    pipeline_stages:
      - json:
          expressions:
            level: level
            message: message
            timestamp: timestamp
      - labels:
          level:
      - timestamp:
          source: timestamp
          format: RFC3339Nano
EOF

    log_success "Promtail configuration created"
}

# Setup recording rules
setup_recording_rules() {
    log_info "Setting up Prometheus recording rules..."
    
    cat > "$MONITORING_DIR/recording_rules.yml" << 'EOF'
groups:
  - name: odoo_dashboard_recording_rules
    interval: 30s
    rules:
      # Request rate by service
      - record: odoo_dashboard:request_rate_5m
        expr: rate(http_requests_total[5m])
        labels:
          job: "{{ $labels.job }}"
          
      # Error rate by service
      - record: odoo_dashboard:error_rate_5m
        expr: |
          rate(http_requests_total{status=~"5.."}[5m]) /
          rate(http_requests_total[5m]) * 100
        labels:
          job: "{{ $labels.job }}"
          
      # Response time percentiles
      - record: odoo_dashboard:response_time_p95_5m
        expr: |
          histogram_quantile(0.95, 
            rate(http_request_duration_seconds_bucket[5m])
          )
        labels:
          job: "{{ $labels.job }}"
          
      - record: odoo_dashboard:response_time_p99_5m
        expr: |
          histogram_quantile(0.99, 
            rate(http_request_duration_seconds_bucket[5m])
          )
        labels:
          job: "{{ $labels.job }}"
          
      # Cache hit rate
      - record: odoo_dashboard:cache_hit_rate_5m
        expr: |
          rate(cache_hits_total[5m]) /
          (rate(cache_hits_total[5m]) + rate(cache_misses_total[5m])) * 100
          
      # Database connection utilization
      - record: odoo_dashboard:db_connection_utilization
        expr: |
          mysql_global_status_threads_connected /
          mysql_global_variables_max_connections * 100
          
      # Memory utilization
      - record: odoo_dashboard:memory_utilization
        expr: |
          (node_memory_MemTotal_bytes - node_memory_MemAvailable_bytes) /
          node_memory_MemTotal_bytes * 100
          
      # CPU utilization
      - record: odoo_dashboard:cpu_utilization
        expr: |
          100 - (avg by(instance) (irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)
EOF

    log_success "Recording rules created"
}

# Create environment file template
create_env_template() {
    log_info "Creating monitoring environment template..."
    
    cat > "$MONITORING_DIR/.env.monitoring.template" << 'EOF'
# Monitoring Configuration
GRAFANA_ADMIN_PASSWORD=admin123
GRAFANA_DOMAIN=grafana.yourdomain.com
GRAFANA_FROM_EMAIL=grafana@yourdomain.com

# SMTP Configuration
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password

# Alert Email Configuration
DEFAULT_ALERT_EMAIL=alerts@yourdomain.com
CRITICAL_ALERT_EMAIL=critical@yourdomain.com
WARNING_ALERT_EMAIL=warnings@yourdomain.com
BUSINESS_ALERT_EMAIL=business@yourdomain.com

# Webhook URLs for notifications
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK
TEAMS_WEBHOOK_URL=https://outlook.office.com/webhook/YOUR/TEAMS/WEBHOOK

# Redis Configuration
REDIS_PASSWORD=your-redis-password

# Database Configuration
DB_USER=monitoring_user
DB_PASSWORD=monitoring_password
EOF

    log_success "Environment template created at $MONITORING_DIR/.env.monitoring.template"
    log_warning "Please copy .env.monitoring.template to .env.monitoring and configure your settings"
}

# Start monitoring stack
start_monitoring() {
    log_info "Starting monitoring stack..."
    
    cd "$MONITORING_DIR"
    
    # Check if environment file exists
    if [[ ! -f ".env.monitoring" ]]; then
        log_warning "Environment file not found, using template values"
        cp ".env.monitoring.template" ".env.monitoring"
    fi
    
    # Start monitoring services
    docker-compose -f docker-compose.monitoring.yml --env-file .env.monitoring up -d
    
    log_success "Monitoring stack started"
    
    # Wait for services to be ready
    log_info "Waiting for services to be ready..."
    sleep 30
    
    # Check service health
    check_monitoring_health
}

# Check monitoring service health
check_monitoring_health() {
    log_info "Checking monitoring service health..."
    
    local services=(
        "prometheus:9090"
        "grafana:3000"
        "alertmanager:9093"
    )
    
    for service in "${services[@]}"; do
        local name="${service%:*}"
        local port="${service#*:}"
        
        if curl -f -s "http://localhost:$port" > /dev/null; then
            log_success "$name is healthy"
        else
            log_error "$name is not responding"
        fi
    done
}

# Stop monitoring stack
stop_monitoring() {
    log_info "Stopping monitoring stack..."
    
    cd "$MONITORING_DIR"
    docker-compose -f docker-compose.monitoring.yml down
    
    log_success "Monitoring stack stopped"
}

# Show monitoring URLs
show_urls() {
    echo ""
    echo "Monitoring URLs:"
    echo "================"
    echo "Prometheus: http://localhost:9090"
    echo "Grafana: http://localhost:3000 (admin/admin123)"
    echo "Alertmanager: http://localhost:9093"
    echo "Node Exporter: http://localhost:9100"
    echo "cAdvisor: http://localhost:8080"
    echo ""
}

# Main function
main() {
    local action="${1:-setup}"
    
    log_info "Odoo Dashboard Monitoring Setup"
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    case "$action" in
        "setup")
            check_dependencies
            create_directories
            setup_grafana_provisioning
            setup_loki_config
            setup_promtail_config
            setup_recording_rules
            create_env_template
            log_success "Monitoring setup completed!"
            log_info "Run '$0 start' to start the monitoring stack"
            ;;
        "start")
            start_monitoring
            show_urls
            ;;
        "stop")
            stop_monitoring
            ;;
        "restart")
            stop_monitoring
            sleep 5
            start_monitoring
            show_urls
            ;;
        "health")
            check_monitoring_health
            ;;
        "urls")
            show_urls
            ;;
        *)
            echo "Usage: $0 {setup|start|stop|restart|health|urls}"
            echo ""
            echo "Commands:"
            echo "  setup    Set up monitoring configuration files"
            echo "  start    Start monitoring stack"
            echo "  stop     Stop monitoring stack"
            echo "  restart  Restart monitoring stack"
            echo "  health   Check monitoring service health"
            echo "  urls     Show monitoring service URLs"
            exit 1
            ;;
    esac
}

# Run main function
main "$@"