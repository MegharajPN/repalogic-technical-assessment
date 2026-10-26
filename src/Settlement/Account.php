<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

class Account
{
    public const TIER_STANDARD = 'Standard';
    public const TIER_VIP      = 'VIP';

    private string $accountId;
    private string $baseCurrency;
    private float $balance;
    private string $tier;

    /** @var int[] Timestamps (in ms) of successful deductions */
    private array $deductionTimestamps = [];

    private bool $overdraftRevoked = false;

    public function __construct(
        string $accountId,
        string $baseCurrency = 'USD',
        float $initialBalance = 0.0,
        string $tier = self::TIER_STANDARD
    ) {
        $this->accountId    = $accountId;
        $this->baseCurrency = $baseCurrency;
        $this->balance      = $initialBalance;
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

    public function recordDeductionTimestamp(int $timestampMs): void
    {
        $this->deductionTimestamps[] = $timestampMs;
    }

    /**
     * Counts how many deductions occurred within the 60-second (60,000 ms) rolling window
     * up to and including the current transaction timestamp.
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
     * Captures the internal state of this account instance for atomic rollback.
     *
     * @return array{balance: float, deductionTimestamps: int[], overdraftRevoked: bool}
     */
    public function createSnapshot(): array
    {
        return [
            'balance'             => $this->balance,
            'deductionTimestamps' => $this->deductionTimestamps,
            'overdraftRevoked'    => $this->overdraftRevoked,
        ];
    }

    /**
     * Restores the internal state in-place on the same object reference.
     *
     * @param array{balance: float, deductionTimestamps: int[], overdraftRevoked: bool} $snapshot
     */
    public function restoreSnapshot(array $snapshot): void
    {
        $this->balance             = $snapshot['balance'];
        $this->deductionTimestamps = $snapshot['deductionTimestamps'];
        $this->overdraftRevoked    = $snapshot['overdraftRevoked'];
    }
}