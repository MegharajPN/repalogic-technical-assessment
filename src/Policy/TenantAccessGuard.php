<?php

declare(strict_types=1);

namespace Repalogic\Policy;

use InvalidArgumentException;

class TenantAccessGuard
{
    // Return States
    public const STATE_ALLOWED        = 'ALLOWED';
    public const STATE_BLOCKED_STATUS = 'BLOCKED_STATUS';
    public const STATE_BLOCKED_USAGE  = 'BLOCKED_USAGE';

    // Subscription Tiers
    public const TIER_STANDARD   = 'Standard';
    public const TIER_PREMIUM    = 'Premium';
    public const TIER_ENTERPRISE = 'Enterprise';

    // Invoice Payment Statuses
    public const STATUS_ACTIVE    = 'Active';
    public const STATUS_OVERDUE   = 'Overdue';
    public const STATUS_SUSPENDED = 'Suspended';

    // Grace period buffer (10% above cap)
    private const GRACE_BUFFER_MULTIPLIER = 1.10;

    /**
     * Evaluates whether a tenant is allowed to execute a high-resource background task.
     *
     * @param string $tier         Subscription Tier (Standard, Premium, Enterprise)
     * @param float  $currentUsage Current monthly usage volume
     * @param float  $hardCap      Tier's monthly hard cap
     * @param string $status       Invoice payment status (Active, Overdue, Suspended)
     * @return string              One of: ALLOWED, BLOCKED_STATUS, BLOCKED_USAGE
     */
    public function evaluate(string $tier, float $currentUsage, float $hardCap, string $status): string
    {
        $this->validateInputs($tier, $currentUsage, $hardCap, $status);

        // Rule 1: Suspended accounts are immediately blocked across all tiers.
        if ($status === self::STATUS_SUSPENDED) {
            return self::STATE_BLOCKED_STATUS;
        }

        // Rule 2: Standard Tier Rules
        if ($tier === self::TIER_STANDARD) {
            // Strictly blocked if invoice is Overdue
            if ($status === self::STATUS_OVERDUE) {
                return self::STATE_BLOCKED_STATUS;
            }

            // Strictly blocked if usage exceeds hard cap
            if ($currentUsage > $hardCap) {
                return self::STATE_BLOCKED_USAGE;
            }

            return self::STATE_ALLOWED;
        }

        // Rule 3: Premium & Enterprise Tiers
        // If Overdue, they get a 10% buffer grace period.
        if ($status === self::STATUS_OVERDUE) {
            $maxAllowedWithBuffer = $hardCap * self::GRACE_BUFFER_MULTIPLIER;

            if ($currentUsage > $maxAllowedWithBuffer) {
                return self::STATE_BLOCKED_USAGE;
            }

            return self::STATE_ALLOWED;
        }

        // For Active Premium & Enterprise accounts, evaluate against standard hard cap
        if ($currentUsage > $hardCap) {
            return self::STATE_BLOCKED_USAGE;
        }

        return self::STATE_ALLOWED;
    }

    private function validateInputs(string $tier, float $currentUsage, float $hardCap, string $status): void
    {
        $validTiers = [self::TIER_STANDARD, self::TIER_PREMIUM, self::TIER_ENTERPRISE];
        $validStatuses = [self::STATUS_ACTIVE, self::STATUS_OVERDUE, self::STATUS_SUSPENDED];

        if (!in_array($tier, $validTiers, true)) {
            throw new InvalidArgumentException("Invalid subscription tier: {$tier}");
        }

        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid invoice status: {$status}");
        }

        if ($currentUsage < 0 || $hardCap <= 0) {
            throw new InvalidArgumentException("Usage must be non-negative and hard cap must be greater than zero.");
        }
    }
}