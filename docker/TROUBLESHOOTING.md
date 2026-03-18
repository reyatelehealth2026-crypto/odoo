# Docker Troubleshooting Guide

Common issues and solutions for the Odoo Dashboard Docker setup.

## Common Issues

### 1. Port Already in Use

**Error**: `bind: address already in use`

**Solution**:
```bash
# Find process using the port
netstat -tulpn | grep :3000
# or on Windows
netstat -ano | findstr :3000

# Kill the process
kill -9 <PID>
# or on Windows
taskkill /PID <PID> /F

# Or change ports in .env.dev
```

### 2. Database Connection Failed

**Error**: `ECONNREFUSED mysql:3306`

**Solutions**:
- Wait for MySQL to fully start (can take 30-60 seconds)
- Check MySQL health: `docker-compose -f docker-compose.dev.yml exec mysql mysqladmin ping`
- Verify credentials in `.env.dev`
- Check MySQL logs: `docker-compose -f docker-compose.dev.yml logs mysql`

### 3. Permission Denied

**Error**: `permission denied` on shell scripts

**Solution**:
```bash
# Linux/Mac
chmod +x docker/scripts/*.sh

# Windows - use .bat files instead
docker/scripts/setup.bat
```

### 4. Out of Disk Space

**Error**: `no space left on device`

**Solutions**:
```bash
# Clean unused Docker resources
docker system prune -af --volumes

# Remove unused images
docker image prune -af

# Remove unused volumes
docker volume prune -f
```

### 5. Build Context Too Large

**Error**: Build context is too large

**Solutions**:
- Check `.dockerignore` files are present
- Exclude `node_modules/` and build directories
- Use multi-stage builds (already implemented)

### 6. Memory Issues

**Error**: Container killed due to memory

**Solutions**:
- Increase Docker Desktop memory limit (4GB+ recommended)
- Check container resource limits in docker-compose files
- Monitor with: `docker stats`

## Health Checks

### Check All Services
```bash
# Development
make health

# Manual checks
curl http://localhost:8080/health  # Nginx
curl http://localhost:4000/health  # Backend
curl http://localhost:3001/health  # WebSocket
curl http://localhost:3000/api/health  # Frontend
```

### Database Health
```bash
# Connect to MySQL
docker-compose -f docker-compose.dev.yml exec mysql mysql -u telepharmacy -p

# Check tables
docker-compose -f docker-compose.dev.yml exec mysql mysql -u telepharmacy -p -e "SHOW TABLES;" telepharmacy
```

### Redis Health
```bash
# Connect to Redis
docker-compose -f docker-compose.dev.yml exec redis redis-cli ping

# Check keys
docker-compose -f docker-compose.dev.yml exec redis redis-cli keys "*"
```

## Performance Issues

### Slow Build Times
- Use Docker BuildKit: `export DOCKER_BUILDKIT=1`
- Enable Docker Desktop experimental features
- Use `.dockerignore` to exclude unnecessary files

### Slow Container Startup
- Increase Docker Desktop resources
- Use volume mounts for development (already configured)
- Check for resource-intensive processes

## Debugging

### View Logs
```bash
# All services
docker-compose -f docker-compose.dev.yml logs -f

# Specific service
docker-compose -f docker-compose.dev.yml logs -f backend

# Last 100 lines
docker-compose -f docker-compose.dev.yml logs --tail=100 frontend
```

### Execute Commands in Containers
```bash
# Backend shell
docker-compose -f docker-compose.dev.yml exec backend /bin/sh

# Run npm commands
docker-compose -f docker-compose.dev.yml exec backend npm run prisma:studio

# Database operations
docker-compose -f docker-compose.dev.yml exec backend npm run prisma:migrate
```

### Network Issues
```bash
# List networks
docker network ls

# Inspect network
docker network inspect odoo-dashboard_odoo-dashboard-network

# Test connectivity between containers
docker-compose -f docker-compose.dev.yml exec frontend ping backend
```

## Environment Issues

### Environment Variables Not Loading
- Verify `.env.dev` exists and has correct format
- Check `--env-file` parameter in docker-compose commands
- Restart containers after changing environment files

### SSL/HTTPS Issues (Production)
- Verify SSL certificates in `docker/nginx/ssl/`
- Check certificate permissions and validity
- Test with HTTP first, then enable HTTPS

## Recovery Procedures

### Complete Reset
```bash
# Stop all services
docker-compose -f docker-compose.dev.yml down -v

# Remove all containers, networks, volumes
docker system prune -af --volumes

# Rebuild from scratch
make dev-start
```

### Database Reset
```bash
# Remove only database volume
docker-compose -f docker-compose.dev.yml down
docker volume rm odoo-dashboard_mysql_data_dev

# Restart with fresh database
make dev-start
```

### Cache Reset
```bash
# Clear Redis cache
docker-compose -f docker-compose.dev.yml exec redis redis-cli FLUSHALL

# Clear application caches
docker-compose -f docker-compose.dev.yml exec backend npm run cache:clear
docker-compose -f docker-compose.dev.yml exec frontend npm run build
```

## Getting Help

If you're still experiencing issues:

1. Check Docker Desktop status and resources
2. Verify system requirements (4GB+ RAM, 10GB+ disk space)
3. Review container logs for specific error messages
4. Test with minimal configuration first
5. Check for conflicting services on the same ports

### Useful Commands for Diagnosis
```bash
# System information
docker system info
docker version

# Container status
docker-compose -f docker-compose.dev.yml ps

# Resource usage
docker stats

# Network connectivity
docker-compose -f docker-compose.dev.yml exec frontend nslookup backend
```