#!/bin/bash

# Phase Execution Script: Gradual Migration Plan
# Purpose: Execute phased rollout with monitoring and rollback capabilities
# Requirements: TC-3.1, TC-3.3

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LOG_DIR="$PROJECT_ROOT/logs/migration"
CONFIG_FILE="$PROJECT_ROOT/.env"

# Phase configuration
declare -A PHASE_CONFIG=(
    ["1"]="Week 1-2: Parallel System Monitoring"
    ["2"]="Week 3: Dashboard Rollout (5% → 25%)"
    ["3"]="Week 4: Order Management Enablement (0% → 15%)"
    ["4"]="Week 5: Payment Processing Rollout (0% → 10%)"
    ["5"]="Week 6: Full Feature Rollout (25% → 50%)"
    ["6"]="Week 7: Complete Migration (50% → 100%)"
)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/phase-execution.log"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/phase-execution.log"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/phase-execution.log"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/phase-execution.log"
}

log_phase() {
    echo -e "${PURPLE}[PHASE]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_DIR/phase-execution.log"
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

# Get current system metrics
get_system_metrics() {
    local metrics_file="$LOG_DIR/metrics_$(date +%Y%m%d_%H%M%S).json"
    
    log_info "Collecting system metrics..."
    
    # Collect metrics from various sources
    local legacy_health=$(curl -s -f "http://localhost:8080/api/health.php" || echo "unhealthy")
    local modern_health=$(curl -s -f "http://localhost:4000/api/v1/health" || echo "unhealthy")
    local websocket_health=$(curl -s -f "http://localhost:3001/health" || echo "unhealthy")
    
    # Get feature flag status
    local feature_flags=$(curl -s "http://localhost/api/feature-flags" || echo "{}")
    
    # Get routing metrics
    local routing_metrics=$(curl -s "http://localhost/api/feature-flags/metrics" || echo "{}")
    
    # Create metrics JSON
    cat > "$metrics_file" << EOF
{
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "system_health": {
        "legacy_system": "$legacy_health",
        "modern_system": "$modern_health",
        "websocket_server": "$websocket_health"
    },
    "feature_flags": $feature_flags,
    "routing_metrics": $routing_metrics,
    "infrastructure": {
        "containers_running": $(docker-compose -f docker/docker-compose.migration.yml ps --services | wc -l),
        "disk_usage": "$(df -h / | awk 'NR==2 {print $5}')",
        "memory_usage": "$(free | awk 'NR==2{printf "%.2f%%", $3*100/$2}')"
    }
}
EOF

    echo "$metrics_file"
}

# Check system health before phase execution
check_system_health() {
    log_info "Checking system health before phase execution..."
    
    local health_issues=0
    
    # Check essential services
    local services=("legacy-web" "modern-backend" "modern-frontend" "websocket" "traefik" "mysql" "redis")
    
    for service in "${services[@]}"; do
        if docker-compose -f docker/docker-compose.migration.yml ps "$service" | grep -q "Up"; then
            log_success "Service healthy: $service"
        else
            log_error "Service unhealthy: $service"
            ((health_issues++))
        fi
    done
    
    # Check database connectivity
    if mysql -h "${MYSQL_HOST:-localhost}" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" -e "SELECT 1" &>/dev/null; then
        log_success "Database connectivity: OK"
    else
        log_error "Database connectivity: FAILED"
        ((health_issues++))
    fi
    
    # Check Redis connectivity
    if redis-cli -h "${REDIS_HOST:-localhost}" ping | grep -q "PONG"; then
        log_success "Redis connectivity: OK"
    else
        log_error "Redis connectivity: FAILED"
        ((health_issues++))
    fi
    
    # Check error rates
    local error_rate=$(curl -s "http://localhost:8080/metrics" | grep -o 'error_rate{.*} [0-9.]*' | awk '{print $2}' || echo "0")
    if (( $(echo "$error_rate < 0.05" | bc -l) )); then
        log_success "Error rate acceptable: ${error_rate}%"
    else
        log_warning "Error rate elevated: ${error_rate}%"
        ((health_issues++))
    fi
    
    if [[ $health_issues -gt 0 ]]; then
        log_error "System health check failed with $health_issues issues"
        return 1
    fi
    
    log_success "System health check passed"
    return 0
}

# Update feature flag rollout percentage
update_feature_flag() {
    local flag_name="$1"
    local percentage="$2"
    local updated_by="${3:-system}"
    
    log_info "Updating feature flag: $flag_name to $percentage%"
    
    local response=$(curl -s -X PUT \
        -H "Content-Type: application/json" \
        -d "{\"flagName\":\"$flag_name\",\"percentage\":$percentage,\"updatedBy\":\"$updated_by\"}" \
        "http://localhost/api/feature-flags/rollout")
    
    if echo "$response" | grep -q '"success":true'; then
        log_success "Feature flag updated: $flag_name = $percentage%"
        return 0
    else
        log_error "Failed to update feature flag: $flag_name"
        echo "$response" | tee -a "$LOG_DIR/phase-execution.log"
        return 1
    fi
}

# Monitor system for specified duration
monitor_system() {
    local duration_minutes="$1"
    local check_interval="${2:-60}" # seconds
    
    log_info "Monitoring system for $duration_minutes minutes (checking every $check_interval seconds)"
    
    local end_time=$(($(date +%s) + duration_minutes * 60))
    local monitoring_issues=0
    
    while [[ $(date +%s) -lt $end_time ]]; do
        # Collect metrics
        local metrics_file=$(get_system_metrics)
        
        # Check for issues
        local legacy_health=$(jq -r '.system_health.legacy_system' "$metrics_file")
        local modern_health=$(jq -r '.system_health.modern_system' "$metrics_file")
        
        if [[ "$legacy_health" != "healthy" ]] || [[ "$modern_health" != "healthy" ]]; then
            log_warning "Health issue detected - Legacy: $legacy_health, Modern: $modern_health"
            ((monitoring_issues++))
        fi
        
        # Check error rates from routing metrics
        local error_rate=$(jq -r '.routing_metrics.error_rate // 0' "$metrics_file")
        if (( $(echo "$error_rate > 0.05" | bc -l) )); then
            log_warning "Elevated error rate detected: ${error_rate}%"
            ((monitoring_issues++))
        fi
        
        # Sleep until next check
        sleep "$check_interval"
    done
    
    log_info "Monitoring completed. Issues detected: $monitoring_issues"
    return $monitoring_issues
}

# Execute Phase 1: Parallel System Monitoring
execute_phase_1() {
    log_phase "Executing Phase 1: Parallel System Monitoring (Week 1-2)"
    
    # Ensure all systems are running
    if ! check_system_health; then
        log_error "Phase 1 aborted due to health check failures"
        return 1
    fi
    
    # Set conservative initial rollout
    update_feature_flag "useNewDashboard" 5
    update_feature_flag "enableRealTimeUpdates" 10
    update_feature_flag "enablePerformanceOptimizations" 25
    
    # Monitor for stability
    log_info "Starting 48-hour stability monitoring..."
    if monitor_system 2880 300; then # 48 hours, check every 5 minutes
        log_success "Phase 1 completed successfully - System stable"
        return 0
    else
        log_error "Phase 1 failed - System instability detected"
        return 1
    fi
}

# Execute Phase 2: Dashboard Rollout
execute_phase_2() {
    log_phase "Executing Phase 2: Dashboard Rollout (Week 3)"
    
    if ! check_system_health; then
        log_error "Phase 2 aborted due to health check failures"
        return 1
    fi
    
    # Gradual dashboard rollout
    local rollout_steps=(10 15 20 25)
    
    for percentage in "${rollout_steps[@]}"; do
        log_info "Increasing dashboard rollout to $percentage%"
        
        if ! update_feature_flag "useNewDashboard" "$percentage"; then
            log_error "Failed to update dashboard rollout to $percentage%"
            return 1
        fi
        
        # Monitor for 6 hours after each increase
        log_info "Monitoring system stability for 6 hours..."
        if ! monitor_system 360 180; then # 6 hours, check every 3 minutes
            log_error "Instability detected at $percentage% rollout"
            
            # Rollback to previous percentage
            local previous_percentage=$((percentage - 5))
            log_warning "Rolling back to $previous_percentage%"
            update_feature_flag "useNewDashboard" "$previous_percentage"
            return 1
        fi
        
        log_success "Dashboard rollout stable at $percentage%"
    done
    
    log_success "Phase 2 completed successfully - Dashboard at 25% rollout"
    return 0
}

# Execute Phase 3: Order Management Enablement
execute_phase_3() {
    log_phase "Executing Phase 3: Order Management Enablement (Week 4)"
    
    if ! check_system_health; then
        log_error "Phase 3 aborted due to health check failures"
        return 1
    fi
    
    # Enable order management for selected users first
    log_info "Enabling order management for admin users"
    update_feature_flag "useNewOrderManagement" 5
    
    # Monitor for 12 hours
    if ! monitor_system 720 300; then # 12 hours, check every 5 minutes
        log_error "Order management rollout failed"
        update_feature_flag "useNewOrderManagement" 0
        return 1
    fi
    
    # Increase to 15%
    log_info "Increasing order management rollout to 15%"
    update_feature_flag "useNewOrderManagement" 15
    
    # Monitor for 24 hours
    if ! monitor_system 1440 600; then # 24 hours, check every 10 minutes
        log_error "Order management rollout unstable at 15%"
        update_feature_flag "useNewOrderManagement" 5
        return 1
    fi
    
    log_success "Phase 3 completed successfully - Order management at 15% rollout"
    return 0
}

# Execute Phase 4: Payment Processing Rollout
execute_phase_4() {
    log_phase "Executing Phase 4: Payment Processing Rollout (Week 5)"
    
    if ! check_system_health; then
        log_error "Phase 4 aborted due to health check failures"
        return 1
    fi
    
    # Enable payment processing cautiously (financial operations)
    log_info "Enabling payment processing for admin users only"
    update_feature_flag "useNewPaymentProcessing" 2
    
    # Extended monitoring for financial features
    if ! monitor_system 1440 300; then # 24 hours, check every 5 minutes
        log_error "Payment processing rollout failed"
        update_feature_flag "useNewPaymentProcessing" 0
        return 1
    fi
    
    # Gradual increase
    log_info "Increasing payment processing rollout to 10%"
    update_feature_flag "useNewPaymentProcessing" 10
    
    # Monitor for 48 hours (critical financial feature)
    if ! monitor_system 2880 600; then # 48 hours, check every 10 minutes
        log_error "Payment processing rollout unstable at 10%"
        update_feature_flag "useNewPaymentProcessing" 2
        return 1
    fi
    
    log_success "Phase 4 completed successfully - Payment processing at 10% rollout"
    return 0
}

# Execute Phase 5: Full Feature Rollout
execute_phase_5() {
    log_phase "Executing Phase 5: Full Feature Rollout (Week 6)"
    
    if ! check_system_health; then
        log_error "Phase 5 aborted due to health check failures"
        return 1
    fi
    
    # Enable remaining features
    log_info "Enabling webhook management"
    update_feature_flag "useNewWebhookManagement" 20
    
    log_info "Enabling customer management"
    update_feature_flag "useNewCustomerManagement" 15
    
    # Increase main features
    log_info "Increasing dashboard rollout to 50%"
    update_feature_flag "useNewDashboard" 50
    
    log_info "Increasing order management to 30%"
    update_feature_flag "useNewOrderManagement" 30
    
    log_info "Increasing payment processing to 25%"
    update_feature_flag "useNewPaymentProcessing" 25
    
    # Comprehensive monitoring
    if ! monitor_system 2160 900; then # 36 hours, check every 15 minutes
        log_error "Full feature rollout unstable"
        
        # Rollback to Phase 4 levels
        log_warning "Rolling back to Phase 4 levels"
        update_feature_flag "useNewDashboard" 25
        update_feature_flag "useNewOrderManagement" 15
        update_feature_flag "useNewPaymentProcessing" 10
        update_feature_flag "useNewWebhookManagement" 0
        update_feature_flag "useNewCustomerManagement" 0
        return 1
    fi
    
    log_success "Phase 5 completed successfully - All features partially rolled out"
    return 0
}

# Execute Phase 6: Complete Migration
execute_phase_6() {
    log_phase "Executing Phase 6: Complete Migration (Week 7)"
    
    if ! check_system_health; then
        log_error "Phase 6 aborted due to health check failures"
        return 1
    fi
    
    # Final rollout to 100%
    log_info "Beginning final migration to 100%"
    
    # Gradual increase to 100%
    local final_steps=(60 75 90 100)
    
    for percentage in "${final_steps[@]}"; do
        log_info "Increasing all features to $percentage%"
        
        update_feature_flag "useNewDashboard" "$percentage"
        update_feature_flag "useNewOrderManagement" "$percentage"
        update_feature_flag "useNewPaymentProcessing" "$percentage"
        update_feature_flag "useNewWebhookManagement" "$percentage"
        update_feature_flag "useNewCustomerManagement" "$percentage"
        
        # Extended monitoring for final steps
        local monitor_duration=720 # 12 hours
        if [[ $percentage -eq 100 ]]; then
            monitor_duration=1440 # 24 hours for final step
        fi
        
        if ! monitor_system $monitor_duration 600; then # Check every 10 minutes
            log_error "Migration unstable at $percentage%"
            
            # Rollback to previous step
            local previous_percentage=$((percentage - 15))
            log_warning "Rolling back to $previous_percentage%"
            
            update_feature_flag "useNewDashboard" "$previous_percentage"
            update_feature_flag "useNewOrderManagement" "$previous_percentage"
            update_feature_flag "useNewPaymentProcessing" "$previous_percentage"
            update_feature_flag "useNewWebhookManagement" "$previous_percentage"
            update_feature_flag "useNewCustomerManagement" "$previous_percentage"
            
            return 1
        fi
        
        log_success "Migration stable at $percentage%"
    done
    
    # Decommission legacy system
    log_info "Migration complete - preparing legacy system decommission"
    
    # Stop legacy containers (but keep data)
    docker-compose -f docker/docker-compose.migration.yml stop legacy-web
    
    log_success "Phase 6 completed successfully - Migration to modern system complete!"
    return 0
}

# Emergency rollback function
emergency_rollback() {
    local reason="$1"
    
    log_error "EMERGENCY ROLLBACK INITIATED: $reason"
    
    # Set all feature flags to 0%
    update_feature_flag "useNewDashboard" 0
    update_feature_flag "useNewOrderManagement" 0
    update_feature_flag "useNewPaymentProcessing" 0
    update_feature_flag "useNewWebhookManagement" 0
    update_feature_flag "useNewCustomerManagement" 0
    
    # Restart legacy system if stopped
    docker-compose -f docker/docker-compose.migration.yml start legacy-web
    
    # Update Traefik weights to 100% legacy
    log_info "Updating load balancer to route 100% traffic to legacy system"
    
    # Wait for systems to stabilize
    sleep 30
    
    # Verify rollback
    if curl -s -f "http://localhost:8080/api/health.php" > /dev/null; then
        log_success "Emergency rollback completed - Legacy system operational"
    else
        log_error "Emergency rollback failed - Manual intervention required"
        exit 1
    fi
}

# Generate phase execution report
generate_phase_report() {
    local phase="$1"
    local status="$2"
    local metrics_file="$3"
    
    local report_file="$LOG_DIR/phase_${phase}_report_$(date +%Y%m%d_%H%M%S).md"
    
    cat > "$report_file" << EOF
# Phase $phase Execution Report

**Phase:** ${PHASE_CONFIG[$phase]}
**Status:** $status
**Execution Date:** $(date '+%Y-%m-%d %H:%M:%S')
**Duration:** $(grep "Phase $phase" "$LOG_DIR/phase-execution.log" | tail -2 | head -1 | cut -d' ' -f1-2) to $(date '+%Y-%m-%d %H:%M:%S')

## Summary

Phase $phase execution has been $status.

## System Metrics

$(cat "$metrics_file" | jq '.')

## Feature Flag Status

$(curl -s "http://localhost/api/feature-flags" | jq '.')

## Routing Metrics

$(curl -s "http://localhost/api/feature-flags/metrics" | jq '.')

## Recommendations

EOF

    case $status in
        "COMPLETED")
            echo "- Phase $phase completed successfully" >> "$report_file"
            echo "- System is stable and ready for next phase" >> "$report_file"
            echo "- Continue monitoring for 24 hours before next phase" >> "$report_file"
            ;;
        "FAILED")
            echo "- Phase $phase failed and requires investigation" >> "$report_file"
            echo "- Review system logs for error details" >> "$report_file"
            echo "- Consider rollback if issues persist" >> "$report_file"
            ;;
        "ROLLED_BACK")
            echo "- Phase $phase was rolled back due to instability" >> "$report_file"
            echo "- Investigate root cause before retry" >> "$report_file"
            echo "- System returned to previous stable state" >> "$report_file"
            ;;
    esac
    
    log_info "Phase $phase report generated: $report_file"
}

# Main execution function
main() {
    local phase="${1:-}"
    local auto_proceed="${2:-false}"
    
    if [[ -z "$phase" ]]; then
        echo "Usage: $0 <phase_number> [auto_proceed]"
        echo "Available phases:"
        for p in "${!PHASE_CONFIG[@]}"; do
            echo "  $p: ${PHASE_CONFIG[$p]}"
        done
        exit 1
    fi
    
    if [[ ! "${PHASE_CONFIG[$phase]:-}" ]]; then
        log_error "Invalid phase number: $phase"
        exit 1
    fi
    
    # Setup
    load_config
    mkdir -p "$LOG_DIR"
    
    log_info "Starting phase execution: Phase $phase"
    log_info "Description: ${PHASE_CONFIG[$phase]}"
    
    # Collect initial metrics
    local initial_metrics=$(get_system_metrics)
    
    # Execute phase
    local phase_status="FAILED"
    
    case $phase in
        "1")
            if execute_phase_1; then
                phase_status="COMPLETED"
            fi
            ;;
        "2")
            if execute_phase_2; then
                phase_status="COMPLETED"
            fi
            ;;
        "3")
            if execute_phase_3; then
                phase_status="COMPLETED"
            fi
            ;;
        "4")
            if execute_phase_4; then
                phase_status="COMPLETED"
            fi
            ;;
        "5")
            if execute_phase_5; then
                phase_status="COMPLETED"
            fi
            ;;
        "6")
            if execute_phase_6; then
                phase_status="COMPLETED"
            fi
            ;;
        *)
            log_error "Phase $phase not implemented"
            exit 1
            ;;
    esac
    
    # Collect final metrics
    local final_metrics=$(get_system_metrics)
    
    # Generate report
    generate_phase_report "$phase" "$phase_status" "$final_metrics"
    
    if [[ "$phase_status" == "COMPLETED" ]]; then
        log_success "Phase $phase execution completed successfully!"
        
        if [[ "$auto_proceed" == "true" ]] && [[ $phase -lt 6 ]]; then
            local next_phase=$((phase + 1))
            log_info "Auto-proceeding to Phase $next_phase in 1 hour..."
            sleep 3600
            exec "$0" "$next_phase" "$auto_proceed"
        fi
    else
        log_error "Phase $phase execution failed!"
        
        # Ask for rollback confirmation
        read -p "Do you want to perform emergency rollback? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            emergency_rollback "Phase $phase execution failed"
        fi
        
        exit 1
    fi
}

# Handle interrupt signals for emergency rollback
trap 'emergency_rollback "Script interrupted"' INT TERM

# Execute main function if script is run directly
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi