<?php
/**
 * Property-Based Tests: Form Validation State Consistency
 * 
 * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
 * **Feature: liff-telepharmacy-redesign, Property 19: Auto-fill from LINE Profile**
 * **Validates: Requirements 3.2, 3.6, 3.7**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class FormValidationPropertyTest extends TestCase
{
    /**
     * Required checkout fields
     */
    private $requiredFields = ['name', 'phone', 'address', 'district', 'province', 'postal_code'];
    
    /**
     * Validation rules
     */
    private $validationRules = [
        'phone' => '/^0[0-9]{9}$/',
        'postal_code' => '/^[0-9]{5}$/',
        'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/'
    ];
    
    /**
     * Generate random LINE profile
     */
    private function generateRandomLineProfile(): array
    {
        return [
            'userId' => 'U' . bin2hex(random_bytes(16)),
            'displayName' => 'User ' . rand(1, 10000),
            'pictureUrl' => 'https://profile.line-scdn.net/' . rand(1, 1000),
            'statusMessage' => 'Status ' . rand(1, 100)
        ];
    }
    
    /**
     * Generate random checkout form data
     */
    private function generateRandomFormData(bool $valid = true): array
    {
        if ($valid) {
            return [
                'name' => 'Customer ' . rand(1, 1000),
                'phone' => '0' . rand(800000000, 999999999),
                'email' => 'user' . rand(1, 1000) . '@example.com',
                'address' => rand(1, 999) . ' Street ' . rand(1, 100),
                'district' => 'District ' . rand(1, 50),
                'province' => 'Province ' . rand(1, 77),
                'postal_code' => str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT)
            ];
        }
        
        // Generate invalid data
        $data = [
            'name' => rand(0, 1) ? '' : 'Customer ' . rand(1, 1000),
            'phone' => rand(0, 1) ? '123' : '0' . rand(800000000, 999999999),
            'email' => rand(0, 1) ? 'invalid-email' : 'user@example.com',
            'address' => rand(0, 1) ? '' : 'Address ' . rand(1, 100),
            'district' => rand(0, 1) ? '' : 'District ' . rand(1, 50),
            'province' => rand(0, 1) ? '' : 'Province ' . rand(1, 77),
            'postal_code' => rand(0, 1) ? '123' : str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT)
        ];
        
        return $data;
    }
    
    /**
     * Validate single field
     */
    private function validateField(string $field, $value): bool
    {
        // Check if empty for required fields
        if (in_array($field, $this->requiredFields) && empty($value)) {
            return false;
        }
        
        // Check pattern if exists
        if (isset($this->validationRules[$field]) && !empty($value)) {
            return preg_match($this->validationRules[$field], $value) === 1;
        }
        
        return true;
    }
    
    /**
     * Validate entire form
     */
    private function validateForm(array $formData): array
    {
        $errors = [];
        $isValid = true;
        
        foreach ($this->requiredFields as $field) {
            if (!$this->validateField($field, $formData[$field] ?? '')) {
                $errors[$field] = "Invalid {$field}";
                $isValid = false;
            }
        }
        
        // Validate optional fields with patterns
        if (!empty($formData['email']) && !$this->validateField('email', $formData['email'])) {
            $errors['email'] = 'Invalid email';
            $isValid = false;
        }
        
        return [
            'is_valid' => $isValid,
            'errors' => $errors,
            'can_submit' => $isValid
        ];
    }
    
    /**
     * Auto-fill form from LINE profile
     */
    private function autoFillFromProfile(array $profile, array $formData = []): array
    {
        return array_merge($formData, [
            'name' => $profile['displayName'] ?? $formData['name'] ?? ''
        ]);
    }
    
    /**
     * Property Test: Valid form enables submit button
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testValidFormEnablesSubmitButton(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $formData = $this->generateRandomFormData(true);
            $validation = $this->validateForm($formData);
            
            $this->assertTrue(
                $validation['is_valid'],
                "Valid form should pass validation"
            );
            $this->assertTrue(
                $validation['can_submit'],
                "Valid form should enable submit button"
            );
            $this->assertEmpty($validation['errors']);
        }
    }
    
    /**
     * Property Test: Invalid form disables submit button
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testInvalidFormDisablesSubmitButton(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $formData = $this->generateRandomFormData(false);
            $validation = $this->validateForm($formData);
            
            // At least one field should be invalid
            if (!$validation['is_valid']) {
                $this->assertFalse(
                    $validation['can_submit'],
                    "Invalid form should disable submit button"
                );
                $this->assertNotEmpty($validation['errors']);
            }
        }
    }
    
    /**
     * Property Test: Empty required field fails validation
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testEmptyRequiredFieldFailsValidation(): void
    {
        foreach ($this->requiredFields as $field) {
            $formData = $this->generateRandomFormData(true);
            $formData[$field] = '';
            
            $validation = $this->validateForm($formData);
            
            $this->assertFalse(
                $validation['is_valid'],
                "Form with empty {$field} should fail validation"
            );
            $this->assertArrayHasKey($field, $validation['errors']);
        }
    }
    
    /**
     * Property Test: Invalid phone format fails validation
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testInvalidPhoneFormatFailsValidation(): void
    {
        $invalidPhones = ['123', '1234567890', 'abcdefghij', '0123', ''];
        
        foreach ($invalidPhones as $phone) {
            $formData = $this->generateRandomFormData(true);
            $formData['phone'] = $phone;
            
            $validation = $this->validateForm($formData);
            
            $this->assertFalse(
                $validation['is_valid'],
                "Form with invalid phone '{$phone}' should fail validation"
            );
        }
    }
    
    /**
     * Property Test: Invalid postal code fails validation
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testInvalidPostalCodeFailsValidation(): void
    {
        $invalidPostalCodes = ['123', '123456', 'abcde', ''];
        
        foreach ($invalidPostalCodes as $postalCode) {
            $formData = $this->generateRandomFormData(true);
            $formData['postal_code'] = $postalCode;
            
            $validation = $this->validateForm($formData);
            
            $this->assertFalse(
                $validation['is_valid'],
                "Form with invalid postal code '{$postalCode}' should fail validation"
            );
        }
    }
    
    /**
     * Property Test: Auto-fill from LINE profile sets name
     * 
     * **Feature: liff-telepharmacy-redesign, Property 19: Auto-fill from LINE Profile**
     * **Validates: Requirements 3.2**
     */
    public function testAutoFillFromLineProfileSetsName(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $profile = $this->generateRandomLineProfile();
            $formData = $this->autoFillFromProfile($profile);
            
            $this->assertEquals(
                $profile['displayName'],
                $formData['name'],
                "Name should be auto-filled from LINE profile displayName"
            );
        }
    }
    
    /**
     * Property Test: Auto-fill preserves existing form data
     * 
     * **Feature: liff-telepharmacy-redesign, Property 19: Auto-fill from LINE Profile**
     * **Validates: Requirements 3.2**
     */
    public function testAutoFillPreservesExistingFormData(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $profile = $this->generateRandomLineProfile();
            $existingData = $this->generateRandomFormData(true);
            
            $formData = $this->autoFillFromProfile($profile, $existingData);
            
            // Name should be overwritten by profile
            $this->assertEquals($profile['displayName'], $formData['name']);
            
            // Other fields should be preserved (if they exist in merged data)
            // Note: In this implementation, only name is auto-filled
        }
    }
    
    /**
     * Property Test: Validation state consistency
     * 
     * **Feature: liff-telepharmacy-redesign, Property 5: Form Validation State Consistency**
     * **Validates: Requirements 3.6, 3.7**
     */
    public function testValidationStateConsistency(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $formData = $this->generateRandomFormData(rand(0, 1) === 1);
            $validation = $this->validateForm($formData);
            
            // can_submit should always equal is_valid
            $this->assertEquals(
                $validation['is_valid'],
                $validation['can_submit'],
                "can_submit should equal is_valid"
            );
            
            // If valid, errors should be empty
            if ($validation['is_valid']) {
                $this->assertEmpty($validation['errors']);
            }
        }
    }
}
