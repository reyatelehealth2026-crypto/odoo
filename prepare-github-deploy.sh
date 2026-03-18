#!/bin/bash
# prepare-github-deploy.sh
# สคริปต์เตรียมการ deploy โปรเจคขึ้น GitHub

set -e

echo "🚀 เตรียมการ Deploy โปรเจค Odoo Dashboard ขึ้น GitHub"
echo "=========================================================="

# สี
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ฟังก์ชันแสดงข้อความ
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# 1. ตรวจสอบว่าอยู่ใน root directory
echo ""
echo "📁 ตรวจสอบ directory..."
if [ ! -f "composer.json" ] || [ ! -f "package.json" ]; then
    print_error "ไม่พบไฟล์ composer.json หรือ package.json"
    print_error "กรุณารันสคริปต์นี้ใน root directory ของโปรเจค"
    exit 1
fi
print_success "อยู่ใน root directory ถูกต้อง"

# 2. ตรวจสอบ .gitignore
echo ""
echo "🔍 ตรวจสอบ .gitignore..."
if [ ! -f ".gitignore" ]; then
    print_warning ".gitignore ไม่พบ กำลังสร้างใหม่..."
    cat > .gitignore << 'EOF'
# Environment files
.env
.env.local
.env.*.local
.env.prod
.env.production

# Dependencies
node_modules/
vendor/

# Build outputs
dist/
build/
*.log

# IDE
.vscode/
.idea/
*.swp
*.swo
*~

# OS
.DS_Store
Thumbs.db

# Uploads & Cache
uploads/*
!uploads/.gitkeep
cache/
*.cache

# Logs
logs/
*.log
npm-debug.log*
yarn-debug.log*
yarn-error.log*

# Database
*.sql
*.sqlite
*.db

# Sensitive data
config/database.php
config/secrets.php

# Temporary files
tmp/
temp/
*.tmp

# Docker
docker-compose.override.yml

# Backup files
*.bak
*.backup
*~

# PHP
composer.phar
composer.lock
EOF
    print_success "สร้าง .gitignore เรียบร้อย"
else
    print_success ".gitignore พบแล้ว"
fi

# 3. ตรวจสอบไฟล์ sensitive
echo ""
echo "🔐 ตรวจสอบไฟล์ sensitive..."

SENSITIVE_FILES=(
    ".env"
    "config/database.php"
    ".env.prod"
    ".env.production"
)

FOUND_SENSITIVE=false
for file in "${SENSITIVE_FILES[@]}"; do
    if [ -f "$file" ]; then
        if git ls-files --error-unmatch "$file" 2>/dev/null; then
            print_warning "ไฟล์ $file อยู่ใน git tracking!"
            FOUND_SENSITIVE=true
        fi
    fi
done

if [ "$FOUND_SENSITIVE" = true ]; then
    print_warning "พบไฟล์ sensitive ใน git tracking"
    echo "ต้องการลบออกจาก git tracking หรือไม่? (y/n)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        for file in "${SENSITIVE_FILES[@]}"; do
            if [ -f "$file" ]; then
                git rm --cached "$file" 2>/dev/null || true
                print_success "ลบ $file จาก git tracking"
            fi
        done
    fi
else
    print_success "ไม่พบไฟล์ sensitive ใน git tracking"
fi

# 4. สร้าง .env.example
echo ""
echo "📝 สร้าง .env.example..."
if [ -f ".env" ] && [ ! -f ".env.example" ]; then
    # สร้าง .env.example โดยลบค่า sensitive ออก
    sed 's/=.*/=/' .env > .env.example
    print_success "สร้าง .env.example จาก .env"
elif [ ! -f ".env.example" ]; then
    cat > .env.example << 'EOF'
# Application
NODE_ENV=production
APP_NAME=
APP_URL=

# Database
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# Redis
REDIS_HOST=
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT
JWT_SECRET=
JWT_ACCESS_EXPIRY=15m
JWT_REFRESH_EXPIRY=7d

# API
API_PREFIX=/api/v1
API_PORT=4000

# LINE
LINE_CHANNEL_ACCESS_TOKEN=
LINE_CHANNEL_SECRET=

# Odoo
ODOO_API_URL=
ODOO_API_KEY=
EOF
    print_success "สร้าง .env.example template"
fi

# 5. ตรวจสอบ Git
echo ""
echo "🔧 ตรวจสอบ Git..."
if [ ! -d ".git" ]; then
    print_warning "ยังไม่ได้ initialize git repository"
    echo "ต้องการ initialize git หรือไม่? (y/n)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        git init
        print_success "Initialize git repository เรียบร้อย"
    else
        print_error "ยกเลิกการ deploy"
        exit 1
    fi
else
    print_success "Git repository พร้อมแล้ว"
fi

# 6. ตรวจสอบ remote
echo ""
echo "🌐 ตรวจสอบ Git remote..."
if git remote | grep -q "origin"; then
    CURRENT_REMOTE=$(git remote get-url origin)
    print_warning "มี remote origin อยู่แล้ว: $CURRENT_REMOTE"
    echo "ต้องการเปลี่ยน remote หรือไม่? (y/n)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        git remote remove origin
        print_success "ลบ remote เดิมแล้ว"
    fi
fi

# 7. เพิ่ม remote ใหม่
if ! git remote | grep -q "origin"; then
    echo ""
    echo "กรุณาใส่ URL ของ GitHub repository:"
    echo "(ตัวอย่าง: https://github.com/reyatelehealth2026-crypto/odoo.git)"
    read -r REPO_URL
    
    if [ -z "$REPO_URL" ]; then
        REPO_URL="https://github.com/reyatelehealth2026-crypto/odoo.git"
        print_warning "ใช้ URL default: $REPO_URL"
    fi
    
    git remote add origin "$REPO_URL"
    print_success "เพิ่ม remote origin: $REPO_URL"
fi

# 8. ตรวจสอบ branch
echo ""
echo "🌿 ตรวจสอบ branch..."
CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")
if [ -z "$CURRENT_BRANCH" ]; then
    print_warning "ยังไม่มี branch"
    git checkout -b main
    print_success "สร้าง branch main"
elif [ "$CURRENT_BRANCH" != "main" ]; then
    print_warning "อยู่ใน branch: $CURRENT_BRANCH"
    echo "ต้องการเปลี่ยนเป็น main หรือไม่? (y/n)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        git branch -M main
        print_success "เปลี่ยนเป็น branch main"
    fi
else
    print_success "อยู่ใน branch main แล้ว"
fi

# 9. สร้าง README.md (ถ้ายังไม่มี)
echo ""
echo "📄 ตรวจสอบ README.md..."
if [ ! -f "README.md" ]; then
    print_warning "ไม่พบ README.md กำลังสร้าง..."
    cat > README.md << 'EOF'
# LINE Telepharmacy Platform - Odoo Dashboard Modernization

ระบบ Dashboard สมัยใหม่สำหรับจัดการร้านขายยาและ LINE Official Account

## 🚀 Features

- **Dashboard Overview**: แสดงข้อมูลสรุปแบบ real-time
- **Order Management**: จัดการคำสั่งซื้อและติดตามสถานะ
- **Payment Processing**: ระบบจัดการการชำระเงินและ slip matching
- **Customer Management**: จัดการข้อมูลลูกค้าและ LINE account
- **Webhook Monitoring**: ติดตามและจัดการ webhook events
- **Real-time Updates**: อัพเดทข้อมูลแบบ real-time ผ่าน WebSocket

## 🛠️ Tech Stack

### Backend
- **PHP 8.0+**: Legacy system
- **Node.js 18+**: Modern API server
- **MySQL 8.0+**: Database
- **Redis 7.0+**: Caching

### Frontend
- **Next.js 14**: React framework
- **TypeScript**: Type safety
- **Tailwind CSS**: Styling
- **React Query**: State management

### Infrastructure
- **Docker**: Containerization
- **Nginx**: Load balancer
- **PM2**: Process management
- **Grafana**: Monitoring

## 📦 Installation

### Prerequisites
- Docker 20.10+
- Docker Compose 2.0+
- Node.js 18+ (for development)
- PHP 8.0+ (for legacy system)

### Quick Start

```bash
# Clone repository
git clone https://github.com/reyatelehealth2026-crypto/odoo.git
cd odoo

# Copy environment file
cp .env.example .env

# Edit .env with your configuration
nano .env

# Start with Docker
docker compose up -d

# Access the application
# Frontend: http://localhost:3000
# Backend API: http://localhost:4000
# WebSocket: ws://localhost:3001
```

## 📚 Documentation

- [Deployment Guide (Thai)](docs/DEPLOYMENT_GUIDE_TH.md)
- [API Documentation](docs/API_DOCUMENTATION.md)
- [Database Schema](backend/DATABASE.md)
- [Testing Guide](backend/src/test/README.md)

## 🧪 Testing

```bash
# Backend tests
cd backend
npm test

# Frontend tests
cd frontend
npm test

# PHP tests
composer test
```

## 🚢 Deployment

See [Deployment Guide](docs/DEPLOYMENT_GUIDE_TH.md) for detailed instructions.

```bash
# Production deployment
docker compose -f docker-compose.prod.yml up -d
```

## 📝 License

Proprietary - All rights reserved

## 👥 Team

- Development Team: RE-YA Telehealth 2026
- Contact: support@re-ya.com
EOF
    print_success "สร้าง README.md เรียบร้อย"
else
    print_success "README.md มีอยู่แล้ว"
fi

# 10. แสดงสถานะ Git
echo ""
echo "📊 สถานะ Git ปัจจุบัน:"
echo "----------------------------------------"
git status --short

# 11. สรุปและคำแนะนำ
echo ""
echo "=========================================================="
echo "✅ เตรียมการเรียบร้อย! พร้อม deploy ขึ้น GitHub"
echo "=========================================================="
echo ""
echo "📋 ขั้นตอนต่อไป:"
echo ""
echo "1. ตรวจสอบไฟล์ที่จะ commit:"
echo "   ${YELLOW}git status${NC}"
echo ""
echo "2. เพิ่มไฟล์ทั้งหมด:"
echo "   ${YELLOW}git add .${NC}"
echo ""
echo "3. Commit:"
echo "   ${YELLOW}git commit -m \"Initial commit: Odoo Dashboard Modernization\"${NC}"
echo ""
echo "4. Push ขึ้น GitHub:"
echo "   ${YELLOW}git push -u origin main${NC}"
echo ""
echo "5. ถ้า push ไม่ผ่าน (repository ไม่ว่าง):"
echo "   ${YELLOW}git pull origin main --allow-unrelated-histories${NC}"
echo "   ${YELLOW}git push -u origin main${NC}"
echo ""
echo "หรือรันคำสั่งเดียว:"
echo "${GREEN}bash quick-deploy-github.sh${NC}"
echo ""

# สร้างสคริปต์ quick deploy
cat > quick-deploy-github.sh << 'EOFSCRIPT'
#!/bin/bash
# Quick deploy to GitHub

set -e

echo "🚀 Deploying to GitHub..."

# Add all files
git add .

# Commit
echo "📝 Enter commit message (or press Enter for default):"
read -r COMMIT_MSG
if [ -z "$COMMIT_MSG" ]; then
    COMMIT_MSG="Update: $(date '+%Y-%m-%d %H:%M:%S')"
fi

git commit -m "$COMMIT_MSG" || echo "No changes to commit"

# Push
echo "📤 Pushing to GitHub..."
git push -u origin main || {
    echo "⚠️  Push failed. Trying to pull first..."
    git pull origin main --allow-unrelated-histories
    git push -u origin main
}

echo "✅ Deploy complete!"
echo "🌐 Check your repository at: https://github.com/reyatelehealth2026-crypto/odoo"
EOFSCRIPT

chmod +x quick-deploy-github.sh

print_success "สร้างสคริปต์ quick-deploy-github.sh เรียบร้อย"
