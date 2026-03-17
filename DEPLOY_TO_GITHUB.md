# คู่มือ Deploy โปรเจค Odoo Dashboard ขึ้น GitHub

## ภาพรวม

เอกสารนี้อธิบายขั้นตอนการ deploy โปรเจค LINE Telepharmacy Platform - Odoo Dashboard Modernization ขึ้น GitHub repository

**Repository URL**: https://github.com/reyatelehealth2026-crypto/odoo.git

## วิธีการ Deploy

### วิธีที่ 1: ใช้ Automated Script (แนะนำ)

```bash
# รันสคริปต์เตรียมการ
bash prepare-github-deploy.sh

# สคริปต์จะทำการ:
# ✅ ตรวจสอบ directory structure
# ✅ ตรวจสอบและสร้าง .gitignore
# ✅ ตรวจสอบไฟล์ sensitive
# ✅ สร้าง .env.example
# ✅ Initialize git repository
# ✅ เพิ่ม remote origin
# ✅ สร้าง README.md (ถ้ายังไม่มี)

# จากนั้นรัน quick deploy
bash quick-deploy-github.sh
```

### วิธีที่ 2: Manual Deployment

## ขั้นตอนการ Deploy แบบ Manual

### 1. เตรียมไฟล์ก่อน Push

```bash
# ตรวจสอบว่าอยู่ใน root directory ของโปรเจค
pwd

# ตรวจสอบ git status
git status
```

### 2. Initialize Git Repository (ถ้ายังไม่ได้ทำ)

```bash
# Initialize git
git init

# เพิ่มไฟล์ทั้งหมด
git add .

# Commit ครั้งแรก
git commit -m "Initial commit: Odoo Dashboard Modernization - Complete implementation"
```

### 3. เชื่อมต่อกับ GitHub Repository

```bash
# เพิ่ม remote repository
git remote add origin https://github.com/reyatelehealth2026-crypto/odoo.git

# เปลี่ยน branch เป็น main
git branch -M main

# Push ขึ้น GitHub
git push -u origin main
```

### 4. ตรวจสอบหลัง Push

```bash
# ตรวจสอบ remote
git remote -v

# ตรวจสอบ branch
git branch -a
```

## สิ่งที่ควรทำก่อน Push

### ✅ ตรวจสอบ .gitignore

ไฟล์ที่ไม่ควร push (ตรวจสอบว่าอยู่ใน .gitignore):

**Environment & Configuration:**
- `.env`, `.env.local`, `.env.*.local`
- `config/config.php` (contains database credentials)
- `config/installed.lock`

**Dependencies:**
- `node_modules/`
- `vendor/`
- `composer.lock`
- `package-lock.json`

**Build & Cache:**
- `*.log`
- `logs/`
- `cache/`
- `.phpunit.result.cache`

**Uploads & User Data:**
- `uploads/*` (except .gitkeep and .htaccess)
- `backup/`

**IDE & OS:**
- `.vscode/`, `.idea/`
- `.DS_Store`, `Thumbs.db`
- `*.swp`, `*.swo`

**Sensitive Files:**
- `*.pem`, `*.key`
- `id_rsa`, `*.ppk`
- `*.secret`

### ✅ ลบข้อมูล Sensitive

ตรวจสอบว่าไม่มีข้อมูลเหล่านี้ใน code:

**Database:**
- Database passwords
- Connection strings with credentials

**API Keys:**
- LINE Channel Access Token
- LINE Channel Secret
- Gemini API Key
- OpenAI API Key
- Odoo API credentials

**Security:**
- JWT secrets
- Encryption keys
- Session secrets

**ตัวอย่างการลบไฟล์ sensitive ออกจาก git:**

```bash
# ลบไฟล์ออกจาก git tracking (แต่ยังเก็บไฟล์ไว้ใน local)
git rm --cached .env
git rm --cached config/config.php

# Commit การเปลี่ยนแปลง
git commit -m "Remove sensitive files from tracking"
```

### ✅ เตรียม Environment Example Files

สร้างไฟล์ตัวอย่างสำหรับ configuration:

```bash
# สร้าง .env.example จาก .env (ลบค่า sensitive)
cp .env .env.example
# แก้ไข .env.example ให้เหลือแค่ key โดยไม่มีค่า

# สร้าง .env.prod.example
cp .env.prod .env.prod.example
# แก้ไขเช่นเดียวกัน
```

### ✅ ตรวจสอบ README.md

README.md ควรมีข้อมูล:
- Project overview
- Features
- Installation instructions
- Configuration guide
- Deployment instructions
- License information

## หลัง Push แล้ว

### ตั้งค่า GitHub Repository

1. **ไปที่ Repository**: https://github.com/reyatelehealth2026-crypto/odoo

2. **ตั้งค่า Repository Settings**:
   - **Description**: "LINE Telepharmacy Platform - Modern Odoo Dashboard with Next.js + Node.js"
   - **Topics**: `php`, `nodejs`, `nextjs`, `typescript`, `line-bot`, `telepharmacy`, `dashboard`
   - **Visibility**: Private (แนะนำ) หรือ Public

3. **เพิ่ม Secrets สำหรับ CI/CD** (Settings → Secrets and variables → Actions):
   ```
   DB_PASSWORD=your_database_password
   JWT_SECRET=your_jwt_secret
   LINE_CHANNEL_SECRET=your_line_secret
   REDIS_PASSWORD=your_redis_password
   ```

4. **ตั้งค่า Branch Protection** (Settings → Branches):
   - Protect `main` branch
   - Require pull request reviews
   - Require status checks to pass

5. **เปิดใช้งาน GitHub Actions** (ถ้ามี workflow):
   - Actions → Enable workflows

### Clone ไปยัง Production Server

```bash
# SSH เข้า production server
ssh user@your-server.com

# ไปยัง directory ที่ต้องการ
cd /var/www

# Clone repository
git clone https://github.com/reyatelehealth2026-crypto/odoo.git
cd odoo

# Checkout production branch (ถ้ามี)
git checkout production

# ตั้งค่า environment
cp .env.prod.example .env
nano .env

# ติดตั้ง dependencies
composer install --no-dev --optimize-autoloader
npm ci --production

# รัน migrations
cd backend
npm run prisma:migrate

# Build frontend
cd ../frontend
npm run build

# Deploy with Docker
cd ..
docker compose -f docker-compose.prod.yml up -d
```

### ตั้งค่า Webhook (Optional)

สำหรับ auto-deployment เมื่อมีการ push:

1. **ไปที่ Settings → Webhooks → Add webhook**

2. **Payload URL**: `https://your-server.com/deploy-webhook`

3. **Content type**: `application/json`

4. **Secret**: สร้าง secret key สำหรับ verify webhook

5. **Events**: เลือก `Just the push event`

### ตั้งค่า GitHub Pages (Optional)

สำหรับ documentation:

1. **Settings → Pages**
2. **Source**: Deploy from a branch
3. **Branch**: `main` / `docs` folder
4. **Custom domain**: docs.yourdomain.com (optional)

## คำสั่งที่ใช้บ่อย

### การจัดการ Repository

```bash
# ดูสถานะ
git status

# ดู remote
git remote -v

# ดู branches
git branch -a

# ดู commit history
git log --oneline --graph --all
```

### การ Pull Updates

```bash
# Pull updates จาก main branch
git pull origin main

# Pull และ rebase
git pull --rebase origin main

# Pull specific branch
git pull origin feature/new-feature
```

### การ Push Updates

```bash
# เพิ่มไฟล์ทั้งหมด
git add .

# เพิ่มไฟล์เฉพาะ
git add path/to/file.php

# Commit
git commit -m "Update: description of changes"

# Push ไปยัง main
git push origin main

# Force push (ระวัง!)
git push -f origin main
```

### การจัดการ Branches

```bash
# สร้าง branch ใหม่
git checkout -b feature/new-feature

# เปลี่ยน branch
git checkout main

# Merge branch
git checkout main
git merge feature/new-feature

# ลบ branch (local)
git branch -d feature/new-feature

# ลบ branch (remote)
git push origin --delete feature/new-feature
```

### การจัดการ Tags

```bash
# สร้าง tag
git tag -a v1.0.0 -m "Release version 1.0.0"

# Push tag
git push origin v1.0.0

# Push all tags
git push origin --tags

# ลบ tag (local)
git tag -d v1.0.0

# ลบ tag (remote)
git push origin --delete v1.0.0
```

### การแก้ไขปัญหา

```bash
# Undo last commit (keep changes)
git reset --soft HEAD~1

# Undo last commit (discard changes)
git reset --hard HEAD~1

# Discard local changes
git checkout -- path/to/file.php

# Stash changes
git stash
git stash pop

# Clean untracked files
git clean -fd
```

## Workflow แนะนำ

### Development Workflow

```bash
# 1. สร้าง feature branch
git checkout -b feature/payment-system

# 2. ทำงานและ commit เป็นระยะ
git add .
git commit -m "Add payment API endpoint"

# 3. Push ไปยัง remote
git push origin feature/payment-system

# 4. สร้าง Pull Request บน GitHub

# 5. หลัง review และ approve
git checkout main
git pull origin main
git merge feature/payment-system
git push origin main

# 6. ลบ feature branch
git branch -d feature/payment-system
git push origin --delete feature/payment-system
```

### Hotfix Workflow

```bash
# 1. สร้าง hotfix branch จาก main
git checkout main
git pull origin main
git checkout -b hotfix/critical-bug

# 2. แก้ไขและ commit
git add .
git commit -m "Fix: critical bug in payment processing"

# 3. Merge กลับเข้า main
git checkout main
git merge hotfix/critical-bug
git push origin main

# 4. Tag version
git tag -a v1.0.1 -m "Hotfix: payment bug"
git push origin v1.0.1

# 5. ลบ hotfix branch
git branch -d hotfix/critical-bug
```

### Release Workflow

```bash
# 1. สร้าง release branch
git checkout -b release/v1.1.0

# 2. Update version numbers
# แก้ไข package.json, composer.json, etc.

# 3. Commit version bump
git add .
git commit -m "Bump version to 1.1.0"

# 4. Merge เข้า main
git checkout main
git merge release/v1.1.0

# 5. Tag release
git tag -a v1.1.0 -m "Release version 1.1.0"
git push origin main --tags

# 6. ลบ release branch
git branch -d release/v1.1.0
```

## Best Practices

### Commit Messages

ใช้ format:
```
<type>: <subject>

<body>

<footer>
```

**Types:**
- `feat`: Feature ใหม่
- `fix`: Bug fix
- `docs`: Documentation
- `style`: Code style (formatting)
- `refactor`: Code refactoring
- `test`: เพิ่ม tests
- `chore`: Maintenance tasks

**ตัวอย่าง:**
```bash
git commit -m "feat: Add payment slip upload API

- Implement file upload endpoint
- Add image validation
- Store slip metadata in database

Closes #123"
```

### Branch Naming

- `feature/feature-name` - Features ใหม่
- `bugfix/bug-description` - Bug fixes
- `hotfix/critical-issue` - Hotfixes
- `release/version` - Release branches
- `docs/documentation-update` - Documentation

### Pull Request Guidelines

1. **Title**: ชัดเจนและสื่อความหมาย
2. **Description**: อธิบายการเปลี่ยนแปลงและเหตุผล
3. **Screenshots**: แนบภาพถ้ามีการเปลี่ยนแปลง UI
4. **Testing**: อธิบายวิธีการทดสอบ
5. **Checklist**: ใช้ checklist สำหรับ review

## การแก้ไขปัญหาที่พบบ่อย

### Push ถูก Reject

```bash
# ถ้า remote มีการเปลี่ยนแปลงที่ local ไม่มี
git pull origin main --rebase
git push origin main
```

### Merge Conflicts

```bash
# 1. Pull latest changes
git pull origin main

# 2. แก้ไข conflicts ในไฟล์
# ลบ markers: <<<<<<<, =======, >>>>>>>

# 3. Add resolved files
git add .

# 4. Continue merge
git commit -m "Resolve merge conflicts"

# 5. Push
git push origin main
```

### Large Files Error

```bash
# ถ้าไฟล์ใหญ่เกิน 100MB
# ใช้ Git LFS (Large File Storage)

# ติดตั้ง Git LFS
git lfs install

# Track large files
git lfs track "*.zip"
git lfs track "*.sql"

# Add .gitattributes
git add .gitattributes

# Commit และ push
git add .
git commit -m "Add Git LFS tracking"
git push origin main
```

## เอกสารอ้างอิง

- [Git Documentation](https://git-scm.com/doc)
- [GitHub Guides](https://guides.github.com/)
- [Production Deployment Guide](docs/DEPLOYMENT_GUIDE_TH.md)
- [Docker Deployment](DEPLOYMENT_GUIDE.md)
- [API Documentation](docs/API_DOCUMENTATION.md)

## การติดต่อและสนับสนุน

- **Issues**: https://github.com/reyatelehealth2026-crypto/odoo/issues
- **Discussions**: https://github.com/reyatelehealth2026-crypto/odoo/discussions
- **Email**: support@re-ya.com
