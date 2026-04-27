<?php
/**
 * CycleResult — value object returned by RFMCalculator::computeAvgCycle()
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.1
 */

declare(strict_types=1);

namespace Classes\CRM;

final class CycleResult
{
    public function __construct(
        public readonly ?float $cycleDays,
        public readonly string $confidence
    ) {
    }
}
