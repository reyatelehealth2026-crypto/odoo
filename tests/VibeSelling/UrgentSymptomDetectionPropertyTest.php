<?php
/**
 * Property-Based Test: Urgent Symptom Detection
 * 
 * **Feature: vibe-selling-os-v2, Property 13: Urgent Symptom Detection**
 * **Validates: Requirements 1.5**
 * 
 * Property: For any symptom analysis with severity level "severe" or "urgent", 
 * the system should flag the conversation and recommend hospital visit.
 */

namespace Tests\VibeSelling;

use PHPUnit\Framework\TestCase;
use PDO;

class UrgentSymptomDetectionPropertyTest extends TestCase
{
    private $db;
    private $lineAccountId = 1;
    private $service;
    
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
        
        require_once __DIR__ . '/../../classes/PharmacyImageAnalyzerService.php';
        $this->service = new \PharmacyImageAnalyzerService($this->db, $this->lineAccountId);
    }
    
    private function createTables(): void
    {
        $this->db->exec("CREATE TABLE ai_settings (
            id INTEGER PRIMARY KEY, line_account_id INTEGER,
            gemini_api_key VARCHAR(255), model VARCHAR(100)
        )");
        $this->db->exec("CREATE TABLE symptom_analysis_cache (
            id INTEGER PRIMARY KEY, image_hash VARCHAR(64), image_url TEXT,
            analysis_result TEXT, is_urgent TINYINT(1), created_at DATETIME, expires_at DATETIME
        )");
    }

    /**
     * Property Test: Severe Severity Triggers Urgent Flag
     * **Feature: vibe-selling-os-v2, Property 13** **Validates: Requirements 1.5**
     */
    public function testSevereSeverityTriggersUrgentFlag(): void
    {
        $analysisResult = [
            'condition' => 'แผลติดเชื้อรุนแรง',
            'severity' => 'severe',
            'needsDoctor' => false,
            'warnings' => []
        ];
        
        $result = $this->service->checkUrgency($analysisResult);
        
        $this->assertTrue($result['isUrgent'], "Severe severity should trigger urgent flag");
        $this->assertNotEmpty($result['reason'], "Should provide reason for urgency");
        $this->assertNotEmpty($result['recommendation'], "Should provide recommendation");
    }

    /**
     * Property Test: Urgent Severity Triggers Urgent Flag
     */
    public function testUrgentSeverityTriggersUrgentFlag(): void
    {
        $analysisResult = [
            'condition' => 'อาการแพ้รุนแรง',
            'severity' => 'urgent',
            'needsDoctor' => false,
            'warnings' => []
        ];
        
        $result = $this->service->checkUrgency($analysisResult);
        
        $this->assertTrue($result['isUrgent'], "Urgent severity should trigger urgent flag");
    }

    /**
     * Property Test: Mild Severity Does Not Trigger Urgent Flag
     */
    public function testMildSeverityDoesNotTriggerUrgentFlag(): void
    {
        $analysisResult = [
            'condition' => 'ผื่นเล็กน้อย',
            'severity' => 'mild',
            'needsDoctor' => false,
            'warnings' => []
        ];
        
        $result = $this->service->checkUrgency($analysisResult);
        
        $this->assertFalse($result['isUrgent'], "Mild severity should not trigger urgent flag");
    }

    /**
     * Property Test: NeedsDoctor Flag Triggers Urgent
     */
    public function testNeedsDoctorFlagTriggersUrgent(): void
    {
        $analysisResult = [
            'condition' => 'อาการไม่ชัดเจน',
            'severity' => 'moderate',
            'needsDoctor' => true,
            'doctorReason' => 'ต้องการการตรวจวินิจฉัยเพิ่มเติม',
            'warnings' => []
        ];
        
        $result = $this->service->checkUrgency($analysisResult);
        
        $this->assertTrue($result['isUrgent'], "needsDoctor flag should trigger urgent");
    }

    /**
     * Property Test: Urgent Condition Keywords Trigger Flag
     */
    public function testUrgentConditionKeywordsTriggersFlag(): void
    {
        $urgentConditions = [
            'severe allergic reaction',
            'difficulty breathing',
            'chest pain',
            'severe bleeding',
            'หายใจลำบาก',
            'เจ็บหน้าอก',
            'อาการแพ้รุนแรง'
        ];
        
        foreach ($urgentConditions as $condition) {
            $analysisResult = [
                'condition' => $condition,
                'conditionEn' => $condition,
                'severity' => 'moderate',
                'needsDoctor' => false,
                'warnings' => []
            ];
            
            $result = $this->service->checkUrgency($analysisResult);
            
            $this->assertTrue($result['isUrgent'],
                "Urgent condition '{$condition}' should trigger urgent flag");
        }
    }

    /**
     * Property Test: Urgency Result Contains Required Fields
     */
    public function testUrgencyResultContainsRequiredFields(): void
    {
        $analysisResult = [
            'condition' => 'test',
            'severity' => 'severe',
            'needsDoctor' => false,
            'warnings' => []
        ];
        
        $result = $this->service->checkUrgency($analysisResult);
        
        $this->assertArrayHasKey('isUrgent', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('severityLabel', $result);
    }

    /**
     * Property Test: Random Severity Levels Property
     */
    public function testRandomSeverityLevelsProperty(): void
    {
        $severityLevels = ['mild', 'moderate', 'severe', 'urgent'];
        $urgentLevels = ['severe', 'urgent'];
        
        for ($i = 0; $i < 100; $i++) {
            $severity = $severityLevels[array_rand($severityLevels)];
            
            $analysisResult = [
                'condition' => 'Test condition ' . $i,
                'severity' => $severity,
                'needsDoctor' => false,
                'warnings' => []
            ];
            
            $result = $this->service->checkUrgency($analysisResult);
            
            if (in_array($severity, $urgentLevels)) {
                $this->assertTrue($result['isUrgent'],
                    "Severity '{$severity}' should trigger urgent on iteration {$i}");
            }
        }
    }
}
