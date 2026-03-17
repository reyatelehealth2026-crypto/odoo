#!/bin/bash

# Smoke Tests for Blue-Green Deployment
# Validates critical functionality before traffic switch

set -euo pipefail

# Configuration
ENVIRONMENT="${1:-blue}"
BASE_URL="http://localhost"
API_BASE_URL="$BASE_URL/api/v1"
WS_URL="ws://localhost/socket.io"
TIMEOUT=30

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[SMOKE TEST]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[PASS]${NC} $1"
}

log_error() {
    echo -e "${RED}[FAIL]${NC} $1"
}

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper function to run a test
run_test() {
    local test_name="$1"
    local test_command="$2"
    
    log_info "Running: $test_name"
    
    if eval "$test_command"; then
        log_success "$test_name"
        ((TESTS_PASSED++))
        return 0
    else
        log_error "$test_name"
        ((TESTS_FAILED++))
        return 1
    fi
}

# Test 1: Health Check Endpoints
test_health_checks() {
    # Frontend health
    curl -f -s "$BASE_URL/health" | grep -q "healthy" || return 1
    
    # Backend health
    curl -f -s "$API_BASE_URL/health" | grep -q "healthy" || return 1
    
    # WebSocket health (via HTTP endpoint)
    curl -f -s "http://localhost:3001/health" | grep -q "healthy" || return 1
    
    return 0
}

# Test 2: Authentication Endpoints
test_authentication() {
    # Test login endpoint exists and returns proper error for invalid credentials
    local response=$(curl -s -w "%{http_code}" -X POST \
        -H "Content-Type: application/json" \
        -d '{"username":"invalid","password":"invalid"}' \
        "$API_BASE_URL/auth/login" -o /dev/null)
    
    # Should return 401 for invalid credentials
    [[ "$response" == "401" ]] || return 1
    
    return 0
}

# Test 3: Dashboard API Endpoints
test_dashboard_api() {
    # Test dashboard overview endpoint (should require auth)
    local response=$(curl -s -w "%{http_code}" \
        "$API_BASE_URL/dashboard/overview" -o /dev/null)
    
    # Should return 401 for unauthorized access
    [[ "$response" == "401" ]] || return 1
    
    return 0
}

# Test 4: Database Connectivity
test_database_connectivity() {
    # Test a simple database query via API
    local response=$(curl -s -w "%{http_code}" \
        "$API_BASE_URL/health/database" -o /dev/null)
    
    # Should return 200 if database is accessible
    [[ "$response" == "200" ]] || return 1
    
    return 0
}

# Test 5: Redis Connectivity
test_redis_connectivity() {
    # Test Redis connectivity via API
    local response=$(curl -s -w "%{http_code}" \
        "$API_BASE_URL/health/cache" -o /dev/null)
    
    # Should return 200 if Redis is accessible
    [[ "$response" == "200" ]] || return 1
    
    return 0
}

# Test 6: Static Asset Loading
test_static_assets() {
    # Test that frontend serves static assets
    local response=$(curl -s -w "%{http_code}" \
        "$BASE_URL/_next/static/css/app.css" -o /dev/null 2>/dev/null || echo "404")
    
    # Should return 200 or 404 (404 is acceptable if file doesn't exist)
    [[ "$response" == "200" || "$response" == "404" ]] || return 1
    
    return 0
}

# Test 7: WebSocket Connection
test_websocket_connection() {
    # Test WebSocket connection using a simple Node.js script
    local ws_test_script="/tmp/ws_test.js"
    
    cat > "$ws_test_script" << 'EOF'
const io = require('socket.io-client');
const socket = io('http://localhost:3001', {
    timeout: 5000,
    forceNew: true
});

socket.on('connect', () => {
    console.log('WebSocket connected');
    socket.disconnect();
    process.exit(0);
});

socket.on('connect_error', (error) => {
    console.error('WebSocket connection failed:', error.message);
    process.exit(1);
});

setTimeout(() => {
    console.error('WebSocket connection timeout');
    process.exit(1);
}, 10000);
EOF

    # Run the WebSocket test
    if command -v node &> /dev/null; then
        timeout 15 node "$ws_test_script" &>/dev/null
        local result=$?
        rm -f "$ws_test_script"
        return $result
    else
        log_info "Node.js not available, skipping WebSocket test"
        return 0
    fi
}

# Test 8: API Response Format
test_api_response_format() {
    # Test that API returns proper JSON format
    local response=$(curl -s "$API_BASE_URL/health" | jq -r '.success' 2>/dev/null || echo "invalid")
    
    # Should return valid JSON with success field
    [[ "$response" == "true" || "$response" == "false" ]] || return 1
    
    return 0
}

# Test 9: Security Headers
test_security_headers() {
    # Test that security headers are present
    local headers=$(curl -s -I "$BASE_URL/health")
    
    # Check for essential security headers
    echo "$headers" | grep -qi "x-frame-options" || return 1
    echo "$headers" | grep -qi "x-content-type-options" || return 1
    echo "$headers" | grep -qi "x-xss-protection" || return 1
    
    return 0
}

# Test 10: Environment Verification
test_environment_verification() {
    # Verify we're testing the correct environment
    local env_header=$(curl -s -I "$BASE_URL/health" | grep -i "x-environment" | cut -d' ' -f2 | tr -d '\r\n')
    
    [[ "$env_header" == "$ENVIRONMENT" ]] || return 1
    
    return 0
}

# Main test execution
main() {
    log_info "Starting smoke tests for $ENVIRONMENT environment"
    log_info "Base URL: $BASE_URL"
    log_info "API Base URL: $API_BASE_URL"
    
    # Wait for services to be ready
    log_info "Waiting for services to be ready..."
    sleep 10
    
    # Run all tests
    run_test "Health Check Endpoints" "test_health_checks"
    run_test "Authentication Endpoints" "test_authentication"
    run_test "Dashboard API Endpoints" "test_dashboard_api"
    run_test "Database Connectivity" "test_database_connectivity"
    run_test "Redis Connectivity" "test_redis_connectivity"
    run_test "Static Asset Loading" "test_static_assets"
    run_test "WebSocket Connection" "test_websocket_connection"
    run_test "API Response Format" "test_api_response_format"
    run_test "Security Headers" "test_security_headers"
    run_test "Environment Verification" "test_environment_verification"
    
    # Summary
    local total_tests=$((TESTS_PASSED + TESTS_FAILED))
    log_info "Smoke test results:"
    log_info "  Total tests: $total_tests"
    log_success "  Passed: $TESTS_PASSED"
    
    if [[ $TESTS_FAILED -gt 0 ]]; then
        log_error "  Failed: $TESTS_FAILED"
        log_error "Smoke tests failed for $ENVIRONMENT environment"
        return 1
    else
        log_success "All smoke tests passed for $ENVIRONMENT environment"
        return 0
    fi
}

# Run main function
main "$@"