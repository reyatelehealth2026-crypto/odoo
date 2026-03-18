#!/bin/bash
# Check if repository is ready for GitHub deployment

set -e

echo "=========================================="
echo "🔍 ตรวจสอบความพร้อมก่อน Deploy"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

ISSUES_FOUND=0

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
    ISSUES_FOUND=$((ISSUES_FOUND + 1))
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
    ISSUES_FOUND=$((ISSUES_FOUND + 1))
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# 1. ตรวจสอบว่าอยู่ใน root directory
echo "1️⃣  ตรวจสอบ Directory Structure"
echo "----------------------------------------"
if [ -f "composer.json" ] && [ -f "package.json" ]; then
    print_success "อยู่ใน root directory ถูกต้อง"
else
    print_error "ไม่พบ composer.json หรือ package.json"
    echo "   กรุณารันสคริปต์นี้ใน root directory ของโปรเจค"
fi
echo ""

# 2. ตรวจสอบ Git
echo "2️⃣  ตรวจสอบ Git Repository"
echo "----------------------------------------"
if [ -d ".git" ]; then
    print_success "Git repository initialized"
    
    # ตรวจสอบ branch
    CURRENT_BRANCH=$(git branch --show-current)
    if [ "$CURRENT_BRANCH" = "main" ]; then
        print_success "อยู่ใน branch main"
    else
        print_warning "อยู่ใน branch: $CURRENT_BRANCH (ควรเป็น main)"
    fi
    
    # ตรวจสอบ remote
    if git remote | grep -q "origin"; then
        REMOTE_URL=$(git remote get-url origin)
        print_success "Remote origin: $REMOTE_URL"
    else
        print_warning "ยังไม่ได้ตั้งค่า remote origin"
    fi
else
    print_error "ยังไม่ได้ initialize git repository"
    echo "   รัน: git init"
fi
echo ""

# 3. ตรวจสอบ .gitignore
echo "3️⃣  ตรวจสอบ .gitignore"
echo "----------------------------------------"
if [ -f ".gitignore" ]; then
    print_success ".gitignore พบแล้ว"
    
    # ตรวจสอบว่ามี patterns สำคัญ
    REQUIRED_PATTERNS=(".env" "config/config.php" "vendor/" "node_modules/" "*.log")
    for pattern in "${REQUIRED_PATTERNS[@]}"; do
        if grep -q "$pattern" .gitignore; then
            print_success "  ✓ $pattern"
        else
            print_warning "  ✗ $pattern ไม่อยู่ใน .gitignore"
        fi
    done
else
    print_error ".gitignore ไม่พบ"
fi
echo ""

# 4. ตรวจสอบไฟล์ sensitive
echo "4️⃣  ตรวจสอบไฟล์ Sensitive"
echo "----------------------------------------"
SENSITIVE_FILES=(
    ".env"
    ".env.local"
    ".env.prod"
    "config/config.php"
    "config/config.local.php"
)

SENSITIVE_IN_GIT=false
for file in "${SENSITIVE_FILES[@]}"; do
    if [ -f "$file" ]; then
        if git ls-files --error-unmatch "$file" 2>/dev/null; then
            print_error "$file อยู่ใน git tracking!"
            SENSITIVE_IN_GIT=true
        else
            print_success "$file ไม่อยู่ใน git tracking"
        fi
    fi
done

if [ "$SENSITIVE_IN_GIT" = false ]; then
    print_success "ไม่พบไฟล์ sensitive ใน git tracking"
fi
echo ""

# 5. ตรวจสอบ .env.example
echo "5️⃣  ตรวจสอบ Environment Examples"
echo "----------------------------------------"
if [ -f ".env.example" ]; then
    print_success ".env.example พบแล้ว"
else
    print_warning ".env.example ไม่พบ (ควรมีสำหรับ documentation)"
fi

if [ -f ".env.prod.example" ]; then
    print_success ".env.prod.example พบแล้ว"
else
    print_warning ".env.prod.example ไม่พบ"
fi
echo ""

# 6. ตรวจสอบ README.md
echo "6️⃣  ตรวจสอบ Documentation"
echo "----------------------------------------"
if [ -f "README.md" ]; then
    print_success "README.md พบแล้ว"
    
    # ตรวจสอบขนาด
    SIZE=$(wc -c < "README.md")
    if [ $SIZE -gt 500 ]; then
        print_success "  README.md มีเนื้อหาเพียงพอ ($SIZE bytes)"
    else
        print_warning "  README.md มีเนื้อหาน้อย ($SIZE bytes)"
    fi
else
    print_warning "README.md ไม่พบ (ควรมีสำหรับ GitHub)"
fi

if [ -f "docs/DEPLOYMENT_GUIDE_TH.md" ]; then
    print_success "Deployment Guide พบแล้ว"
else
    print_warning "docs/DEPLOYMENT_GUIDE_TH.md ไม่พบ"
fi
echo ""

# 7. ตรวจสอบ Dependencies
echo "7️⃣  ตรวจสอบ Dependencies"
echo "----------------------------------------"
if [ -d "vendor" ]; then
    print_success "PHP dependencies installed (vendor/)"
else
    print_warning "PHP dependencies ยังไม่ได้ติดตั้ง (รัน: composer install)"
fi

if [ -d "node_modules" ]; then
    print_success "Node.js dependencies installed (node_modules/)"
else
    print_warning "Node.js dependencies ยังไม่ได้ติดตั้ง (รัน: npm install)"
fi

if [ -d "backend/node_modules" ]; then
    print_success "Backend dependencies installed"
else
    print_warning "Backend dependencies ยังไม่ได้ติดตั้ง (รัน: cd backend && npm install)"
fi

if [ -d "frontend/node_modules" ]; then
    print_success "Frontend dependencies installed"
else
    print_warning "Frontend dependencies ยังไม่ได้ติดตั้ง (รัน: cd frontend && npm install)"
fi
echo ""

# 8. ตรวจสอบขนาด Repository
echo "8️⃣  ตรวจสอบขนาด Repository"
echo "----------------------------------------"
if [ -d ".git" ]; then
    REPO_SIZE=$(du -sh .git | cut -f1)
    print_info "ขนาด .git directory: $REPO_SIZE"
    
    # ตรวจสอบไฟล์ใหญ่
    print_info "ตรวจสอบไฟล์ใหญ่ (>10MB)..."
    LARGE_FILES=$(find . -type f -size +10M ! -path "./.git/*" ! -path "./node_modules/*" ! -path "./vendor/*" 2>/dev/null)
    if [ -z "$LARGE_FILES" ]; then
        print_success "ไม่พบไฟล์ใหญ่ (>10MB)"
    else
        print_warning "พบไฟล์ใหญ่:"
        echo "$LARGE_FILES" | while read -r file; do
            SIZE=$(du -h "$file" | cut -f1)
            echo "     $SIZE - $file"
        done
        print_info "   พิจารณาใช้ Git LFS สำหรับไฟล์ใหญ่"
    fi
fi
echo ""

# 9. ตรวจสอบ Git Status
echo "9️⃣  ตรวจสอบ Git Status"
echo "----------------------------------------"
if [ -d ".git" ]; then
    UNTRACKED=$(git ls-files --others --exclude-standard | wc -l)
    MODIFIED=$(git diff --name-only | wc -l)
    STAGED=$(git diff --cached --name-only | wc -l)
    
    print_info "Untracked files: $UNTRACKED"
    print_info "Modified files: $MODIFIED"
    print_info "Staged files: $STAGED"
    
    if [ $UNTRACKED -gt 0 ] || [ $MODIFIED -gt 0 ]; then
        print_warning "มีไฟล์ที่ยังไม่ได้ commit"
    else
        print_success "ไม่มีไฟล์ที่รอ commit"
    fi
fi
echo ""

# 10. สรุปผล
echo "=========================================="
echo "📊 สรุปผลการตรวจสอบ"
echo "=========================================="
echo ""

if [ $ISSUES_FOUND -eq 0 ]; then
    print_success "ผ่านการตรวจสอบทั้งหมด! พร้อม deploy ขึ้น GitHub"
    echo ""
    echo "🚀 ขั้นตอนถัดไป:"
    echo "   1. รัน: bash deploy-to-github.sh"
    echo "   2. หรือ: deploy-to-github.bat (Windows)"
    echo ""
else
    print_warning "พบปัญหา $ISSUES_FOUND รายการ"
    echo ""
    echo "🔧 แนะนำให้แก้ไขปัญหาก่อน deploy:"
    echo "   - ตรวจสอบไฟล์ sensitive"
    echo "   - เพิ่ม .env.example"
    echo "   - อัพเดท README.md"
    echo "   - ติดตั้ง dependencies"
    echo ""
    echo "หรือสามารถ deploy ได้เลยถ้าปัญหาไม่สำคัญ:"
    echo "   bash deploy-to-github.sh"
    echo ""
fi

exit 0
