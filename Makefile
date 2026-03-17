# Makefile for Odoo Dashboard Docker Operations
# Simplifies common Docker commands for development and production

.PHONY: help dev-start dev-stop dev-logs dev-shell prod-deploy prod-stop prod-logs clean

# Default target
help:
	@echo "Odoo Dashboard Docker Commands"
	@echo "=============================="
	@echo ""
	@echo "Development:"
	@echo "  dev-start    Start development environment"
	@echo "  dev-stop     Stop development environment"
	@echo "  dev-logs     View development logs"
	@echo "  dev-shell    Open shell in backend container"
	@echo "  dev-clean    Clean development environment"
	@echo ""
	@echo "Production:"
	@echo "  prod-deploy  Deploy to production"
	@echo "  prod-stop    Stop production environment"
	@echo "  prod-logs    View production logs"
	@echo "  prod-clean   Clean production environment"
	@echo ""
	@echo "Database:"
	@echo "  db-migrate   Run database migrations"
	@echo "  db-seed      Seed database with test data"
	@echo "  db-studio    Open Prisma Studio"
	@echo ""
	@echo "Utilities:"
	@echo "  clean        Clean all Docker resources"
	@echo "  health       Check service health"

# Development commands
dev-start:
	@echo "🚀 Starting development environment..."
	@chmod +x docker/scripts/dev-start.sh
	@./docker/scripts/dev-start.sh

dev-stop:
	@echo "🛑 Stopping development environment..."
	@chmod +x docker/scripts/dev-stop.sh
	@./docker/scripts/dev-stop.sh

dev-logs:
	@docker-compose -f docker-compose.dev.yml logs -f

dev-shell:
	@docker-compose -f docker-compose.dev.yml exec backend /bin/sh

dev-clean:
	@echo "🧹 Cleaning development environment..."
	@docker-compose -f docker-compose.dev.yml down -v --remove-orphans
	@docker system prune -f

# Production commands
prod-deploy:
	@echo "🚀 Deploying to production..."
	@chmod +x docker/scripts/prod-deploy.sh
	@./docker/scripts/prod-deploy.sh

prod-stop:
	@echo "🛑 Stopping production environment..."
	@chmod +x docker/scripts/prod-stop.sh
	@./docker/scripts/prod-stop.sh

prod-logs:
	@docker-compose -f docker-compose.prod.yml logs -f

prod-clean:
	@echo "🧹 Cleaning production environment..."
	@docker-compose -f docker-compose.prod.yml down -v --remove-orphans

# Database commands
db-migrate:
	@echo "🗄️ Running database migrations..."
	@docker-compose -f docker-compose.dev.yml exec backend npm run prisma:migrate

db-seed:
	@echo "🌱 Seeding database..."
	@docker-compose -f docker-compose.dev.yml exec backend npm run prisma:seed

db-studio:
	@echo "🎨 Opening Prisma Studio..."
	@docker-compose -f docker-compose.dev.yml exec backend npm run prisma:studio

# Utility commands
clean:
	@echo "🧹 Cleaning all Docker resources..."
	@docker system prune -af --volumes
	@docker network prune -f

health:
	@echo "🏥 Checking service health..."
	@curl -f http://localhost:8080/health || echo "❌ Nginx not responding"
	@curl -f http://localhost:4000/health || echo "❌ Backend not responding"
	@curl -f http://localhost:3001/health || echo "❌ WebSocket not responding"