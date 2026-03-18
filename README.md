# 🏥 LINE Telepharmacy CRM Platform

ระบบจัดการร้านขายยาและ LINE Official Account แบบครบวงจร

![Version](https://img.shields.io/badge/version-3.2-green)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-blue)
![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-orange)
![License](https://img.shields.io/badge/license-MIT-purple)

---

## ✨ Features

### 💬 CRM & Communication
- Multi-bot LINE OA management
- Real-time chat inbox with multi-assignee
- Broadcast & scheduled messages
- Auto-reply rules with priority
- Drip campaigns
- Rich Menu management

### 🛒 E-commerce
- Product catalog management
- Shopping cart & checkout
- Order management
- Payment verification
- Inventory tracking

### 🎯 Loyalty Program
- Points earning rules
- Tier-based membership
- Rewards redemption
- Points expiration
- Birthday rewards

### 🤖 AI Assistant
- Pharmacy AI (Gemini)
- Symptom assessment
- Drug interaction check
- Health profile integration
- Red flag detection

### 🏥 Telepharmacy
- Pharmacist profiles
- Video call appointments
- Consultation notes
- Prescription management
- Medication reminders

### 📊 Analytics & Reports
- Customer analytics
- Sales reports
- Campaign performance
- **Modern Odoo Dashboard** (Next.js + Node.js modernization - **Ready for Implementation**)
  - Real-time dashboard updates with WebSocket integration
  - Enhanced performance (<1s load times, <3% error rate)
  - Modern TypeScript architecture with comprehensive testing
  - JWT authentication and role-based access control
  - Comprehensive audit logging and security features
  - Docker containerization and production deployment ready

---

## 📋 Requirements

- **PHP** >= 8.0
- **MySQL** >= 5.7 or MariaDB >= 10.2
- **Extensions**: PDO, PDO_MySQL, cURL, JSON, mbstring, OpenSSL
- **HTTPS** (required for LINE Webhook)

---

## 🚀 Quick Start

### Option 1: Installation Wizard (Recommended)

1. **Upload files** to your web server
2. **Open browser** and navigate to:
   ```
   https://yourdomain.com/install/wizard.php
   ```
3. **Follow the 7-step wizard**:
   - Welcome
   - System requirements check
   - Database configuration
   - Application settings
   - LINE API configuration
   - Admin account creation
   - Installation

4. **Delete** the `install/` folder after installation

### Option 2: Manual Installation

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p telepharmacy < database/install_complete_latest.sql

# 3. Copy config
cp config/config.example.php config/config.php

# 4. Edit config.php with your settings
nano config/config.php

# 5. Create admin user (in MySQL)
INSERT INTO admin_users (username, email, password, role, is_active) 
VALUES ('admin', 'admin@example.com', '$2y$10$...', 'super_admin', 1);
```

---

## ⚙️ Configuration

### LINE Messaging API

1. Go to [LINE Developers Console](https://developers.line.biz/console/)
2. Create a **Messaging API** channel
3. Get **Channel Secret** and **Channel Access Token**
4. Set **Webhook URL**:
   ```
   https://yourdomain.com/webhook.php?account=1
   ```
5. Enable **Use webhook**, disable **Auto-reply**

### LIFF Apps

Create LIFF apps for:
- Main app (full mode, `/liff/`)
- Share (tall mode, `/liff/?page=share`)

### AI Configuration (Optional)

Set up in Admin > AI Settings:
- **Gemini API Key**: Get from [Google AI Studio](https://aistudio.google.com/)
- **OpenAI API Key**: Get from [OpenAI Platform](https://platform.openai.com/)

---

## 📁 Directory Structure

```
├── api/              # REST API endpoints
├── classes/          # Service classes
├── config/           # Configuration files
├── cron/             # Scheduled tasks
├── database/         # SQL migrations
├── includes/         # Shared includes
├── install/          # Installation wizard
├── liff/             # LIFF SPA application
├── admin/            # Admin panel
├── shop/             # Shop management
├── backend/          # Modern Node.js API (Odoo Dashboard modernization)
├── frontend/         # Modern Next.js frontend (Odoo Dashboard modernization)
├── docker/           # Docker configuration for modernized stack
├── index.php         # Landing page
├── webhook.php       # LINE webhook
└── inbox-v2.php      # Chat inbox
```

## 🚀 Modernization Project

> **📋 Quick Reference**: [Dashboard Code Review Summary](DASHBOARD_CODE_REVIEW_SUMMARY.md) - Executive summary of legacy dashboard review and optimization plan

The platform is undergoing a major modernization effort for the Odoo Dashboard system:

### Current State (PHP)
- Legacy PHP dashboard with 4700+ lines
- 15% error rate, slow load times
- Monolithic architecture

### Target State (Next.js + Node.js)
- **Frontend**: Next.js 14 + TypeScript + Tailwind CSS
- **Backend**: Node.js + Fastify + Prisma ORM
- **Performance**: <1s load times, <3% error rate
- **Features**: Real-time updates, comprehensive audit logging
- **Infrastructure**: Docker + Redis + Nginx

### Implementation Status
The modernization is tracked in `.kiro/specs/odoo-dashboard-modernization/tasks.md` with 17 major task groups covering:

**✅ COMPLETED INFRASTRUCTURE (Task 1)**
- ✅ **1.1** Next.js 14 frontend with TypeScript, Tailwind CSS, ESLint, Prettier
- ✅ **1.2** Node.js backend with Fastify, Prisma ORM, clean architecture
- ✅ **1.3** Docker containerization with multi-stage builds for dev/prod
- 📋 **1.4** Development tooling (hot reload, debugging, pre-commit hooks) - *Optional*

**✅ COMPLETED CORE SYSTEMS**
- ✅ Database schema and migration setup (Prisma ORM, performance tables)
- ✅ Authentication and authorization system (JWT, RBAC)
- ✅ Core API infrastructure (Fastify, circuit breaker, Redis caching)
- ✅ Dashboard overview implementation (metrics calculation, API endpoints)
- ✅ Real-time updates system (WebSocket server, React hooks)
- ✅ Order management system (data access layer, API endpoints, frontend)
- ✅ Performance optimization and caching (comprehensive caching, query optimization)
- ✅ Error handling and reliability (error handling, retry mechanisms)
- ✅ Security implementation (input validation, audit logging, rate limiting)
- ✅ Testing framework setup (Jest, Vitest, property-based testing)
- ✅ Deployment and DevOps setup (Docker production, blue-green deployment)
- ✅ Migration system (feature flags, traffic routing, data migration)

**📋 REMAINING IMPLEMENTATION**
- 📋 Payment processing system (slip upload, matching algorithm)
- ✅ Webhook management system (logging, monitoring, retry mechanism) - **COMPLETED**
- 📋 Customer management system (data sync, profile management)
- 📋 Final integration and testing (system testing, security testing)

**Current Status**: **Infrastructure Complete** - Task 1 has been successfully completed with modern Next.js + Node.js stack, Docker containerization, and development environment ready. The project is now ready for feature implementation starting with Task 8 (Payment Processing System).

**Ready for Implementation**: The specification is complete with detailed tasks, requirements traceability, and 15 correctness properties for property-based testing. Developers can begin implementation by following the tasks in `tasks.md`.

---

## 📱 User Roles

| Role | Access |
|------|--------|
| **Super Admin** | Full system access |
| **Admin** | All features except system settings |
| **Pharmacist** | Consultations, prescriptions |
| **Staff** | Chat, orders |
| **User** | Own LINE account only |

---

## 🔧 Cron Jobs

```bash
# Medication reminders (every 15 min)
*/15 * * * * php /path/to/cron/medication_reminder.php

# Appointment reminders (every 30 min)
*/30 * * * * php /path/to/cron/appointment_reminder.php

# Broadcast queue (every 5 min)
*/5 * * * * php /path/to/cron/process_broadcast_queue.php
```

---

## 🛠️ Troubleshooting

### Webhook not working
- Ensure URL is HTTPS
- Verify Channel Secret is correct
- Check webhook.php permissions

### Cannot send messages
- Check Channel Access Token
- Verify token hasn't expired
- Test connection in LINE Accounts

### Upload issues
- Check `uploads/` permissions (755)
- Verify `upload_max_filesize` in php.ini

---

## 📖 Documentation

### General Documentation
- [Architecture](ARCHITECTURE.md)
- [Project Flow](PROJECT_FLOW_DOCUMENTATION.md)
- [CRM Workflow](CRM_WORKFLOW_COMPLETE.md)
- [User Manual](USER_MANUAL.md)
- [Setup Guide](SETUP_GUIDE_COMPLETE.md)

### Deployment Guides
- **[Quick Deploy Guide (Thai)](QUICK_DEPLOY_GUIDE.md)** - 🚀 Fast GitHub deployment (recommended for first-time setup)
- **[GitHub Deployment Guide](DEPLOY_TO_GITHUB.md)** - Complete GitHub deployment documentation
- **[GitHub Push Guide](GITHUB_PUSH_GUIDE.md)** - Detailed Git workflow and troubleshooting
- **[Production Deployment Guide (Thai)](/docs/DEPLOYMENT_GUIDE_TH.md)** - Complete production deployment guide
  - [Part 2: Migration System](/docs/DEPLOYMENT_GUIDE_TH_PART2.md)
  - [Part 3: Monitoring & Maintenance](/docs/DEPLOYMENT_GUIDE_TH_PART3.md)
- **[Docker Deployment Guide](DEPLOYMENT_GUIDE.md)** - Docker-based deployment

### Odoo Dashboard Modernization
- **[Project Overview](/.kiro/specs/odoo-dashboard-modernization/)** - Next.js + Node.js rewrite project
  - [Requirements](/.kiro/specs/odoo-dashboard-modernization/requirements.md)
  - [Design](/.kiro/specs/odoo-dashboard-modernization/design.md)
  - [Implementation Tasks](/.kiro/specs/odoo-dashboard-modernization/tasks.md)

### Legacy Dashboard Optimization
- **[Optimization Guide](DASHBOARD_OPTIMIZATION_GUIDE.md)** - 🚀 **NEW** Complete optimization guide for legacy PHP dashboard
  - Critical fixes (navigation bug, search debouncing)
  - Performance optimizations (cache warming, lazy loading, optimistic UI)
  - Implementation checklist with expected 50% performance improvement
- **[Code Review (Thai)](docs/ODOO_DASHBOARD_REVIEW.md)** - Concise code review summary in Thai
- **[Detailed Analysis (English)](ODOO_DASHBOARD_ANALYSIS.md)** - Comprehensive technical analysis
  - Correctness issues identified (section loading, cache keys, navigation)
  - Performance optimization recommendations (API batching, lazy loading, debouncing)
  - Implementation priorities and expected outcomes

### API Documentation
- [Customer Management API](/docs/API_CUSTOMER_MANAGEMENT.md) - Search, profile, and LINE connection management
- [Webhook Management System](/docs/WEBHOOK_MANAGEMENT_SYSTEM.md) - Webhook logging and monitoring
- [Audit Logging](/docs/AUDIT_LOGGING.md) - Enhanced audit trail and session management

---

## 📄 License

MIT License - Free for personal and commercial use.

---

## 🤝 Support

For issues and feature requests, please create an Issue in the repository.

---

Made with ❤️ for LINE Telepharmacy Management
