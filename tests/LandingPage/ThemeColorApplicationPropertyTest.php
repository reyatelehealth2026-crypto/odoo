<?php
/**
 * Property-Based Test: Theme Color Application
 * 
 * **Feature: public-landing-page, Property 3: Theme color application**
 * **Validates: Requirements 1.5**
 * 
 * Property: For any custom primary color in Shop Settings, the Landing Page CSS 
 * SHALL include that color value in the theme styles.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/LandingPageRenderer.php';

class ThemeColorApplicationPropertyTest extends TestCase
{
    /**
     * Generate random valid hex colors for property testing
     * 
     * @return array Array of test data sets
     */
    public function validHexColorProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random valid hex colors for property testing
        for ($i = 0; $i < 100; $i++) {
            $color = $this->generateRandomHexColor();
            $testCases["color_{$i}"] = [$color];
        }
        
        return $testCases;
    }

    /**
     * Generate pairs of primary and secondary colors
     * 
     * @return array Array of test data sets
     */
    public function colorPairProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random color pairs for property testing
        for ($i = 0; $i < 100; $i++) {
            $primary = $this->generateRandomHexColor();
            $secondary = $this->generateRandomHexColor();
            $testCases["pair_{$i}"] = [$primary, $secondary];
        }
        
        return $testCases;
    }

    /**
     * Generate invalid color formats
     * 
     * @return array Array of invalid color test data
     */
    public function invalidColorProvider(): array
    {
        return [
            'no_hash' => ['FF5733'],
            'short_format' => ['#F53'],
            'too_long' => ['#FF5733AA'],
            'invalid_chars' => ['#GGGGGG'],
            'empty' => [''],
            'spaces' => ['# FF5733'],
            'lowercase_invalid' => ['#gggggg'],
            'mixed_invalid' => ['#12345G'],
        ];
    }

    /**
     * Property Test: Valid hex color appears in rendered CSS
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testValidColorAppearsInCss(string $primaryColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($primaryColor);
        
        // The primary color should appear in the CSS
        $this->assertStringContainsString(
            $primaryColor,
            $css,
            "Primary color '{$primaryColor}' should appear in rendered CSS"
        );
        
        // CSS should contain the --primary variable
        $this->assertStringContainsString(
            '--primary:',
            $css,
            "CSS should contain --primary variable"
        );
        
        // CSS should be wrapped in :root selector
        $this->assertStringContainsString(
            ':root',
            $css,
            "CSS should be wrapped in :root selector"
        );
    }

    /**
     * Property Test: Both primary and secondary colors appear in CSS
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider colorPairProvider
     */
    public function testColorPairAppearsInCss(string $primaryColor, string $secondaryColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($primaryColor, $secondaryColor);
        
        // Primary color should appear
        $this->assertStringContainsString(
            $primaryColor,
            $css,
            "Primary color '{$primaryColor}' should appear in CSS"
        );
        
        // Secondary color should appear as --primary-light
        $this->assertStringContainsString(
            $secondaryColor,
            $css,
            "Secondary color '{$secondaryColor}' should appear in CSS"
        );
        
        // Both variables should be present
        $this->assertStringContainsString('--primary:', $css);
        $this->assertStringContainsString('--primary-light:', $css);
    }

    /**
     * Property Test: Extracted color matches input color
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testExtractedColorMatchesInput(string $primaryColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($primaryColor);
        $extractedColor = \LandingPageRenderer::extractColorFromCss($css, '--primary');
        
        $this->assertEquals(
            $primaryColor,
            $extractedColor,
            "Extracted color should match input color '{$primaryColor}'"
        );
    }

    /**
     * Property Test: Invalid colors fall back to default
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider invalidColorProvider
     */
    public function testInvalidColorFallsBackToDefault(string $invalidColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($invalidColor);
        $extractedColor = \LandingPageRenderer::extractColorFromCss($css, '--primary');
        
        // Should fall back to default color
        $this->assertEquals(
            \LandingPageRenderer::DEFAULT_PRIMARY_COLOR,
            $extractedColor,
            "Invalid color '{$invalidColor}' should fall back to default"
        );
    }

    /**
     * Property Test: RGB values are correctly extracted from hex
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testRgbValuesCorrectlyExtracted(string $primaryColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($primaryColor);
        
        // Extract expected RGB values
        $expectedR = hexdec(substr($primaryColor, 1, 2));
        $expectedG = hexdec(substr($primaryColor, 3, 2));
        $expectedB = hexdec(substr($primaryColor, 5, 2));
        $expectedRgb = "{$expectedR}, {$expectedG}, {$expectedB}";
        
        // CSS should contain the RGB values
        $this->assertStringContainsString(
            "--primary-rgb: {$expectedRgb}",
            $css,
            "CSS should contain correct RGB values for '{$primaryColor}'"
        );
    }

    /**
     * Property Test: isValidHexColor correctly validates colors
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testIsValidHexColorAcceptsValidColors(string $color): void
    {
        $this->assertTrue(
            \LandingPageRenderer::isValidHexColor($color),
            "Valid color '{$color}' should be accepted"
        );
    }

    /**
     * Property Test: isValidHexColor rejects invalid colors
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider invalidColorProvider
     */
    public function testIsValidHexColorRejectsInvalidColors(string $color): void
    {
        $this->assertFalse(
            \LandingPageRenderer::isValidHexColor($color),
            "Invalid color '{$color}' should be rejected"
        );
    }

    /**
     * Property Test: normalizeHexColor returns valid color or fallback
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testNormalizeHexColorReturnsValidColor(string $color): void
    {
        $normalized = \LandingPageRenderer::normalizeHexColor($color);
        
        // Normalized color should be valid
        $this->assertTrue(
            \LandingPageRenderer::isValidHexColor($normalized),
            "Normalized color should be valid"
        );
        
        // For valid input, should return same color
        $this->assertEquals(
            $color,
            $normalized,
            "Valid color should be returned unchanged"
        );
    }

    /**
     * Property Test: CSS output is well-formed
     * 
     * **Feature: public-landing-page, Property 3: Theme color application**
     * **Validates: Requirements 1.5**
     * 
     * @dataProvider validHexColorProvider
     */
    public function testCssOutputIsWellFormed(string $primaryColor): void
    {
        $css = \LandingPageRenderer::renderThemeColors($primaryColor);
        
        // Should start with :root
        $this->assertMatchesRegularExpression(
            '/^:root\s*\{/',
            $css,
            "CSS should start with :root {"
        );
        
        // Should end with closing brace
        $this->assertMatchesRegularExpression(
            '/\}$/',
            $css,
            "CSS should end with }"
        );
        
        // Should contain all required variables
        $requiredVars = ['--primary:', '--primary-dark:', '--primary-light:', '--primary-rgb:'];
        foreach ($requiredVars as $var) {
            $this->assertStringContainsString(
                $var,
                $css,
                "CSS should contain {$var}"
            );
        }
    }

    /**
     * Generate a random valid hex color
     */
    private function generateRandomHexColor(): string
    {
        $r = str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT);
        $g = str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT);
        $b = str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT);
        
        return '#' . strtoupper($r . $g . $b);
    }
}
