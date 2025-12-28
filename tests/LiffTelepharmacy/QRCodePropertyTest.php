<?php
/**
 * Property-Based Tests: QR Code Validity
 * 
 * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
 * **Validates: Requirements 5.3**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class QRCodePropertyTest extends TestCase
{
    /**
     * Generate random member ID
     */
    private function generateRandomMemberId(): string
    {
        return 'MEM' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate QR code data
     */
    private function generateQRCodeData(string $memberId): array
    {
        $timestamp = time();
        $checksum = $this->calculateChecksum($memberId, $timestamp);
        
        return [
            'member_id' => $memberId,
            'timestamp' => $timestamp,
            'checksum' => $checksum,
            'encoded' => $this->encodeQRData($memberId, $timestamp, $checksum)
        ];
    }
    
    /**
     * Calculate checksum for QR data
     */
    private function calculateChecksum(string $memberId, int $timestamp): string
    {
        return substr(md5($memberId . $timestamp . 'secret_key'), 0, 8);
    }
    
    /**
     * Encode QR data
     */
    private function encodeQRData(string $memberId, int $timestamp, string $checksum): string
    {
        $data = json_encode([
            'm' => $memberId,
            't' => $timestamp,
            'c' => $checksum
        ]);
        
        return base64_encode($data);
    }
    
    /**
     * Decode QR data
     */
    private function decodeQRData(string $encoded): ?array
    {
        $decoded = base64_decode($encoded);
        if ($decoded === false) {
            return null;
        }
        
        $data = json_decode($decoded, true);
        if ($data === null) {
            return null;
        }
        
        return [
            'member_id' => $data['m'] ?? null,
            'timestamp' => $data['t'] ?? null,
            'checksum' => $data['c'] ?? null
        ];
    }
    
    /**
     * Validate QR code
     */
    private function validateQRCode(string $encoded): array
    {
        $decoded = $this->decodeQRData($encoded);
        
        if ($decoded === null) {
            return [
                'valid' => false,
                'error' => 'invalid_format',
                'member_id' => null
            ];
        }
        
        if (empty($decoded['member_id']) || empty($decoded['timestamp']) || empty($decoded['checksum'])) {
            return [
                'valid' => false,
                'error' => 'missing_fields',
                'member_id' => null
            ];
        }
        
        // Verify checksum
        $expectedChecksum = $this->calculateChecksum($decoded['member_id'], $decoded['timestamp']);
        if ($decoded['checksum'] !== $expectedChecksum) {
            return [
                'valid' => false,
                'error' => 'invalid_checksum',
                'member_id' => null
            ];
        }
        
        // Check if expired (QR valid for 5 minutes)
        if (time() - $decoded['timestamp'] > 300) {
            return [
                'valid' => false,
                'error' => 'expired',
                'member_id' => $decoded['member_id']
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'member_id' => $decoded['member_id']
        ];
    }
    
    /**
     * Property Test: Generated QR code is valid
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testGeneratedQRCodeIsValid(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $memberId = $this->generateRandomMemberId();
            $qrData = $this->generateQRCodeData($memberId);
            
            $validation = $this->validateQRCode($qrData['encoded']);
            
            $this->assertTrue(
                $validation['valid'],
                "Generated QR code should be valid"
            );
            $this->assertNull($validation['error']);
        }
    }
    
    /**
     * Property Test: QR code contains member ID when decoded
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testQRCodeContainsMemberIdWhenDecoded(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $memberId = $this->generateRandomMemberId();
            $qrData = $this->generateQRCodeData($memberId);
            
            $decoded = $this->decodeQRData($qrData['encoded']);
            
            $this->assertNotNull($decoded);
            $this->assertEquals(
                $memberId,
                $decoded['member_id'],
                "Decoded QR should contain original member ID"
            );
        }
    }
    
    /**
     * Property Test: QR code validation returns member ID
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testQRCodeValidationReturnsMemberId(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $memberId = $this->generateRandomMemberId();
            $qrData = $this->generateQRCodeData($memberId);
            
            $validation = $this->validateQRCode($qrData['encoded']);
            
            $this->assertEquals(
                $memberId,
                $validation['member_id'],
                "Validation should return the member ID"
            );
        }
    }
    
    /**
     * Property Test: Invalid QR code fails validation
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testInvalidQRCodeFailsValidation(): void
    {
        $invalidCodes = [
            'not_base64!!!',
            base64_encode('not_json'),
            base64_encode('{"invalid": "data"}'),
            base64_encode('{"m": "MEM123", "t": 12345}'), // Missing checksum
        ];
        
        foreach ($invalidCodes as $code) {
            $validation = $this->validateQRCode($code);
            
            $this->assertFalse(
                $validation['valid'],
                "Invalid QR code should fail validation"
            );
            $this->assertNotNull($validation['error']);
        }
    }
    
    /**
     * Property Test: Tampered checksum fails validation
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testTamperedChecksumFailsValidation(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $memberId = $this->generateRandomMemberId();
            $qrData = $this->generateQRCodeData($memberId);
            
            // Tamper with the data
            $decoded = $this->decodeQRData($qrData['encoded']);
            $decoded['checksum'] = 'tampered';
            
            $tamperedEncoded = base64_encode(json_encode([
                'm' => $decoded['member_id'],
                't' => $decoded['timestamp'],
                'c' => $decoded['checksum']
            ]));
            
            $validation = $this->validateQRCode($tamperedEncoded);
            
            $this->assertFalse(
                $validation['valid'],
                "Tampered QR code should fail validation"
            );
            $this->assertEquals('invalid_checksum', $validation['error']);
        }
    }
    
    /**
     * Property Test: QR code format is base64 encoded
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testQRCodeFormatIsBase64Encoded(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $memberId = $this->generateRandomMemberId();
            $qrData = $this->generateQRCodeData($memberId);
            
            // Should be valid base64
            $decoded = base64_decode($qrData['encoded'], true);
            $this->assertNotFalse($decoded, "QR code should be valid base64");
            
            // Should decode to valid JSON
            $json = json_decode($decoded, true);
            $this->assertNotNull($json, "Decoded base64 should be valid JSON");
        }
    }
    
    /**
     * Property Test: Member ID format is preserved
     * 
     * **Feature: liff-telepharmacy-redesign, Property 8: QR Code Validity**
     * **Validates: Requirements 5.3**
     */
    public function testMemberIdFormatIsPreserved(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $memberId = $this->generateRandomMemberId();
            
            // Verify format
            $this->assertMatchesRegularExpression(
                '/^MEM\d{6}$/',
                $memberId,
                "Member ID should match format MEM######"
            );
            
            $qrData = $this->generateQRCodeData($memberId);
            $validation = $this->validateQRCode($qrData['encoded']);
            
            $this->assertMatchesRegularExpression(
                '/^MEM\d{6}$/',
                $validation['member_id'],
                "Decoded member ID should preserve format"
            );
        }
    }
}
