<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use InvalidArgumentException;

final class Account
{
    public const TIER_STANDARD = 'Standard';
    public const TIER_VIP      = 'VIP';

    private string $accountId;
    private string $baseCurrency;
    private float $balance;
    private string $tier;

    /** @var int[] Timestamps (in ms) of successful deductions */
    private array $deductionTimestamps = [];

    private ?int $lastTransactionTimestampMs = null;

    private bool $overdraftRevoked = false;

    public function __construct(
        string $accountId,
        string $baseCurrency = 'USD',
        float $initialBalance = 0.0,
        string $tier = self::TIER_STANDARD
    ) {
        if (trim($accountId) === '') {
            throw new InvalidArgumentException("Account ID cannot be empty.");
        }

        if (trim($baseCurrency) === '') {
            throw new InvalidArgumentException("Base currency cannot be empty.");
        }

        if (!in_array($tier, [self::TIER_STANDARD, self::TIER_VIP], true)) {
            throw new InvalidArgumentException("Invalid account tier: {$tier}");
        }

        if (!is_finite($initialBalance)) {
            throw new InvalidArgumentException("Initial balance must be a finite number.");
        }

        if ($tier === self::TIER_STANDARD && $initialBalance < 0.0) {
            throw new InvalidArgumentException("Standard accounts cannot be initialized with a negative balance.");
        }

        $this->accountId    = $accountId;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
        $this->balance      = round($initialBalance, 4);
        $this->tier         = $tier;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function setBalance(float $balance): void
    {
        if (!is_finite($balance)) {
            throw new InvalidArgumentException("Balance must be a finite number.");
        }
        $this->balance = round($balance, 4);
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function isVip(): bool
    {
        return $this->tier === self::TIER_VIP;
    }

    public function isOverdraftRevoked(): bool
    {
        return $this->overdraftRevoked;
    }

    public function revokeOverdraft(): void
    {
        $this->overdraftRevoked = true;
    }

    public function getLastTransactionTimestampMs(): ?int
    {
        return $this->lastTransactionTimestampMs;
    }

    public function recordTransactionTimestamp(int $timestampMs, bool $isDeduction): void
    {
        $this->lastTransactionTimestampMs = $timestampMs;
        if ($isDeduction) {
            $this->deductionTimestamps[] = $timestampMs;
        }
    }

    /**
     * Counts how many deductions occurred within the 60-second (60,000 ms) rolling window[cite: 2, 3].
     */
    public function getDeductionCountInWindow(int $currentTimestampMs, int $windowMs = 60000): int
    {
        $windowStart = $currentTimestampMs - $windowMs;
        $count = 0;

        foreach ($this->deductionTimestamps as $ts) {
            if ($ts >= $windowStart && $ts <= $currentTimestampMs) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Captures the internal state of this account instance for atomic rollback[cite: 4].
     *
     * @return array{balance: float, deductionTimestamps: int[], lastTransactionTimestampMs: ?int, overdraftRevoked: bool}
     */
    public function createSnapshot(): array
    {
        return [
            'balance'                    => $this->balance,
            'deductionTimestamps'        => $this->deductionTimestamps,
            'lastTransactionTimestampMs' => $this->lastTransactionTimestampMs,
            'overdraftRevoked'           => $this->overdraftRevoked,
        ];
    }

    /**
     * Restores the internal state in-place on the same object reference[cite: 4].
     *
     * @param array{balance: float, deductionTimestamps: int[], lastTransactionTimestampMs: ?int, overdraftRevoked: bool} $snapshot
     */
    public function restoreSnapshot(array $snapshot): void
    {
        $this->balance                    = $snapshot['balance'];
        $this->deductionTimestamps        = $snapshot['deductionTimestamps'];
        $this->lastTransactionTimestampMs = $snapshot['lastTransactionTimestampMs'];
        $this->overdraftRevoked           = $snapshot['overdraftRevoked'];
    }
}