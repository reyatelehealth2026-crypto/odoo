#!/bin/bash
# Development Environment Shutdown Script
# Gracefully stops all development services

set -e

echo "🛑 Stopping Odoo Dashboard Development Environment..."

# Stop services
docker-compose -f docker-compose.dev.yml --env-file .env.dev down

echo "✅ Development environment stopped!"
echo ""
echo "💡 To remove volumes (database data), run:"
echo "   docker-compose -f docker-compose.dev.yml down -v"