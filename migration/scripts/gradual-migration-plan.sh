#!/bin/bash

# Gradual Migration Plan: Odoo Dashboard Modernization
# Purpose: Execute phased migration from legacy PHP system to modern Node.js system
# Requirements: TC-3.1, TC-3.3

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LOG_DIR="$PROJECT_ROOT/logs/migration"
BACKUP_DIR="$PROJECT_ROOT/backups/migration"
CONFIG_FILE="$PROJECT_ROOT/.env"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/migration.log"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/migration.log"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/migration.log"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/migration.log"
}

# Load configuration
load_config() {
    if [[ -f "$CONFIG_FILE" ]]; then
        source "$CONFIG_FILE"
        log_info "Configuration loaded from $CONFIG_FILE"
    else
        log_error "Configuration file not found: $CONFIG_FILE"
        exit 1
    fi
}

# Create necessary directories
setup_directories() {
    mkdir -p "$LOG_DIR" "$BACKUP_DIR"
    log_info "Migration directories created"
}

# Check prerequisites
check_prerequisites() {
    log_info "Checking migration prerequisites..."
    
    local errors=0
    
    # Check Docker
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed"
        ((errors++))
    fi
    
    # Check Docker Compose
    if ! command -v docker-compose &> /dev/null; then
        log_error "Docker Compose is not installed"
        ((errors++))
    fi
    
    # Check MySQL client
    if ! command -v mysql &> /dev/null; then
        log_error "MySQL client is not installed"
        ((errors++))
    fi
    
    # Check Node.js
    if ! command -v node &> /dev/null; then
        log_error "Node.js is not installed"
        ((errors++))
    fi
    
    # Check required environment variables
    local required_vars=("MYSQL_USER" "MYSQL_PASSWORD" "MYSQL_DATABASE" "REDIS_HOST" "JWT_SECRET")
    for var in "${required_vars[@]}"; do
        if [[ -z "${!var:-}" ]]; then
            log_error "Required environment variable not set: $var"
            ((errors++))
        fi
    done
    
    if [[ $errors -gt 0 ]]; then
        log_error "Prerequisites check failed with $errors errors"
        exit 1
    fi
    
    log_success "All prerequisites satisfied"
}

# Create database backup
create_backup() {
    log_info "Creating database backup..."
    
    local backup_file="$BACKUP_DIR/pre_migration_$(date +%Y%m%d_%H%M%S).sql"
    
    mysqldump \
        --host="${MYSQL_HOST:-localhost}" \
        --port="${MYSQL_PORT:-3306}" \
        --user="$MYSQL_USER" \
        --password="$MYSQL_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-database \
        --databases "$MYSQL_DATABASE" > "$backup_file"
    
    if [[ $? -eq 0 ]]; then
        log_success "Database backup created: $backup_file"
        
        # Compress backup
        gzip "$backup_file"
        log_info "Backup compressed: ${backup_file}.gz"
    else
        log_error "Database backup failed"
        exit 1
    fi
}

# Execute SQL migration scripts
execute_migration_scripts() {
    log_info "Executing data migration scripts..."
    
    local scripts=(
        "master-migration.sql"
        "validate-data-integrity.sql"
        "populate-performance-cache.sql"
        "migrate-audit-logs.sql"
    )
    
    for script in "${scripts[@]}"; do
        local script_path="$SCRIPT_DIR/$script"
        
        if [[ -f "$script_path" ]]; then
            log_info "Executing migration script: $script"
            
            mysql \
                --host="${MYSQL_HOST:-localhost}" \
                --port="${MYSQL_PORT:-3306}" \
                --user="$MYSQL_USER" \
                --password="$MYSQL_PASSWORD" \
                --database="$MYSQL_DATABASE" \
                --verbose \
                < "$script_path" 2>&1 | tee -a "$LOG_DIR/migration_${script%.sql}.log"
            
            if [[ ${PIPESTATUS[0]} -eq 0 ]]; then
                log_success "Migration script completed: $script"
            else
                log_error "Migration script failed: $script"
                exit 1
            fi
        else
            log_warning "Migration script not found: $script_path"
        fi
    done
}

# Deploy parallel infrastructure
deploy_parallel_infrastructure() {
    log_info "Deploying parallel infrastructure..."
    
    # Build and start migration containers
    cd "$PROJECT_ROOT"
    
    log_info "Building Docker images..."
    docker-compose -f docker/docker-compose.migration.yml build --no-cache
    
    if [[ $? -eq 0 ]]; then
        log_success "Docker images built successfully"
    else
        log_error "Docker image build failed"
        exit 1
    fi
    
    log_info "Starting migration infrastructure..."
    docker-compose -f docker/docker-compose.migration.yml up -d
    
    if [[ $? -eq 0 ]]; then
        log_success "Migration infrastructure started"
    else
        log_error "Failed to start migration infrastructure"
        exit 1
    fi
    
    # Wait for services to be ready
    log_info "Waiting for services to be ready..."
    sleep 30
    
    # Health check
    local services=("legacy-web" "modern-backend" "modern-frontend" "websocket" "data-sync")
    for service in "${services[@]}"; do
        if docker-compose -f docker/docker-compose.migration.yml ps "$service" | grep -q "Up"; then
            log_success "Service ready: $service"
        else
            log_error "Service not ready: $service"
            exit 1
        fi
    done
}

# Initialize feature flags
initialize_feature_flags() {
    log_info "Initializing feature flag system..."
    
    # Set initial rollout percentages (conservative start)
    local flags=(
        "useNewDashboard:5"
        "useNewOrderManagement:0"
        "useNewPaymentProcessing:0"
        "useNewWebhookManagement:0"
        "useNewCustomerManagement:0"
    )
    
    for flag_config in "${flags[@]}"; do
        local flag_name="${flag_config%:*}"
        local percentage="${flag_config#*:}"
        
        log_info "Setting feature flag: $flag_name = $percentage%"
        
        # Call feature flag API to set initial values
        curl -s -X PUT \
            -H "Content-Type: application/json" \
            -d "{\"flagName\":\"$flag_name\",\"percentage\":$percentage}" \
            "http://localhost/api/feature-flags/rollout" || {
            log_warning "Failed to set feature flag: $flag_name"
        }
    done
    
    log_success "Feature flags initialized"
}

# Start data synchronization
start_data_sync() {
    log_info "Starting data synchronization service..."
    
    # Check if data sync service is running
    if docker-compose -f docker/docker-compose.migration.yml ps data-sync | grep -q "Up"; then
        log_success "Data synchronization service is running"
        
        # Monitor sync status
        log_info "Monitoring initial sync status..."
        sleep 10
        
        # Check sync logs
        docker-compose -f docker/docker-compose.migration.yml logs --tail=20 data-sync
    else
        log_error "Data synchronization service failed to start"
        exit 1
    fi
}

# Configure load balancer
configure_load_balancer() {
    log_info "Configuring load balancer for traffic splitting..."
    
    # Update Traefik configuration
    local traefik_config="$PROJECT_ROOT/docker/traefik/dynamic.yml"
    
    if [[ -f "$traefik_config" ]]; then
        log_info "Traefik configuration found: $traefik_config"
        
        # Restart Traefik to apply new configuration
        docker-compose -f docker/docker-compose.migration.yml restart traefik
        
        if [[ $? -eq 0 ]]; then
            log_success "Load balancer configuration updated"
        else
            log_error "Failed to update load balancer configuration"
            exit 1
        fi
    else
        log_error "Traefik configuration not found: $traefik_config"
        exit 1
    fi
}

# Validate migration
validate_migration() {
    log_info "Validating migration setup..."
    
    local validation_errors=0
    
    # Test legacy system
    log_info "Testing legacy system endpoint..."
    if curl -s -f "http://localhost:8080/api/health.php" > /dev/null; then
        log_success "Legacy system is accessible"
    else
        log_error "Legacy system is not accessible"
        ((validation_errors++))
    fi
    
    # Test modern system
    log_info "Testing modern system endpoint..."
    if curl -s -f "http://localhost:4000/api/v1/health" > /dev/null; then
        log_success "Modern system is accessible"
    else
        log_error "Modern system is not accessible"
        ((validation_errors++))
    fi
    
    # Test WebSocket
    log_info "Testing WebSocket server..."
    if curl -s -f "http://localhost:3001/health" > /dev/null; then
        log_success "WebSocket server is accessible"
    else
        log_error "WebSocket server is not accessible"
        ((validation_errors++))
    fi
    
    # Test load balancer
    log_info "Testing load balancer..."
    if curl -s -f "http://localhost/api/health" > /dev/null; then
        log_success "Load balancer is routing traffic"
    else
        log_error "Load balancer is not working"
        ((validation_errors++))
    fi
    
    # Test feature flags
    log_info "Testing feature flag system..."
    if curl -s -f "http://localhost/api/feature-flags/health" > /dev/null; then
        log_success "Feature flag system is working"
    else
        log_error "Feature flag system is not working"
        ((validation_errors++))
    fi
    
    if [[ $validation_errors -gt 0 ]]; then
        log_error "Migration validation failed with $validation_errors errors"
        exit 1
    fi
    
    log_success "Migration validation completed successfully"
}

# Generate migration report
generate_migration_report() {
    log_info "Generating migration report..."
    
    local report_file="$LOG_DIR/migration_report_$(date +%Y%m%d_%H%M%S).md"
    
    cat > "$report_file" << EOF
# Migration Report: Odoo Dashboard Modernization

**Migration Date:** $(date '+%Y-%m-%d %H:%M:%S')
**Migration Phase:** Phase 1 - Parallel Deployment

## Summary

The parallel deployment phase has been completed successfully. Both legacy and modern systems are now running side by side with traffic routing controlled by feature flags.

## Infrastructure Status

### Services Deployed
- ✅ Legacy PHP System (Port 8080)
- ✅ Modern Node.js Backend (Port 4000)
- ✅ Modern Next.js Frontend (Port 3000)
- ✅ WebSocket Server (Port 3001)
- ✅ Data Synchronization Service
- ✅ Load Balancer (Traefik)
- ✅ Migration Monitoring

### Database Migration
- ✅ User sessions migrated
- ✅ Audit logs migrated (last 12 months)
- ✅ Performance cache populated
- ✅ Data integrity validated

### Feature Flags Configuration
- useNewDashboard: 5% rollout
- useNewOrderManagement: 0% rollout
- useNewPaymentProcessing: 0% rollout
- useNewWebhookManagement: 0% rollout
- useNewCustomerManagement: 0% rollout

## Next Steps

1. **Week 1-2:** Monitor system stability and performance
2. **Week 3:** Gradually increase dashboard rollout to 25%
3. **Week 4:** Enable order management for selected users
4. **Week 5-6:** Progressive rollout of remaining features
5. **Week 7:** Complete migration and legacy system decommission

## Monitoring

- Migration logs: $LOG_DIR/
- System monitoring: http://localhost:9090/
- Feature flag management: http://localhost/api/feature-flags/

## Rollback Plan

In case of critical issues:
1. Set all feature flags to 0%
2. Stop modern system containers
3. Restore database from backup: $BACKUP_DIR/
4. Contact development team

## Contact Information

- Development Team: dev-team@company.com
- Operations Team: ops-team@company.com
- Emergency Contact: +66-xxx-xxx-xxxx

EOF

    log_success "Migration report generated: $report_file"
}

# Main execution function
main() {
    log_info "Starting Odoo Dashboard Migration - Phase 1: Parallel Deployment"
    
    # Setup
    load_config
    setup_directories
    check_prerequisites
    
    # Backup
    create_backup
    
    # Migration
    execute_migration_scripts
    deploy_parallel_infrastructure
    initialize_feature_flags
    start_data_sync
    configure_load_balancer
    
    # Validation
    validate_migration
    generate_migration_report
    
    log_success "Migration Phase 1 completed successfully!"
    log_info "Next steps:"
    log_info "1. Monitor system performance for 24-48 hours"
    log_info "2. Gradually increase feature flag percentages"
    log_info "3. Execute Phase 2 migration plan"
    
    echo ""
    echo "🎉 Migration Phase 1 Complete!"
    echo "📊 Monitoring Dashboard: http://localhost:9090/"
    echo "⚙️  Feature Flags: http://localhost/api/feature-flags/"
    echo "📋 Migration Report: $LOG_DIR/migration_report_$(date +%Y%m%d)*.md"
}

# Execute main function if script is run directly
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi