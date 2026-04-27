<?php
/**
 * CustomerChurnDashboardTest — structural & integration tests for Phase 3
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.5, §7
 * Called by: PHPUnit runner via `composer test` (auto-discovered by path)
 *
 * Test strategy: source-scan assertions (no live DB or HTTP needed).
 * This allows the tests to run before the migration is applied and before
 * a web server is available — matching the project's property-based test idiom.
 *
 * Tests:
 *   T1  – API contains admin permission gate (401 on auth failure)
 *   T2  – kpi action initialises all 6 segment counts
 *   T3  – kpi response envelope has required keys
 *   T4  – watchlist action uses LIMIT/OFFSET pagination
 *   T5  – watchlist pagination params are clamped to sane bounds
 *   T6  – watchlist segment filter uses named PDO param (no injection risk)
 *   T7  – watchlist validates segment against allow-list
 *   T8  – invalid segment falls back to 'all'
 *   T9  – dashboard page exists with admin gate (403)
 *   T10 – dashboard page renders soft-launch banner
 *   T11 – dashboard page renders all 6 KPI element IDs
 *   T12 – JS file exists with 60-second polling interval
 *   T13 – JS uses textContent not innerHTML for user data (XSS guard)
 *   T14 – API endpoint contains no DML statements (read-only)
 *   T15 – cohort action returns trend_30d from customer_segment_history
 *   T16 – health action returns gemini quota + soft_launch + system_enabled
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;

final class CustomerChurnDashboardTest extends TestCase
{
    private string $rootDir;
    private string $apiFile;
    private string $dashFile;
    private string $jsFile;

    // ── Setup ──────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->rootDir  = dirname(__DIR__, 2); // two levels up from tests/CRM/
        $this->apiFile  = $this->rootDir . '/api/churn-dashboard-data.php';
        $this->dashFile = $this->rootDir . '/customer-churn.php';
        $this->jsFile   = $this->rootDir . '/assets/js/customer-churn.js';

        $this->assertFileExists($this->apiFile,  'api/churn-dashboard-data.php must exist (Phase 3)');
        $this->assertFileExists($this->dashFile, 'customer-churn.php must exist (Phase 3)');
        $this->assertFileExists($this->jsFile,   'assets/js/customer-churn.js must exist (Phase 3)');
    }

    protected function tearDown(): void
    {
        // Ensure no session leakage between tests.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function readSource(string $path): string
    {
        $src = file_get_contents($path);
        $this->assertNotFalse($src, "Cannot read file: $path");
        return (string) $src;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T1 — API permission gate: 401 on auth failure
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testApiContainsAdminPermissionGateWith401(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            'isAdmin()',
            $src,
            'API must call isAdmin() as permission gate'
        );
        $this->assertStringContainsString(
            'churnApiError(401',
            $src,
            'API must call churnApiError(401, ...) when auth fails'
        );
        $this->assertStringContainsString(
            'auth_check.php',
            $src,
            'API must include auth_check.php to load isAdmin()'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T2 — kpi action: all 6 segment keys initialised
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testKpiActionInitialisesAllSixSegments(): void
    {
        $src = $this->readSource($this->apiFile);

        $segments = ['Champion', 'Watchlist', 'At-Risk', 'Lost', 'Churned', 'Hibernating'];
        foreach ($segments as $seg) {
            $this->assertStringContainsString(
                "'$seg'",
                $src,
                "kpi action must initialise segment '$seg'"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T3 — kpi action: response envelope keys
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testKpiActionResponseEnvelopeHasRequiredKeys(): void
    {
        $src = $this->readSource($this->apiFile);

        foreach (["'segments'", "'total_eligible'", "'last_computed_at'"] as $key) {
            $this->assertStringContainsString(
                $key,
                $src,
                "kpi churnApiOk payload must include $key"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T4 — watchlist action: LIMIT / OFFSET pagination
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testWatchlistActionUsesPaginationLimitOffset(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString('LIMIT',    $src, 'watchlist must use SQL LIMIT');
        $this->assertStringContainsString('OFFSET',   $src, 'watchlist must use SQL OFFSET');
        $this->assertStringContainsString("'per_page'", $src, "watchlist response must include 'per_page'");
        $this->assertStringContainsString("'page'",     $src, "watchlist response must include 'page'");
        $this->assertStringContainsString("'total'",    $src, "watchlist response must include 'total' count");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T5 — watchlist action: pagination params clamped
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testWatchlistPaginationParamsClamped(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            'min(100',
            $src,
            'per_page must be capped at 100 via min(100, ...)'
        );
        $this->assertStringContainsString(
            'max(10',
            $src,
            'per_page must have floor of 10 via max(10, ...)'
        );
        $this->assertStringContainsString(
            'max(1,',
            $src,
            'page must have floor of 1 via max(1, ...)'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T6 — watchlist action: named PDO param prevents SQL injection
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testWatchlistSegmentFilterUsesNamedPdoParam(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            ':seg',
            $src,
            'watchlist must bind segment via named PDO param :seg'
        );
        $this->assertStringContainsString(
            'bindValue',
            $src,
            'watchlist must call bindValue() for parameterised query'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T7 — watchlist action: allow-list validation on segment param
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testWatchlistSegmentFilterValidatedAgainstAllowList(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            'allowedSegments',
            $src,
            'watchlist must define $allowedSegments allow-list'
        );
        $this->assertStringContainsString(
            'in_array($segFilter, $allowedSegments, true)',
            $src,
            'watchlist must validate $segFilter against $allowedSegments with strict comparison'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T8 — watchlist action: invalid segment coerced to 'all'
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testWatchlistInvalidSegmentCoercedToAll(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            "\$segFilter = 'all'",
            $src,
            "watchlist must coerce invalid segment to 'all'"
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T9 — dashboard page: exists + admin gate with 403
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testDashboardPageExistsWithAdminGateAnd403(): void
    {
        $src = $this->readSource($this->dashFile);

        $this->assertStringContainsString(
            'isAdmin()',
            $src,
            'customer-churn.php must gate on isAdmin()'
        );
        $this->assertStringContainsString(
            '403',
            $src,
            'customer-churn.php must send HTTP 403 for non-admin'
        );
        $this->assertStringContainsString(
            "require_once __DIR__ . '/includes/header.php'",
            $src,
            'customer-churn.php must include header.php per CLAUDE.md convention'
        );
        $this->assertStringContainsString(
            "require_once __DIR__ . '/includes/footer.php'",
            $src,
            'customer-churn.php must include footer.php per CLAUDE.md convention'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T10 — dashboard page: soft-launch banner
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testDashboardPageRendersSoftLaunchBanner(): void
    {
        $src = $this->readSource($this->dashFile);

        $this->assertStringContainsString(
            'soft_launch',
            $src,
            'Dashboard must read soft_launch from churn_settings'
        );
        // Thai banner text required by spec
        $this->assertStringContainsString(
            'soft-launch',
            $src,
            'Dashboard must render soft-launch UI banner'
        );
        $this->assertStringContainsString(
            'notification',
            strtolower($src),
            'Soft-launch banner must mention notifications'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T11 — dashboard page: all 6 KPI element IDs
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testDashboardPageRendersAllSixKpiCardIds(): void
    {
        $src = $this->readSource($this->dashFile);

        // Dashboard generates IDs via PHP loop using $segId derived from each
        // segment label (lowercased, spaces/hyphens → underscore). We assert
        // (a) the id template exists, and (b) every expected segment label is
        // present in the segments definition map so the loop visits each one.
        $this->assertStringContainsString(
            'id="kpi-<?= htmlspecialchars($segId',
            $src,
            "Dashboard must emit element id='kpi-<segId>' inside the segments loop"
        );

        $expectedSegmentLabels = ['Champion', 'Watchlist', 'At-Risk', 'Lost', 'Churned', 'Hibernating'];
        foreach ($expectedSegmentLabels as $label) {
            $this->assertStringContainsString(
                "'$label'",
                $src,
                "Segments map must include '$label' so the kpi loop renders an id for it"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T12 — JS file: 60-second polling interval gated by document.hidden
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testJsFileHas60SecondPollingGatedByDocumentHidden(): void
    {
        $src = $this->readSource($this->jsFile);

        $this->assertStringContainsString(
            '60_000',
            $src,
            'JS must define POLL_INTERVAL = 60_000 ms'
        );
        $this->assertStringContainsString(
            'document.hidden',
            $src,
            'JS polling loop must check document.hidden before firing'
        );
        $this->assertStringContainsString(
            'setInterval',
            $src,
            'JS must use setInterval for polling'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T13 — JS file: textContent used, innerHTML not assigned for user data
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testJsFileUsesTextContentNotInnerHtmlForUserData(): void
    {
        $src = $this->readSource($this->jsFile);

        $this->assertStringContainsString(
            'textContent',
            $src,
            'JS must use textContent for DOM updates (XSS-safe per CLAUDE.md)'
        );
        $this->assertStringNotContainsString(
            '.innerHTML =',
            $src,
            'JS must not assign .innerHTML with server-derived data'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T14 — API endpoint: read-only (no DML)
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testApiEndpointContainsNoDmlStatements(): void
    {
        $srcUpper = strtoupper($this->readSource($this->apiFile));

        foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP TABLE', 'TRUNCATE'] as $dml) {
            $this->assertStringNotContainsString(
                $dml,
                $srcUpper,
                "api/churn-dashboard-data.php must not contain '$dml' (read-only spec)"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T15 — cohort action: trend_30d from customer_segment_history
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testCohortActionReturnsTrend30dFromSegmentHistory(): void
    {
        $src = $this->readSource($this->apiFile);

        $this->assertStringContainsString(
            'trend_30d',
            $src,
            "cohort churnApiOk payload must include 'trend_30d'"
        );
        $this->assertStringContainsString(
            'customer_segment_history',
            $src,
            'cohort action must query customer_segment_history for 30-day trend'
        );
        $this->assertStringContainsString(
            'INTERVAL 30 DAY',
            $src,
            'cohort trend must filter changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T16 — health action: gemini quota + soft_launch + system_enabled
    // ══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function testHealthActionReturnsGeminiQuotaSoftLaunchAndSystemEnabled(): void
    {
        $src = $this->readSource($this->apiFile);

        foreach (["'gemini_calls_today'", "'gemini_daily_cap'", "'soft_launch'", "'system_enabled'"] as $key) {
            $this->assertStringContainsString(
                $key,
                $src,
                "health churnApiOk payload must include $key"
            );
        }
        $this->assertStringContainsString(
            'churn_settings',
            $src,
            'health action must query churn_settings table'
        );
    }
}
