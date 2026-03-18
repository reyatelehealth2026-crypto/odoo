#!/bin/bash

# Blue-Green Deployment Script for Odoo Dashboard
# Provides zero-downtime deployment with automatic rollback capability

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="docker-compose.prod.yml"
HEALTH_CHECK_TIMEOUT=300
HEALTH_CHECK_INTERVAL=10

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
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
    
    local deps=("docker" "docker-compose" "curl" "jq")
    for dep in "${deps[@]}"; do
        if ! command -v "$dep" &> /dev/null; then
            log_error "$dep is not installed or not in PATH"
            exit 1
        fi
    done
    
    log_success "All dependencies are available"
}

# Get current active environment (blue or green)
get_active_environment() {
    local nginx_config="/tmp/nginx_current.conf"
    
    # Extract current upstream from nginx config
    if docker-compose -f "$COMPOSE_FILE" exec -T nginx cat /etc/nginx/conf.d/default.conf > "$nginx_config" 2>/dev/null; then
        if grep -q "server frontend-blue:" "$nginx_config"; then
            echo "blue"
        elif grep -q "server frontend-green:" "$nginx_config"; then
            echo "green"
        else
            echo "none"
        fi
    else
        echo "none"
    fi
    
    rm -f "$nginx_config"
}

# Get inactive environment (opposite of active)
get_inactive_environment() {
    local active="$1"
    if [[ "$active" == "blue" ]]; then
        echo "green"
    elif [[ "$active" == "green" ]]; then
        echo "blue"
    else
        echo "blue"  # Default to blue if no active environment
    fi
}

# Health check for services
health_check() {
    local environment="$1"
    local service_name="$2"
    local health_endpoint="$3"
    local timeout="$4"
    
    log_info "Performing health check for $service_name ($environment)..."
    
    local start_time=$(date +%s)
    local end_time=$((start_time + timeout))
    
    while [[ $(date +%s) -lt $end_time ]]; do
        if docker-compose -f "$COMPOSE_FILE" exec -T "$service_name-$environment" curl -f "$health_endpoint" &>/dev/null; then
            log_success "$service_name ($environment) is healthy"
            return 0
        fi
        
        log_info "Waiting for $service_name ($environment) to become healthy..."
        sleep "$HEALTH_CHECK_INTERVAL"
    done
    
    log_error "$service_name ($environment) failed health check after ${timeout}s"
    return 1
}

# Wait for all services to be healthy
wait_for_services() {
    local environment="$1"
    
    log_info "Waiting for all services in $environment environment to be healthy..."
    
    # Check backend health
    if ! health_check "$environment" "backend" "http://localhost:4000/health" "$HEALTH_CHECK_TIMEOUT"; then
        return 1
    fi
    
    # Check frontend health
    if ! health_check "$environment" "frontend" "http://localhost:3000/api/health" "$HEALTH_CHECK_TIMEOUT"; then
        return 1
    fi
    
    # Check websocket health
    if ! health_check "$environment" "websocket" "http://localhost:3001/health" "$HEALTH_CHECK_TIMEOUT"; then
        return 1
    fi
    
    log_success "All services in $environment environment are healthy"
    return 0
}

# Update nginx configuration to switch traffic
switch_traffic() {
    local target_environment="$1"
    
    log_info "Switching traffic to $target_environment environment..."
    
    # Create new nginx config
    local nginx_config_template="$PROJECT_ROOT/docker/nginx/blue-green-template.conf"
    local nginx_config_active="$PROJECT_ROOT/docker/nginx/active.conf"
    
    # Replace placeholders in template
    sed "s/{{ENVIRONMENT}}/$target_environment/g" "$nginx_config_template" > "$nginx_config_active"
    
    # Update nginx configuration
    docker-compose -f "$COMPOSE_FILE" exec -T nginx nginx -s reload
    
    if [[ $? -eq 0 ]]; then
        log_success "Traffic switched to $target_environment environment"
        return 0
    else
        log_error "Failed to switch traffic to $target_environment environment"
        return 1
    fi
}

# Rollback to previous environment
rollback() {
    local previous_environment="$1"
    
    log_warning "Initiating rollback to $previous_environment environment..."
    
    if switch_traffic "$previous_environment"; then
        log_success "Rollback completed successfully"
        return 0
    else
        log_error "Rollback failed"
        return 1
    fi
}

# Deploy new version to inactive environment
deploy_to_environment() {
    local environment="$1"
    local image_tag="$2"
    
    log_info "Deploying version $image_tag to $environment environment..."
    
    # Set environment-specific variables
    export DEPLOY_ENVIRONMENT="$environment"
    export IMAGE_TAG="$image_tag"
    
    # Build and start services in target environment
    docker-compose -f "$COMPOSE_FILE" -f "docker-compose.$environment.yml" build
    docker-compose -f "$COMPOSE_FILE" -f "docker-compose.$environment.yml" up -d
    
    # Wait for services to be ready
    if wait_for_services "$environment"; then
        log_success "Deployment to $environment environment completed"
        return 0
    else
        log_error "Deployment to $environment environment failed"
        return 1
    fi
}

# Cleanup old environment
cleanup_environment() {
    local environment="$1"
    
    log_info "Cleaning up $environment environment..."
    
    # Stop and remove containers
    docker-compose -f "$COMPOSE_FILE" -f "docker-compose.$environment.yml" down
    
    # Remove unused images (keep last 3 versions)
    docker images --format "table {{.Repository}}:{{.Tag}}\t{{.CreatedAt}}" | \
        grep "odoo-dashboard" | \
        sort -k2 -r | \
        tail -n +4 | \
        awk '{print $1}' | \
        xargs -r docker rmi
    
    log_success "Cleanup of $environment environment completed"
}

# Main deployment function
main() {
    local image_tag="${1:-latest}"
    local skip_tests="${2:-false}"
    
    log_info "Starting blue-green deployment for Odoo Dashboard"
    log_info "Image tag: $image_tag"
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Check dependencies
    check_dependencies
    
    # Get current environment state
    local active_env=$(get_active_environment)
    local target_env=$(get_inactive_environment "$active_env")
    
    log_info "Active environment: $active_env"
    log_info "Target environment: $target_env"
    
    # Deploy to inactive environment
    if ! deploy_to_environment "$target_env" "$image_tag"; then
        log_error "Deployment failed"
        exit 1
    fi
    
    # Run smoke tests if not skipped
    if [[ "$skip_tests" != "true" ]]; then
        log_info "Running smoke tests..."
        if ! bash "$SCRIPT_DIR/smoke-tests.sh" "$target_env"; then
            log_error "Smoke tests failed"
            cleanup_environment "$target_env"
            exit 1
        fi
        log_success "Smoke tests passed"
    fi
    
    # Switch traffic to new environment
    if ! switch_traffic "$target_env"; then
        log_error "Traffic switch failed"
        cleanup_environment "$target_env"
        exit 1
    fi
    
    # Wait a bit and verify the switch was successful
    sleep 30
    log_info "Verifying deployment..."
    
    if wait_for_services "$target_env"; then
        log_success "Deployment verification successful"
        
        # Cleanup old environment
        if [[ "$active_env" != "none" ]]; then
            cleanup_environment "$active_env"
        fi
        
        log_success "Blue-green deployment completed successfully!"
        log_info "Active environment is now: $target_env"
    else
        log_error "Deployment verification failed, initiating rollback..."
        if [[ "$active_env" != "none" ]]; then
            rollback "$active_env"
        fi
        cleanup_environment "$target_env"
        exit 1
    fi
}

# Handle script arguments
case "${1:-}" in
    "deploy")
        main "${2:-latest}" "${3:-false}"
        ;;
    "rollback")
        active_env=$(get_active_environment)
        previous_env=$(get_inactive_environment "$active_env")
        rollback "$previous_env"
        ;;
    "status")
        active_env=$(get_active_environment)
        echo "Active environment: $active_env"
        ;;
    *)
        echo "Usage: $0 {deploy|rollback|status} [image_tag] [skip_tests]"
        echo ""
        echo "Commands:"
        echo "  deploy [tag] [skip_tests]  Deploy new version (default: latest, false)"
        echo "  rollback                   Rollback to previous version"
        echo "  status                     Show current active environment"
        echo ""
        echo "Examples:"
        echo "  $0 deploy v1.2.3"
        echo "  $0 deploy latest true"
        echo "  $0 rollback"
        echo "  $0 status"
        exit 1
        ;;
esac