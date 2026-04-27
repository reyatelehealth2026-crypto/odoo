<?php

/**
 * Unit Tests: Churn Settings Validation Rules
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 5-6
 *
 * Validates the same rules enforced by api/churn-settings-update.php.
 * Tests are pure PHP — no DB required. A static validate() helper mirrors
 * the endpoint logic so rules can be tested in isolation from the HTTP layer.
 *
 * Test cases:
 *   V1:  rejects threshold_at_risk < 1.0
 *   V2:  rejects threshold_at_risk > 5.0
 *   V3:  rejects threshold_lost <= threshold_at_risk (less than)
 *   V3b: rejects threshold_lost == threshold_at_risk (equal)
 *   V4:  rejects threshold_churned <= threshold_lost
 *   V5:  rejects non-JSON notification_recipients
 *   V5b: rejects JSON object (not array) notification_recipients
 *   V6:  rejects JSON array with non-integer elements
 *   V7:  valid payload passes all checks
 *   V7b: empty recipients array is valid
 *   V8:  strictly increasing thresholds at minimum values are valid
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;

final class ChurnSettingsValidationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Inline validator — mirrors api/churn-settings-update.php validation logic.
    // Returns null on success, or a string error message on the first failure.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private static function validate(array $input): ?string
    {
        $thresholdAtRisk  = (float) ($input['threshold_at_risk']               ?? 1.50);
        $thresholdLost    = (float) ($input['threshold_lost']                  ?? 2.00);
        $thresholdChurned = (float) ($input['threshold_churned']               ?? 3.00);
        $hysteresisBuffer = (float) ($input['hysteresis_buffer']               ?? 0.20);
        $highValueTHB     = (float) ($input['high_value_threshold_thb']        ?? 100000.0);
        $topPercent       = (int)   ($input['high_value_top_percent']          ?? 20);
        $geminiCap        = (int)   ($input['gemini_daily_cap_calls']          ?? 200);
        $freqCap          = (int)   ($input['notification_frequency_cap_days'] ?? 14);
        $recipients       = (string)($input['notification_recipients']         ?? '[]');

        if ($thresholdAtRisk < 1.0 || $thresholdAtRisk > 5.0) {
            return 'threshold_at_risk ต้องอยู่ระหว่าง 1.0 ถึง 5.0';
        }
        if ($thresholdLost <= $thresholdAtRisk) {
            return 'threshold_lost ต้องมากกว่า threshold_at_risk';
        }
        if ($thresholdLost < 1.0 || $thresholdLost > 5.0) {
            return 'threshold_lost ต้องอยู่ระหว่าง 1.0 ถึง 5.0';
        }
        if ($thresholdChurned <= $thresholdLost) {
            return 'threshold_churned ต้องมากกว่า threshold_lost';
        }
        if ($thresholdChurned < 1.0 || $thresholdChurned > 10.0) {
            return 'threshold_churned ต้องอยู่ระหว่าง 1.0 ถึง 10.0';
        }
        if ($hysteresisBuffer < 0.0 || $hysteresisBuffer > 1.0) {
            return 'hysteresis_buffer ต้องอยู่ระหว่าง 0.0 ถึง 1.0';
        }
        if ($topPercent < 1 || $topPercent > 50) {
            return 'high_value_top_percent ต้องอยู่ระหว่าง 1 ถึง 50';
        }
        if ($highValueTHB < 0.0) {
            return 'high_value_threshold_thb ต้องไม่ติดลบ';
        }
        if ($geminiCap < 0 || $geminiCap > 10000) {
            return 'gemini_daily_cap_calls ต้องอยู่ระหว่าง 0 ถึง 10000';
        }
        if ($freqCap < 1 || $freqCap > 365) {
            return 'notification_frequency_cap_days ต้องอยู่ระหว่าง 1 ถึง 365';
        }

        $decoded = json_decode($recipients, true);
        if (
            json_last_error() !== JSON_ERROR_NONE
            || !is_array($decoded)
            || !array_is_list($decoded)
        ) {
            return 'notification_recipients ต้องเป็น JSON array ที่ถูกต้อง เช่น [1, 3, 7]';
        }
        foreach ($decoded as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit((string) $item))) {
                return 'notification_recipients ต้องมีเฉพาะ admin ID (ตัวเลข) เท่านั้น';
            }
        }

        return null;
    }

    /** Returns a fully valid payload. Override individual keys per test. */
    private static function validPayload(): array
    {
        return [
            'threshold_at_risk'               => 1.50,
            'threshold_lost'                  => 2.00,
            'threshold_churned'               => 3.00,
            'hysteresis_buffer'               => 0.20,
            'high_value_threshold_thb'        => 100000.0,
            'high_value_top_percent'          => 20,
            'gemini_daily_cap_calls'          => 200,
            'notification_frequency_cap_days' => 14,
            'notification_recipients'         => '[1, 3, 7]',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // threshold_at_risk bounds
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * V1: threshold_at_risk below minimum (1.0) must be rejected.
     */
    public function testRejectsThresholdAtRiskBelowMinimum(): void
    {
        $payload = self::validPayload();
        $payload['threshold_at_risk'] = 0.5;

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail for threshold_at_risk < 1.0');
        $this->assertStringContainsString('threshold_at_risk', $error);
    }

    /**
     * V2: threshold_at_risk above maximum (5.0) must be rejected.
     */
    public function testRejectsThresholdAtRiskAboveMaximum(): void
    {
        $payload = self::validPayload();
        $payload['threshold_at_risk']  = 5.5;
        $payload['threshold_lost']     = 6.0;  // keep ordering valid
        $payload['threshold_churned']  = 7.0;

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail for threshold_at_risk > 5.0');
        $this->assertStringContainsString('threshold_at_risk', $error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Threshold ordering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * V3: threshold_lost less than threshold_at_risk must be rejected.
     */
    public function testRejectsThresholdLostLessThanAtRisk(): void
    {
        $payload = self::validPayload();
        $payload['threshold_at_risk'] = 2.00;
        $payload['threshold_lost']    = 1.50;

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail when threshold_lost < threshold_at_risk');
        $this->assertStringContainsString('threshold_lost', $error);
    }

    /**
     * V3b: threshold_lost equal to threshold_at_risk must also be rejected
     * (boundaries must be strictly increasing per spec §3).
     */
    public function testRejectsThresholdLostEqualToAtRisk(): void
    {
        $payload = self::validPayload();
        $payload['threshold_at_risk'] = 1.50;
        $payload['threshold_lost']    = 1.50;

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail when threshold_lost == threshold_at_risk');
        $this->assertStringContainsString('threshold_lost', $error);
    }

    /**
     * V4: threshold_churned less than or equal to threshold_lost must be rejected.
     */
    public function testRejectsThresholdChurnedNotGreaterThanLost(): void
    {
        $payload = self::validPayload();
        $payload['threshold_lost']    = 2.50;
        $payload['threshold_churned'] = 2.00;

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail when threshold_churned <= threshold_lost');
        $this->assertStringContainsString('threshold_churned', $error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // notification_recipients
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * V5: plain non-JSON string must be rejected.
     */
    public function testRejectsNotificationRecipientsNonJson(): void
    {
        $payload = self::validPayload();
        $payload['notification_recipients'] = 'not-json-at-all';

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail for non-JSON notification_recipients');
        $this->assertStringContainsString('notification_recipients', $error);
    }

    /**
     * V5b: JSON object (not array) must be rejected.
     */
    public function testRejectsNotificationRecipientsJsonObject(): void
    {
        $payload = self::validPayload();
        $payload['notification_recipients'] = '{"admin": 1}';

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail for JSON object — only arrays are valid');
        $this->assertStringContainsString('notification_recipients', $error);
    }

    /**
     * V6: JSON array containing string elements must be rejected.
     * Recipients must be integer admin_ids only.
     */
    public function testRejectsNotificationRecipientsWithStringElements(): void
    {
        $payload = self::validPayload();
        $payload['notification_recipients'] = '["admin_one", "admin_two"]';

        $error = self::validate($payload);

        $this->assertNotNull($error, 'Must fail for non-integer elements in recipients array');
        $this->assertStringContainsString('notification_recipients', $error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Valid payloads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * V7: canonical valid payload must produce no error.
     */
    public function testValidPayloadPassesAllChecks(): void
    {
        $this->assertNull(
            self::validate(self::validPayload()),
            'Valid payload must produce no validation error'
        );
    }

    /**
     * V7b: empty recipients array [] is valid — no managers configured yet.
     */
    public function testEmptyRecipientsArrayIsValid(): void
    {
        $payload = self::validPayload();
        $payload['notification_recipients'] = '[]';

        $this->assertNull(self::validate($payload));
    }

    /**
     * V8: strictly increasing thresholds at boundary minimum values must be valid.
     */
    public function testThresholdOrderingIsStrictlyEnforcedAtMinimum(): void
    {
        $payload = self::validPayload();
        $payload['threshold_at_risk']  = 1.00;
        $payload['threshold_lost']     = 1.01;
        $payload['threshold_churned']  = 1.02;

        $this->assertNull(
            self::validate($payload),
            'Strictly increasing thresholds at minimum values must be valid'
        );
    }
}
