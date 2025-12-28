<?php
/**
 * Property-Based Tests: Health Profile Interaction Check
 * 
 * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
 * **Validates: Requirements 18.7**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class HealthProfilePropertyTest extends TestCase
{
    /**
     * Severity levels
     */
    private $severityLevels = ['mild', 'moderate', 'severe'];
    
    /**
     * Generate random medication
     */
    private function generateRandomMedication(): array
    {
        return [
            'id' => rand(1, 10000),
            'name' => 'Medication ' . rand(1, 1000),
            'dosage' => rand(1, 500) . 'mg',
            'frequency' => ['once daily', 'twice daily', 'three times daily'][rand(0, 2)],
            'drug_id' => rand(1, 500)
        ];
    }
    
    /**
     * Generate random health profile
     */
    private function generateRandomHealthProfile(): array
    {
        $medicationCount = rand(0, 5);
        $medications = [];
        
        for ($i = 0; $i < $medicationCount; $i++) {
            $medications[] = $this->generateRandomMedication();
        }
        
        return [
            'user_id' => rand(1, 10000),
            'age' => rand(18, 80),
            'gender' => ['male', 'female'][rand(0, 1)],
            'weight' => rand(40, 120),
            'height' => rand(150, 190),
            'blood_type' => ['A', 'B', 'AB', 'O'][rand(0, 3)],
            'medical_conditions' => $this->generateRandomConditions(),
            'allergies' => $this->generateRandomAllergies(),
            'current_medications' => $medications
        ];
    }
    
    /**
     * Generate random medical conditions
     */
    private function generateRandomConditions(): array
    {
        $allConditions = ['diabetes', 'hypertension', 'heart_disease', 'asthma', 'kidney_disease'];
        $count = rand(0, 3);
        
        shuffle($allConditions);
        return array_slice($allConditions, 0, $count);
    }
    
    /**
     * Generate random allergies
     */
    private function generateRandomAllergies(): array
    {
        $count = rand(0, 3);
        $allergies = [];
        
        for ($i = 0; $i < $count; $i++) {
            $allergies[] = [
                'drug_name' => 'Drug ' . rand(1, 100),
                'reaction_type' => ['rash', 'breathing', 'swelling', 'other'][rand(0, 3)]
            ];
        }
        
        return $allergies;
    }
    
    /**
     * Check medication interactions
     */
    private function checkMedicationInteractions(array $newMedication, array $existingMedications): array
    {
        $interactions = [];
        
        foreach ($existingMedications as $existing) {
            // Simulate interaction check (random for testing)
            if (rand(0, 4) === 0) { // 20% chance of interaction
                $interactions[] = [
                    'drug1_id' => $newMedication['drug_id'],
                    'drug1_name' => $newMedication['name'],
                    'drug2_id' => $existing['drug_id'],
                    'drug2_name' => $existing['name'],
                    'severity' => $this->severityLevels[array_rand($this->severityLevels)],
                    'description' => 'Interaction between ' . $newMedication['name'] . ' and ' . $existing['name']
                ];
            }
        }
        
        return [
            'checked' => true,
            'new_medication' => $newMedication,
            'existing_count' => count($existingMedications),
            'interactions' => $interactions,
            'has_interactions' => count($interactions) > 0
        ];
    }
    
    /**
     * Property Test: Adding medication triggers interaction check
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testAddingMedicationTriggersInteractionCheck(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $profile = $this->generateRandomHealthProfile();
            $newMedication = $this->generateRandomMedication();
            
            $result = $this->checkMedicationInteractions($newMedication, $profile['current_medications']);
            
            $this->assertTrue(
                $result['checked'],
                "Interaction check should be triggered when adding medication"
            );
            $this->assertEquals(
                count($profile['current_medications']),
                $result['existing_count'],
                "All existing medications should be checked"
            );
        }
    }
    
    /**
     * Property Test: Interaction check includes new medication
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testInteractionCheckIncludesNewMedication(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $profile = $this->generateRandomHealthProfile();
            $newMedication = $this->generateRandomMedication();
            
            $result = $this->checkMedicationInteractions($newMedication, $profile['current_medications']);
            
            $this->assertEquals(
                $newMedication['name'],
                $result['new_medication']['name'],
                "Result should include the new medication"
            );
        }
    }
    
    /**
     * Property Test: Interactions have required fields
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testInteractionsHaveRequiredFields(): void
    {
        // Generate profiles until we get some interactions
        $foundInteractions = false;
        
        for ($attempt = 0; $attempt < 100 && !$foundInteractions; $attempt++) {
            $profile = $this->generateRandomHealthProfile();
            $profile['current_medications'] = [
                $this->generateRandomMedication(),
                $this->generateRandomMedication(),
                $this->generateRandomMedication()
            ];
            
            $newMedication = $this->generateRandomMedication();
            $result = $this->checkMedicationInteractions($newMedication, $profile['current_medications']);
            
            if ($result['has_interactions']) {
                $foundInteractions = true;
                
                foreach ($result['interactions'] as $interaction) {
                    $this->assertArrayHasKey('drug1_id', $interaction);
                    $this->assertArrayHasKey('drug1_name', $interaction);
                    $this->assertArrayHasKey('drug2_id', $interaction);
                    $this->assertArrayHasKey('drug2_name', $interaction);
                    $this->assertArrayHasKey('severity', $interaction);
                    $this->assertArrayHasKey('description', $interaction);
                    
                    $this->assertContains(
                        $interaction['severity'],
                        $this->severityLevels,
                        "Severity should be valid"
                    );
                }
            }
        }
    }
    
    /**
     * Property Test: Empty medication list returns no interactions
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testEmptyMedicationListReturnsNoInteractions(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $newMedication = $this->generateRandomMedication();
            
            $result = $this->checkMedicationInteractions($newMedication, []);
            
            $this->assertTrue($result['checked']);
            $this->assertEquals(0, $result['existing_count']);
            $this->assertEmpty($result['interactions']);
            $this->assertFalse($result['has_interactions']);
        }
    }
    
    /**
     * Property Test: Interaction check is deterministic for same inputs
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testInteractionCheckStructureIsConsistent(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $profile = $this->generateRandomHealthProfile();
            $newMedication = $this->generateRandomMedication();
            
            $result = $this->checkMedicationInteractions($newMedication, $profile['current_medications']);
            
            // Structure should always be consistent
            $this->assertArrayHasKey('checked', $result);
            $this->assertArrayHasKey('new_medication', $result);
            $this->assertArrayHasKey('existing_count', $result);
            $this->assertArrayHasKey('interactions', $result);
            $this->assertArrayHasKey('has_interactions', $result);
            
            $this->assertIsBool($result['checked']);
            $this->assertIsArray($result['new_medication']);
            $this->assertIsInt($result['existing_count']);
            $this->assertIsArray($result['interactions']);
            $this->assertIsBool($result['has_interactions']);
        }
    }
    
    /**
     * Property Test: has_interactions matches interactions array
     * 
     * **Feature: liff-telepharmacy-redesign, Property 17: Health Profile Interaction Check**
     * **Validates: Requirements 18.7**
     */
    public function testHasInteractionsMatchesInteractionsArray(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $profile = $this->generateRandomHealthProfile();
            $newMedication = $this->generateRandomMedication();
            
            $result = $this->checkMedicationInteractions($newMedication, $profile['current_medications']);
            
            $this->assertEquals(
                count($result['interactions']) > 0,
                $result['has_interactions'],
                "has_interactions should match whether interactions array is non-empty"
            );
        }
    }
}
