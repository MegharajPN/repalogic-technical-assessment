<?php

declare(strict_types=1);

namespace Repalogic\Policy;

use InvalidArgumentException;

final class TenantAccessGuard
{
    public const STATE_ALLOWED        = 'ALLOWED';
    public const STATE_BLOCKED_STATUS = 'BLOCKED_STATUS';
    public const STATE_BLOCKED_USAGE  = 'BLOCKED_USAGE';

    public const TIER_STANDARD   = 'Standard';
    public const TIER_PREMIUM    = 'Premium';
    public const TIER_ENTERPRISE = 'Enterprise';

    public const STATUS_ACTIVE    = 'Active';
    public const STATUS_OVERDUE   = 'Overdue';
    public const STATUS_SUSPENDED = 'Suspended';

    private const GRACE_BUFFER_MULTIPLIER = 1.10;
    private const PRECISION_DECIMALS      = 4;

    public function evaluate(string $tier, float $currentUsage, float $hardCap, string $status): string
    {
        $this->validateInputs($tier, $currentUsage, $hardCap, $status);

        $normalizedUsage = round($currentUsage, self::PRECISION_DECIMALS);
        $normalizedCap   = round($hardCap, self::PRECISION_DECIMALS);

        // Rule 1: Suspended accounts are immediately blocked across all tiers[cite: 1].
        if ($status === self::STATUS_SUSPENDED) {
            return self::STATE_BLOCKED_STATUS;
        }

        // Rule 2: Standard Tier Rules[cite: 1]
        if ($tier === self::TIER_STANDARD) {
            if ($status === self::STATUS_OVERDUE) {
                return self::STATE_BLOCKED_STATUS;
            }

            if ($normalizedUsage > $normalizedCap) {
                return self::STATE_BLOCKED_USAGE;
            }

            return self::STATE_ALLOWED;
        }

        // Rule 3: Premium & Enterprise Tiers (Overdue 10% Grace Buffer)[cite: 1]
        if ($status === self::STATUS_OVERDUE) {
            // Round buffer ceiling to prevent IEEE-754 floating-point drift (e.g. 1000 * 1.10 = 1100.0000000000002)
            $maxAllowedWithBuffer = round($normalizedCap * self::GRACE_BUFFER_MULTIPLIER, self::PRECISION_DECIMALS);

            if ($normalizedUsage > $maxAllowedWithBuffer) {
                return self::STATE_BLOCKED_USAGE;
            }

            return self::STATE_ALLOWED;
        }

        // Active Premium & Enterprise accounts[cite: 1]
        if ($normalizedUsage > $normalizedCap) {
            return self::STATE_BLOCKED_USAGE;
        }

        return self::STATE_ALLOWED;
    }

    private function validateInputs(string $tier, float $currentUsage, float $hardCap, string $status): void
    {
        $validTiers    = [self::TIER_STANDARD, self::TIER_PREMIUM, self::TIER_ENTERPRISE];
        $validStatuses = [self::STATUS_ACTIVE, self::STATUS_OVERDUE, self::STATUS_SUSPENDED];

        if (!in_array($tier, $validTiers, true)) {
            throw new InvalidArgumentException("Invalid subscription tier: {$tier}");
        }

        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid invoice status: {$status}");
        }

        // Block NaN and INF attacks
        if (!is_finite($currentUsage) || !is_finite($hardCap)) {
            throw new InvalidArgumentException("Usage and hard cap must be finite numbers.");
        }

        if ($currentUsage < 0.0 || $hardCap <= 0.0) {
            throw new InvalidArgumentException("Usage must be non-negative and hard cap must be greater than zero.");
        }
    }
}