<?php
/**
 * Property-Based Test: Shop Data Display Consistency
 * 
 * **Feature: public-landing-page, Property 1: Shop data display consistency**
 * **Validates: Requirements 1.2**
 * 
 * Property: For any shop settings in the database, the Landing Page SHALL display 
 * the shop name and logo that match the stored values exactly.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/LandingPageRenderer.php';

class ShopDataDisplayPropertyTest extends TestCase
{
    /**
     * Generate random shop names for property testing
     * 
     * @return array Array of test data sets
     */
    public function shopNameProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random shop names for property testing
        for ($i = 0; $i < 100; $i++) {
            $shopName = $this->generateRandomShopName();
            $testCases["shop_name_{$i}"] = [$shopName];
        }
        
        return $testCases;
    }

    /**
     * Generate random shop logo URLs for property testing
     * 
     * @return array Array of test data sets
     */
    public function shopLogoProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random logo URLs for property testing
        for ($i = 0; $i < 100; $i++) {
            $logoUrl = $this->generateRandomLogoUrl();
            $shopName = $this->generateRandomShopName();
            $testCases["shop_logo_{$i}"] = [$logoUrl, $shopName];
        }
        
        return $testCases;
    }

    /**
     * Property Test: Shop name appears exactly in rendered HTML
     * 
     * **Feature: public-landing-page, Property 1: Shop data display consistency**
     * **Validates: Requirements 1.2**
     * 
     * @dataProvider shopNameProvider
     */
    public function testShopNameAppearsInRenderedHtml(string $shopName): void
    {
        $renderedHtml = \LandingPageRenderer::renderShopName($shopName);
        
        // The shop name should appear in the HTML (properly escaped)
        $this->assertStringContainsString(
            htmlspecialchars($shopName),
            $renderedHtml,
            "Shop name '{$shopName}' should appear in rendered HTML"
        );
        
        // Verify it's wrapped in the correct element
        $this->assertStringContainsString(
            'class="shop-name"',
            $renderedHtml,
            "Shop name should be in element with class 'shop-name'"
        );
    }

    /**
     * Property Test: Shop logo URL appears exactly in rendered HTML
     * 
     * **Feature: public-landing-page, Property 1: Shop data display consistency**
     * **Validates: Requirements 1.2**
     * 
     * @dataProvider shopLogoProvider
     */
    public function testShopLogoAppearsInRenderedHtml(string $logoUrl, string $shopName): void
    {
        $renderedHtml = \LandingPageRenderer::renderShopLogo($logoUrl, $shopName);
        
        // The logo URL should appear in the HTML (properly escaped)
        $this->assertStringContainsString(
            htmlspecialchars($logoUrl),
            $renderedHtml,
            "Logo URL '{$logoUrl}' should appear in rendered HTML"
        );
        
        // Verify it's an img element
        $this->assertStringContainsString(
            '<img',
            $renderedHtml,
            "Logo should be rendered as img element"
        );
        
        // Verify alt text contains shop name
        $this->assertStringContainsString(
            'alt="' . htmlspecialchars($shopName) . '"',
            $renderedHtml,
            "Logo alt text should contain shop name"
        );
    }

    /**
     * Property Test: Empty logo shows placeholder
     * 
     * **Feature: public-landing-page, Property 1: Shop data display consistency**
     * **Validates: Requirements 1.2**
     */
    public function testEmptyLogoShowsPlaceholder(): void
    {
        $renderedHtml = \LandingPageRenderer::renderShopLogo(null, 'Test Shop');
        
        $this->assertStringContainsString(
            'logo-placeholder',
            $renderedHtml,
            "Empty logo should show placeholder"
        );
        
        $this->assertStringNotContainsString(
            '<img',
            $renderedHtml,
            "Empty logo should not render img element"
        );
    }

    /**
     * Property Test: Hero section displays shop name and description
     * 
     * **Feature: public-landing-page, Property 1: Shop data display consistency**
     * **Validates: Requirements 1.2**
     * 
     * @dataProvider shopNameProvider
     */
    public function testHeroSectionDisplaysShopData(string $shopName): void
    {
        $description = $this->generateRandomDescription();
        $renderedHtml = \LandingPageRenderer::renderHeroSection($shopName, $description, null);
        
        // Shop name should appear in hero title
        $this->assertStringContainsString(
            htmlspecialchars($shopName),
            $renderedHtml,
            "Shop name should appear in hero section"
        );
        
        // Description should appear
        $this->assertStringContainsString(
            htmlspecialchars($description),
            $renderedHtml,
            "Description should appear in hero section"
        );
    }

    /**
     * Generate a random shop name
     */
    private function generateRandomShopName(): string
    {
        $prefixes = ['ร้านยา', 'เภสัชกรรม', 'Pharmacy', 'Drug Store', 'Health', 'Care'];
        $suffixes = ['สุขภาพดี', 'ใจดี', 'Plus', 'Pro', 'Center', '24', 'Express'];
        $names = ['ABC', 'XYZ', 'สมชาย', 'สมหญิง', 'Golden', 'Silver', 'Green', 'Blue'];
        
        $prefix = $prefixes[array_rand($prefixes)];
        $name = $names[array_rand($names)];
        $suffix = $suffixes[array_rand($suffixes)];
        
        // Sometimes include special characters
        $special = ['', ' & ', ' - ', ' @ ', ''];
        $sep = $special[array_rand($special)];
        
        return $prefix . $sep . $name . ' ' . $suffix;
    }

    /**
     * Generate a random logo URL
     */
    private function generateRandomLogoUrl(): string
    {
        $domains = ['example.com', 'cdn.test.com', 'images.pharmacy.co.th', 'storage.local'];
        $paths = ['logo', 'images/brand', 'assets/img', 'uploads/logos'];
        $extensions = ['png', 'jpg', 'svg', 'webp'];
        
        $domain = $domains[array_rand($domains)];
        $path = $paths[array_rand($paths)];
        $ext = $extensions[array_rand($extensions)];
        $filename = 'logo_' . bin2hex(random_bytes(4));
        
        return "https://{$domain}/{$path}/{$filename}.{$ext}";
    }

    /**
     * Generate a random description
     */
    private function generateRandomDescription(): string
    {
        $descriptions = [
            'ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร',
            'Your trusted online pharmacy with professional consultation',
            'บริการด้านสุขภาพครบวงจร ส่งถึงบ้าน',
            'Quality medicines delivered to your door',
            'เภสัชกรพร้อมให้คำปรึกษา 24 ชั่วโมง',
        ];
        
        return $descriptions[array_rand($descriptions)];
    }
}
