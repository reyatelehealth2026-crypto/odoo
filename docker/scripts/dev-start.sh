#!/bin/bash
# Development Environment Startup Script
# Starts all services with hot reload and development tools

set -e

echo "🚀 Starting Odoo Dashboard Development Environment..."

# Check if .env.dev exists
if [ ! -f .env.dev ]; then
    echo "📋 Creating .env.dev from example..."
    cp .env.dev.example .env.dev
    echo "⚠️  Please update .env.dev with your configuration"
fi

# Create necessary directories
echo "📁 Creating log directories..."
mkdir -p logs/backend logs/websocket logs/nginx

# Build and start services
echo "🔨 Building and starting services..."
docker-compose -f docker-compose.dev.yml --env-file .env.dev up --build -d

# Wait for services to be healthy
echo "⏳ Waiting for services to be ready..."
sleep 10

# Check service health
echo "🏥 Checking service health..."
docker-compose -f docker-compose.dev.yml ps

# Run database migrations if needed
echo "🗄️  Running database migrations..."
docker-compose -f docker-compose.dev.yml exec backend npm run prisma:migrate || echo "⚠️  Migration failed or not needed"

# Generate Prisma client
echo "🔧 Generating Prisma client..."
docker-compose -f docker-compose.dev.yml exec backend npm run prisma:generate || echo "⚠️  Prisma generation failed"

echo "✅ Development environment is ready!"
echo ""
echo "🌐 Services available at:"
echo "   Frontend:  http://localhost:3000"
echo "   Backend:   http://localhost:4000"
echo "   WebSocket: http://localhost:3001"
echo "   Nginx:     http://localhost:8080"
echo "   MySQL:     localhost:3306"
echo "   Redis:     localhost:6379"
echo ""
echo "📊 View logs with: docker-compose -f docker-compose.dev.yml logs -f [service]"
echo "🛑 Stop with: ./docker/scripts/dev-stop.sh"