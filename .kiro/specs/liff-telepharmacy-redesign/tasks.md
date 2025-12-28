# Implementation Plan

## LIFF Telepharmacy Redesign - Task List

- [x] 1. Project Setup and Core Infrastructure





  - [x] 1.1 Create unified LIFF entry point and SPA router


    - Create `liff/index.php` as single entry point
    - Implement client-side router in `liff/assets/js/router.js`
    - Set up state management in `liff/assets/js/store.js`
    - _Requirements: 1.1, 1.2, 1.3_

  - [x] 1.2 Create unified CSS design system

    - Create `liff/assets/css/liff-app.css` with color palette, typography, spacing
    - Implement CSS variables for theming
    - Add responsive breakpoints and safe area support
    - _Requirements: 1.4, 1.5_
  - [x]* 1.3 Write property test for touch target minimum size
    - **Property 2: Touch Target Minimum Size**
    - Test file: tests/LiffTelepharmacy/TouchTargetPropertyTest.php
    - **Validates: Requirements 1.5**

  - [x] 1.4 Implement LIFF initialization and authentication

    - Create `liff/assets/js/liff-app.js` main controller
    - Handle LIFF init, login, profile retrieval
    - Implement error handling for network failures

    - _Requirements: 1.2, 1.6_



- [x] 2. Skeleton Loading and Performance Components



  - [x] 2.1 Create skeleton loading components

    - Implement `Skeleton.productCard()`, `Skeleton.memberCard()`, `Skeleton.orderCard()`
    - Add CSS animations for skeleton pulse effect
    - _Requirements: 8.1, 8.3_

  - [x] 2.2 Implement lazy loading for images

    - Add `loading="lazy"` and placeholder images
    - Implement intersection observer for image loading
    - _Requirements: 8.5_

- [ ] 3. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.


- [x] 4. Home Dashboard (Telecare Style)




  - [x] 4.1 Create home dashboard layout


    - Implement header with shop logo and notifications
    - Create member card section with gradient background
    - Add 3x2 service grid with icons
    - _Requirements: 13.1, 13.4, 13.5_

  - [x] 4.2 Implement member card component

    - Display member name, ID, tier, points, expiry
    - Add QR code generation for POS scanning
    - Implement tier progress bar
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 13.2, 13.3_
  - [x]* 4.3 Write property test for QR code validity
    - **Property 8: QR Code Validity**
    - Test file: tests/LiffTelepharmacy/QRCodePropertyTest.php
    - **Validates: Requirements 5.3**
  - [x]* 4.4 Write property test for tier progress bounds
    - **Property 9: Tier Progress Bounds**
    - Test file: tests/LiffTelepharmacy/TierProgressPropertyTest.php
    - **Validates: Requirements 5.4**

  - [x] 4.5 Create AI assistant quick actions section

    - Add gradient background section
    - Implement quick symptom buttons
    - _Requirements: 13.6, 13.7_


  - [x] 4.6 Create available pharmacists section





    - Display pharmacist cards with photo, name, specialty
    - Add "Book" button for each pharmacist
    - _Requirements: 13.8, 13.9_


- [x] 5. Shop Page - Product Catalog






  - [x] 5.1 Create shop page layout with 2-column grid





    - Implement sticky search bar and category filter
    - Create product grid with responsive columns
    - _Requirements: 2.1, 2.2_
  - [x] 5.2 Implement product card component

    - Display image, name, price, sale price, badges
    - Add Rx badge for prescription products
    - Add wishlist heart button
    - _Requirements: 2.3, 11.1_
  - [x]* 5.3 Write property test for product card required elements
    - **Property 3: Product Card Required Elements**
    - Test file: tests/LiffTelepharmacy/ProductCardPropertyTest.php
    - **Validates: Requirements 2.3**
  - [x]* 5.4 Write property test for prescription badge display
    - **Property 10: Prescription Badge Display**
    - Test file: tests/LiffTelepharmacy/ProductCardPropertyTest.php
    - **Validates: Requirements 11.1**
  - [x] 5.5 Implement AJAX add-to-cart functionality

    - Add product to cart without page reload
    - Show visual feedback on button
    - Update cart badge count
    - _Requirements: 2.4_
  - [x] 5.6 Implement floating cart summary bar

    - Display item count and total price
    - Show/hide based on cart state
    - _Requirements: 2.5_
  - [x]* 5.7 Write property test for cart summary visibility
    - **Property 4: Cart Summary Visibility**
    - Test file: tests/LiffTelepharmacy/CartSummaryPropertyTest.php
    - **Validates: Requirements 2.5**
  - [x] 5.8 Implement infinite scroll pagination

    - Load more products on scroll to bottom
    - Show loading indicator during fetch
    - _Requirements: 2.6_
  - [x]* 5.9 Write property test for infinite scroll loading
    - **Property 20: Infinite Scroll Loading**
    - Test file: tests/LiffTelepharmacy/InfiniteScrollPropertyTest.php
    - **Validates: Requirements 2.6**
  - [x] 5.10 Implement real-time search filtering

    - Filter products as user types
    - Debounce search input
    - _Requirements: 2.7_

- [ ] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Cart and Checkout





  - [x] 7.1 Create cart page with item list


    - Display cart items with quantity controls
    - Show subtotal, discount, shipping, total
    - _Requirements: 3.1_
  - [x] 7.2 Implement cart serialization/deserialization


    - Serialize cart to JSON for storage
    - Deserialize cart from storage
    - _Requirements: 2.9, 2.10_
  - [x]* 7.3 Write property test for cart serialization round-trip
    - **Property 1: Cart Serialization Round-Trip**
    - Test file: tests/LiffTelepharmacy/CartSerializationPropertyTest.php
    - **Validates: Requirements 2.9, 2.10**
  - [x] 7.4 Implement one-page checkout form


    - Create address input with saved address option
    - Add payment method selection (Transfer, Card, PromptPay)
    - Auto-fill from LINE profile
    - _Requirements: 3.1, 3.2, 3.3, 3.4_
  - [x]* 7.5 Write property test for auto-fill from LINE profile
    - **Property 19: Auto-fill from LINE Profile**
    - Test file: tests/LiffTelepharmacy/FormValidationPropertyTest.php
    - **Validates: Requirements 3.2**
  - [x] 7.6 Implement real-time form validation


    - Validate inputs as user types
    - Show inline error messages
    - Enable/disable Place Order button
    - _Requirements: 3.5, 3.6, 3.7_
  - [x]* 7.7 Write property test for form validation state consistency
    - **Property 5: Form Validation State Consistency**
    - Test file: tests/LiffTelepharmacy/FormValidationPropertyTest.php
    - **Validates: Requirements 3.6, 3.7**
  - [x] 7.8 Implement order placement with loading state


    - Show loading state on submit
    - Prevent duplicate submissions
    - Create order via API
    - _Requirements: 3.8, 3.9_


  - [x] 7.9 Implement coupon/promo code input
    - Add promo code input field
    - Validate code via API
    - Apply discount or show error
    - _Requirements: 17.4, 17.5, 17.6, 17.7_
  - [x]* 7.10 Write property test for promo code validation response
    - **Property 16: Promo Code Validation Response**
    - Test file: tests/LiffTelepharmacy/PromoCodePropertyTest.php
    - **Validates: Requirements 17.5, 17.6, 17.7**

- [ ] 8. Checkpoint - Ensure all tests pass



  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Drug Interaction System





  - [x] 9.1 Create drug interaction checker service


    - Implement API endpoint for interaction checking
    - Query drug interaction database
    - Return severity and recommendations
    - _Requirements: 12.1_
  - [x]* 9.2 Write property test for drug interaction check trigger
    - **Property 13: Drug Interaction Check Trigger**
    - Test file: tests/LiffTelepharmacy/DrugInteractionPropertyTest.php
    - **Validates: Requirements 12.1**


  - [x] 9.3 Implement interaction warning modal
    - Display severity with color coding
    - Show interacting drugs and description


    - Handle Mild, Moderate, Severe differently
    - _Requirements: 12.2, 12.3, 12.8_
  - [x] 9.4 Implement severe interaction blocking
    - Block product addition for severe interactions
    - Offer pharmacist consultation

    - _Requirements: 12.4_
  - [x]* 9.5 Write property test for severe interaction block
    - **Property 14: Severe Interaction Block**
    - Test file: tests/LiffTelepharmacy/DrugInteractionPropertyTest.php
    - **Validates: Requirements 12.4**
  - [x] 9.6 Implement moderate interaction acknowledgment

    - Allow addition with checkbox acknowledgment
    - Record acknowledged interactions
    - _Requirements: 12.5_
  - [x]* 9.7 Write property test for moderate interaction acknowledgment
    - **Property 15: Moderate Interaction Acknowledgment**
    - Test file: tests/LiffTelepharmacy/DrugInteractionPropertyTest.php
    - **Validates: Requirements 12.5**

- [x] 10. Prescription Drug Flow





  - [x] 10.1 Implement prescription product detection


    - Check is_prescription flag on products
    - Show Rx badge and warning label
    - _Requirements: 11.1, 11.2_
  - [x] 10.2 Implement prescription consultation requirement

    - Block checkout for Rx items without approval
    - Show "Consult Pharmacist" button
    - _Requirements: 11.3, 11.4_
  - [x]* 10.3 Write property test for prescription checkout block
    - **Property 11: Prescription Checkout Block**
    - Test file: tests/LiffTelepharmacy/PrescriptionPropertyTest.php
    - **Validates: Requirements 11.3**
  - [x] 10.4 Implement prescription approval system


    - Create approval record on pharmacist approval
    - Set 24-hour expiry
    - Unlock checkout on approval
    - _Requirements: 11.7, 11.9_
  - [x]* 10.5 Write property test for prescription approval expiry
    - **Property 12: Prescription Approval Expiry**
    - Test file: tests/LiffTelepharmacy/PrescriptionPropertyTest.php
    - **Validates: Requirements 11.9**
  - [x] 10.6 Implement approval expiry handling


    - Check approval validity before checkout
    - Require re-consultation if expired
    - _Requirements: 11.10_

- [ ] 11. Checkpoint - Ensure all tests pass




  - Ensure all tests pass, ask the user if questions arise.



- [x] 12. Order History and Tracking



  - [x] 12.1 Create order history page


    - Display orders in timeline/card view
    - Sort by date descending
    - _Requirements: 4.1_
  - [x]* 12.2 Write property test for order history sorting
    - **Property 6: Order History Sorting**
    - Test file: tests/LiffTelepharmacy/OrderHistoryPropertyTest.php
    - **Validates: Requirements 4.1**
  - [x] 12.3 Implement order status badges





    - Display status with color coding
    - Update badge on status change
    - _Requirements: 4.2, 4.6_
  - [x]* 12.4 Write property test for order status badge presence

    - **Property 7: Order Status Badge Presence**
    - **Validates: Requirements 4.2**






  - [x] 12.5 Implement expandable order details


    - Show items, tracking, address on tap
    - _Requirements: 4.3_

  - [x] 12.6 Implement re-order functionality



    - Add all items from order to cart
    - Navigate to checkout
    - _Requirements: 4.4, 4.5_

  - [x] 12.7 Implement delivery tracking




    - Display delivery timeline
    - Show tracking number and carrier
    - Link to carrier tracking page
    - _Requirements: 19.1, 19.2, 19.3, 19.4_

- [x] 13. Video Call System





  - [x] 13.1 Create pre-call permission check UI


    - Request camera and microphone permissions
    - Show permission status
    - Handle permission denied
    - _Requirements: 6.1, 6.6_
  - [x] 13.2 Implement WebRTC video call











    - Esta    blish peer-to-peer connection
    - Display remote and local video
    - _Requirements: 6.2, 6.3_
  - [x] 13.3 Implement call controls


    - Add Mute, Camera toggle, End Call buttons
    - Handle iOS WebRTC limitations
    - _Requirements: 6.4, 6.5, 6.8_
  - [x] 13.4 Implement call session recording


    - Record session duration
    - Save consultation notes
    - _Requirements: 6.7_

- [ ] 14. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 15. LIFF-to-Bot Message Bridge





  - [x] 15.1 Implement LIFF sendMessages integration


    - Send action messages via liff.sendMessages()
    - Handle different action types
    - _Requirements: 20.1, 20.2, 20.4, 20.5, 20.6, 20.7, 20.8_
  - [x]* 15.2 Write property test for LIFF message sending
    - **Property 18: LIFF Message Fallback**
    - Test file: tests/LiffTelepharmacy/LiffMessagePropertyTest.php
    - **Validates: Requirements 20.1, 20.10**
  - [x] 15.3 Implement API fallback for external browser


    - Detect when liff.sendMessages() unavailable
    - Send via API instead
    - _Requirements: 20.10_

  - [x] 15.4 Implement webhook handler for LIFF messages

    - Process LIFF-triggered messages
    - Reply with Flex Messages
    - _Requirements: 20.3, 20.9, 20.12_

- [x] 16. Health Profile Management










  - [x] 16.1 Create health profile page



    - Display sections for personal info, medical history, allergies, medications
    - Show completion percentage
    - _Requirements: 18.1, 18.10_


  - [x] 16.2 Implement personal info editing


    - Allow input of age, gender, weight, height, blood type
    - _Requirements: 18.2_


  - [x] 16.3 Implement medical history editing


    - Provide checkboxes for common conditions
    - _Requirements: 18.3_


  - [x] 16.4 Implement allergy management


    - Add drug allergies with autocomplete
    - Record reaction type
    - _Requirements: 18.4, 18.5_



  - [x] 16.5 Implement current medications management

    - Add medications with dosage and frequency
    - Check for interactions on add
    - _Requirements: 18.6, 18.7_
  - [ ]* 16.6 Write property test for health profile interaction check
    - **Property 17: Health Profile Interaction Check**
    - **Validates: Requirements 18.7**


- [x] 17. Wishlist and Notifications




  - [x] 17.1 Implement wishlist functionality


    - Add/remove products from wishlist
    - Display wishlist page
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5_

  - [x] 17.2 Implement notification settings page

    - Display category toggles
    - Save preferences via API
    - _Requirements: 14.1, 14.2, 14.3_
  - [x] 17.3 Implement medication reminder system


    - Create reminder schedules
    - Send LINE push notifications
    - Track adherence
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7_

- [x] 18. AI Assistant Integration





  - [x] 18.1 Create AI chat interface











    - Display chat bubbles with animations
    - Add quick symptom selection buttons
    - _Requirements: 7.1, 7.2_
  - [x] 18.2 Implement AI response handling


    - Show typing indicator
    - Display AI responses
    - Handle product recommendations
    - _Requirements: 7.3, 7.4_

  - [x] 18.3 Implement emergency symptom detection

    - Display prominent alert for red flags
    - Offer emergency contact options
    - _Requirements: 7.5_

- [x] 19. Bottom Navigation and Routing











  - [x] 19.1 Create bottom navigation component



    - Display 4-5 main sections with icons
    - Highlight active item
    - Show cart badge
    - _Requirements: 9.1, 9.2, 9.3, 9.4_
  - [x] 19.2 Implement page transitions




    - Add smooth slide/fade transitions
    - Handle safe area insets
    - _Requirements: 9.5, 9.6_

- [x] 20. Final Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 21. Loyalty Points Dashboard






  - [x] 21.1 Create points dashboard page

    - Display available points balance with animated counter
    - Show summary card with total earned, used, and expired points
    - Display tier status with progress bar to next tier
    - _Requirements: 21.1, 21.2, 21.3, 21.4_
  - [ ]* 21.2 Write property test for points data serialization round-trip
    - **Property 21: Points Data Serialization Round-Trip**
    - **Validates: Requirements 21.9, 21.10**
  - [ ]* 21.3 Write property test for points dashboard summary consistency
    - **Property 22: Points Dashboard Summary Consistency**
    - **Validates: Requirements 21.2**
  - [ ]* 21.4 Write property test for tier progress calculation
    - **Property 23: Tier Progress Calculation**
    - **Validates: Requirements 21.4**
  - [x] 21.5 Implement pending points display


    - Show pending points with expected confirmation date
    - _Requirements: 21.5_
  - [x] 21.6 Implement recent transactions section


    - Display last 5 transactions with link to full history
    - _Requirements: 21.6_
  - [ ]* 21.7 Write property test for recent transactions limit
    - **Property 24: Recent Transactions Limit**
    - **Validates: Requirements 21.6**
  - [x] 21.8 Implement points expiry warning


    - Show warning if points expire within 30 days
    - _Requirements: 21.8_
  - [ ]* 21.9 Write property test for points expiry warning display
    - **Property 25: Points Expiry Warning Display**
    - **Validates: Requirements 21.8**
  - [x] 21.10 Implement zero balance state


    - Display motivational message with "Start Shopping" CTA
    - _Requirements: 21.7_

- [ ] 22. Checkpoint - Ensure all tests pass


  - Ensure all tests pass, ask the user if questions arise.


- [x] 23. Points History & Transactions




  - [x] 23.1 Create points history page


    - Display transactions in chronological order (newest first)
    - Show transaction type icon, description, points, balance, timestamp
    - _Requirements: 22.1, 22.2_
  - [x]* 23.2 Write property test for transaction history sorting
    - **Property 26: Transaction History Sorting**
    - Test file: tests/LiffTelepharmacy/TransactionHistoryPropertyTest.php
    - **Validates: Requirements 22.1**
  - [x]* 23.3 Write property test for transaction display elements
    - **Property 27: Transaction Display Elements**
    - Test file: tests/LiffTelepharmacy/TransactionHistoryPropertyTest.php
    - **Validates: Requirements 22.2**



  - [x] 23.4 Implement transaction type styling



    - Green/plus for earned, red/minus for redeemed, gray for expired
    - _Requirements: 22.3, 22.4, 22.5_
  - [x]* 23.5 Write property test for transaction type styling
    - **Property 28: Transaction Type Styling**
    - Test file: tests/LiffTelepharmacy/TransactionHistoryPropertyTest.php
    - **Validates: Requirements 22.3, 22.4, 22.5**
  - [x] 23.6 Implement transaction filter tabs

    - Add filter tabs for All, Earned, Redeemed, Expired
    - _Requirements: 22.6_
  - [x]* 23.7 Write property test for transaction filter functionality
    - **Property 29: Transaction Filter Functionality**
    - Test file: tests/LiffTelepharmacy/TransactionHistoryPropertyTest.php
    - **Validates: Requirements 22.6**
  - [x] 23.8 Implement infinite scroll for transactions

    - Load more transactions on scroll to bottom
    - _Requirements: 22.7_
  - [x] 23.9 Implement transaction reference display

    - Show order ID or reward name when applicable
    - _Requirements: 22.8_
  - [x] 23.10 Implement empty state for no transactions

    - Display illustration and "Start Shopping" button
    - _Requirements: 22.9_
  - [x] 23.11 Implement summary totals for filtered period

    - Show totals at top of history page
    - _Requirements: 22.10_
  - [x]* 23.12 Write property test for transaction history serialization
    - **Property 30: Transaction History Serialization Round-Trip**
    - Test file: tests/LiffTelepharmacy/TransactionHistoryPropertyTest.php
    - **Validates: Requirements 22.11, 22.12**

- [ ] 24. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 25. Rewards Catalog & Redemption





  - [x] 25.1 Create rewards catalog page


    - Display available rewards in grid layout with images
    - Show reward image, name, points required, stock availability
    - _Requirements: 23.1, 23.2_
  - [x]* 25.2 Write property test for reward card required elements
    - **Property 31: Reward Card Required Elements**
    - Test file: tests/LiffTelepharmacy/RewardsRedemptionPropertyTest.php
    - **Validates: Requirements 23.2**
  - [x] 25.3 Implement reward availability display

    - Show stock count, out-of-stock badge, or grayed-out state
    - _Requirements: 23.3, 23.4, 23.5_
  - [x]* 25.4 Write property test for reward availability display
    - **Property 32: Reward Availability Display**
    - Test file: tests/LiffTelepharmacy/RewardsRedemptionPropertyTest.php
    - **Validates: Requirements 23.3, 23.4, 23.5**
  - [x] 25.5 Implement reward detail modal

    - Display full description and terms on tap
    - _Requirements: 23.6_
  - [x] 25.6 Implement redemption process


    - Deduct points and generate unique redemption code
    - _Requirements: 23.7_
  - [x]* 25.7 Write property test for redemption points deduction
    - **Property 33: Redemption Points Deduction**
    - Test file: tests/LiffTelepharmacy/RewardsRedemptionPropertyTest.php
    - **Validates: Requirements 23.7**
  - [x]* 25.8 Write property test for redemption code uniqueness
    - **Property 34: Redemption Code Uniqueness**
    - Test file: tests/LiffTelepharmacy/RewardsRedemptionPropertyTest.php
    - **Validates: Requirements 23.7**
  - [x] 25.9 Implement redemption success modal

    - Display success with redemption code and confetti animation
    - Send LINE notification with details
    - _Requirements: 23.8, 23.9_
  - [x] 25.10 Implement My Rewards tab

    - Show redeemed rewards with status (Pending/Approved/Delivered/Cancelled)
    - _Requirements: 23.10_
  - [x]* 25.11 Write property test for redemption status display
    - **Property 35: Redemption Status Display**
    - Test file: tests/LiffTelepharmacy/RewardsRedemptionPropertyTest.php
    - **Validates: Requirements 23.10**
  - [x] 25.12 Implement reward expiry handling


    - Display expiry countdown and send reminder 3 days before
    - _Requirements: 23.11_
  - [ ]* 25.13 Write property test for redemption data serialization
    - **Property 36: Redemption Data Serialization Round-Trip**
    - **Validates: Requirements 23.12, 23.13**

- [ ] 26. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.


- [x] 27. Admin Rewards Management



  - [x] 27.1 Create admin rewards list page

    - Display all rewards with status, stock, and redemption count
    - _Requirements: 24.1_

  - [x] 27.2 Implement reward creation form

    - Capture name, description, image, points, stock, validity period
    - Support reward types: Discount Coupon, Free Shipping, Physical Gift, Product Voucher
    - _Requirements: 24.2, 24.3, 24.4_

  - [x] 27.3 Implement reward editing

    - Update reward details with immediate reflection in LIFF
    - _Requirements: 24.5_

  - [x] 27.4 Implement reward disable functionality

    - Hide from catalog while preserving existing redemptions
    - _Requirements: 24.6_
  - [x] 27.5 Create redemption requests list


    - Display user info, reward, code, and status
    - _Requirements: 24.7_

  - [x] 27.6 Implement redemption approval workflow

    - Update status and send LINE notification
    - _Requirements: 24.8_

  - [x] 27.7 Implement delivery tracking for redemptions

    - Record delivery timestamp and update status
    - _Requirements: 24.9_


  - [x] 27.8 Implement redemption report export

    - Generate CSV with all redemption data for selected period
    - _Requirements: 24.10_


- [x] 28. Points Earning Rules Configuration

  - [x] 28.1 Create points settings admin page
    - Display current earning rules and multipliers
    - Admin page created at admin-points-settings.php with tabs for rules, campaigns, categories, tiers
    - _Requirements: 25.1_
  - [x] 28.2 Implement base earning rate configuration
    - Allow configuration of points per baht spent
    - Implemented in admin-points-settings.php (rules tab)
    - _Requirements: 25.2_
  - [x] 28.3 Implement bonus multiplier campaigns UI
    - Add full UI for campaigns tab in admin-points-settings.php
    - Include campaign list, create/edit forms, toggle active status
    - Backend API already exists in api/admin/points-rules.php
    - _Requirements: 25.3_
  - [x] 28.4 Implement category bonus configuration UI
    - Add full UI for categories tab in admin-points-settings.php
    - Include category bonus list, add/edit/delete functionality
    - Backend API already exists in api/admin/points-rules.php
    - _Requirements: 25.4_
  - [x] 28.5 Implement tier multiplier configuration
    - Configure earning boost per membership tier
    - Implemented in admin-points-settings.php (tiers tab)
    - _Requirements: 25.5_
  - [x] 28.6 Implement minimum order and expiry settings
    - Configure minimum order amount and expiry period
    - Implemented in admin-points-settings.php (rules tab)
    - _Requirements: 25.6, 25.7_
  - [x] 28.7 Implement tier threshold configuration
    - Configure points required for Silver, Gold, Platinum tiers
    - Implemented in admin-points-settings.php (tiers tab)
    - _Requirements: 25.8_
  - [x]* 28.8 Write property test for points earning calculation
    - **Property 37: Points Earning Calculation**
    - Test file: tests/LiffTelepharmacy/PointsCalculationPropertyTest.php
    - **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
  - [x]* 28.9 Write property test for tier threshold progression
    - **Property 38: Tier Threshold Progression**
    - Test file: tests/LiffTelepharmacy/TierProgressPropertyTest.php
    - **Validates: Requirements 25.8**
  - [ ]* 28.10 Write property test for points rules serialization
    - **Property 39: Points Rules Serialization Round-Trip**
    - **Validates: Requirements 25.11, 25.12**
  - [x] 28.11 Implement rules display for users
    - Show current active rules and bonus campaigns
    - Implemented in liff-points-rules.php with api/points-rules.php
    - _Requirements: 25.10_

- [x] 29. Final Checkpoint - Ensure all tests pass










  - Ensure all tests pass, ask the user if questions arise.
