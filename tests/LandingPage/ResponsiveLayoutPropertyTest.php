<?php
/**
 * Property-Based Test: Responsive Layout Adaptation
 * 
 * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
 * **Validates: Requirements 4.1, 4.2, 4.3**
 * 
 * Property: For any viewport width, the Landing Page layout SHALL adapt appropriately 
 * (mobile layout for width < 768px, desktop layout for width >= 768px).
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/LandingPageRenderer.php';

class ResponsiveLayoutPropertyTest extends TestCase
{
    /**
     * Generate random mobile viewport widths (< 768px)
     * 
     * @return array Array of test data sets
     */
    public function mobileViewportProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random mobile viewport widths
        for ($i = 0; $i < 100; $i++) {
            $width = rand(320, 767);
            $testCases["mobile_width_{$width}"] = [$width];
        }
        
        return $testCases;
    }

    /**
     * Generate random tablet viewport widths (768px - 1023px)
     * 
     * @return array Array of test data sets
     */
    public function tabletViewportProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random tablet viewport widths
        for ($i = 0; $i < 100; $i++) {
            $width = rand(768, 1023);
            $testCases["tablet_width_{$width}"] = [$width];
        }
        
        return $testCases;
    }

    /**
     * Generate random desktop viewport widths (1024px - 1279px)
     * 
     * @return array Array of test data sets
     */
    public function desktopViewportProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random desktop viewport widths
        for ($i = 0; $i < 100; $i++) {
            $width = rand(1024, 1279);
            $testCases["desktop_width_{$width}"] = [$width];
        }
        
        return $testCases;
    }

    /**
     * Generate random large desktop viewport widths (>= 1280px)
     * 
     * @return array Array of test data sets
     */
    public function largeDesktopViewportProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random large desktop viewport widths
        for ($i = 0; $i < 100; $i++) {
            $width = rand(1280, 2560);
            $testCases["large_desktop_width_{$width}"] = [$width];
        }
        
        return $testCases;
    }

    /**
     * Generate random viewport widths across all ranges
     * 
     * @return array Array of test data sets
     */
    public function anyViewportProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random viewport widths across all ranges
        for ($i = 0; $i < 100; $i++) {
            $width = rand(320, 2560);
            $testCases["any_width_{$width}"] = [$width];
        }
        
        return $testCases;
    }

    /**
     * Property Test: Mobile viewports return 'mobile' layout type
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider mobileViewportProvider
     */
    public function testMobileViewportReturnsMobileLayout(int $viewportWidth): void
    {
        $layoutType = \LandingPageRenderer::getLayoutType($viewportWidth);
        
        $this->assertEquals(
            'mobile',
            $layoutType,
            "Viewport width {$viewportWidth}px should return 'mobile' layout"
        );
    }

    /**
     * Property Test: Tablet viewports return 'tablet' layout type
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider tabletViewportProvider
     */
    public function testTabletViewportReturnsTabletLayout(int $viewportWidth): void
    {
        $layoutType = \LandingPageRenderer::getLayoutType($viewportWidth);
        
        $this->assertEquals(
            'tablet',
            $layoutType,
            "Viewport width {$viewportWidth}px should return 'tablet' layout"
        );
    }

    /**
     * Property Test: Desktop viewports return 'desktop' layout type
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider desktopViewportProvider
     */
    public function testDesktopViewportReturnsDesktopLayout(int $viewportWidth): void
    {
        $layoutType = \LandingPageRenderer::getLayoutType($viewportWidth);
        
        $this->assertEquals(
            'desktop',
            $layoutType,
            "Viewport width {$viewportWidth}px should return 'desktop' layout"
        );
    }

    /**
     * Property Test: Large desktop viewports return 'large-desktop' layout type
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider largeDesktopViewportProvider
     */
    public function testLargeDesktopViewportReturnsLargeDesktopLayout(int $viewportWidth): void
    {
        $layoutType = \LandingPageRenderer::getLayoutType($viewportWidth);
        
        $this->assertEquals(
            'large-desktop',
            $layoutType,
            "Viewport width {$viewportWidth}px should return 'large-desktop' layout"
        );
    }

    /**
     * Property Test: Mobile CTA visible only on mobile viewports
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider mobileViewportProvider
     */
    public function testMobileCtaVisibleOnMobile(int $viewportWidth): void
    {
        $this->assertTrue(
            \LandingPageRenderer::shouldShowMobileCta($viewportWidth),
            "Mobile CTA should be visible at {$viewportWidth}px"
        );
        
        $this->assertFalse(
            \LandingPageRenderer::shouldShowHeaderCta($viewportWidth),
            "Header CTA should be hidden at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Header CTA visible on tablet and larger viewports
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider tabletViewportProvider
     */
    public function testHeaderCtaVisibleOnTablet(int $viewportWidth): void
    {
        $this->assertTrue(
            \LandingPageRenderer::shouldShowHeaderCta($viewportWidth),
            "Header CTA should be visible at {$viewportWidth}px"
        );
        
        $this->assertFalse(
            \LandingPageRenderer::shouldShowMobileCta($viewportWidth),
            "Mobile CTA should be hidden at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Services grid has 1 column on mobile
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider mobileViewportProvider
     */
    public function testServicesGridOneColumnOnMobile(int $viewportWidth): void
    {
        $columns = \LandingPageRenderer::getServicesGridColumns($viewportWidth);
        
        $this->assertEquals(
            1,
            $columns,
            "Services grid should have 1 column at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Services grid has 3 columns on tablet and larger
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider tabletViewportProvider
     */
    public function testServicesGridThreeColumnsOnTablet(int $viewportWidth): void
    {
        $columns = \LandingPageRenderer::getServicesGridColumns($viewportWidth);
        
        $this->assertEquals(
            3,
            $columns,
            "Services grid should have 3 columns at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Promotions grid columns adapt correctly
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider anyViewportProvider
     */
    public function testPromotionsGridColumnsAdapt(int $viewportWidth): void
    {
        $columns = \LandingPageRenderer::getPromotionsGridColumns($viewportWidth);
        
        if ($viewportWidth >= \LandingPageRenderer::BREAKPOINT_LARGE_DESKTOP) {
            $expected = 6;
        } elseif ($viewportWidth >= \LandingPageRenderer::BREAKPOINT_DESKTOP) {
            $expected = 4;
        } elseif ($viewportWidth >= \LandingPageRenderer::BREAKPOINT_TABLET) {
            $expected = 3;
        } else {
            $expected = 2;
        }
        
        $this->assertEquals(
            $expected,
            $columns,
            "Promotions grid should have {$expected} columns at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Layout type is always one of the valid types
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider anyViewportProvider
     */
    public function testLayoutTypeIsValid(int $viewportWidth): void
    {
        $layoutType = \LandingPageRenderer::getLayoutType($viewportWidth);
        $validTypes = ['mobile', 'tablet', 'desktop', 'large-desktop'];
        
        $this->assertContains(
            $layoutType,
            $validTypes,
            "Layout type '{$layoutType}' should be one of: " . implode(', ', $validTypes)
        );
    }

    /**
     * Property Test: Responsive CSS contains all breakpoints
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     */
    public function testResponsiveCssContainsAllBreakpoints(): void
    {
        $css = \LandingPageRenderer::getResponsiveCss();
        
        // Should contain tablet breakpoint
        $this->assertStringContainsString(
            (string) \LandingPageRenderer::BREAKPOINT_TABLET . 'px',
            $css,
            "CSS should contain tablet breakpoint"
        );
        
        // Should contain desktop breakpoint
        $this->assertStringContainsString(
            (string) \LandingPageRenderer::BREAKPOINT_DESKTOP . 'px',
            $css,
            "CSS should contain desktop breakpoint"
        );
        
        // Should contain large desktop breakpoint
        $this->assertStringContainsString(
            (string) \LandingPageRenderer::BREAKPOINT_LARGE_DESKTOP . 'px',
            $css,
            "CSS should contain large desktop breakpoint"
        );
    }

    /**
     * Property Test: Responsive CSS contains media queries
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     */
    public function testResponsiveCssContainsMediaQueries(): void
    {
        $css = \LandingPageRenderer::getResponsiveCss();
        
        // Should contain @media rules
        $this->assertStringContainsString(
            '@media',
            $css,
            "CSS should contain @media rules"
        );
        
        // Should contain min-width queries
        $this->assertStringContainsString(
            'min-width',
            $css,
            "CSS should contain min-width queries"
        );
    }

    /**
     * Property Test: Mobile CTA and Header CTA are mutually exclusive
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     * 
     * @dataProvider anyViewportProvider
     */
    public function testCtaVisibilityMutuallyExclusive(int $viewportWidth): void
    {
        $showMobile = \LandingPageRenderer::shouldShowMobileCta($viewportWidth);
        $showHeader = \LandingPageRenderer::shouldShowHeaderCta($viewportWidth);
        
        // Exactly one should be true
        $this->assertNotEquals(
            $showMobile,
            $showHeader,
            "Mobile CTA and Header CTA should be mutually exclusive at {$viewportWidth}px"
        );
    }

    /**
     * Property Test: Breakpoint boundary - exactly at 768px is tablet
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     */
    public function testBreakpointBoundaryTablet(): void
    {
        // At exactly 768px should be tablet
        $this->assertEquals('tablet', \LandingPageRenderer::getLayoutType(768));
        
        // At 767px should be mobile
        $this->assertEquals('mobile', \LandingPageRenderer::getLayoutType(767));
    }

    /**
     * Property Test: Breakpoint boundary - exactly at 1024px is desktop
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     */
    public function testBreakpointBoundaryDesktop(): void
    {
        // At exactly 1024px should be desktop
        $this->assertEquals('desktop', \LandingPageRenderer::getLayoutType(1024));
        
        // At 1023px should be tablet
        $this->assertEquals('tablet', \LandingPageRenderer::getLayoutType(1023));
    }

    /**
     * Property Test: Breakpoint boundary - exactly at 1280px is large-desktop
     * 
     * **Feature: public-landing-page, Property 4: Responsive layout adaptation**
     * **Validates: Requirements 4.1, 4.2, 4.3**
     */
    public function testBreakpointBoundaryLargeDesktop(): void
    {
        // At exactly 1280px should be large-desktop
        $this->assertEquals('large-desktop', \LandingPageRenderer::getLayoutType(1280));
        
        // At 1279px should be desktop
        $this->assertEquals('desktop', \LandingPageRenderer::getLayoutType(1279));
    }
}
