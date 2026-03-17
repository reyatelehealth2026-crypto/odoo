#!/bin/bash
# Production Environment Shutdown Script
# Gracefully stops production services

set -e

echo "🛑 Stopping Odoo Dashboard Production Environment..."

# Check if .env.prod exists
if [ ! -f .env.prod ]; then
    echo "❌ .env.prod file not found!"
    exit 1
fi

# Stop services gracefully
echo "⏳ Gracefully stopping services..."
docker-compose -f docker-compose.prod.yml --env-file .env.prod stop

# Remove containers
echo "🗑️  Removing containers..."
docker-compose -f docker-compose.prod.yml --env-file .env.prod down

echo "✅ Production environment stopped!"
echo ""
echo "💡 To remove volumes (database data), run:"
echo "   docker-compose -f docker-compose.prod.yml down -v"
echo ""
echo "⚠️  WARNING: This will permanently delete all data!"