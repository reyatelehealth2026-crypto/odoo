# Landing Page Property Tests

## Setup Requirements

1. **PHP 7.4+** must be installed
2. **Composer** must be installed

## Installation

```bash
composer install
```

## Running Tests

```bash
# Run all tests
composer test

# Or directly with PHPUnit
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/LandingPage/ShopDataDisplayPropertyTest.php
./vendor/bin/phpunit tests/LandingPage/LiffUrlCorrectnessPropertyTest.php
```

## Test Coverage

### Property 1: Shop Data Display Consistency
- **File:** `tests/LandingPage/ShopDataDisplayPropertyTest.php`
- **Validates:** Requirements 1.2
- **Property:** For any shop settings in the database, the Landing Page SHALL display the shop name and logo that match the stored values exactly.

### Property 2: LIFF URL Correctness
- **File:** `tests/LandingPage/LiffUrlCorrectnessPropertyTest.php`
- **Validates:** Requirements 2.2, 2.3
- **Property:** For any LINE Account with a valid LIFF ID, the CTA button's redirect URL SHALL contain that exact LIFF ID.

## Property-Based Testing Approach

These tests use PHPUnit data providers to generate 100+ random test cases per property, ensuring the properties hold across a wide range of inputs.
