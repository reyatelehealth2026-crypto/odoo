<?php
/**
 * Property-Based Test: LIFF URL Correctness
 * 
 * **Feature: public-landing-page, Property 2: LIFF URL correctness**
 * **Validates: Requirements 2.2, 2.3**
 * 
 * Property: For any LINE Account with a valid LIFF ID, the CTA button's redirect URL 
 * SHALL contain that exact LIFF ID.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/LandingPageRenderer.php';

class LiffUrlCorrectnessPropertyTest extends TestCase
{
    /**
     * Generate random LIFF IDs for property testing
     * 
     * @return array Array of test data sets
     */
    public function liffIdProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random LIFF IDs for property testing
        for ($i = 0; $i < 100; $i++) {
            $liffId = $this->generateRandomLiffId();
            $testCases["liff_id_{$i}"] = [$liffId];
        }
        
        return $testCases;
    }

    /**
     * Generate edge case LIFF IDs
     * 
     * @return array Array of edge case test data
     */
    public function edgeCaseLiffIdProvider(): array
    {
        return [
            'numeric_only' => ['1234567890'],
            'alphanumeric' => ['abc123def456'],
            'with_dash' => ['1234-5678-9012'],
            'long_id' => ['1234567890123456789012345678901234567890'],
            'short_id' => ['123'],
        ];
    }

    /**
     * Property Test: LIFF URL contains exact LIFF ID
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     * 
     * @dataProvider liffIdProvider
     */
    public function testLiffUrlContainsExactLiffId(string $liffId): void
    {
        $liffUrl = \LandingPageRenderer::buildLiffUrl($liffId);
        
        // URL should not be null for valid LIFF ID
        $this->assertNotNull(
            $liffUrl,
            "LIFF URL should not be null for valid LIFF ID"
        );
        
        // URL should contain the exact LIFF ID
        $this->assertStringContainsString(
            $liffId,
            $liffUrl,
            "LIFF URL should contain the exact LIFF ID '{$liffId}'"
        );
        
        // URL should follow LINE LIFF format
        $this->assertStringStartsWith(
            'https://liff.line.me/',
            $liffUrl,
            "LIFF URL should start with LINE LIFF domain"
        );
        
        // URL should end with the LIFF ID
        $this->assertStringEndsWith(
            $liffId,
            $liffUrl,
            "LIFF URL should end with the LIFF ID"
        );
    }

    /**
     * Property Test: CTA button href contains exact LIFF ID
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     * 
     * @dataProvider liffIdProvider
     */
    public function testCtaButtonContainsLiffId(string $liffId): void
    {
        $liffUrl = \LandingPageRenderer::buildLiffUrl($liffId);
        $buttonHtml = \LandingPageRenderer::renderLiffButton($liffUrl);
        
        // Button should contain the LIFF URL
        $this->assertStringContainsString(
            htmlspecialchars($liffUrl),
            $buttonHtml,
            "CTA button should contain the LIFF URL"
        );
        
        // Button should contain the LIFF ID
        $this->assertStringContainsString(
            $liffId,
            $buttonHtml,
            "CTA button href should contain the LIFF ID '{$liffId}'"
        );
        
        // Button should be a link element
        $this->assertStringContainsString(
            '<a href=',
            $buttonHtml,
            "CTA button should be an anchor element"
        );
    }

    /**
     * Property Test: Edge case LIFF IDs work correctly
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     * 
     * @dataProvider edgeCaseLiffIdProvider
     */
    public function testEdgeCaseLiffIds(string $liffId): void
    {
        $liffUrl = \LandingPageRenderer::buildLiffUrl($liffId);
        
        $this->assertNotNull($liffUrl);
        $this->assertStringContainsString($liffId, $liffUrl);
        $this->assertEquals("https://liff.line.me/{$liffId}", $liffUrl);
    }

    /**
     * Property Test: Empty LIFF ID returns null URL
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     */
    public function testEmptyLiffIdReturnsNull(): void
    {
        $this->assertNull(\LandingPageRenderer::buildLiffUrl(null));
        $this->assertNull(\LandingPageRenderer::buildLiffUrl(''));
    }

    /**
     * Property Test: Null LIFF URL shows "Coming Soon" button
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     */
    public function testNullLiffUrlShowsComingSoon(): void
    {
        $buttonHtml = \LandingPageRenderer::renderLiffButton(null);
        
        // Should show "Coming Soon" message
        $this->assertStringContainsString(
            'เร็วๆ นี้',
            $buttonHtml,
            "Null LIFF URL should show 'Coming Soon' message"
        );
        
        // Should not be a clickable link
        $this->assertStringNotContainsString(
            '<a href=',
            $buttonHtml,
            "Null LIFF URL should not render as clickable link"
        );
    }

    /**
     * Property Test: LIFF URL format is consistent
     * 
     * **Feature: public-landing-page, Property 2: LIFF URL correctness**
     * **Validates: Requirements 2.2, 2.3**
     * 
     * @dataProvider liffIdProvider
     */
    public function testLiffUrlFormatConsistency(string $liffId): void
    {
        $liffUrl = \LandingPageRenderer::buildLiffUrl($liffId);
        $expectedUrl = "https://liff.line.me/{$liffId}";
        
        $this->assertEquals(
            $expectedUrl,
            $liffUrl,
            "LIFF URL format should be consistent: https://liff.line.me/{LIFF_ID}"
        );
    }

    /**
     * Generate a random LIFF ID (simulating real LINE LIFF IDs)
     */
    private function generateRandomLiffId(): string
    {
        // LINE LIFF IDs are typically numeric strings
        $length = rand(10, 20);
        $liffId = '';
        
        for ($i = 0; $i < $length; $i++) {
            $liffId .= rand(0, 9);
        }
        
        // Sometimes include a dash (some LIFF IDs have format like 1234567890-abcdefgh)
        if (rand(0, 3) === 0) {
            $pos = rand(5, strlen($liffId) - 5);
            $liffId = substr($liffId, 0, $pos) . '-' . substr($liffId, $pos);
        }
        
        return $liffId;
    }
}
