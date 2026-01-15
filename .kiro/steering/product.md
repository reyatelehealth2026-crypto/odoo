# Product Overview

LINE Telepharmacy Platform - A comprehensive pharmacy management system integrated with LINE Official Account.

## Core Purpose
Multi-tenant SaaS platform for pharmacies to manage:
- Customer communication via LINE messaging
- E-commerce (online shop with LIFF)
- Point-of-sale (POS) for walk-in customers
- Pharmacy operations (drug dispensing, consultations)
- Inventory and warehouse management (WMS)
- Customer loyalty and membership programs
- AI-powered pharmacy assistant (Vibe Selling OS v2)

## Target Users
- **Pharmacy owners/admins**: Full system management
- **Pharmacists**: Drug dispensing, consultations, video calls
- **Staff**: Order processing, customer service, POS
- **Marketing**: Broadcast, campaigns, rich menu
- **Customers**: Shop via LINE LIFF app

## Key Integrations
- LINE Messaging API & LIFF SDK
- Google Gemini AI / OpenAI for pharmacy assistant
- CNY Pharmacy API for product sync
- Telegram for admin notifications
- WebRTC for video consultations

## Business Domain
Thai pharmacy retail with telepharmacy capabilities, supporting prescription drugs, OTC products, and health consultations.

## Implemented Feature Modules (from Specs)

### Core Operations
- **POS System**: Walk-in sales, shifts, returns, receipts
- **Accounting**: AP/AR, expenses, payment/receipt vouchers, aging reports
- **Inventory**: Stock management, movements, adjustments, low-stock alerts
- **Procurement**: Purchase orders, goods receive, suppliers
- **WMS (Pick-Pack-Ship)**: Order fulfillment workflow

### Customer Engagement
- **Inbox Chat Upgrade**: Quick reply templates, analytics, real-time updates
- **Vibe Selling OS v2**: AI pharmacy assistant with HUD widgets, ghost drafts
- **Membership**: Points, tiers, rewards redemption
- **Landing Page**: SEO, FAQ, testimonials, trust badges

### Pharmacy Operations
- **Put Away & Location**: Warehouse locations, batch/lot tracking, FIFO/FEFO
- **Goods Receive & Disposal**: Batch creation from GR, disposal with expense tracking
- **Drug Interactions**: Allergy checks, interaction warnings

### Platform
- **LIFF Telepharmacy**: SPA for customers (shop, orders, health profile, video call)
- **AI Integration**: Triage engine, red flag detection, pharmacist escalation
- **Admin Menu Restructure**: Role-based access control
