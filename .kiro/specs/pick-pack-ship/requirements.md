# Requirements Document

## Introduction

ระบบ Pick-Pack-Ship (WMS - Warehouse Management System) สำหรับจัดการกระบวนการจัดส่งสินค้าจากคลัง โดยมีขั้นตอนการทำงาน 3 ขั้นตอนหลัก:
1. **Pick (หยิบสินค้า)** - หยิบสินค้าจากชั้นวางตามรายการออเดอร์
2. **Pack (แพ็คสินค้า)** - บรรจุสินค้าลงกล่อง/ซอง พร้อมพิมพ์ใบปะหน้า
3. **Ship (จัดส่ง)** - ส่งมอบให้ขนส่งและอัพเดทเลขพัสดุ

ระบบนี้จะช่วยให้การจัดการออเดอร์มีประสิทธิภาพ ลดความผิดพลาด และติดตามสถานะได้แบบ real-time

## Glossary

- **WMS (Warehouse Management System)**: ระบบจัดการคลังสินค้า
- **Pick List**: รายการสินค้าที่ต้องหยิบจากคลัง
- **Packing Slip**: ใบรายการสินค้าที่แนบไปกับพัสดุ
- **Shipping Label**: ใบปะหน้าพัสดุสำหรับจัดส่ง
- **Batch Processing**: การประมวลผลหลายออเดอร์พร้อมกัน
- **Wave Picking**: การหยิบสินค้าหลายออเดอร์ในรอบเดียว
- **Picker**: พนักงานหยิบสินค้า
- **Packer**: พนักงานแพ็คสินค้า
- **Carrier**: บริษัทขนส่ง (Kerry, Flash, J&T, Thailand Post, etc.)
- **Tracking Number**: เลขพัสดุสำหรับติดตามการจัดส่ง
- **Admin User**: ผู้ดูแลระบบที่มีสิทธิ์จัดการ WMS

## Requirements

### Requirement 1: Pick List Management

**User Story:** As a warehouse staff, I want to view and manage pick lists, so that I can efficiently pick products for orders.

#### Acceptance Criteria

1. WHEN orders are confirmed/paid THEN the WMS System SHALL automatically add them to the pick queue with status "pending_pick"
2. WHEN a picker views the pick queue THEN the WMS System SHALL display orders sorted by priority (oldest first, or by shipping method urgency)
3. WHEN a picker starts picking an order THEN the WMS System SHALL change order status to "picking" and record picker assignment
4. WHEN a picker views pick list THEN the WMS System SHALL display product name, SKU, quantity, and storage location (if available)
5. WHEN a picker confirms item picked THEN the WMS System SHALL mark the item as picked and update progress
6. WHEN all items in an order are picked THEN the WMS System SHALL change order status to "picked" and move to pack queue

### Requirement 2: Batch/Wave Picking

**User Story:** As a warehouse manager, I want to create batch pick lists, so that pickers can efficiently pick multiple orders in one trip.

#### Acceptance Criteria

1. WHEN a manager creates a batch pick THEN the WMS System SHALL combine items from multiple orders into a consolidated pick list
2. WHEN creating batch pick THEN the WMS System SHALL group items by product to minimize walking distance
3. WHEN a picker completes batch picking THEN the WMS System SHALL sort items back to individual orders for packing
4. WHEN viewing batch pick list THEN the WMS System SHALL show total quantity per product and which orders need each item
5. IF an item is out of stock during picking THEN the WMS System SHALL allow marking as "short" and notify for backorder handling

### Requirement 3: Pack Station Management

**User Story:** As a packer, I want to pack orders efficiently, so that products are properly packaged for shipping.

#### Acceptance Criteria

1. WHEN an order arrives at pack station THEN the WMS System SHALL display order details, items, and customer shipping address
2. WHEN a packer scans/selects an order THEN the WMS System SHALL verify all items are picked and ready for packing
3. WHEN a packer confirms packing THEN the WMS System SHALL record package dimensions and weight (optional)
4. WHEN packing is complete THEN the WMS System SHALL generate packing slip with order details and item list
5. WHEN packing is complete THEN the WMS System SHALL change order status to "packed" and move to ship queue
6. IF items are missing during packing THEN the WMS System SHALL flag the order and notify for investigation

### Requirement 4: Shipping Label Generation

**User Story:** As a packer, I want to generate shipping labels, so that packages can be properly labeled for carriers.

#### Acceptance Criteria

1. WHEN a packer requests shipping label THEN the WMS System SHALL generate label with recipient name, address, and order number
2. WHEN generating label THEN the WMS System SHALL include sender information from shop settings
3. WHEN generating label THEN the WMS System SHALL display barcode/QR code for tracking (if tracking number available)
4. WHEN printing label THEN the WMS System SHALL support standard label sizes (A6, 10x15cm)
5. WHEN label is printed THEN the WMS System SHALL record print timestamp for audit

### Requirement 5: Carrier Integration & Tracking

**User Story:** As a shipper, I want to assign carriers and tracking numbers, so that customers can track their packages.

#### Acceptance Criteria

1. WHEN a shipper selects carrier THEN the WMS System SHALL record carrier name (Kerry, Flash, J&T, Thailand Post, etc.)
2. WHEN a shipper enters tracking number THEN the WMS System SHALL validate format and store with the order
3. WHEN tracking number is added THEN the WMS System SHALL change order status to "shipped" and notify customer via LINE
4. WHEN viewing shipped orders THEN the WMS System SHALL display carrier, tracking number, and ship date
5. WHEN clicking tracking number THEN the WMS System SHALL open carrier tracking page in new tab

### Requirement 6: WMS Dashboard

**User Story:** As a warehouse manager, I want to view WMS dashboard, so that I can monitor fulfillment operations.

#### Acceptance Criteria

1. WHEN viewing WMS dashboard THEN the WMS System SHALL display counts for each status (pending_pick, picking, picked, packing, packed, shipped)
2. WHEN viewing dashboard THEN the WMS System SHALL show today's fulfillment metrics (orders processed, average time per order)
3. WHEN viewing dashboard THEN the WMS System SHALL highlight overdue orders (not shipped within SLA)
4. WHEN viewing dashboard THEN the WMS System SHALL show picker/packer performance summary
5. WHEN clicking status count THEN the WMS System SHALL navigate to filtered order list

### Requirement 7: Order Status Synchronization

**User Story:** As a system, I want to synchronize order status between WMS and LIFF, so that customers see accurate delivery status.

#### Acceptance Criteria

1. WHEN order status changes in WMS THEN the WMS System SHALL update the transactions table status field
2. WHEN order moves to "picking" THEN the WMS System SHALL set status to "processing" for customer view
3. WHEN order moves to "packed" THEN the WMS System SHALL set status to "ready_to_ship" for customer view
4. WHEN order moves to "shipped" THEN the WMS System SHALL set status to "shipping" and store tracking number
5. WHEN status changes THEN the WMS System SHALL send LINE notification to customer with status update

### Requirement 8: Print Queue Management

**User Story:** As a warehouse staff, I want to manage print queues, so that I can batch print labels and packing slips.

#### Acceptance Criteria

1. WHEN multiple orders are ready THEN the WMS System SHALL allow selecting multiple orders for batch printing
2. WHEN batch printing packing slips THEN the WMS System SHALL generate multi-page PDF with one slip per page
3. WHEN batch printing shipping labels THEN the WMS System SHALL generate labels in sequence
4. WHEN print job completes THEN the WMS System SHALL mark orders as "label_printed"
5. WHEN reprinting is needed THEN the WMS System SHALL allow reprint with audit log

### Requirement 9: Returns & Exceptions Handling

**User Story:** As a warehouse staff, I want to handle exceptions and returns, so that problematic orders are properly managed.

#### Acceptance Criteria

1. WHEN an item is damaged during picking THEN the WMS System SHALL allow marking as damaged and trigger stock adjustment
2. WHEN an order cannot be fulfilled THEN the WMS System SHALL allow marking as "on_hold" with reason
3. WHEN a return is received THEN the WMS System SHALL create return record and update stock after inspection
4. WHEN viewing exceptions THEN the WMS System SHALL display all orders with issues requiring attention
5. WHEN resolving exception THEN the WMS System SHALL record resolution action and staff member

### Requirement 10: Data Serialization for Reports

**User Story:** As a developer, I want to serialize WMS data, so that I can generate reports and integrate with external systems.

#### Acceptance Criteria

1. WHEN exporting fulfillment data THEN the WMS System SHALL serialize to JSON with all status timestamps
2. WHEN generating daily report THEN the WMS System SHALL include orders processed, average fulfillment time, and exceptions
3. WHEN round-trip serialization occurs THEN the WMS System SHALL produce equivalent data after serialize then deserialize
4. WHEN exporting for carrier THEN the WMS System SHALL generate CSV with shipping details in carrier-required format
