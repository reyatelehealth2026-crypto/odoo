# คู่มือ Push โค้ดขึ้น GitHub

## ขั้นตอนการ Deploy

### วิธีที่ 1: ใช้ Bash Script (แนะนำ)

```bash
bash push-to-github.sh
```

### วิธีที่ 2: ใช้ Windows Batch Script

```cmd
push-to-github.bat
```

### วิธีที่ 3: Manual Commands (ตามคำแนะนำของ GitHub)

```bash
# ถ้ายังไม่มี git init
git init

# เพิ่มไฟล์ทั้งหมด
git add .

# Commit
git commit -m "Initial commit: Odoo Dashboard Modernization - Production Ready"

# เปลี่ยนชื่อ branch เป็น main
git branch -M main

# เพิ่ม remote repository
git remote add origin https://github.com/reyatelehealth2026-crypto/odoo.git

# Push ขึ้น GitHub
git push -u origin main
```

## การ Authentication

GitHub ไม่รองรับ password authentication แล้ว คุณต้องใช้ **Personal Access Token (PAT)**

### สร้าง Personal Access Token:

1. ไปที่ GitHub: https://github.com/settings/tokens
2. คลิก "Generate new token" → "Generate new token (classic)"
3. ตั้งชื่อ token: `Odoo Dashboard Deploy`
4. เลือก scopes:
   - ✅ `repo` (Full control of private repositories)
   - ✅ `workflow` (Update GitHub Action workflows)
5. คลิก "Generate token"
6. **คัดลอก token ทันที** (จะไม่แสดงอีก!)

### ใช้ Token เมื่อ Push:

เมื่อ Git ถาม username/password:

```
Username: your-github-username
Password: ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (paste your token here)
```

## ถ้า Repository ไม่ว่าง (มีไฟล์อยู่แล้ว)

ถ้า push ไม่ผ่านเพราะ remote มีไฟล์อยู่แล้ว:

```bash
# ดึงไฟล์จาก remote มาก่อน
git pull origin main --allow-unrelated-histories

# แก้ conflicts (ถ้ามี)
# จากนั้น commit
git add .
git commit -m "Merge with remote"

# Push
git push origin main
```

หรือ **Force Push** (ระวัง: จะลบไฟล์ใน remote ทั้งหมด):

```bash
git push -f origin main
```

## ตรวจสอบหลัง Push

1. เปิด: https://github.com/reyatelehealth2026-crypto/odoo
2. ตรวจสอบว่าไฟล์ทั้งหมดอัพโหลดครบ
3. ตรวจสอบว่า `.env` และไฟล์ sensitive ไม่ถูกอัพโหลด (ต้องอยู่ใน `.gitignore`)

## ไฟล์ที่ต้องไม่อัพโหลด (ควรอยู่ใน .gitignore)

```
.env
.env.local
.env.*.local
config/config.php (ถ้ามี credentials)
vendor/
node_modules/
*.log
.DS_Store
```

## ตั้งค่า Repository หลัง Push

### 1. Branch Protection

Settings → Branches → Add rule:
- Branch name pattern: `main`
- ✅ Require pull request reviews before merging
- ✅ Require status checks to pass before merging

### 2. Secrets (สำหรับ CI/CD)

Settings → Secrets and variables → Actions → New repository secret:
- `DB_HOST`
- `DB_PASSWORD`
- `LINE_CHANNEL_SECRET`
- `JWT_SECRET`
- etc.

### 3. Collaborators

Settings → Collaborators → Add people

### 4. GitHub Actions (Optional)

สร้างไฟล์ `.github/workflows/deploy.yml` สำหรับ auto-deployment

## Troubleshooting

### ปัญหา: Authentication failed

**แก้ไข**: ใช้ Personal Access Token แทน password

### ปัญหา: Repository not empty

**แก้ไข**: 
```bash
git pull origin main --allow-unrelated-histories
# หรือ
git push -f origin main  # ระวัง: จะลบไฟล์ remote
```

### ปัญหา: Large files (>100MB)

**แก้ไข**: ใช้ Git LFS
```bash
git lfs install
git lfs track "*.zip"
git lfs track "*.sql"
git add .gitattributes
git commit -m "Add Git LFS"
```

### ปัญหา: .env ถูกอัพโหลด

**แก้ไข**:
```bash
# ลบออกจาก git (แต่เก็บไฟล์ไว้)
git rm --cached .env

# เพิ่มใน .gitignore
echo ".env" >> .gitignore

# Commit
git add .gitignore
git commit -m "Remove .env from git"
git push origin main
```

## คำสั่ง Git ที่ใช้บ่อย

```bash
# ดู status
git status

# ดู remote
git remote -v

# ดู branch
git branch -a

# Pull อัพเดทล่าสุด
git pull origin main

# Push การเปลี่ยนแปลง
git add .
git commit -m "Update: description"
git push origin main

# ยกเลิกการเปลี่ยนแปลง
git checkout -- filename.php

# ดู history
git log --oneline

# สร้าง branch ใหม่
git checkout -b feature/new-feature

# เปลี่ยน branch
git checkout main
```

## สรุป

1. รัน: `bash push-to-github.sh` หรือ `push-to-github.bat`
2. ใส่ GitHub username และ Personal Access Token
3. ตรวจสอบที่ https://github.com/reyatelehealth2026-crypto/odoo
4. ตั้งค่า branch protection และ collaborators
5. เริ่มใช้งาน Git workflow ปกติ

---

**หมายเหตุ**: ถ้ามีปัญหาหรือข้อสงสัย สามารถดู Git documentation ได้ที่: https://git-scm.com/doc
