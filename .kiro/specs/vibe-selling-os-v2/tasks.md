# Implementation Plan: Vibe Selling OS v2 (Pharmacy Edition)

## Phase 1: Database & Core Infrastructure

- [x] 1. Create database migration for v2 tables
  - [x] 1.1 Create migration file `database/migration_vibe_selling_v2.sql`
    - Create `customer_health_profiles` table
    - Create `symptom_analysis_cache` table
    - Create `drug_recognition_cache` table
    - Create `prescription_ocr_results` table
    - Create `pharmacy_ghost_learning` table
    - Create `consultation_stages` table
    - Create `pharmacy_context_keywords` table
    - Create `consultation_analytics` table
    - Add indexes for performance
    - _Requirements: 10.1, 10.4_

  - [x] 1.2 Create migration runner `install/run_vibe_selling_v2_migration.php`
    - _Requirements: 10.1_

- [x] 2. Checkpoint - Ensure migration runs successfully
  - Ensure all tests pass, ask the user if questions arise.

## Phase 2: Core Services - Pricing & Health Profile

- [x] 3. Implement DrugPricingEngineService
  - [x] 3.1 Create `classes/DrugPricingEngineService.php`
    - Implement `calculateMargin()` method
    - Implement `getMaxDiscount()` method
    - Implement `suggestAlternatives()` method
    - Implement `getCustomerLoyalty()` method
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

  - [x] 3.2 Write property test for margin calculation
    - **Property 1: Drug Margin Calculation Correctness**
    - **Validates: Requirements 3.1**

  - [x] 3.3 Write property test for max discount preserves margin
    - **Property 2: Maximum Discount Preserves Minimum Margin**
    - **Validates: Requirements 3.2**

- [x] 4. Implement CustomerHealthEngineService
  - [x] 4.1 Create `classes/CustomerHealthEngineService.php`
    - Implement `getHealthProfile()` method
    - Implement `getAllergies()` method
    - Implement `getMedications()` method
    - Implement `classifyCustomer()` method
    - Implement `getDraftStyle()` method
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [x] 4.2 Write property test for health profile classification
    - **Property 4: Health Profile Classification Completeness**
    - **Validates: Requirements 2.1**

- [x] 5. Checkpoint - Ensure pricing and health services work
  - Ensure all tests pass, ask the user if questions arise.

## Phase 3: Image Analysis Services

- [x] 6. Implement PharmacyImageAnalyzerService
  - [x] 6.1 Create `classes/PharmacyImageAnalyzerService.php`
    - Implement `analyzeSymptom()` method using Gemini Vision API
    - Implement `identifyDrug()` method
    - Implement `ocrPrescription()` method
    - Implement `checkUrgency()` method
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [x] 6.2 Write property test for urgent symptom detection
    - **Property 13: Urgent Symptom Detection**
    - **Validates: Requirements 1.5**

## Phase 4: Drug Recommendation Engine

- [x] 7. Implement DrugRecommendEngineService
  - [x] 7.1 Create `classes/DrugRecommendEngineService.php`
    - Implement `getForSymptoms()` method
    - Implement `checkInteractions()` method using existing drug_interactions table
    - Implement `getRefillReminders()` method
    - Implement `generateDrugCard()` method
    - Implement `getSafeAlternatives()` method
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6_

  - [x] 7.2 Write property test for allergy check before recommendation
    - **Property 8: Allergy Check Before Recommendation**
    - **Validates: Requirements 2.6, 7.2**

  - [x] 7.3 Write property test for drug interaction detection
    - **Property 9: Drug Interaction Detection**
    - **Validates: Requirements 1.4, 7.2**

  - [x] 7.4 Write property test for out-of-stock exclusion
    - **Property 10: Recommendations Exclude Out-of-Stock Drugs**
    - **Validates: Requirements 10.2**

- [x] 8. Checkpoint - Ensure recommendation engine works
  - Ensure all tests pass, ask the user if questions arise.

## Phase 5: Ghost Draft & Consultation Analyzer

- [x] 9. Implement PharmacyGhostDraftService
  - [x] 9.1 Create `classes/PharmacyGhostDraftService.php`
    - Implement `generateDraft()` method with pharmacy context
    - Implement `learnFromEdit()` method
    - Implement `addDisclaimer()` method for prescription drugs
    - Implement `getPredictionConfidence()` method
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6_

  - [x] 9.2 Write property test for ghost draft learning
    - **Property 11: Ghost Draft Learning Stores Edit Data**
    - **Validates: Requirements 6.5**

  - [x] 9.3 Write property test for prescription drug disclaimer
    - **Property 12: Prescription Drug Disclaimer**
    - **Validates: Requirements 6.6**

- [x] 10. Implement ConsultationAnalyzerService
  - [x] 10.1 Create `classes/ConsultationAnalyzerService.php`
    - Implement `detectStage()` method
    - Implement `getContextWidgets()` method
    - Implement `getQuickActions()` method
    - Implement `detectUrgency()` method
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

  - [x] 10.2 Write property test for symptom keyword triggers widget
    - **Property 6: Symptom Keyword Triggers Drug Widget**
    - **Validates: Requirements 4.1**

  - [x] 10.3 Write property test for drug name triggers info widget
    - **Property 7: Drug Name Triggers Info Widget**
    - **Validates: Requirements 4.2**

- [x] 11. Checkpoint - Ensure ghost draft and analyzer work
  - Ensure all tests pass, ask the user if questions arise.

## Phase 6: API Layer

- [x] 12. Create API endpoints for v2
  - [x] 12.1 Create `api/inbox-v2.php`
    - Implement POST `/analyze-symptom` endpoint
    - Implement POST `/analyze-drug` endpoint
    - Implement POST `/analyze-prescription` endpoint
    - Implement GET `/customer-health` endpoint
    - Implement POST `/ghost-draft` endpoint
    - Implement GET `/drug-info` endpoint
    - Implement POST `/check-interactions` endpoint
    - Implement GET `/recommendations` endpoint
    - Implement GET `/context-widgets` endpoint
    - Implement GET `/consultation-stage` endpoint
    - Implement GET `/quick-actions` endpoint
    - Implement GET `/analytics` endpoint
    - Implement POST `/record-analytics` endpoint
    - _Requirements: 1.1-1.6, 2.1-2.6, 3.1-3.5, 4.1-4.6, 6.1-6.6, 7.1-7.6, 8.1-8.5, 9.1-9.5_

- [x] 13. Checkpoint - Ensure API endpoints work
  - Ensure all tests pass, ask the user if questions arise.

## Phase 7: Frontend - Inbox V2 UI

- [x] 14. Create Inbox V2 main page
  - [x] 14.1 Create `inbox-v2.php` base structure
    - Copy structure from inbox.php
    - Add v2 service integrations
    - Add HUD Dashboard container
    - _Requirements: 4.1-4.6_

  - [x] 14.2 Create HUD Widget components (HTML/CSS structure)
    - Drug Info Widget
    - Interaction Checker Widget
    - Allergy Warning Widget
    - Symptom Analyzer Widget
    - Pricing Engine Widget
    - Medical History Widget
    - Customer Profile Widget
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 15. Implement Ghost Draft UI JavaScript
  - [x] 15.1 Add ghost draft JavaScript functionality
    - Implement `generateGhostDraft()` function to call API
    - Show AI draft as faded text in input field
    - Tab to accept, type to replace
    - _Requirements: 6.2, 6.3, 6.4_

  - [x] 15.2 Add draft learning feedback
    - Track edits and send to learning API via `learnFromEdit()`
    - _Requirements: 6.5_

- [x] 16. Implement Context-Aware Quick Actions
  - [x] 16.1 Add dynamic quick action buttons
    - Fetch actions from `/quick-actions` API based on consultation stage
    - Change based on consultation stage
    - Highlight urgent actions when needed
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

- [x] 17. Implement HUD Widget JavaScript Interactivity
  - [x] 17.1 Add JavaScript for dynamic widget updates
    - Implement `refreshHUD()` function
    - Implement `toggleWidget()` function
    - Auto-update widgets based on message context via `/context-widgets` API
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_

- [x] 18. Checkpoint - Ensure UI components work
  - Ensure all tests pass, ask the user if questions arise.

## Phase 8: Image Analysis UI

- [x] 19. Add image upload functionality to chat
  - [x] 19.1 Add image upload button and preview
    - Upload image → preview → send
    - _Requirements: 1.1_

- [x] 20. Add specialized image analysis buttons
  - [x] 20.1 Add symptom image analysis button
    - Upload symptom image → call `/analyze-symptom` API → show results in HUD widget
    - _Requirements: 1.1, 1.5_

  - [x] 20.2 Add drug photo recognition button
    - Upload drug photo → call `/analyze-drug` API → show drug info in HUD widget
    - _Requirements: 1.2_

  - [x] 20.3 Add prescription OCR button
    - Upload prescription → call `/analyze-prescription` API → show drug list with interaction check
    - _Requirements: 1.3, 1.4_

## Phase 9: Voice Command (Optional)

- [ ]* 21. Implement Voice Command Center
  - [ ]* 21.1 Add voice input button with Web Speech API
    - Listen for "Vibe" wake word
    - Process voice commands
    - _Requirements: 5.1, 5.4, 5.5_

  - [ ]* 21.2 Add voice response (Text-to-Speech)
    - Respond with customer history summary
    - Warn about drug interactions verbally
    - _Requirements: 5.2, 5.3_

## Phase 10: Analytics Dashboard

- [x] 22. Create consultation analytics UI
  - [x] 22.1 Add analytics tab to inbox-v2.php
    - Show consultation success rate by symptom category
    - Show response time impact
    - Show AI suggestion acceptance rate
    - Fetch data from `/analytics` API endpoint
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

- [x] 23. Final Checkpoint - Full system test
  - Ensure all tests pass, ask the user if questions arise.

## Phase 11: Integration & Polish

- [x] 24. Integrate with existing pharmacy systems
  - [x] 24.1 Connect to existing drug_interactions table
    - _Requirements: 10.5_

  - [x] 24.2 Connect to users table for medical history
    - Use existing medical_conditions, drug_allergies, current_medications fields
    - _Requirements: 10.1, 10.4_

  - [x] 24.3 Connect to business_items for drug inventory
    - _Requirements: 10.2, 10.3_

- [x] 25. Add v2 toggle in settings
  - [x] 25.1 Add setting to enable/disable v2
    - Graceful fallback to v1 when disabled
    - _Requirements: 10.6_

- [x] 26. Final Checkpoint - Complete system verification
  - Ensure all tests pass, ask the user if questions arise.
