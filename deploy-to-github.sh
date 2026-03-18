#!/bin/bash
# Deploy to GitHub Repository Script
# Repository: https://github.com/reyatelehealth2026-crypto/odoo.git
#
# For quick deployment guide, see: QUICK_DEPLOY_GUIDE.md

set -e

echo "=========================================="
echo "🚀 Deploy to GitHub Repository"
echo "=========================================="
echo ""
echo "📖 For quick guide, see: QUICK_DEPLOY_GUIDE.md"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# 1. ตรวจสอบว่าอยู่ใน main branch
echo "📍 ตรวจสอบ branch..."
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    print_warning "อยู่ใน branch: $CURRENT_BRANCH"
    echo "ต้องการเปลี่ยนเป็น main หรือไม่? (y/n)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        git checkout main
        print_success "เปลี่ยนเป็น branch main แล้ว"
    else
        print_error "ยกเลิกการ deploy"
        exit 1
    fi
else
    print_success "อยู่ใน branch main แล้ว"
fi

# 2. ตรวจสอบ remote
echo ""
echo "🌐 ตรวจสอบ remote repository..."
if git remote | grep -q "origin"; then
    REMOTE_URL=$(git remote get-url origin)
    print_success "Remote origin: $REMOTE_URL"
else
    print_error "ไม่พบ remote origin"
    echo "กำลังเพิ่ม remote..."
    git remote add origin https://github.com/reyatelehealth2026-crypto/odoo.git
    print_success "เพิ่ม remote origin แล้ว"
fi

# 3. ตรวจสอบไฟล์ sensitive
echo ""
echo "🔐 ตรวจสอบไฟล์ sensitive..."
SENSITIVE_FOUND=false

# ตรวจสอบว่าไฟล์ sensitive ไม่อยู่ใน staging area
if git diff --cached --name-only | grep -E "\.env$|config\.php$|\.secret$|\.key$|\.pem$"; then
    print_error "พบไฟล์ sensitive ใน staging area!"
    print_info "กำลังลบออกจาก staging..."
    git reset HEAD .env 2>/dev/null || true
    git reset HEAD config/config.php 2>/dev/null || true
    SENSITIVE_FOUND=true
fi

if [ "$SENSITIVE_FOUND" = false ]; then
    print_success "ไม่พบไฟล์ sensitive ใน staging area"
fi

# 4. แสดงสถานะ Git
echo ""
echo "📊 สถานะ Git ปัจจุบัน:"
echo "----------------------------------------"
git status --short

# 5. ถามยืนยันก่อน commit
echo ""
echo "📝 ต้องการ commit และ push ไฟล์เหล่านี้หรือไม่? (y/n)"
read -r response
if [[ ! "$response" =~ ^[Yy]$ ]]; then
    print_warning "ยกเลิกการ deploy"
    exit 0
fi

# 6. Stage ไฟล์ทั้งหมด
echo ""
echo "📦 กำลัง stage ไฟล์..."
git add .

# ลบไฟล์ sensitive ออกจาก staging (double check)
git reset HEAD .env 2>/dev/null || true
git reset HEAD .env.local 2>/dev/null || true
git reset HEAD config/config.php 2>/dev/null || true
git reset HEAD config/config.local.php 2>/dev/null || true

print_success "Stage ไฟล์เรียบร้อย"

# 7. Commit
echo ""
echo "💾 กำลัง commit..."
COMMIT_MSG="Deploy: Odoo Dashboard Modernization - Production Ready

✨ Features:
- Real-time dashboard with WebSocket
- Customer management with LINE integration
- Payment processing with automated matching
- Comprehensive security implementation
- Performance optimization with caching
- Full test coverage (93+ test files)
- Production-ready deployment scripts
- Monitoring and alerting setup
- Migration system for gradual rollout
- Complete documentation (Thai/English)

🏗️ Tech Stack:
- Backend: PHP 8.0+ / Node.js + Express + TypeScript
- Frontend: Next.js 14 + React 18 + TypeScript
- Database: MySQL 8.0+ / Redis
- Infrastructure: Docker + Nginx + Traefik
- Monitoring: Grafana + Prometheus

📚 Documentation:
- Deployment Guide (Thai): docs/DEPLOYMENT_GUIDE_TH.md
- API Documentation: docs/API_CUSTOMER_MANAGEMENT.md
- Testing Guide: backend/src/test/README.md
- Production Readiness: TASK_17_4_PRODUCTION_READINESS_CHECKPOINT.md"

if git commit -m "$COMMIT_MSG"; then
    print_success "Commit เรียบร้อย"
else
    print_warning "ไม่มีการเปลี่ยนแปลงที่ต้อง commit หรือ commit ล้มเหลว"
fi

# 8. Push to GitHub
echo ""
echo "📤 กำลัง push ไปยัง GitHub..."
echo ""
print_info "คุณอาจถูกถามข้อมูล authentication:"
print_info "Username: your-github-username"
print_info "Password: ghp_xxxx... (Personal Access Token)"
echo ""

if git push -u origin main; then
    echo ""
    echo "=========================================="
    print_success "Deploy สำเร็จ! 🎉"
    echo "=========================================="
    echo ""
    echo "📍 Repository: https://github.com/reyatelehealth2026-crypto/odoo"
    echo ""
    echo "✅ ขั้นตอนถัดไป:"
    echo "   1. เปิด: https://github.com/reyatelehealth2026-crypto/odoo"
    echo "   2. ตรวจสอบว่าไฟล์ทั้งหมดอัพโหลดถูกต้อง"
    echo "   3. ตั้งค่า Branch Protection (Settings → Branches)"
    echo "   4. เพิ่ม Secrets สำหรับ CI/CD (Settings → Secrets)"
    echo "   5. เพิ่ม Collaborators (Settings → Collaborators)"
    echo ""
    echo "📖 เอกสารเพิ่มเติม:"
    echo "   - Deployment Guide: docs/DEPLOYMENT_GUIDE_TH.md"
    echo "   - GitHub Guide: GITHUB_PUSH_GUIDE.md"
    echo ""
else
    echo ""
    echo "=========================================="
    print_error "Push ล้มเหลว!"
    echo "=========================================="
    echo ""
    echo "🔧 วิธีแก้ไขปัญหาที่พบบ่อย:"
    echo ""
    echo "1. Authentication Failed:"
    echo "   - ใช้ Personal Access Token แทน password"
    echo "   - สร้างที่: https://github.com/settings/tokens"
    echo ""
    echo "2. Repository Not Empty:"
    echo "   - Pull ก่อน: git pull origin main --allow-unrelated-histories"
    echo "   - หรือ Force push: git push -f origin main (ระวัง!)"
    echo ""
    echo "3. Network Issues:"
    echo "   - ตรวจสอบการเชื่อมต่ออินเทอร์เน็ต"
    echo "   - ลองใหม่อีกครั้ง"
    echo ""
    exit 1
fi
