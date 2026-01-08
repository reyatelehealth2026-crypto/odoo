-- Migration: Add CNY API compatible fields to business_items
-- Run this migration to support full CNY API data sync
-- Version: 2.0 - Full CNY fields support

-- Add name_en column for English product name
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS name_en VARCHAR(500) NULL AFTER name;

-- Ensure other CNY fields exist
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS generic_name VARCHAR(500) NULL COMMENT 'ชื่อสามัญ/สารสำคัญ (spec_name)';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS usage_instructions TEXT NULL COMMENT 'วิธีใช้ (how_to_use)';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(255) NULL COMMENT 'ผู้ผลิต';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) NULL;
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS unit VARCHAR(100) NULL COMMENT 'หน่วยจำนวน เช่น ขวด[ 60ML ]';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS base_unit VARCHAR(50) NULL COMMENT 'หน่วยนับ เช่น ขวด, กล่อง, แผง';

-- CNY specific fields for full compatibility
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS product_price JSON NULL COMMENT 'ราคาตามกลุ่มลูกค้า JSON array';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS properties_other TEXT NULL COMMENT 'สรรพคุณอื่นๆ';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500) NULL COMMENT 'URL รูปภาพจาก CNY';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS cny_id INT NULL COMMENT 'ID จาก CNY API';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS cny_category VARCHAR(100) NULL COMMENT 'หมวดหมู่จาก CNY';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS hashtag VARCHAR(500) NULL COMMENT 'Hashtag สำหรับค้นหา';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS qty_incoming INT DEFAULT 0 COMMENT 'จำนวนที่กำลังเข้า';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS enable TINYINT(1) DEFAULT 1 COMMENT 'เปิด/ปิดขาย';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS last_synced_at TIMESTAMP NULL COMMENT 'เวลา sync ล่าสุด';

-- Add indexes for search
CREATE INDEX IF NOT EXISTS idx_business_items_barcode ON business_items(barcode);
CREATE INDEX IF NOT EXISTS idx_business_items_cny_id ON business_items(cny_id);
CREATE INDEX IF NOT EXISTS idx_business_items_enable ON business_items(enable);
