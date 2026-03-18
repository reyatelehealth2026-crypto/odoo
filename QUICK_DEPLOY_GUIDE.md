# คู่มือ Deploy ขึ้น GitHub แบบรวดเร็ว

## 🚀 วิธีการ Deploy (เลือก 1 วิธี)

### วิธีที่ 1: Windows (แนะนำสำหรับ Windows)

```cmd
deploy-to-github.bat
```

### วิธีที่ 2: Linux/Mac/Git Bash

```bash
bash deploy-to-github.sh
```

## 📋 สิ่งที่สคริปต์จะทำให้อัตโนมัติ

✅ ตรวจสอบว่าอยู่ใน branch `main`  
✅ ตรวจสอบ remote repository  
✅ ตรวจสอบและลบไฟล์ sensitive ออกจาก staging  
✅ แสดงสถานะไฟล์ที่จะ commit  
✅ ถามยืนยันก่อน push  
✅ Commit และ push ขึ้น GitHub  

## 🔐 การ Authentication

เมื่อ Git ถาม username/password:

```
Username: your-github-username
Password: ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**หมายเหตุ**: Password คือ **Personal Access Token** ไม่ใช่ password ของ GitHub

### สร้าง Personal Access Token:

1. ไปที่: https://github.com/settings/tokens
2. คลิก **"Generate new token"** → **"Generate new token (classic)"**
3. ตั้งชื่อ: `Odoo Dashboard Deploy`
4. เลือก scopes:
   - ✅ `repo` (Full control of private repositories)
   - ✅ `workflow` (Update GitHub Action workflows)
5. คลิก **"Generate token"**
6. **คัดลอก token ทันที** (จะไม่แสดงอีก!)

## 📁 ไฟล์ที่จะไม่ถูก Push (อยู่ใน .gitignore)

- `.env`, `.env.local`, `.env.*.local`
- `config/config.php`
- `vendor/`, `node_modules/`
- `*.log`, `logs/`
- `uploads/*` (ยกเว้น .gitkeep)
- ไฟล์ sensitive อื่นๆ

## ✅ หลัง Push สำเร็จ

### 1. ตรวจสอบ Repository

เปิด: https://github.com/reyatelehealth2026-crypto/odoo

### 2. ตั้งค่า Branch Protection

**Settings** → **Branches** → **Add rule**:
- Branch name pattern: `main`
- ✅ Require pull request reviews before merging
- ✅ Require status checks to pass before merging

### 3. เพิ่ม Secrets (สำหรับ CI/CD)

**Settings** → **Secrets and variables** → **Actions** → **New repository secret**:

```
DB_HOST=your_database_host
DB_PASSWORD=your_database_password
JWT_SECRET=your_jwt_secret
LINE_CHANNEL_SECRET=your_line_channel_secret
REDIS_PASSWORD=your_redis_password
```

### 4. เพิ่ม Collaborators

**Settings** → **Collaborators** → **Add people**

## 🔧 แก้ไขปัญหาที่พบบ่อย

### ปัญหา: Authentication failed

**แก้ไข**: ใช้ Personal Access Token แทน password

### ปัญหา: Repository not empty

**แก้ไข**:
```bash
# Pull ก่อน
git pull origin main --allow-unrelated-histories
git push origin main

# หรือ Force push (ระวัง: จะลบไฟล์ใน remote)
git push -f origin main
```

### ปัญหา: Large files (>100MB)

**แก้ไข**: ใช้ Git LFS
```bash
git lfs install
git lfs track "*.zip"
git lfs track "*.sql"
git add .gitattributes
git commit -m "Add Git LFS"
git push origin main
```

### ปัญหา: .env ถูกอัพโหลด

**แก้ไข**:
```bash
# ลบออกจาก git (แต่เก็บไฟล์ไว้)
git rm --cached .env
git commit -m "Remove .env from git"
git push origin main
```

## 📚 เอกสารเพิ่มเติม

- [Deployment Guide (Thai)](docs/DEPLOYMENT_GUIDE_TH.md)
- [GitHub Push Guide](GITHUB_PUSH_GUIDE.md)
- [Full Deploy Guide](DEPLOY_TO_GITHUB.md)

## 🎯 คำสั่งที่ใช้บ่อย

```bash
# ดูสถานะ
git status

# Pull updates
git pull origin main

# Push updates
git add .
git commit -m "Update: description"
git push origin main

# ดู remote
git remote -v

# ดู branches
git branch -a
```

## 📞 ติดต่อ

- Repository: https://github.com/reyatelehealth2026-crypto/odoo
- Issues: https://github.com/reyatelehealth2026-crypto/odoo/issues
