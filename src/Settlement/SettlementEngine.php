<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use InvalidArgumentException;
use Repalogic\Settlement\Exceptions\CircuitBreakerException;
use Repalogic\Settlement\Exceptions\InsufficientFundsException;
use Throwable;

class SettlementEngine
{
    private const VIP_OVERDRAFT_LIMIT_USD    = 500.00;
    private const VELOCITY_MAX_DEDUCTIONS    = 3;
    private const VELOCITY_WINDOW_MS         = 60000;
    private const CIRCUIT_BREAKER_THRESHOLD  = 3;

    /** @var array<string, Account> */
    private array $accounts = [];

    /**
     * Exchange rates relative to 1 USD (e.g., ['USD' => 1.0, 'INR' => 90.0])
     * @var array<string, float>
     */
    private array $exchangeRates;

    /** @var array<int, array<string, mixed>> */
    private array $auditLedger = [];

    /** @var array<string, int> Consecutive failure count per vendor_id */
    private array $vendorFailureCounts = [];

    /** @var array<string, bool> Circuit breaker status per vendor_id */
    private array $trippedVendors = [];

    /** @var array<int, string> Circuit breaker error log */
    private array $circuitBreakerLogs = [];

    public function __construct(array $exchangeRates = ['USD' => 1.0, 'INR' => 90.0, 'EUR' => 0.92])
    {
        $this->exchangeRates = $exchangeRates;
    }

    public function registerAccount(Account $account): void
    {
        $this->accounts[$account->getAccountId()] = $account;
    }

    public function getAccount(string $accountId): Account
    {
        if (!isset($this->accounts[$accountId])) {
            throw new InvalidArgumentException("Account {$accountId} not found.");
        }
        return $this->accounts[$accountId];
    }

    public function getAuditLedger(): array
    {
        return $this->auditLedger;
    }

    public function getCircuitBreakerLogs(): array
    {
        return $this->circuitBreakerLogs;
    }

    public function isVendorTripped(string $vendorId): bool
    {
        return !empty($this->trippedVendors[$vendorId]);
    }

    /**
     * Processes a single transaction against all business rules.
     *
     * @throws CircuitBreakerException
     * @throws InsufficientFundsException
     * @throws InvalidArgumentException
     */
    public function processTransaction(Transaction $tx): void
    {
        $vendorId = $tx->vendorId;

        // 1. Check Vendor Circuit Breaker State First
        if ($this->isVendorTripped($vendorId)) {
            $message = "Circuit breaker active for vendor {$vendorId}. Transaction blocked.";
            $this->circuitBreakerLogs[] = $message;
            throw new CircuitBreakerException($message);
        }

        try {
            if (!isset($this->accounts[$tx->accountId])) {
                throw new InvalidArgumentException("Account {$tx->accountId} does not exist.");
            }

            if ($tx->amount <= 0) {
                throw new InvalidArgumentException("Transaction amount must be positive.");
            }

            $account = $this->accounts[$tx->accountId];

            // 2. Multi-Currency Conversion to Account's Base Currency
            [$convertedAmount, $appliedRate] = $this->convertToBaseCurrency(
                $tx->amount,
                $tx->currency,
                $account->getBaseCurrency()
            );

            // 3. Evaluate Transaction Type & Balance Rules
            if ($tx->transactionType === Transaction::TYPE_TOP_UP) {
                $newBalance = $account->getBalance() + $convertedAmount;
                $baseImpact = $convertedAmount;
            } elseif ($tx->transactionType === Transaction::TYPE_DEDUCTION) {
                $this->evaluateDeductionRules($account, $convertedAmount, $tx->timestampMs);
                $newBalance = $account->getBalance() - $convertedAmount;
                $baseImpact = -$convertedAmount;
                $account->recordDeductionTimestamp($tx->timestampMs);
            } else {
                throw new InvalidArgumentException("Unsupported transaction type: {$tx->transactionType}");
            }

            // 4. Commit State & Audit Log
            $account->setBalance($newBalance);

            // Reset consecutive failure count for this vendor upon a valid transaction
            $this->vendorFailureCounts[$vendorId] = 0;

            $this->auditLedger[] = [
                'vendor_id'        => $tx->vendorId,
                'account_id'       => $tx->accountId,
                'transaction_type' => $tx->transactionType,
                'original_amount'  => $tx->amount,
                'currency'         => $tx->currency,
                'rate_applied'     => $appliedRate,
                'base_impact'      => $baseImpact,
                'new_balance'      => $account->getBalance(),
                'timestamp_ms'     => $tx->timestampMs,
            ];
        } catch (Throwable $e) {
            // Increment vendor failure counter on validation or insufficient funds failure
            $this->recordVendorFailure($vendorId);
            throw $e;
        }
    }

/**
     * Processes an atomic batch of transactions.
     * If any transaction in the batch fails, all state changes (balances, ledger, vendor counters)
     * made during the batch are cleanly rolled back in-place.
     *
     * @param Transaction[] $transactions
     * @throws Throwable
     */
    public function processBatch(array $transactions): void
    {
        // Snapshot current engine state before batch execution
        $accountStateSnapshots = [];
        foreach ($this->accounts as $id => $account) {
            $accountStateSnapshots[$id] = $account->createSnapshot();
        }
        $ledgerSnapshot         = $this->auditLedger;
        $failureCountsSnapshot  = $this->vendorFailureCounts;
        $trippedVendorsSnapshot = $this->trippedVendors;
        $cbLogsSnapshot         = $this->circuitBreakerLogs;

        try {
            foreach ($transactions as $tx) {
                $this->processTransaction($tx);
            }
        } catch (Throwable $e) {
            // Atomic Rollback: Restore state in-place on each existing Account object
            foreach ($accountStateSnapshots as $id => $snapshot) {
                if (isset($this->accounts[$id])) {
                    $this->accounts[$id]->restoreSnapshot($snapshot);
                }
            }
            $this->auditLedger         = $ledgerSnapshot;
            $this->vendorFailureCounts = $failureCountsSnapshot;
            $this->trippedVendors      = $trippedVendorsSnapshot;
            $this->circuitBreakerLogs  = $cbLogsSnapshot;

            throw $e;
        }
    }

    private function evaluateDeductionRules(Account $account, float $deductionInBase, int $timestampMs): void
    {
        $projectedBalance = $account->getBalance() - $deductionInBase;

        // Standard Account: Balance can never drop below $0.00
        if (!$account->isVip()) {
            if ($projectedBalance < 0.0) {
                throw new InsufficientFundsException(
                    "Standard account {$account->getAccountId()} cannot drop below 0.00."
                );
            }
            return;
        }

        // VIP Account Velocity Check:
        // Count prior deductions in the rolling 60s window.
        // If there are already 3 deductions in the last 60s, this transaction is the 4th (> 3 deductions in 60s),
        // which immediately revokes overdraft protection.
        $priorDeductionsInWindow = $account->getDeductionCountInWindow($timestampMs, self::VELOCITY_WINDOW_MS);
        if (($priorDeductionsInWindow + 1) > self::VELOCITY_MAX_DEDUCTIONS) {
            $account->revokeOverdraft();
        }

        // If overdraft was revoked, balance cannot go below 0
        if ($account->isOverdraftRevoked()) {
            if ($projectedBalance < 0.0) {
                throw new InsufficientFundsException(
                    "VIP Overdraft protection revoked for account {$account->getAccountId()} due to velocity limit breach."
                );
            }
            return;
        }

        // Active VIP Overdraft: Allowed up to -$500.00 USD converted to base currency
        [$maxOverdraftInBase] = $this->convertToBaseCurrency(
            self::VIP_OVERDRAFT_LIMIT_USD,
            'USD',
            $account->getBaseCurrency()
        );

        if ($projectedBalance < -$maxOverdraftInBase) {
            throw new InsufficientFundsException(
                "VIP account {$account->getAccountId()} exceeded hard overdraft limit of -{$maxOverdraftInBase}."
            );
        }
    }

    /**
     * Converts an amount from source currency to target base currency.
     *
     * @return array{0: float, 1: float} [convertedAmount, appliedExchangeRate]
     */
    private function convertToBaseCurrency(float $amount, string $fromCurrency, string $baseCurrency): array
    {
        if (!isset($this->exchangeRates[$fromCurrency]) || !isset($this->exchangeRates[$baseCurrency])) {
            throw new InvalidArgumentException("Missing exchange rate for {$fromCurrency} or {$baseCurrency}.");
        }

        if ($fromCurrency === $baseCurrency) {
            return [round($amount, 4), 1.0];
        }

        // Convert source currency -> USD -> target base currency
        $rateFromUsdToSource = $this->exchangeRates[$fromCurrency];
        $rateFromUsdToBase   = $this->exchangeRates[$baseCurrency];

        $effectiveRate   = $rateFromUsdToSource / $rateFromUsdToBase;
        $convertedAmount = round($amount / $effectiveRate, 4);

        return [$convertedAmount, round($effectiveRate, 4)];
    }

    private function recordVendorFailure(string $vendorId): void
    {
        $this->vendorFailureCounts[$vendorId] = ($this->vendorFailureCounts[$vendorId] ?? 0) + 1;

        if ($this->vendorFailureCounts[$vendorId] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->trippedVendors[$vendorId] = true;
        }
    }
}