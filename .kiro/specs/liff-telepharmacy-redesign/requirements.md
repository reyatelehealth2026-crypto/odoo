# Requirements Document

## Introduction

ระบบ LIFF Telepharmacy Redesign เป็นการปรับปรุงและสร้างใหม่ระบบ LINE Front-end Framework (LIFF) สำหรับร้านขายยาออนไลน์ โดยเน้นประสบการณ์ผู้ใช้ที่ดีที่สุด (Best-in-class UX) สำหรับ Telepharmacy System ที่ครอบคลุม E-commerce, CRM (Membership), และ Telemedicine (Video Consultation) ผ่าน LINE Application

## Glossary

- **LIFF_App**: LINE Front-end Framework Application ที่ทำงานภายใน LINE App
- **LINE_User**: ผู้ใช้งานที่เข้าถึงระบบผ่าน LINE Official Account
- **Pharmacist**: เภสัชกรที่ให้บริการปรึกษาและอนุมัติยา
- **Product_Card**: Component แสดงข้อมูลสินค้าในรูปแบบ Card
- **Cart_Summary**: Component แสดงสรุปตะกร้าสินค้า
- **Skeleton_Loading**: UI Pattern แสดง placeholder ขณะโหลดข้อมูล
- **Member_Card**: บัตรสมาชิกดิจิทัลแสดงข้อมูลและสิทธิประโยชน์
- **Video_Call_Session**: การสนทนาผ่านวิดีโอระหว่าง LINE_User และ Pharmacist
- **Touch_Target**: พื้นที่สัมผัสบนหน้าจอ (ขั้นต่ำ 44px)
- **Infinite_Scroll**: Pattern การโหลดข้อมูลเพิ่มเมื่อเลื่อนถึงท้ายหน้า
- **One_Page_Checkout**: รูปแบบ Checkout ที่รวมทุกขั้นตอนในหน้าเดียว
- **Prescription_Product**: สินค้าประเภทยาที่ต้องมีใบสั่งแพทย์หรือการอนุมัติจากเภสัชกรก่อนจำหน่าย
- **Drug_Interaction**: ปฏิกิริยาระหว่างยาที่อาจเป็นอันตรายเมื่อใช้ร่วมกัน
- **Interaction_Severity**: ระดับความรุนแรงของปฏิกิริยาระหว่างยา (Mild, Moderate, Severe)
- **Telecare_Dashboard**: หน้าแรกแบบ Dashboard สไตล์ Telecare ที่รวมบริการทั้งหมด
- **Loyalty_Points**: คะแนนสะสมที่ได้รับจากการซื้อสินค้าและสามารถแลกเป็นรางวัลได้
- **Points_Transaction**: รายการเคลื่อนไหวของคะแนน (ได้รับ/ใช้/หมดอายุ)
- **Reward_Catalog**: รายการของรางวัลที่สามารถแลกได้ด้วยคะแนนสะสม
- **Redemption_Code**: รหัสยืนยันการแลกรางวัลที่ระบบสร้างขึ้นโดยอัตโนมัติ
- **Tier_Level**: ระดับสมาชิก (Silver/Gold/Platinum) ที่กำหนดสิทธิประโยชน์
- **Points_Multiplier**: ตัวคูณคะแนนพิเศษสำหรับแคมเปญหรือระดับสมาชิก
- **Points_Expiry**: วันหมดอายุของคะแนนสะสม

## Requirements

### Requirement 1: Unified LIFF Application Shell

**User Story:** As a LINE_User, I want a consistent and fast-loading LIFF application, so that I can navigate between features seamlessly without page reloads.

#### Acceptance Criteria

1. WHEN a LINE_User opens the LIFF_App THEN the system SHALL display a Skeleton_Loading screen within 100 milliseconds
2. WHEN the LIFF_App initializes THEN the system SHALL authenticate the LINE_User and retrieve profile data automatically
3. WHEN a LINE_User navigates between sections THEN the system SHALL use client-side routing without full page reloads
4. WHEN the LIFF_App loads THEN the system SHALL apply a consistent color palette using Medical Green (#11B0A6), Trust Blue (#3B82F6), and Clean White (#F8FAFC)
5. WHEN rendering interactive elements THEN the system SHALL ensure all Touch_Target areas have minimum dimensions of 44 pixels height
6. WHEN the LIFF_App encounters a network error THEN the system SHALL display a user-friendly error message with retry option

### Requirement 2: Shop Page - Product Catalog

**User Story:** As a LINE_User, I want to browse pharmacy products easily on mobile, so that I can find and purchase medicines quickly.

#### Acceptance Criteria

1. WHEN a LINE_User opens the Shop page THEN the system SHALL display products in a 2-column grid layout optimized for mobile viewing
2. WHEN the Shop page loads THEN the system SHALL display a sticky search bar and category filter at the top of the viewport
3. WHEN a LINE_User views a Product_Card THEN the system SHALL display product image, name, price (highlighted), and "Add to Cart" button
4. WHEN a LINE_User taps "Add to Cart" THEN the system SHALL add the product via AJAX without page reload and show visual feedback
5. WHEN products are added to cart THEN the system SHALL display a floating Cart_Summary bar showing item count and total price
6. WHEN a LINE_User scrolls to the bottom of the product list THEN the system SHALL load additional products using Infinite_Scroll pattern
7. WHEN a LINE_User searches for a product THEN the system SHALL filter results in real-time as the user types
8. WHEN displaying product prices THEN the system SHALL show sale prices in red with original price struck through
9. WHEN serializing cart data for storage THEN the system SHALL encode using JSON format
10. WHEN retrieving cart data from storage THEN the system SHALL decode JSON and reconstruct the cart object

### Requirement 3: Checkout Page - One Page Flow

**User Story:** As a LINE_User, I want to complete my purchase quickly with minimal steps, so that I can receive my medicines without friction.

#### Acceptance Criteria

1. WHEN a LINE_User opens the Checkout page THEN the system SHALL display all checkout steps in a One_Page_Checkout format
2. WHEN the Checkout page loads THEN the system SHALL auto-fill customer name and phone from LINE Profile if available
3. WHEN a LINE_User enters delivery address THEN the system SHALL offer options to use saved address or pin location on map
4. WHEN a LINE_User selects payment method THEN the system SHALL display radio options for Transfer, Credit Card, and QR PromptPay
5. WHEN a LINE_User enters form data THEN the system SHALL validate inputs in real-time and display inline error messages
6. IF any required field is empty or invalid THEN the system SHALL disable the "Place Order" button and highlight the error
7. WHEN all form fields are valid THEN the system SHALL enable and prominently display the "Place Order" button
8. WHEN a LINE_User taps "Place Order" THEN the system SHALL show a loading state and prevent duplicate submissions
9. WHEN an order is successfully placed THEN the system SHALL display confirmation with order number and send LINE notification

### Requirement 4: Order History Page

**User Story:** As a LINE_User, I want to view my past orders and easily reorder medicines, so that I can manage my recurring prescriptions efficiently.

#### Acceptance Criteria

1. WHEN a LINE_User opens Order History THEN the system SHALL display orders in a timeline or card list view sorted by date descending
2. WHEN displaying an order THEN the system SHALL show status badge indicating Pending, Packing, Shipping, or Completed
3. WHEN a LINE_User taps an order card THEN the system SHALL expand the card to show order details including items and tracking
4. WHEN viewing a completed order THEN the system SHALL display a "Re-order" button for quick reordering
5. WHEN a LINE_User taps "Re-order" THEN the system SHALL add all items from that order to cart and navigate to Checkout
6. WHEN order status changes THEN the system SHALL update the status badge color accordingly (Yellow for Pending, Blue for Packing, Purple for Shipping, Green for Completed)
7. IF no orders exist THEN the system SHALL display an empty state with illustration and "Start Shopping" button

### Requirement 5: Member Card - Digital Loyalty

**User Story:** As a LINE_User, I want to view my membership status and points, so that I can track my rewards and benefits.

#### Acceptance Criteria

1. WHEN a LINE_User opens Member_Card THEN the system SHALL display a premium-looking digital card with CSS gradients
2. WHEN displaying Member_Card THEN the system SHALL show member name, Member ID, tier (Silver/Gold/Platinum), and points balance prominently
3. WHEN displaying Member_Card THEN the system SHALL generate and display a QR Code for pharmacist scanning at POS
4. WHEN displaying tier progress THEN the system SHALL show a progress bar indicating points needed for next tier upgrade
5. WHEN a LINE_User is not registered THEN the system SHALL display a registration prompt with benefits explanation
6. WHEN points balance changes THEN the system SHALL animate the points counter update
7. WHEN displaying tier badge THEN the system SHALL use gradient colors matching tier level (Silver: gray gradient, Gold: amber gradient, Platinum: dark gradient)

### Requirement 6: Video Call - Tele-consultation

**User Story:** As a LINE_User, I want to consult with a pharmacist via video call, so that I can get professional advice for my health concerns.

#### Acceptance Criteria

1. WHEN a LINE_User initiates video consultation THEN the system SHALL display a pre-call check UI for camera and microphone permissions
2. WHEN permissions are granted THEN the system SHALL establish WebRTC connection using peer-to-peer signaling
3. WHILE a Video_Call_Session is active THEN the system SHALL display a large video area with remote video prominent
4. WHILE a Video_Call_Session is active THEN the system SHALL display Mute, Camera toggle, and End Call buttons with clear icons
5. WHEN a LINE_User taps "End Call" THEN the system SHALL display a red confirmation button and terminate the session
6. IF camera or microphone permission is denied THEN the system SHALL display instructions to enable permissions in device settings
7. WHEN video call ends THEN the system SHALL record session duration and display consultation summary
8. IF running on iOS THEN the system SHALL handle WebRTC limitations by offering external browser option when necessary

### Requirement 7: AI Pharmacy Assistant Integration

**User Story:** As a LINE_User, I want to get AI-powered health advice, so that I can receive quick guidance before consulting a pharmacist.

#### Acceptance Criteria

1. WHEN a LINE_User opens AI Assistant THEN the system SHALL display a chat interface with quick symptom selection buttons
2. WHEN a LINE_User selects a symptom THEN the system SHALL send the symptom to AI and display typing indicator
3. WHEN AI responds THEN the system SHALL display the response in a chat bubble with smooth animation
4. WHEN AI recommends products THEN the system SHALL display product cards with "Add to Cart" functionality
5. WHEN AI detects emergency symptoms THEN the system SHALL display a prominent alert with emergency contact options
6. WHEN a LINE_User requests pharmacist consultation THEN the system SHALL offer to initiate Video_Call_Session

### Requirement 8: Performance and Loading States

**User Story:** As a LINE_User, I want the app to feel fast and responsive, so that I have a smooth experience even on slow connections.

#### Acceptance Criteria

1. WHEN any page loads data THEN the system SHALL display Skeleton_Loading placeholders matching the expected content layout
2. WHEN an API call takes longer than 300 milliseconds THEN the system SHALL display a loading indicator
3. WHEN data loads successfully THEN the system SHALL animate the transition from skeleton to actual content
4. WHEN a button is tapped THEN the system SHALL provide immediate visual feedback within 100 milliseconds
5. WHEN images load THEN the system SHALL use lazy loading and display placeholder until loaded
6. WHEN the app is offline THEN the system SHALL display cached data if available with offline indicator

### Requirement 9: Navigation and Bottom Tab Bar

**User Story:** As a LINE_User, I want easy navigation between main features, so that I can access any section with one tap.

#### Acceptance Criteria

1. WHEN the LIFF_App is open THEN the system SHALL display a fixed bottom navigation bar with 4-5 main sections
2. WHEN a LINE_User taps a navigation item THEN the system SHALL highlight the active item and navigate to that section
3. WHEN displaying navigation items THEN the system SHALL show icon and label for each item
4. WHEN cart has items THEN the system SHALL display a badge on the cart navigation icon showing item count
5. WHEN navigating between sections THEN the system SHALL use smooth page transitions (slide or fade)
6. WHEN the bottom navigation is displayed THEN the system SHALL account for iOS safe area insets

### Requirement 10: Appointment Booking

**User Story:** As a LINE_User, I want to book appointments with pharmacists, so that I can schedule consultations at convenient times.

#### Acceptance Criteria

1. WHEN a LINE_User opens Appointments THEN the system SHALL display available pharmacists with their schedules
2. WHEN a LINE_User selects a pharmacist THEN the system SHALL show available time slots for the next 7 days
3. WHEN a LINE_User selects a time slot THEN the system SHALL confirm the booking and add to calendar
4. WHEN an appointment is booked THEN the system SHALL send LINE notification reminder 30 minutes before
5. WHEN viewing My Appointments THEN the system SHALL display upcoming and past appointments in chronological order
6. WHEN an appointment time arrives THEN the system SHALL display a "Join Video Call" button

### Requirement 11: Prescription Drug Flow

**User Story:** As a LINE_User, I want to purchase prescription medicines safely, so that I receive proper pharmacist verification before dispensing.

#### Acceptance Criteria

1. WHEN displaying a prescription-required product THEN the system SHALL show a "Rx" badge and "Requires Pharmacist Approval" label
2. WHEN a LINE_User adds a prescription product to cart THEN the system SHALL display a modal explaining the approval process
3. WHEN a LINE_User proceeds to checkout with prescription items THEN the system SHALL require pharmacist consultation before payment
4. WHEN prescription items are in cart THEN the system SHALL display a "Consult Pharmacist" button prominently
5. WHEN a LINE_User initiates prescription consultation THEN the system SHALL create a Video_Call_Session with patient information pre-loaded
6. WHILE a Pharmacist reviews prescription items THEN the system SHALL display patient medical history and current medications
7. WHEN a Pharmacist approves prescription items THEN the system SHALL unlock the checkout process and record approval timestamp
8. IF a Pharmacist rejects prescription items THEN the system SHALL notify the LINE_User with rejection reason and remove items from cart
9. WHEN prescription approval is granted THEN the system SHALL set an expiry time of 24 hours for the approval
10. IF prescription approval expires THEN the system SHALL require re-consultation before checkout

### Requirement 12: Drug Interaction Warning System

**User Story:** As a LINE_User, I want to be warned about potential drug interactions, so that I can avoid harmful medication combinations.

#### Acceptance Criteria

1. WHEN a LINE_User adds a product to cart THEN the system SHALL check for interactions with existing cart items and user medication history
2. IF a drug interaction is detected THEN the system SHALL display a warning modal with severity level (Mild, Moderate, Severe)
3. WHEN displaying interaction warning THEN the system SHALL show the interacting drugs, interaction type, and recommended action
4. IF interaction severity is Severe THEN the system SHALL block the addition and require pharmacist consultation
5. IF interaction severity is Moderate THEN the system SHALL allow addition with user acknowledgment checkbox
6. IF interaction severity is Mild THEN the system SHALL display informational notice without blocking
7. WHEN a LINE_User has saved medication history THEN the system SHALL check new cart items against saved medications
8. WHEN displaying drug interaction THEN the system SHALL use color coding (Red for Severe, Orange for Moderate, Yellow for Mild)
9. WHEN a LINE_User proceeds to checkout with interaction warnings THEN the system SHALL display summary of all acknowledged interactions
10. WHEN a Pharmacist reviews an order THEN the system SHALL highlight any drug interactions for verification

### Requirement 13: Telecare-Style Home Dashboard

**User Story:** As a LINE_User, I want a beautiful home dashboard like Telecare app, so that I can access all services from one place with a premium feel.

#### Acceptance Criteria

1. WHEN a LINE_User opens the LIFF_App THEN the system SHALL display a Telecare-style home dashboard with member card at top
2. WHEN displaying the home dashboard THEN the system SHALL show a premium member card with gradient background and mascot illustration
3. WHEN displaying member card THEN the system SHALL show company name, member name, ID, expiry date, points, and tier badge
4. WHEN displaying the home dashboard THEN the system SHALL show a 3x2 service grid with icons (Shop, Cart, Orders, AI Assistant, Symptom Check, Redeem Points)
5. WHEN displaying service icons THEN the system SHALL use rounded square icon boxes with subtle shadows and category-specific colors
6. WHEN displaying the home dashboard THEN the system SHALL show an AI Assistant quick action section with gradient background
7. WHEN displaying AI quick actions THEN the system SHALL show common symptom buttons (Headache, Cold, Stomachache, Allergy)
8. WHEN displaying the home dashboard THEN the system SHALL show available pharmacists section with doctor cards
9. WHEN displaying pharmacist cards THEN the system SHALL show photo, name, specialty, rating, and "Book" button
10. WHEN the home dashboard loads THEN the system SHALL use skeleton loading for member card and pharmacist list



### Requirement 14: Push Notification Settings

**User Story:** As a LINE_User, I want to manage my notification preferences, so that I receive only relevant updates without being overwhelmed.

#### Acceptance Criteria

1. WHEN a LINE_User opens Notification Settings THEN the system SHALL display categorized notification toggles
2. WHEN displaying notification categories THEN the system SHALL show Order Updates, Promotions, Appointment Reminders, Drug Reminders, and Health Tips
3. WHEN a LINE_User toggles a notification category THEN the system SHALL save the preference immediately via API
4. WHEN a LINE_User enables Order Updates THEN the system SHALL send notifications for order confirmation, shipping, and delivery
5. WHEN a LINE_User enables Appointment Reminders THEN the system SHALL send notifications 24 hours and 30 minutes before appointments
6. WHEN a LINE_User enables Drug Reminders THEN the system SHALL allow setting custom reminder times for medication schedules
7. WHEN setting Drug Reminders THEN the system SHALL display a time picker and frequency selector (Daily, Twice daily, Custom)
8. WHEN a LINE_User disables all notifications THEN the system SHALL show a confirmation dialog explaining potential missed updates
9. WHEN displaying notification settings THEN the system SHALL show the current status of each category with toggle switches
10. WHEN notification preferences are saved THEN the system SHALL display a success toast message

### Requirement 15: Medication Reminder System

**User Story:** As a LINE_User with chronic conditions, I want to set medication reminders, so that I never miss taking my prescribed medicines.

#### Acceptance Criteria

1. WHEN a LINE_User opens Medication Reminders THEN the system SHALL display a list of active medication schedules
2. WHEN adding a new medication reminder THEN the system SHALL allow selection from order history or manual entry
3. WHEN setting reminder details THEN the system SHALL capture medication name, dosage, frequency, and reminder times
4. WHEN a reminder time arrives THEN the system SHALL send a LINE push notification with medication details
5. WHEN a LINE_User receives a reminder THEN the system SHALL include a "Mark as Taken" action button
6. WHEN a LINE_User marks medication as taken THEN the system SHALL record the timestamp and update adherence tracking
7. WHEN viewing medication history THEN the system SHALL display adherence percentage and missed doses
8. WHEN a LINE_User misses multiple doses THEN the system SHALL offer to connect with a pharmacist for consultation
9. WHEN displaying medication reminders THEN the system SHALL show pill icons with color coding by medication type
10. WHEN a medication supply is running low THEN the system SHALL suggest reordering with one-tap "Re-order" button


### Requirement 16: Wishlist and Favorites

**User Story:** As a LINE_User, I want to save products to a wishlist, so that I can easily find and purchase them later.

#### Acceptance Criteria

1. WHEN viewing a product THEN the system SHALL display a heart icon button to add/remove from wishlist
2. WHEN a LINE_User taps the heart icon THEN the system SHALL toggle wishlist status with animation and save via API
3. WHEN a product is in wishlist THEN the system SHALL display a filled heart icon in red color
4. WHEN a LINE_User opens Wishlist page THEN the system SHALL display all saved products in a grid layout
5. WHEN displaying wishlist items THEN the system SHALL show product image, name, price, and "Add to Cart" button
6. WHEN a wishlist product goes on sale THEN the system SHALL send a LINE notification to the user
7. WHEN a wishlist product is back in stock THEN the system SHALL send a LINE notification to the user
8. WHEN a LINE_User removes item from wishlist THEN the system SHALL show undo option for 5 seconds
9. IF wishlist is empty THEN the system SHALL display an empty state with "Browse Products" button
10. WHEN displaying wishlist THEN the system SHALL show total item count in the page header


### Requirement 17: Coupon and Promo Code System

**User Story:** As a LINE_User, I want to apply discount coupons and promo codes, so that I can save money on my purchases.

#### Acceptance Criteria

1. WHEN a LINE_User opens My Coupons page THEN the system SHALL display available coupons categorized by type (Discount, Free Shipping, Points Bonus)
2. WHEN displaying a coupon THEN the system SHALL show discount value, minimum order amount, expiry date, and applicable categories
3. WHEN a coupon is near expiry (within 3 days) THEN the system SHALL highlight it with "Expiring Soon" badge
4. WHEN a LINE_User proceeds to checkout THEN the system SHALL display a promo code input field
5. WHEN a LINE_User enters a promo code THEN the system SHALL validate the code via API and display result within 500 milliseconds
6. IF promo code is valid THEN the system SHALL apply the discount and show the savings amount in green
7. IF promo code is invalid or expired THEN the system SHALL display an error message explaining the reason
8. WHEN displaying checkout summary THEN the system SHALL show applied coupon with option to remove
9. WHEN a LINE_User has applicable coupons THEN the system SHALL suggest the best coupon automatically at checkout
10. WHEN a new coupon is received THEN the system SHALL send a LINE notification with coupon details and "Use Now" button
11. WHEN displaying coupon THEN the system SHALL show a "Copy Code" button for manual entry
12. IF coupon has usage limit THEN the system SHALL display remaining uses (e.g., "2/3 uses remaining")


### Requirement 18: Health Profile Management

**User Story:** As a LINE_User, I want to manage my health profile, so that pharmacists can provide personalized advice and the system can check for drug interactions.

#### Acceptance Criteria

1. WHEN a LINE_User opens Health Profile THEN the system SHALL display sections for Personal Info, Medical History, Allergies, and Current Medications
2. WHEN editing Personal Info THEN the system SHALL allow input of age, gender, weight, height, and blood type
3. WHEN editing Medical History THEN the system SHALL provide checkboxes for common conditions (Diabetes, Hypertension, Heart Disease, Kidney Disease, Liver Disease, Pregnancy)
4. WHEN editing Allergies THEN the system SHALL allow adding drug allergies with autocomplete from drug database
5. WHEN adding an allergy THEN the system SHALL record the reaction type (Rash, Breathing difficulty, Swelling, Other)
6. WHEN editing Current Medications THEN the system SHALL allow adding medications with dosage and frequency
7. WHEN adding current medication THEN the system SHALL check for interactions with existing medications and display warnings
8. WHEN a LINE_User saves Health Profile THEN the system SHALL encrypt sensitive data before storage
9. WHEN a Pharmacist views patient during consultation THEN the system SHALL display the Health Profile summary
10. WHEN Health Profile is incomplete THEN the system SHALL show a completion percentage and prompt to complete
11. WHEN a LINE_User adds a new allergy THEN the system SHALL flag any cart items containing that allergen
12. WHEN displaying Health Profile THEN the system SHALL show last updated date and "Edit" buttons for each section


### Requirement 19: Delivery Tracking

**User Story:** As a LINE_User, I want to track my order delivery in real-time, so that I know exactly when my medicines will arrive.

#### Acceptance Criteria

1. WHEN an order is shipped THEN the system SHALL send a LINE notification with tracking number and carrier name
2. WHEN a LINE_User opens order details THEN the system SHALL display a visual timeline of delivery status
3. WHEN displaying delivery timeline THEN the system SHALL show stages: Order Placed, Confirmed, Packing, Shipped, Out for Delivery, Delivered
4. WHEN an order has tracking number THEN the system SHALL display a "Track Package" button linking to carrier tracking page
5. WHEN delivery status updates THEN the system SHALL send a LINE push notification with the new status
6. WHEN order is "Out for Delivery" THEN the system SHALL display estimated delivery time window
7. WHEN displaying tracking THEN the system SHALL show delivery address with option to view on map
8. IF delivery is delayed THEN the system SHALL notify the LINE_User with new estimated delivery date
9. WHEN order is delivered THEN the system SHALL prompt for delivery confirmation and optional feedback
10. WHEN displaying tracking timeline THEN the system SHALL show timestamps for each completed stage
11. WHEN a LINE_User has multiple active orders THEN the system SHALL display a summary card for each with current status
12. WHEN tracking information is unavailable THEN the system SHALL display "Tracking will be available soon" message


### Requirement 20: LIFF-to-Bot Message Bridge

**User Story:** As a LINE_User, I want my LIFF actions to trigger bot responses, so that I receive immediate confirmations and the bot can reply with rich messages using reply tokens.

#### Acceptance Criteria

1. WHEN a LINE_User completes an action in LIFF THEN the system SHALL send a message to the LINE OA bot via liff.sendMessages()
2. WHEN sending LIFF message THEN the system SHALL include action type and relevant data in a structured format
3. WHEN the bot receives a LIFF-triggered message THEN the system SHALL process it and reply using the reply token
4. WHEN a LINE_User places an order THEN the system SHALL send "สั่งซื้อสำเร็จ #ORDER_ID" message to trigger bot confirmation
5. WHEN a LINE_User books an appointment THEN the system SHALL send "นัดหมายสำเร็จ" message to trigger bot calendar card
6. WHEN a LINE_User requests pharmacist consultation THEN the system SHALL send "ขอปรึกษาเภสัชกร" message to trigger bot queue notification
7. WHEN a LINE_User redeems points THEN the system SHALL send "แลกแต้มสำเร็จ" message to trigger bot reward confirmation
8. WHEN a LINE_User updates health profile THEN the system SHALL send "อัพเดทข้อมูลสุขภาพ" message to trigger bot acknowledgment
9. WHEN the bot replies to LIFF action THEN the system SHALL use Flex Message for rich visual confirmation
10. WHEN liff.sendMessages() is not available (external browser) THEN the system SHALL fallback to API-based notification
11. WHEN sending LIFF message THEN the system SHALL show a brief loading state and success feedback in LIFF UI
12. WHEN bot receives order message THEN the system SHALL reply with order summary Flex Message including items, total, and tracking link


### Requirement 21: Loyalty Points Dashboard

**User Story:** As a LINE_User, I want to view my loyalty points balance and earning summary, so that I can track my rewards progress and plan my redemptions.

#### Acceptance Criteria

1. WHEN a LINE_User opens Points Dashboard THEN the system SHALL display current available points balance prominently with animated counter
2. WHEN displaying Points Dashboard THEN the system SHALL show total earned points, used points, and expired points in a summary card
3. WHEN displaying Points Dashboard THEN the system SHALL show current tier status (Silver/Gold/Platinum) with progress bar to next tier
4. WHEN displaying tier progress THEN the system SHALL calculate and display points needed for next tier upgrade
5. WHEN a LINE_User has pending points THEN the system SHALL display pending points with expected confirmation date
6. WHEN displaying Points Dashboard THEN the system SHALL show recent transactions (last 5) with quick link to full history
7. WHEN points balance is zero THEN the system SHALL display motivational message with "Start Shopping" call-to-action
8. WHEN displaying Points Dashboard THEN the system SHALL show points expiry warning if points expire within 30 days
9. WHEN serializing points data for display THEN the system SHALL encode using JSON format
10. WHEN retrieving points data from API THEN the system SHALL decode JSON and reconstruct the points object


### Requirement 22: Points History & Transactions

**User Story:** As a LINE_User, I want to view my complete points transaction history, so that I can track how I earned and spent my loyalty points.

#### Acceptance Criteria

1. WHEN a LINE_User opens Points History THEN the system SHALL display transactions in chronological order (newest first)
2. WHEN displaying a transaction THEN the system SHALL show transaction type icon, description, points amount, balance after, and timestamp
3. WHEN displaying earn transactions THEN the system SHALL show green plus icon with positive points in green color
4. WHEN displaying redeem transactions THEN the system SHALL show red minus icon with negative points in red color
5. WHEN displaying expired transactions THEN the system SHALL show gray icon with "หมดอายุ" label
6. WHEN a LINE_User filters transactions THEN the system SHALL provide filter tabs for All, Earned, Redeemed, and Expired
7. WHEN a LINE_User scrolls to bottom THEN the system SHALL load more transactions using Infinite_Scroll pattern
8. WHEN displaying transaction details THEN the system SHALL show reference order ID or reward name when applicable
9. IF no transactions exist THEN the system SHALL display empty state with illustration and "Start Shopping" button
10. WHEN displaying Points History THEN the system SHALL show summary totals for filtered period at top
11. WHEN serializing transaction history for storage THEN the system SHALL encode using JSON format
12. WHEN retrieving transaction history from API THEN the system SHALL decode JSON and reconstruct transaction objects


### Requirement 23: Rewards Catalog & Redemption

**User Story:** As a LINE_User, I want to browse and redeem rewards using my loyalty points, so that I can get valuable benefits from my purchases.

#### Acceptance Criteria

1. WHEN a LINE_User opens Rewards Catalog THEN the system SHALL display available rewards in a grid layout with images
2. WHEN displaying a reward card THEN the system SHALL show reward image, name, points required, and stock availability
3. WHEN a reward has limited stock THEN the system SHALL display remaining quantity with "เหลือ X ชิ้น" label
4. WHEN a reward is out of stock THEN the system SHALL display "หมดแล้ว" badge and disable redemption
5. WHEN a LINE_User has insufficient points THEN the system SHALL gray out unredeemable rewards and show points needed
6. WHEN a LINE_User taps a reward THEN the system SHALL display reward detail modal with full description and terms
7. WHEN a LINE_User confirms redemption THEN the system SHALL deduct points and generate unique redemption code
8. WHEN redemption is successful THEN the system SHALL display success modal with redemption code and confetti animation
9. WHEN redemption is successful THEN the system SHALL send LINE notification with redemption details and QR code
10. WHEN displaying My Rewards tab THEN the system SHALL show all redeemed rewards with status (Pending/Approved/Delivered/Cancelled)
11. WHEN a reward has expiry date THEN the system SHALL display expiry countdown and send reminder 3 days before
12. WHEN serializing redemption data THEN the system SHALL encode using JSON format
13. WHEN retrieving redemption data from API THEN the system SHALL decode JSON and reconstruct redemption object


### Requirement 24: Admin Rewards Management

**User Story:** As an Admin, I want to manage rewards catalog and redemption settings, so that I can control the loyalty program offerings.

#### Acceptance Criteria

1. WHEN an Admin opens Rewards Management THEN the system SHALL display list of all rewards with status, stock, and redemption count
2. WHEN an Admin creates a new reward THEN the system SHALL capture name, description, image, points required, stock quantity, and validity period
3. WHEN an Admin sets reward type THEN the system SHALL provide options for Discount Coupon, Free Shipping, Physical Gift, and Product Voucher
4. WHEN an Admin sets stock quantity THEN the system SHALL allow unlimited (-1) or specific quantity with auto-disable when depleted
5. WHEN an Admin edits a reward THEN the system SHALL update reward details and reflect changes immediately in LIFF
6. WHEN an Admin disables a reward THEN the system SHALL hide reward from catalog but preserve existing redemptions
7. WHEN an Admin views redemption requests THEN the system SHALL display list with user info, reward, code, and status
8. WHEN an Admin approves a redemption THEN the system SHALL update status and send LINE notification to user
9. WHEN an Admin marks redemption as delivered THEN the system SHALL record delivery timestamp and update status
10. WHEN an Admin exports redemption report THEN the system SHALL generate CSV with all redemption data for selected period
11. WHEN serializing reward configuration THEN the system SHALL encode using JSON format
12. WHEN retrieving reward configuration from database THEN the system SHALL decode JSON and reconstruct reward settings


### Requirement 25: Points Earning Rules Configuration

**User Story:** As an Admin, I want to configure points earning rules, so that I can customize how customers earn loyalty points.

#### Acceptance Criteria

1. WHEN an Admin opens Points Settings THEN the system SHALL display current earning rules and multipliers
2. WHEN an Admin sets base earning rate THEN the system SHALL allow configuration of points per baht spent (e.g., 1 point per 25 baht)
3. WHEN an Admin creates bonus multiplier THEN the system SHALL allow time-limited campaigns (e.g., 2x points weekend)
4. WHEN an Admin sets category bonus THEN the system SHALL allow different earning rates per product category
5. WHEN an Admin sets tier multiplier THEN the system SHALL configure earning boost per membership tier (e.g., Gold = 1.5x)
6. WHEN an Admin sets minimum order THEN the system SHALL configure minimum order amount to earn points
7. WHEN an Admin sets points expiry THEN the system SHALL configure expiry period in months (e.g., 12 months from earn date)
8. WHEN an Admin sets tier thresholds THEN the system SHALL configure points required for Silver, Gold, and Platinum tiers
9. WHEN points rules change THEN the system SHALL apply new rules to future transactions only
10. WHEN displaying earning rules to user THEN the system SHALL show current active rules and any bonus campaigns
11. WHEN serializing points rules configuration THEN the system SHALL encode using JSON format
12. WHEN retrieving points rules from database THEN the system SHALL decode JSON and reconstruct rules object
