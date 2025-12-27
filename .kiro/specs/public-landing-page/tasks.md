# Implementation Plan

## Tasks

- [x] 1. Create admin folder structure





  - [x] 1.1 Create `/admin/` folder





    - Create new admin directory
    - _Require  ments: 6.1_
  - [x] 1.2 Create admin index.php that includes original dashboard





    - Create wrapper that includes the original dashboard functionality
    - Update paths for includes and assets
    - _Requirements: 6.1, 6.3_
  - [x] 1.3 Create admin .htaccess for routing





    - Configure URL rewriting for admin routes
    - _Requirements: 6.1_
  - [x] 1.4 Update auth/login.php redirect path





    - Change redirect from `../index.php` to `../admin/`
    - _Requirements: 6.2_

- [x] 2. Create Landing Page
  - [x] 2.1 Create new index.php as Landing Page
    - Fetch shop settings from database
    - Fetch default LINE account for LIFF URL
    - Render hero section with shop branding
    - _Requirements: 1.1, 1.2, 1.3_
  - [x] 2.2 Implement services section
    - Display feature cards for Shop, Consultation, Appointments
    - Link each service to appropriate LIFF page
    - _Requirements: 1.4, 5.1_
  - [x] 2.3 Implement promotions section
    - Fetch active promotions from database
    - Conditionally display if promotions exist
    - _Requirements: 5.2_



  - [x] 2.4 Implement contact and footer section
    - Display operating hours and contact info
    - Add subtle admin login link
    - _Requirements: 3.1, 3.2, 5.3_
  - [x] 2.5 Implement CTA buttons for LIFF
    - Create prominent button to open LIFF App
    - Use correct LIFF ID from LINE account
    - Style with LINE branding on mobile
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [x] 2.6 Write property test for shop data display
    - **Property 1: Shop data display consistency**
    - **Validates: Requirements 1.2**
  - [x] 2.7 Write property test for LIFF URL correctness

    - **Property 2: LIFF URL correctness**
    - **Validates: Requirements 2.2, 2.3**







- [x] 3. Implement responsive design and themingป









  - [x] 3.1 Add responsive CSS for mobile/desktop layouts

    - Mobile-first design with breakpoints
    - Optimize for LINE in-app browser
    - _Requirements: 4.1, 4.2, 4.3_
  - [x] 3.2 Implement dynamic theme colors


    - Apply primary color from shop settings
    - Fallback to default green theme
    - _Requirements: 1.5_
  - [x] 3.3 Write property test for theme color application



    


    - **Property 3: Theme color application**
    - **Validates: Requirements 1.5**

    - [x] 3.4 Write property test for responsive layout





    - **Property 4: Responsive layout adaptation**
    - **Validates: Requirements 4.1, 4.2, 4.3**

- [x] 4. Update existing admin references






  - [x] 4.1 Update includes/header.php dashboard link

    - Change dashboard link from `/` to `/admin/`
    - _Requirements: 6.3_
  - [x] 4.2 Update any hardcoded redirects to index.php


    - Search and update redirects to use `/admin/`
    - _Requirements: 6.3_

- [x] 5. Checkpoint - Ensure all tests pass





  - Ensure all tests pass, ask the user if questions arise.

- [ ]* 5.1 Write property test for promotions display
  - **Property 5: Promotions conditional display**
  - **Validates: Requirements 5.2**

- [ ]* 5.2 Write property test for admin redirect
  - **Property 6: Admin redirect when authenticated**
  - **Validates: Requirements 6.2**

- [x] 6. Final Checkpoint - Ensure all tests pass





  - Ensure all tests pass, ask the user if questions arise.
