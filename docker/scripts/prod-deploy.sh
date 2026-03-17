#!/bin/bash
# Production Deployment Script
# Deploys the Odoo Dashboard to production with zero downtime

set -e

echo "🚀 Deploying Odoo Dashboard to Production..."

# Check if .env.prod exists
if [ ! -f .env.prod ]; then
    echo "❌ .env.prod file not found!"
    echo "Please create .env.prod from .env.prod.example"
    exit 1
fi

# Validate required environment variables
source .env.prod
required_vars=("DB_PASSWORD" "JWT_SECRET" "DOMAIN_NAME")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Required environment variable $var is not set in .env.prod"
        exit 1
    fi
done

# Create necessary directories
echo "📁 Creating production directories..."
mkdir -p logs/backend logs/websocket logs/nginx
mkdir -p docker/nginx/ssl

# Check SSL certificates
if [ ! -f docker/nginx/ssl/cert.pem ] || [ ! -f docker/nginx/ssl/key.pem ]; then
    echo "⚠️  SSL certificates not found in docker/nginx/ssl/"
    echo "Please add cert.pem and key.pem for HTTPS support"
    echo "Continuing with HTTP only..."
fi

# Build production images
echo "🔨 Building production images..."
docker-compose -f docker-compose.prod.yml --env-file .env.prod build --no-cache

# Run database migrations
echo "🗄️  Running database migrations..."
docker-compose -f docker-compose.prod.yml --env-file .env.prod run --rm backend npm run prisma:migrate

# Start services with rolling update
echo "🔄 Starting production services..."
docker-compose -f docker-compose.prod.yml --env-file .env.prod up -d

# Wait for services to be healthy
echo "⏳ Waiting for services to be ready..."
sleep 30

# Health check
echo "🏥 Performing health checks..."
max_attempts=10
attempt=1

while [ $attempt -le $max_attempts ]; do
    if curl -f http://localhost/health > /dev/null 2>&1; then
        echo "✅ Health check passed!"
        break
    else
        echo "⏳ Health check attempt $attempt/$max_attempts failed, retrying..."
        sleep 10
        ((attempt++))
    fi
done

if [ $attempt -gt $max_attempts ]; then
    echo "❌ Health check failed after $max_attempts attempts"
    echo "🔍 Checking service status..."
    docker-compose -f docker-compose.prod.yml ps
    exit 1
fi

# Show deployment status
echo "📊 Deployment Status:"
docker-compose -f docker-compose.prod.yml ps

echo ""
echo "✅ Production deployment completed successfully!"
echo ""
echo "🌐 Services available at:"
echo "   Frontend:  https://$DOMAIN_NAME"
echo "   API:       https://$DOMAIN_NAME/api"
echo "   WebSocket: wss://$DOMAIN_NAME/socket.io"
echo ""
echo "📊 Monitor with: docker-compose -f docker-compose.prod.yml logs -f [service]"
echo "🛑 Stop with: ./docker/scripts/prod-stop.sh"