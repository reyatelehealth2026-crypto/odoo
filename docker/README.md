# Docker Setup for Odoo Dashboard Modernization

Complete Docker containerization for the Odoo Dashboard modernization project with development and production environments.

## Architecture Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend      │    │    Backend      │    │   WebSocket     │
│   (Next.js)     │    │  (Node.js +     │    │   (Socket.io)   │
│   Port: 3000    │    │   Fastify)      │    │   Port: 3001    │
│                 │    │   Port: 4000    │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                    ┌─────────────────┐
                    │     Nginx       │
                    │  Load Balancer  │
                    │   Port: 80/443  │
                    └─────────────────┘
                                 │
         ┌───────────────────────┼───────────────────────┐
         │                       │                       │
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│     MySQL       │    │     Redis       │    │   File System   │
│   Port: 3306    │    │   Port: 6379    │    │     Volumes     │
│   (Database)    │    │    (Cache)      │    │   (Persistence) │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Quick Start

### Development Environment

```bash
# 1. Copy environment file
cp .env.dev.example .env.dev

# 2. Update configuration in .env.dev
# Edit database passwords, JWT secrets, etc.

# 3. Start development environment
chmod +x docker/scripts/dev-start.sh
./docker/scripts/dev-start.sh

# 4. Access services
# Frontend:  http://localhost:3000
# Backend:   http://localhost:4000
# WebSocket: http://localhost:3001
# Nginx:     http://localhost:8080
```

### Production Environment

```bash
# 1. Copy environment file
cp .env.prod.example .env.prod

# 2. Update production configuration
# Set secure passwords, domain name, SSL certificates

# 3. Deploy to production
chmod +x docker/scripts/prod-deploy.sh
./docker/scripts/prod-deploy.sh
```
## Services

### Frontend (Next.js 14)
- **Container**: `odoo-dashboard-frontend`
- **Technology**: Next.js 14 + TypeScript + Tailwind CSS
- **Features**: Server-side rendering, hot reload (dev), optimized builds
- **Health Check**: `/api/health`

### Backend (Node.js + Fastify)
- **Container**: `odoo-dashboard-backend`
- **Technology**: Node.js + Fastify + Prisma ORM + TypeScript
- **Features**: REST API, JWT authentication, rate limiting
- **Health Check**: `/health`

### WebSocket Server (Socket.io)
- **Container**: `odoo-dashboard-websocket`
- **Technology**: Node.js + Socket.io + Express
- **Features**: Real-time updates, room management, authentication
- **Health Check**: `/health`

### Database (MySQL 8.0)
- **Container**: `odoo-dashboard-mysql`
- **Features**: Optimized configuration, Thai timezone, UTF8MB4
- **Persistence**: Docker volume with automatic backups

### Cache (Redis 7)
- **Container**: `odoo-dashboard-redis`
- **Features**: Session storage, API caching, pub/sub messaging
- **Persistence**: AOF + RDB snapshots

### Load Balancer (Nginx)
- **Container**: `odoo-dashboard-nginx`
- **Features**: SSL termination, rate limiting, static file serving
- **Configuration**: Separate dev/prod configs

## Environment Configuration

### Development (.env.dev)
- Hot reload enabled
- Debug logging
- Swagger documentation
- CORS permissive
- Insecure defaults for ease of development

### Production (.env.prod)
- Security hardened
- Performance optimized
- SSL/TLS encryption
- Rate limiting
- Monitoring enabled

## Commands

### Development
```bash
# Start development environment
./docker/scripts/dev-start.sh

# Stop development environment
./docker/scripts/dev-stop.sh

# View logs
docker-compose -f docker-compose.dev.yml logs -f [service]

# Execute commands in containers
docker-compose -f docker-compose.dev.yml exec backend npm run prisma:studio
docker-compose -f docker-compose.dev.yml exec frontend npm run build
```