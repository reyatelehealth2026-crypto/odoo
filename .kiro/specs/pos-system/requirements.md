# Requirements Document

## Introduction

ระบบ POS (Point of Sale) สำหรับขายสินค้าหน้าร้านขายยา รองรับการขายแบบ Walk-in และสมาชิก เชื่อมต่อกับระบบ Inventory, Accounting และ CRM ที่มีอยู่ ออกแบบให้ใช้งานง่าย รวดเร็ว รองรับการทำงานบน Tablet/PC

## Glossary

- **POS**: Point of Sale - ระบบขายหน้าร้าน
- **Cart**: ตะกร้าสินค้าชั่วคราวก่อนชำระเงิน
- **Transaction**: รายการขายที่ชำระเงินเสร็จสิ้น
- **Receipt**: ใบเสร็จรับเงิน
- **Cashier**: พนักงานขาย/แคชเชียร์
- **Shift**: กะการทำงานของพนักงาน
- **Walk-in Customer**: ลูกค้าทั่วไปที่ไม่ได้เป็นสมาชิก
- **FEFO**: First Expired First Out - ขายสินค้าที่หมดอายุก่อน

## Requirements

### Requirement 1: Sales Screen

**User Story:** As a cashier, I want to quickly add products to cart and process sales, so that I can serve customers efficiently.

#### Acceptance Criteria

1. WHEN a cashier searches for a product by name, SKU, or barcode THEN the POS_System SHALL display matching products within 500ms
2. WHEN a cashier selects a product THEN the POS_System SHALL add the product to the cart with quantity 1
3. WHEN a cashier adjusts the quantity of a cart item THEN the POS_System SHALL update the line total and cart total immediately
4. WHEN a cashier removes an item from cart THEN the POS_System SHALL remove the item and recalculate totals
5. WHEN a product has insufficient stock THEN the POS_System SHALL display a warning and prevent adding more than available quantity
6. WHEN a product is expired THEN the POS_System SHALL prevent adding it to cart and display expiry warning

### Requirement 2: Customer Selection

**User Story:** As a cashier, I want to link a sale to a customer, so that they can earn points and I can track their purchase history.

#### Acceptance Criteria

1. WHEN a cashier starts a new sale THEN the POS_System SHALL default to Walk-in Customer
2. WHEN a cashier searches for a member by phone or name THEN the POS_System SHALL display matching customers
3. WHEN a cashier selects a member THEN the POS_System SHALL display their name, points balance, and membership tier
4. WHEN a member is selected THEN the POS_System SHALL apply any member-specific discounts automatically

### Requirement 3: Discounts

**User Story:** As a cashier, I want to apply discounts to sales, so that I can offer promotions to customers.

#### Acceptance Criteria

1. WHEN a cashier applies a percentage discount to a line item THEN the POS_System SHALL calculate and display the discounted price
2. WHEN a cashier applies a fixed amount discount to a line item THEN the POS_System SHALL subtract the amount from the line total
3. WHEN a cashier applies a bill-level discount THEN the POS_System SHALL apply the discount to the subtotal before tax
4. WHEN a discount exceeds the item or bill total THEN the POS_System SHALL cap the discount at the maximum allowable amount
5. WHEN a discount requires authorization THEN the POS_System SHALL prompt for manager approval

### Requirement 4: Payment Processing

**User Story:** As a cashier, I want to accept multiple payment methods, so that customers can pay in their preferred way.

#### Acceptance Criteria

1. WHEN a cashier selects cash payment THEN the POS_System SHALL display a cash input field and calculate change
2. WHEN a cashier enters cash amount less than total THEN the POS_System SHALL display remaining balance
3. WHEN a cashier selects transfer/QR payment THEN the POS_System SHALL display QR code for payment
4. WHEN a cashier selects card payment THEN the POS_System SHALL record card payment details
5. WHEN a cashier uses split payment THEN the POS_System SHALL allow multiple payment methods for one transaction
6. WHEN a member has sufficient points THEN the POS_System SHALL allow points redemption as partial payment
7. WHEN payment is complete THEN the POS_System SHALL finalize the transaction and generate receipt

### Requirement 5: Receipt Generation

**User Story:** As a cashier, I want to generate and print receipts, so that customers have proof of purchase.

#### Acceptance Criteria

1. WHEN a transaction is completed THEN the POS_System SHALL generate a receipt with transaction details
2. WHEN a cashier clicks print THEN the POS_System SHALL send receipt to thermal printer
3. WHEN a customer is a LINE member THEN the POS_System SHALL offer to send digital receipt via LINE
4. WHEN a cashier requests receipt reprint THEN the POS_System SHALL allow reprinting from transaction history
5. WHEN generating receipt THEN the POS_System SHALL include store info, items, totals, payment method, and transaction number

### Requirement 6: Inventory Integration

**User Story:** As a store manager, I want POS sales to automatically update inventory, so that stock levels are always accurate.

#### Acceptance Criteria

1. WHEN a transaction is completed THEN the POS_System SHALL deduct sold quantities from inventory
2. WHEN deducting stock THEN the POS_System SHALL use FEFO method to select batches
3. WHEN a sale is voided THEN the POS_System SHALL restore the deducted stock
4. WHEN stock reaches reorder level after sale THEN the POS_System SHALL trigger low stock alert
5. WHEN recording stock movement THEN the POS_System SHALL create stock_movement record with transaction reference

### Requirement 7: Shift Management

**User Story:** As a cashier, I want to manage my shift with opening and closing procedures, so that cash handling is accountable.

#### Acceptance Criteria

1. WHEN a cashier starts a shift THEN the POS_System SHALL record opening cash amount and timestamp
2. WHEN a cashier ends a shift THEN the POS_System SHALL prompt for closing cash count
3. WHEN closing shift THEN the POS_System SHALL calculate expected cash vs actual and display variance
4. WHEN closing shift THEN the POS_System SHALL generate shift summary report
5. WHILE a shift is not open THEN the POS_System SHALL prevent processing sales

### Requirement 8: Transaction History

**User Story:** As a cashier, I want to view and manage past transactions, so that I can handle returns and inquiries.

#### Acceptance Criteria

1. WHEN a cashier searches transaction history THEN the POS_System SHALL display transactions by date, receipt number, or customer
2. WHEN a cashier views a transaction THEN the POS_System SHALL display full transaction details
3. WHEN a cashier voids a transaction THEN the POS_System SHALL require manager authorization
4. WHEN a transaction is voided THEN the POS_System SHALL reverse all related records (stock, points, payments)
5. WHEN viewing history THEN the POS_System SHALL only show transactions from current shift by default

### Requirement 9: Daily Reports

**User Story:** As a store manager, I want to view daily sales reports, so that I can monitor store performance.

#### Acceptance Criteria

1. WHEN viewing daily report THEN the POS_System SHALL display total sales, transaction count, and average ticket
2. WHEN viewing daily report THEN the POS_System SHALL show sales breakdown by payment method
3. WHEN viewing daily report THEN the POS_System SHALL show top selling products
4. WHEN viewing daily report THEN the POS_System SHALL show sales by hour chart
5. WHEN exporting report THEN the POS_System SHALL generate PDF or Excel format

### Requirement 10: Points Integration

**User Story:** As a member, I want to earn and redeem points when shopping, so that I get rewards for my loyalty.

#### Acceptance Criteria

1. WHEN a member completes a purchase THEN the POS_System SHALL calculate and award points based on spend
2. WHEN awarding points THEN the POS_System SHALL apply the configured points rate (e.g., 1 point per 25 baht)
3. WHEN a member redeems points THEN the POS_System SHALL deduct points and apply as payment
4. WHEN a transaction is voided THEN the POS_System SHALL reverse any points earned or redeemed
5. WHEN displaying member info THEN the POS_System SHALL show current points balance and pending points

### Requirement 11: Accounting Integration

**User Story:** As an accountant, I want POS sales to integrate with accounting, so that revenue is properly recorded.

#### Acceptance Criteria

1. WHEN a cash sale is completed THEN the POS_System SHALL record revenue in daily sales summary
2. WHEN a credit sale is made THEN the POS_System SHALL create Account Receivable record
3. WHEN generating daily close THEN the POS_System SHALL provide data for accounting reconciliation
4. WHEN recording sales THEN the POS_System SHALL track VAT separately for tax reporting


### Requirement 12: Return and Refund

**User Story:** As a cashier, I want to process product returns and refunds, so that customers can return items and receive their money back.

#### Acceptance Criteria

1. WHEN a cashier initiates a return THEN the POS_System SHALL require the original receipt number or transaction reference
2. WHEN a valid receipt is found THEN the POS_System SHALL display the original transaction items available for return
3. WHEN a cashier selects items to return THEN the POS_System SHALL allow specifying return quantity up to original purchased quantity
4. WHEN processing a return THEN the POS_System SHALL require a return reason selection
5. WHEN a return is processed THEN the POS_System SHALL restore the returned quantity to inventory
6. WHEN a refund is approved THEN the POS_System SHALL process refund via original payment method or cash
7. WHEN a return involves points earned THEN the POS_System SHALL deduct the corresponding points from member account
8. WHEN a return exceeds configurable time limit THEN the POS_System SHALL require manager authorization
9. WHEN a return is completed THEN the POS_System SHALL generate a return receipt with negative amounts
10. WHEN processing refund THEN the POS_System SHALL record the refund transaction linked to original sale
