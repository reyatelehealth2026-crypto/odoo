#!/bin/bash

# Rollback Script for Blue-Green Deployment
# Provides quick rollback capability in case of deployment issues

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="docker-compose.prod.yml"
ROLLBACK_TIMEOUT=60

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[ROLLBACK]${NC} $1"
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

# Get current active environment
get_active_environment() {
    local deployment_status=$(curl -s http://localhost/deployment-status 2>/dev/null || echo "")
    
    if echo "$deployment_status" | grep -q "blue"; then
        echo "blue"
    elif echo "$deployment_status" | grep -q "green"; then
        echo "green"
    else
        echo "unknown"
    fi
}

# Get previous environment from deployment history
get_previous_environment() {
    local current="$1"
    
    if [[ "$current" == "blue" ]]; then
        echo "green"
    elif [[ "$current" == "green" ]]; then
        echo "blue"
    else
        # If current is unknown, try to determine from running containers
        if docker ps --format "table {{.Names}}" | grep -q "backend-blue"; then
            echo "blue"
        elif docker ps --format "table {{.Names}}" | grep -q "backend-green"; then
            echo "green"
        else
            echo "blue"  # Default fallback
        fi
    fi
}

# Check if environment is healthy
check_environment_health() {
    local environment="$1"
    
    log_info "Checking health of $environment environment..."
    
    # Check if containers are running
    local containers=("backend-$environment" "frontend-$environment" "websocket-$environment")
    
    for container in "${containers[@]}"; do
        if ! docker ps --format "table {{.Names}}" | grep -q "$container"; then
            log_error "Container $container is not running"
            return 1
        fi
    done
    
    # Run health checks
    if bash "$SCRIPT_DIR/smoke-tests.sh" "$environment" &>/dev/null; then
        log_success "$environment environment is healthy"
        return 0
    else
        log_error "$environment environment failed health checks"
        return 1
    fi
}

# Start environment if not running
start_environment() {
    local environment="$1"
    
    log_info "Starting $environment environment..."
    
    # Start the environment
    docker-compose -f "$COMPOSE_FILE" -f "docker-compose.$environment.yml" up -d
    
    # Wait for services to be ready
    local timeout=60
    local elapsed=0
    
    while [[ $elapsed -lt $timeout ]]; do
        if check_environment_health "$environment"; then
            log_success "$environment environment started successfully"
            return 0
        fi
        
        log_info "Waiting for $environment environment to be ready..."
        sleep 10
        elapsed=$((elapsed + 10))
    done
    
    log_error "Failed to start $environment environment within ${timeout}s"
    return 1
}

# Switch nginx traffic to target environment
switch_traffic() {
    local target_environment="$1"
    
    log_info "Switching traffic to $target_environment environment..."
    
    # Create new nginx config from template
    local nginx_config_template="$PROJECT_ROOT/docker/nginx/blue-green-template.conf"
    local nginx_config_active="$PROJECT_ROOT/docker/nginx/active.conf"
    
    # Replace placeholders in template
    sed "s/{{ENVIRONMENT}}/$target_environment/g" "$nginx_config_template" > "$nginx_config_active"
    
    # Copy new config to nginx container and reload
    docker cp "$nginx_config_active" "$(docker-compose -f "$COMPOSE_FILE" ps -q nginx):/etc/nginx/conf.d/default.conf"
    docker-compose -f "$COMPOSE_FILE" exec -T nginx nginx -s reload
    
    if [[ $? -eq 0 ]]; then
        log_success "Traffic switched to $target_environment environment"
        return 0
    else
        log_error "Failed to switch traffic to $target_environment environment"
        return 1
    fi
}

# Record rollback event
record_rollback() {
    local from_env="$1"
    local to_env="$2"
    local reason="$3"
    
    local rollback_log="$PROJECT_ROOT/logs/rollback.log"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    mkdir -p "$(dirname "$rollback_log")"
    echo "[$timestamp] ROLLBACK: $from_env -> $to_env | Reason: $reason" >> "$rollback_log"
}

# Emergency rollback (fastest possible)
emergency_rollback() {
    local current_env="$1"
    local target_env="$2"
    
    log_warning "Performing EMERGENCY rollback from $current_env to $target_env"
    
    # Skip health checks, just switch traffic immediately
    if switch_traffic "$target_env"; then
        record_rollback "$current_env" "$target_env" "Emergency rollback"
        log_success "Emergency rollback completed"
        return 0
    else
        log_error "Emergency rollback failed"
        return 1
    fi
}

# Standard rollback with validation
standard_rollback() {
    local current_env="$1"
    local target_env="$2"
    
    log_info "Performing standard rollback from $current_env to $target_env"
    
    # Check if target environment is available and healthy
    if ! check_environment_health "$target_env"; then
        log_warning "Target environment $target_env is not healthy, attempting to start..."
        
        if ! start_environment "$target_env"; then
            log_error "Failed to start target environment $target_env"
            return 1
        fi
    fi
    
    # Switch traffic to target environment
    if switch_traffic "$target_env"; then
        # Verify the rollback was successful
        sleep 10
        if check_environment_health "$target_env"; then
            record_rollback "$current_env" "$target_env" "Standard rollback"
            log_success "Standard rollback completed successfully"
            return 0
        else
            log_error "Rollback verification failed"
            return 1
        fi
    else
        log_error "Failed to switch traffic during rollback"
        return 1
    fi
}

# Main rollback function
main() {
    local rollback_type="${1:-standard}"
    local target_env="${2:-}"
    
    log_info "Starting rollback process..."
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Determine current and target environments
    local current_env=$(get_active_environment)
    
    if [[ -z "$target_env" ]]; then
        target_env=$(get_previous_environment "$current_env")
    fi
    
    log_info "Current environment: $current_env"
    log_info "Target environment: $target_env"
    
    # Validate environments
    if [[ "$current_env" == "$target_env" ]]; then
        log_error "Current and target environments are the same"
        exit 1
    fi
    
    # Perform rollback based on type
    case "$rollback_type" in
        "emergency")
            emergency_rollback "$current_env" "$target_env"
            ;;
        "standard"|*)
            standard_rollback "$current_env" "$target_env"
            ;;
    esac
    
    local rollback_result=$?
    
    if [[ $rollback_result -eq 0 ]]; then
        log_success "Rollback completed successfully!"
        log_info "Active environment is now: $target_env"
        
        # Show rollback status
        echo ""
        echo "Rollback Summary:"
        echo "  From: $current_env"
        echo "  To: $target_env"
        echo "  Type: $rollback_type"
        echo "  Status: SUCCESS"
    else
        log_error "Rollback failed!"
        exit 1
    fi
}

# Handle script arguments
case "${1:-}" in
    "emergency")
        main "emergency" "${2:-}"
        ;;
    "standard"|"")
        main "standard" "${2:-}"
        ;;
    "status")
        current_env=$(get_active_environment)
        previous_env=$(get_previous_environment "$current_env")
        echo "Current environment: $current_env"
        echo "Previous environment: $previous_env"
        ;;
    *)
        echo "Usage: $0 {standard|emergency|status} [target_environment]"
        echo ""
        echo "Commands:"
        echo "  standard [env]    Standard rollback with health checks (default)"
        echo "  emergency [env]   Emergency rollback (fastest, skips health checks)"
        echo "  status           Show current and previous environments"
        echo ""
        echo "Examples:"
        echo "  $0 standard"
        echo "  $0 emergency blue"
        echo "  $0 status"
        exit 1
        ;;
esac