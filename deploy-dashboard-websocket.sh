#!/bin/bash

# Dashboard WebSocket Server Deployment Script
# 
# This script deploys the dashboard WebSocket server alongside the existing
# websocket-server.js for the LINE Telepharmacy Platform.
#
# Requirements: FR-1.4, TC-1.4

set -e

echo "=== Dashboard WebSocket Server Deployment ==="
echo "Starting deployment at $(date)"

# Configuration
WEBSOCKET_PORT=${DASHBOARD_WEBSOCKET_PORT:-3001}
PM2_APP_NAME="dashboard-websocket"
NODE_ENV=${NODE_ENV:-production}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    print_error "Node.js is not installed. Please install Node.js 16+ first."
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node --version | cut -d'v' -f2)
REQUIRED_VERSION="16.0.0"

if ! node -e "process.exit(require('semver').gte('$NODE_VERSION', '$REQUIRED_VERSION') ? 0 : 1)" 2>/dev/null; then
    print_error "Node.js version $NODE_VERSION is too old. Required: $REQUIRED_VERSION+"
    exit 1
fi

print_status "Node.js version: $NODE_VERSION ✓"

# Check if PM2 is installed
if ! command -v pm2 &> /dev/null; then
    print_warning "PM2 is not installed. Installing PM2..."
    npm install -g pm2
fi

print_status "PM2 version: $(pm2 --version) ✓"

# Install dependencies for dashboard WebSocket server
print_status "Installing dashboard WebSocket server dependencies..."

# Create package.json if it doesn't exist
if [ ! -f "package-dashboard.json" ]; then
    print_error "package-dashboard.json not found. Please ensure the dashboard WebSocket files are present."
    exit 1
fi

# Install dependencies
npm install --production --package-lock-only --package-lock=false \
    express@^4.18.2 \
    socket.io@^4.6.1 \
    @socket.io/redis-adapter@^8.2.1 \
    mysql2@^3.6.5 \
    redis@^4.6.5 \
    jsonwebtoken@^9.0.2 \
    dotenv@^16.3.1

print_status "Dependencies installed ✓"

# Check if .env file exists
if [ ! -f ".env" ]; then
    print_warning ".env file not found. Creating from .env.example..."
    if [ -f ".env.example" ]; then
        cp .env.example .env
        print_warning "Please configure .env file with your settings"
    else
        print_error "No .env.example file found. Please create .env file manually."
        exit 1
    fi
fi

# Validate environment variables
print_status "Validating environment configuration..."

# Check required environment variables
REQUIRED_VARS=("DB_HOST" "DB_USER" "DB_PASSWORD" "DB_NAME" "REDIS_HOST")
MISSING_VARS=()

for var in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!var}" ] && ! grep -q "^${var}=" .env; then
        MISSING_VARS+=("$var")
    fi
done

if [ ${#MISSING_VARS[@]} -ne 0 ]; then
    print_error "Missing required environment variables: ${MISSING_VARS[*]}"
    print_error "Please configure these in your .env file"
    exit 1
fi

print_status "Environment configuration ✓"

# Test database connection
print_status "Testing database connection..."
if ! node -e "
const mysql = require('mysql2/promise');
require('dotenv').config();
(async () => {
    try {
        const connection = await mysql.createConnection({
            host: process.env.DB_HOST || 'localhost',
            user: process.env.DB_USER,
            password: process.env.DB_PASSWORD,
            database: process.env.DB_NAME
        });
        await connection.execute('SELECT 1');
        await connection.end();
        console.log('Database connection successful');
    } catch (error) {
        console.error('Database connection failed:', error.message);
        process.exit(1);
    }
})();
"; then
    print_error "Database connection test failed"
    exit 1
fi

print_status "Database connection ✓"

# Test Redis connection
print_status "Testing Redis connection..."
if ! node -e "
const redis = require('redis');
require('dotenv').config();
(async () => {
    try {
        const client = redis.createClient({
            host: process.env.REDIS_HOST || 'localhost',
            port: parseInt(process.env.REDIS_PORT || '6379'),
            password: process.env.REDIS_PASSWORD || undefined
        });
        await client.connect();
        await client.ping();
        await client.quit();
        console.log('Redis connection successful');
    } catch (error) {
        console.error('Redis connection failed:', error.message);
        process.exit(1);
    }
})();
"; then
    print_error "Redis connection test failed"
    exit 1
fi

print_status "Redis connection ✓"

# Stop existing PM2 process if running
if pm2 list | grep -q "$PM2_APP_NAME"; then
    print_status "Stopping existing dashboard WebSocket server..."
    pm2 stop "$PM2_APP_NAME" || true
    pm2 delete "$PM2_APP_NAME" || true
fi

# Start dashboard WebSocket server with PM2
print_status "Starting dashboard WebSocket server on port $WEBSOCKET_PORT..."

pm2 start websocket-dashboard-server.js \
    --name "$PM2_APP_NAME" \
    --env "$NODE_ENV" \
    --max-memory-restart 500M \
    --time \
    --merge-logs \
    --log-date-format "YYYY-MM-DD HH:mm:ss Z"

# Wait for server to start
sleep 3

# Check if server is running
if pm2 list | grep -q "$PM2_APP_NAME.*online"; then
    print_status "Dashboard WebSocket server started successfully ✓"
else
    print_error "Failed to start dashboard WebSocket server"
    pm2 logs "$PM2_APP_NAME" --lines 20
    exit 1
fi

# Test WebSocket server health
print_status "Testing WebSocket server health..."
if curl -f "http://localhost:$WEBSOCKET_PORT/health" > /dev/null 2>&1; then
    print_status "WebSocket server health check ✓"
else
    print_warning "WebSocket server health check failed (this may be normal if server is still starting)"
fi

# Save PM2 configuration
pm2 save

print_status "Dashboard WebSocket server deployment completed successfully!"
print_status "Server is running on port $WEBSOCKET_PORT"
print_status "PM2 app name: $PM2_APP_NAME"

echo ""
echo "=== Useful Commands ==="
echo "View logs:     pm2 logs $PM2_APP_NAME"
echo "Restart:       pm2 restart $PM2_APP_NAME"
echo "Stop:          pm2 stop $PM2_APP_NAME"
echo "Status:        pm2 status"
echo "Health check:  curl http://localhost:$WEBSOCKET_PORT/health"
echo ""

# Show current status
print_status "Current PM2 status:"
pm2 list

echo ""
echo "=== Deployment completed at $(date) ==="