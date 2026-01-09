# Implementation Plan: POS System

## 1. Database Setup and Migration

- [x] 1.1 Create POS database migration file
  - Create `database/migration_pos.sql` with all POS tables
  - Tables: `pos_transactions`, `pos_transaction_items`, `pos_payments`, `pos_shifts`, `pos_returns`, `pos_return_items`
  - Include all indexes and foreign keys as defined in design
  - _Requirements: All_

- [x] 1.2 Create migration runner script
  - Create `install/run_pos_migration.php`
  - Execute migration with error handling
  - _Requirements: All_

## 2. Core Services Implementation

- [x] 2.1 Create POSService class
  - Create `classes/POSService.php`
  - Implement transaction management: `createTransaction()`, `completeTransaction()`, `voidTransaction()`
  - Implement cart operations: `addToCart()`, `updateCartItem()`, `removeFromCart()`
  - Implement discount methods: `applyItemDiscount()`, `applyBillDiscount()`
  - Implement customer methods: `setCustomer()`, `searchCustomers()`
  - Implement `calculateTotals()` with VAT calculation
  - Integrate with existing InventoryService and BatchService for stock checks
  - _Requirements: 1.1-1.6, 2.1-2.4, 3.1-3.5_

- [ ]* 2.2 Write property test for cart calculation consistency
  - **Property 1: Cart Calculation Consistency**
  - Test that cart total equals sum of line totals minus bill discount
  - **Validates: Requirements 1.3, 1.4, 3.1, 3.2, 3.3**

- [ ]* 2.3 Write property test for stock quantity constraint
  - **Property 2: Stock Quantity Constraint**
  - Test that cart quantity never exceeds available stock
  - **Validates: Requirements 1.5**

- [ ]* 2.4 Write property test for expired product exclusion
  - **Property 3: Expired Product Exclusion**
  - Test that no cart item has expiry date before current date
  - **Validates: Requirements 1.6**

- [ ]* 2.5 Write property test for discount cap enforcement
  - **Property 4: Discount Cap Enforcement**
  - Test that discounts never result in negative prices
  - **Validates: Requirements 3.4**

- [x] 2.6 Create POSPaymentService class
  - Create `classes/POSPaymentService.php`
  - Implement `processPayment()` for single payment
  - Implement `processSplitPayment()` for multiple payment methods
  - Implement `processPointsRedemption()` integrating with LoyaltyPoints
  - Implement `calculateChange()` for cash payments
  - Implement `processRefund()` for returns
  - _Requirements: 4.1-4.7_

- [ ]* 2.7 Write property test for payment balance consistency
  - **Property 5: Payment Balance Consistency**
  - Test that sum of all payments equals total amount
  - **Validates: Requirements 4.5, 4.7**

- [ ]* 2.8 Write property test for change calculation accuracy
  - **Property 6: Change Calculation Accuracy**
  - Test that change equals cash received minus total when cash >= total
  - **Validates: Requirements 4.1, 4.2**

- [x] 2.9 Create POSShiftService class
  - Create `classes/POSShiftService.php`
  - Implement `openShift()` with opening cash recording
  - Implement `closeShift()` with closing cash count
  - Implement `getCurrentShift()` to check active shift
  - Implement `getShiftSummary()` for shift report
  - Implement `calculateVariance()` for cash reconciliation
  - _Requirements: 7.1-7.5_

- [ ]* 2.10 Write property test for shift cash variance calculation
  - **Property 10: Shift Cash Variance Calculation**
  - Test variance = actual closing cash - expected cash
  - **Validates: Requirements 7.3**

- [ ]* 2.11 Write property test for shift sales prevention
  - **Property 11: Shift Sales Prevention**
  - Test that sales are rejected when no shift is open
  - **Validates: Requirements 7.5**

## 3. Return and Refund Services

- [x] 3.1 Create POSReturnService class
  - Create `classes/POSReturnService.php`
  - Implement `findTransaction()` by receipt number
  - Implement `getReturnableItems()` to show available items for return
  - Implement `createReturn()` with reason selection
  - Implement `processReturn()` with stock restoration and points reversal
  - Integrate with InventoryService for stock updates
  - Integrate with LoyaltyPoints for points deduction
  - _Requirements: 12.1-12.10_

- [ ]* 3.2 Write property test for return quantity constraint
  - **Property 15: Return Quantity Constraint**
  - Test return quantity <= original quantity - already returned
  - **Validates: Requirements 12.3**

- [ ]* 3.3 Write property test for return transaction linkage
  - **Property 16: Return Transaction Linkage**
  - Test that returns are linked to exactly one original transaction
  - **Validates: Requirements 12.10**

## 4. Receipt and Inventory Integration

- [x] 4.1 Create POSReceiptService class
  - Create `classes/POSReceiptService.php`
  - Implement `generateReceipt()` with all required fields
  - Implement `printReceipt()` for thermal printer
  - Implement `sendLineReceipt()` for LINE members
  - Implement `generateReturnReceipt()` for returns
  - _Requirements: 5.1-5.5_

- [ ]* 4.2 Write property test for receipt content completeness
  - **Property 7: Receipt Content Completeness**
  - Test receipt contains store info, items, totals, payment, transaction number
  - **Validates: Requirements 5.5**

- [x] 4.3 Implement inventory integration in POSService
  - Add stock deduction on transaction completion using FEFO via BatchService
  - Add stock restoration on void/return
  - Create stock_movement records with transaction reference via InventoryService
  - Trigger low stock alerts when threshold reached
  - _Requirements: 6.1-6.5_

- [ ]* 4.4 Write property test for stock movement consistency
  - **Property 8: Stock Movement Consistency**
  - Test stock decreases on sale, increases on void/return
  - **Validates: Requirements 6.1, 6.3, 12.5**

- [ ]* 4.5 Write property test for FEFO batch selection
  - **Property 9: FEFO Batch Selection**
  - Test batches selected in order of earliest expiry first
  - **Validates: Requirements 6.2**

## 5. Points and Accounting Integration

- [x] 5.1 Implement points integration in POSService
  - Calculate and award points on member purchase via LoyaltyPoints class
  - Handle points redemption as payment
  - Reverse points on void/return
  - Display member points balance
  - _Requirements: 10.1-10.5_

- [ ]* 5.2 Write property test for points calculation consistency
  - **Property 13: Points Calculation Consistency**
  - Test points earned = floor(total / points_rate)
  - **Validates: Requirements 10.1, 10.2, 10.4, 12.7**

- [ ]* 5.3 Write property test for points redemption validity
  - **Property 14: Points Redemption Validity**
  - Test redeemed points <= available balance
  - **Validates: Requirements 10.3**

- [x] 5.4 Implement accounting integration
  - Record revenue in daily sales summary
  - Create AR record for credit sales using AccountReceivableService
  - Track VAT separately for tax reporting
  - _Requirements: 11.1-11.4_

## 6. Checkpoint - Core Services

- [x] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## 7. API Endpoints

- [x] 7.1 Create POS API endpoint
  - Create `api/pos.php`
  - Implement endpoints for cart operations (add, update, remove)
  - Implement endpoints for customer search and selection
  - Implement endpoints for discount application
  - Implement endpoints for payment processing
  - Implement endpoints for transaction completion
  - _Requirements: 1.1-1.6, 2.1-2.4, 3.1-3.5, 4.1-4.7_

- [x] 7.2 Create Shift API endpoint
  - Add shift endpoints to `api/pos.php` or create `api/pos-shift.php`
  - Implement open/close shift endpoints
  - Implement shift summary endpoint
  - _Requirements: 7.1-7.5_

- [x] 7.3 Create Returns API endpoint
  - Add return endpoints to `api/pos.php` or create `api/pos-returns.php`
  - Implement find transaction endpoint
  - Implement create/process return endpoints
  - _Requirements: 12.1-12.10_

- [x] 7.4 Create Reports API endpoint
  - Add report endpoints to `api/pos.php` or create `api/pos-reports.php`
  - Implement daily sales report endpoint
  - Implement transaction history endpoint
  - _Requirements: 8.1-8.5, 9.1-9.5_

## 8. Frontend - POS Interface

- [x] 8.1 Create main POS page
  - Create `pos.php` as main POS interface
  - Include header with shift status indicator
  - Create responsive layout for tablet/PC
  - _Requirements: 1.1, 7.5_

- [x] 8.2 Create POS includes structure
  - Create `includes/pos/` directory
  - Create `includes/pos/sales.php` for sales screen component
  - Create `includes/pos/cart.php` for cart component
  - Create `includes/pos/payment.php` for payment modal
  - Create `includes/pos/customer.php` for customer selection
  - _Requirements: 1.1-1.6, 2.1-2.4, 4.1-4.7_

- [x] 8.3 Implement product search and cart UI
  - Product search by name, SKU, barcode with 500ms response
  - Cart display with quantity adjustment
  - Line item discount controls
  - Bill discount controls
  - Real-time total calculation
  - _Requirements: 1.1-1.6, 3.1-3.4_

- [x] 8.4 Implement payment UI
  - Cash payment with change calculation
  - QR code display for transfer payment
  - Card payment recording
  - Split payment interface
  - Points redemption option for members
  - _Requirements: 4.1-4.7_

- [x] 8.5 Implement receipt UI
  - Receipt preview before print
  - Print button for thermal printer
  - Send to LINE option for members
  - _Requirements: 5.1-5.5_

## 9. Frontend - Shift and History

- [x] 9.1 Create shift management UI
  - Create `includes/pos/shift.php`
  - Open shift modal with opening cash input
  - Close shift modal with closing cash count
  - Variance display and confirmation
  - Shift summary report view
  - _Requirements: 7.1-7.5_

- [x] 9.2 Create transaction history UI
  - Create `includes/pos/history.php`
  - Transaction list with search/filter
  - Transaction detail view
  - Void transaction with authorization
  - Reprint receipt option
  - _Requirements: 8.1-8.5_

- [x] 9.3 Create returns UI
  - Create `includes/pos/returns.php`
  - Receipt lookup interface
  - Item selection for return
  - Return reason selection
  - Refund method selection
  - Return receipt generation
  - _Requirements: 12.1-12.10_

## 10. Frontend - Reports

- [x] 10.1 Create daily reports UI
  - Create `includes/pos/reports.php`
  - Daily sales summary display
  - Payment method breakdown
  - Top selling products
  - Sales by hour chart
  - Export to PDF/Excel
  - _Requirements: 9.1-9.5_

- [ ]* 10.2 Write property test for daily report accuracy
  - **Property 17: Daily Report Accuracy**
  - Test total sales = sum of completed transactions
  - **Validates: Requirements 9.1, 9.2**

## 11. Void Transaction Integration

- [x] 11.1 Implement void transaction with full reversal
  - Implement manager authorization check
  - Reverse stock movements via InventoryService
  - Reverse points earned/redeemed via LoyaltyPoints
  - Reverse payment records
  - Update transaction status
  - _Requirements: 8.3, 8.4_

- [ ]* 11.2 Write property test for void reversal completeness
  - **Property 12: Void Reversal Completeness**
  - Test all related records are reversed on void
  - **Validates: Requirements 8.4**

## 12. JavaScript and Assets

- [x] 12.1 Create POS JavaScript
  - Create `assets/js/pos.js`
  - Implement cart state management
  - Implement API calls for all operations
  - Implement barcode scanner integration
  - Implement keyboard shortcuts
  - _Requirements: 1.1-1.6_

- [x] 12.2 Create POS CSS
  - Create `assets/css/pos.css`
  - Responsive design for tablet/PC
  - Touch-friendly buttons
  - Clear visual hierarchy
  - _Requirements: 1.1_

## 13. Final Checkpoint

- [x] 13. Final Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.
