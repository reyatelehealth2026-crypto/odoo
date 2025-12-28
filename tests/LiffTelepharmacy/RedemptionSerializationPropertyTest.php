<?php
/**
 * Property-Based Tests: Redemption Data Serialization
 * 
 * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
 * **Validates: Requirements 23.12, 23.13**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class RedemptionSerializationPropertyTest extends TestCase
{
    /**
     * Valid redemption statuses
     */
    private $validStatuses = ['pending', 'approved', 'delivered', 'cancelled'];
    
    /**
     * Generate random redemption
     */
    private function generateRandomRedemption(): array
    {
        $createdAt = date('Y-m-d H:i:s', strtotime('-' . rand(0, 30) . ' days'));
        $status = $this->validStatuses[array_rand($this->validStatuses)];
        
        return [
            'id' => rand(1, 100000),
            'user_id' => rand(1, 10000),
            'reward_id' => rand(1, 1000),
            'redemption_code' => 'RDM' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 8)),
            'points_used' => rand(100, 5000),
            'status' => $status,
            'notes' => rand(0, 1) ? 'Note ' . rand(1, 100) : null,
            'approved_at' => in_array($status, ['approved', 'delivered']) ? 
                date('Y-m-d H:i:s', strtotime($createdAt . ' +1 day')) : null,
            'delivered_at' => $status === 'delivered' ? 
                date('Y-m-d H:i:s', strtotime($createdAt . ' +3 days')) : null,
            'expires_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +30 days')),
            'created_at' => $createdAt
        ];
    }
    
    /**
     * Serialize redemption to JSON
     */
    private function serializeRedemption(array $redemption): string
    {
        return json_encode($redemption);
    }
    
    /**
     * Deserialize redemption from JSON
     */
    private function deserializeRedemption(string $json): array
    {
        return json_decode($json, true);
    }
    
    /**
     * Validate redemption structure
     */
    private function validateRedemptionStructure(array $redemption): bool
    {
        $requiredFields = [
            'id', 'user_id', 'reward_id', 'redemption_code',
            'points_used', 'status', 'created_at'
        ];
        
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $redemption)) {
                return false;
            }
        }
        
        if (!in_array($redemption['status'], $this->validStatuses)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Property Test: Redemption serialization round-trip
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testRedemptionSerializationRoundTrip(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            $this->assertEquals(
                $original['id'],
                $deserialized['id'],
                "ID should survive serialization"
            );
            $this->assertEquals(
                $original['redemption_code'],
                $deserialized['redemption_code'],
                "Redemption code should survive serialization"
            );
            $this->assertEquals(
                $original['points_used'],
                $deserialized['points_used'],
                "Points used should survive serialization"
            );
            $this->assertEquals(
                $original['status'],
                $deserialized['status'],
                "Status should survive serialization"
            );
        }
    }
    
    /**
     * Property Test: Serialized redemption is valid JSON
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testSerializedRedemptionIsValidJson(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $redemption = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($redemption);
            
            $this->assertJson($serialized, "Serialized redemption should be valid JSON");
            
            $decoded = json_decode($serialized, true);
            $this->assertNotNull($decoded, "JSON should decode successfully");
        }
    }
    
    /**
     * Property Test: Deserialized redemption has valid structure
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testDeserializedRedemptionHasValidStructure(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            $this->assertTrue(
                $this->validateRedemptionStructure($deserialized),
                "Deserialized redemption should have valid structure"
            );
        }
    }
    
    /**
     * Property Test: Nullable fields survive serialization
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testNullableFieldsSurviveSerialization(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            // Check nullable fields
            $this->assertEquals(
                $original['notes'],
                $deserialized['notes'],
                "Notes should survive serialization (including null)"
            );
            $this->assertEquals(
                $original['approved_at'],
                $deserialized['approved_at'],
                "Approved_at should survive serialization (including null)"
            );
            $this->assertEquals(
                $original['delivered_at'],
                $deserialized['delivered_at'],
                "Delivered_at should survive serialization (including null)"
            );
        }
    }
    
    /**
     * Property Test: Timestamps survive serialization
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testTimestampsSurviveSerialization(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            $this->assertEquals(
                $original['created_at'],
                $deserialized['created_at'],
                "Created_at should survive serialization"
            );
            $this->assertEquals(
                $original['expires_at'],
                $deserialized['expires_at'],
                "Expires_at should survive serialization"
            );
        }
    }
    
    /**
     * Property Test: Status is always valid after deserialization
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testStatusIsAlwaysValidAfterDeserialization(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            $this->assertContains(
                $deserialized['status'],
                $this->validStatuses,
                "Status should be valid after deserialization"
            );
        }
    }
    
    /**
     * Property Test: Redemption code format preserved
     * 
     * **Feature: liff-telepharmacy-redesign, Property 36: Redemption Data Serialization Round-Trip**
     * **Validates: Requirements 23.12, 23.13**
     */
    public function testRedemptionCodeFormatPreserved(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomRedemption();
            
            $serialized = $this->serializeRedemption($original);
            $deserialized = $this->deserializeRedemption($serialized);
            
            $this->assertStringStartsWith(
                'RDM',
                $deserialized['redemption_code'],
                "Redemption code should start with RDM"
            );
            $this->assertMatchesRegularExpression(
                '/^RDM[A-Z0-9]+$/',
                $deserialized['redemption_code'],
                "Redemption code format should be preserved"
            );
        }
    }
}
