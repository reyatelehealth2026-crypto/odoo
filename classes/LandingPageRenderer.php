<?php
/**
 * Landing Page Renderer
 * 
 * Extracts rendering logic from index.php for testability.
 * Used for property-based testing of landing page components.
 * 
 * Requirements: 1.2, 1.5, 2.2, 2.3, 4.1, 4.2, 4.3, 5.2
 */

class LandingPageRenderer
{
    /**
     * Default theme colors (Requirements: 1.5)
     */
    const DEFAULT_PRIMARY_COLOR = '#11B0A6';
    const DEFAULT_SECONDARY_COLOR = '#E0F7F5';
    
    /**
     * Responsive breakpoints (Requirements: 4.1, 4.2, 4.3)
     */
    const BREAKPOINT_TABLET = 768;
    const BREAKPOINT_DESKTOP = 1024;
    const BREAKPOINT_LARGE_DESKTOP = 1280;

    /**
     * Render shop name in HTML
     * 
     * @param string $shopName The shop name to render
     * @return string HTML output containing the shop name
     */
    public static function renderShopName(string $shopName): string
    {
        return '<span class="shop-name">' . htmlspecialchars($shopName) . '</span>';
    }

    /**
     * Render shop logo in HTML
     * 
     * @param string|null $shopLogo The logo URL
     * @param string $shopName The shop name for alt text
     * @return string HTML output for the logo
     */
    public static function renderShopLogo(?string $shopLogo, string $shopName): string
    {
        if ($shopLogo) {
            return '<img src="' . htmlspecialchars($shopLogo) . '" alt="' . htmlspecialchars($shopName) . '" class="logo-img">';
        }
        return '<div class="logo-placeholder"><i class="fas fa-clinic-medical"></i></div>';
    }

    /**
     * Build LIFF URL from LIFF ID
     * 
     * @param string|null $liffId The LIFF ID
     * @return string|null The complete LIFF URL or null
     */
    public static function buildLiffUrl(?string $liffId): ?string
    {
        if (empty($liffId)) {
            return null;
        }
        return "https://liff.line.me/{$liffId}";
    }

    /**
     * Render CTA button for LIFF
     * 
     * @param string|null $liffUrl The LIFF URL
     * @param string $buttonText The button text
     * @param string $buttonClass Additional CSS classes
     * @return string HTML output for the button
     */
    public static function renderLiffButton(?string $liffUrl, string $buttonText = 'เปิดแอป', string $buttonClass = 'btn-line'): string
    {
        if (!$liffUrl) {
            return '<span class="btn btn-outline" style="cursor: default;"><i class="fas fa-clock"></i> เร็วๆ นี้</span>';
        }
        return '<a href="' . htmlspecialchars($liffUrl) . '" class="btn ' . htmlspecialchars($buttonClass) . '"><i class="fab fa-line"></i> ' . htmlspecialchars($buttonText) . '</a>';
    }

    /**
     * Validate hex color format
     * 
     * @param string $color Color to validate
     * @return bool True if valid hex color
     */
    public static function isValidHexColor(string $color): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    /**
     * Normalize hex color (ensure valid format with fallback)
     * 
     * @param string $color Color to normalize
     * @param string $fallback Fallback color if invalid
     * @return string Valid hex color
     */
    public static function normalizeHexColor(string $color, string $fallback = self::DEFAULT_PRIMARY_COLOR): string
    {
        if (self::isValidHexColor($color)) {
            return $color;
        }
        return $fallback;
    }

    /**
     * Generate CSS variables for theme colors (Requirements: 1.5, 3.2)
     * 
     * @param string $primaryColor Primary color hex code
     * @param string $secondaryColor Secondary color hex code
     * @return string CSS variable declarations
     */
    public static function renderThemeColors(string $primaryColor, string $secondaryColor = ''): string
    {
        // Validate and normalize colors with fallback to defaults
        $primaryColor = self::normalizeHexColor($primaryColor, self::DEFAULT_PRIMARY_COLOR);
        $secondaryColor = self::normalizeHexColor($secondaryColor, self::DEFAULT_SECONDARY_COLOR);

        // Extract RGB components for rgba() usage
        $r = hexdec(substr($primaryColor, 1, 2));
        $g = hexdec(substr($primaryColor, 3, 2));
        $b = hexdec(substr($primaryColor, 5, 2));

        return ":root {
            --primary: {$primaryColor};
            --primary-dark: {$primaryColor}dd;
            --primary-light: {$secondaryColor};
            --primary-rgb: {$r}, {$g}, {$b};
        }";
    }

    /**
     * Extract color value from CSS variable declaration
     * 
     * @param string $css CSS string containing theme colors
     * @param string $varName Variable name to extract (e.g., '--primary')
     * @return string|null The color value or null if not found
     */
    public static function extractColorFromCss(string $css, string $varName): ?string
    {
        $pattern = '/' . preg_quote($varName, '/') . ':\s*(#[0-9A-Fa-f]{6})/';
        if (preg_match($pattern, $css, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get responsive CSS class based on viewport width (Requirements: 4.1, 4.2, 4.3)
     * 
     * @param int $viewportWidth Viewport width in pixels
     * @return string Layout type: 'mobile', 'tablet', 'desktop', or 'large-desktop'
     */
    public static function getLayoutType(int $viewportWidth): string
    {
        if ($viewportWidth >= self::BREAKPOINT_LARGE_DESKTOP) {
            return 'large-desktop';
        }
        if ($viewportWidth >= self::BREAKPOINT_DESKTOP) {
            return 'desktop';
        }
        if ($viewportWidth >= self::BREAKPOINT_TABLET) {
            return 'tablet';
        }
        return 'mobile';
    }

    /**
     * Check if mobile CTA should be visible based on viewport (Requirements: 2.4)
     * 
     * @param int $viewportWidth Viewport width in pixels
     * @return bool True if mobile CTA should be visible
     */
    public static function shouldShowMobileCta(int $viewportWidth): bool
    {
        return $viewportWidth < self::BREAKPOINT_TABLET;
    }

    /**
     * Check if header CTA should be visible based on viewport
     * 
     * @param int $viewportWidth Viewport width in pixels
     * @return bool True if header CTA should be visible
     */
    public static function shouldShowHeaderCta(int $viewportWidth): bool
    {
        return $viewportWidth >= self::BREAKPOINT_TABLET;
    }

    /**
     * Get grid columns for services based on viewport (Requirements: 4.1, 4.2, 4.3)
     * 
     * @param int $viewportWidth Viewport width in pixels
     * @return int Number of columns
     */
    public static function getServicesGridColumns(int $viewportWidth): int
    {
        if ($viewportWidth >= self::BREAKPOINT_TABLET) {
            return 3;
        }
        return 1;
    }

    /**
     * Get grid columns for promotions based on viewport (Requirements: 4.1, 4.2, 4.3)
     * 
     * @param int $viewportWidth Viewport width in pixels
     * @return int Number of columns
     */
    public static function getPromotionsGridColumns(int $viewportWidth): int
    {
        if ($viewportWidth >= self::BREAKPOINT_LARGE_DESKTOP) {
            return 6;
        }
        if ($viewportWidth >= self::BREAKPOINT_DESKTOP) {
            return 4;
        }
        if ($viewportWidth >= self::BREAKPOINT_TABLET) {
            return 3;
        }
        return 2;
    }

    /**
     * Check if promotions section should be displayed (Requirements: 5.2)
     * 
     * @param array $promotions Array of promotions
     * @return bool True if section should be visible
     */
    public static function shouldShowPromotions(array $promotions): bool
    {
        return !empty($promotions);
    }

    /**
     * Render promotions section
     * 
     * @param array $promotions Array of promotion data
     * @param string|null $liffUrl Base LIFF URL
     * @return string HTML output or empty string if no promotions
     */
    public static function renderPromotionsSection(array $promotions, ?string $liffUrl): string
    {
        if (empty($promotions)) {
            return '';
        }

        $html = '<section class="promotions-section" id="promotions">';
        $html .= '<div class="container">';
        $html .= '<div class="section-title"><h2>สินค้าแนะนำ</h2><p>สินค้าคุณภาพ ราคาพิเศษ</p></div>';
        $html .= '<div class="promotions-grid">';

        foreach ($promotions as $product) {
            $productUrl = $liffUrl ? htmlspecialchars($liffUrl) . '#/product/' . $product['id'] : '#';
            $html .= '<a href="' . $productUrl . '" class="promo-card">';
            $html .= '<div class="promo-info">';
            $html .= '<div class="promo-name">' . htmlspecialchars($product['name'] ?? '') . '</div>';
            $html .= '<div class="promo-price">฿' . number_format($product['price'] ?? 0, 0) . '</div>';
            $html .= '</div></a>';
        }

        $html .= '</div></div></section>';
        return $html;
    }

    /**
     * Render hero section
     * 
     * @param string $shopName Shop name
     * @param string $shopDescription Shop description
     * @param string|null $liffUrl LIFF URL
     * @return string HTML output
     */
    public static function renderHeroSection(string $shopName, string $shopDescription, ?string $liffUrl): string
    {
        $html = '<section class="hero-section">';
        $html .= '<div class="container"><div class="hero-content">';
        $html .= '<h1 class="hero-title">' . htmlspecialchars($shopName) . '</h1>';
        $html .= '<p class="hero-subtitle">' . htmlspecialchars($shopDescription) . '</p>';
        $html .= '<div class="hero-cta">';
        $html .= self::renderLiffButton($liffUrl, 'เริ่มใช้งานเลย', 'btn-primary');
        $html .= '</div></div></div></section>';
        return $html;
    }

    /**
     * Generate responsive CSS for the landing page (Requirements: 4.1, 4.2, 4.3)
     * 
     * @return string Complete responsive CSS
     */
    public static function getResponsiveCss(): string
    {
        return '
        /* Mobile-first base styles */
        .services-grid { grid-template-columns: 1fr; }
        .promotions-grid { grid-template-columns: repeat(2, 1fr); }
        .contact-grid { grid-template-columns: 1fr; }
        .mobile-cta { display: flex; }
        .header-cta { display: none; }
        body { padding-bottom: 80px; }
        
        /* Tablet and up (' . self::BREAKPOINT_TABLET . 'px+) */
        @media (min-width: ' . self::BREAKPOINT_TABLET . 'px) {
            .services-grid { grid-template-columns: repeat(3, 1fr); }
            .promotions-grid { grid-template-columns: repeat(3, 1fr); }
            .contact-grid { grid-template-columns: repeat(2, 1fr); }
            .mobile-cta { display: none; }
            .header-cta { display: block; }
            body { padding-bottom: 0; }
        }
        
        /* Desktop (' . self::BREAKPOINT_DESKTOP . 'px+) */
        @media (min-width: ' . self::BREAKPOINT_DESKTOP . 'px) {
            .promotions-grid { grid-template-columns: repeat(4, 1fr); }
            .contact-grid { grid-template-columns: repeat(4, 1fr); }
        }
        
        /* Large Desktop (' . self::BREAKPOINT_LARGE_DESKTOP . 'px+) */
        @media (min-width: ' . self::BREAKPOINT_LARGE_DESKTOP . 'px) {
            .promotions-grid { grid-template-columns: repeat(6, 1fr); }
        }
        ';
    }
}
