<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use InvalidArgumentException;
use Repalogic\Settlement\Exceptions\CircuitBreakerException;
use Repalogic\Settlement\Exceptions\InsufficientFundsException;
use Throwable;

final class SettlementEngine
{
    private const VIP_OVERDRAFT_LIMIT_USD   = 500.00;
    private const VELOCITY_MAX_DEDUCTIONS   = 3;
    private const VELOCITY_WINDOW_MS        = 60000;
    private const CIRCUIT_BREAKER_THRESHOLD = 3;

    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, float> */
    private array $exchangeRates = [];

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
        if (empty($exchangeRates)) {
            throw new InvalidArgumentException("Exchange rate matrix cannot be empty.");
        }

        foreach ($exchangeRates as $currency => $rate) {
            if (!is_string($currency) || trim($currency) === '') {
                throw new InvalidArgumentException("Currency code must be a non-empty string.");
            }
            if ((!is_float($rate) && !is_int($rate)) || !is_finite((float) $rate) || $rate <= 0) {
                throw new InvalidArgumentException("Exchange rate for {$currency} must be a positive finite number.");
            }
            $this->exchangeRates[strtoupper(trim($currency))] = (float) $rate;
        }
    }

    public function registerAccount(Account $account): void
    {
        $baseCurrency = $account->getBaseCurrency();
        if (!isset($this->exchangeRates[$baseCurrency])) {
            throw new InvalidArgumentException("Account base currency {$baseCurrency} is not supported by exchange matrix.");
        }

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
     * Processes a single transaction against all business rules[cite: 2, 3].
     *
     * @throws CircuitBreakerException
     * @throws InsufficientFundsException
     * @throws InvalidArgumentException
     */
    public function processTransaction(Transaction $tx): void
    {
        $vendorId = trim($tx->vendorId);
        if ($vendorId === '') {
            throw new InvalidArgumentException("Vendor ID cannot be empty.");
        }

        // 1. Check Vendor Circuit Breaker State First[cite: 3]
        if ($this->isVendorTripped($vendorId)) {
            $message = "Circuit breaker active for vendor {$vendorId}. Transaction blocked.";
            $this->circuitBreakerLogs[] = $message;
            throw new CircuitBreakerException($message);
        }

        try {
            if (!isset($this->accounts[$tx->accountId])) {
                throw new InvalidArgumentException("Account {$tx->accountId} does not exist.");
            }

            if (!is_finite($tx->amount) || $tx->amount <= 0.0) {
                throw new InvalidArgumentException("Transaction amount must be a positive finite number.");
            }

            if ($tx->timestampMs < 0) {
                throw new InvalidArgumentException("Transaction timestamp cannot be negative.");
            }

            $account = $this->accounts[$tx->accountId];

            // Security Check: Prevent backdated timestamp replay attacks that bypass the 60s rolling velocity window
            $lastTs = $account->getLastTransactionTimestampMs();
            if ($lastTs !== null && $tx->timestampMs < $lastTs) {
                throw new InvalidArgumentException(
                    "Out-of-order backdated timestamp ({$tx->timestampMs}ms < {$lastTs}ms) rejected for sequential stream."
                );
            }

            // 2. Multi-Currency Conversion to Account's Base Currency[cite: 2]
            [$convertedAmount, $appliedRate] = $this->convertToBaseCurrency(
                $tx->amount,
                strtoupper(trim($tx->currency)),
                $account->getBaseCurrency()
            );

            // Security Check: Prevent "Salami Slicing" (micro-transactions that round to 0.0000 in base currency)
            if ($convertedAmount <= 0.0) {
                throw new InvalidArgumentException("Transaction amount is too small after currency conversion (rounds to 0).");
            }

            // 3. Evaluate Transaction Type & Balance Rules[cite: 2, 3]
            if ($tx->transactionType === Transaction::TYPE_TOP_UP) {
                $newBalance = round($account->getBalance() + $convertedAmount, 4);
                $baseImpact = $convertedAmount;
                $account->recordTransactionTimestamp($tx->timestampMs, false);
            } elseif ($tx->transactionType === Transaction::TYPE_DEDUCTION) {
                $this->evaluateDeductionRules($account, $convertedAmount, $tx->timestampMs);
                $newBalance = round($account->getBalance() - $convertedAmount, 4);
                $baseImpact = -$convertedAmount;
                $account->recordTransactionTimestamp($tx->timestampMs, true);
            } else {
                throw new InvalidArgumentException("Unsupported transaction type: {$tx->transactionType}");
            }

            // 4. Commit State & Audit Log[cite: 3]
            $account->setBalance($newBalance);

            // Reset consecutive failure count for this vendor upon a valid transaction
            $this->vendorFailureCounts[$vendorId] = 0;

            $this->auditLedger[] = [
                'vendor_id'        => $vendorId,
                'account_id'       => $tx->accountId,
                'transaction_type' => $tx->transactionType,
                'original_amount'  => $tx->amount,
                'currency'         => strtoupper(trim($tx->currency)),
                'rate_applied'     => $appliedRate,
                'base_impact'      => $baseImpact,
                'new_balance'      => $account->getBalance(),
                'timestamp_ms'     => $tx->timestampMs,
            ];
        } catch (Throwable $e) {
            // Increment vendor failure counter on validation or insufficient funds failure[cite: 3]
            $this->recordVendorFailure($vendorId);
            throw $e;
        }
    }

    /**
     * Processes an atomic batch of transactions.
     * If any transaction in the batch fails, account balances and ledger entries
     * are cleanly rolled back in-place, while vendor failure counters accurately
     * increment from their pre-batch state so failed batches cannot reset failure streaks.
     *
     * @param Transaction[] $transactions
     * @throws Throwable
     */
    public function processBatch(array $transactions): void
    {
        if (empty($transactions)) {
            throw new InvalidArgumentException("Transaction batch cannot be empty.");
        }

        // Snapshot current account, ledger, and pre-batch vendor failure counts
        $accountStateSnapshots = [];
        foreach ($this->accounts as $id => $account) {
            $accountStateSnapshots[$id] = $account->createSnapshot();
        }
        $ledgerSnapshot        = $this->auditLedger;
        $failureCountsSnapshot = $this->vendorFailureCounts;

        $currentTx = null;

        try {
            foreach ($transactions as $tx) {
                if (!$tx instanceof Transaction) {
                    throw new InvalidArgumentException("Batch must only contain Transaction instances.");
                }
                $currentTx = $tx;
                $this->processTransaction($tx);
            }
        } catch (Throwable $e) {
            // 1. Atomic Rollback: Restore account balances/timestamps and ledger in-place.
            foreach ($accountStateSnapshots as $id => $snapshot) {
                if (isset($this->accounts[$id])) {
                    $this->accounts[$id]->restoreSnapshot($snapshot);
                }
            }
            $this->auditLedger = $ledgerSnapshot;

            // 2. Restore pre-batch vendor failure counts so uncommitted transactions earlier
            // in the failed batch cannot artificially reset the vendor's failure streak to 0!
            $this->vendorFailureCounts = $failureCountsSnapshot;

            if ($currentTx instanceof Transaction) {
                $failedVendorId = trim($currentTx->vendorId);
                if ($failedVendorId !== '' && !$this->isVendorTripped($failedVendorId)) {
                    $this->recordVendorFailure($failedVendorId);
                }
            }

            throw $e;
        }
    }

    private function evaluateDeductionRules(Account $account, float $deductionInBase, int $timestampMs): void
    {
        $projectedBalance = round($account->getBalance() - $deductionInBase, 4);

        // Standard Account: Balance can never drop below $0.00[cite: 2]
        if (!$account->isVip()) {
            if ($projectedBalance < 0.0) {
                throw new InsufficientFundsException(
                    "Standard account {$account->getAccountId()} cannot drop below 0.00."
                );
            }
            return;
        }

        // VIP Account Velocity Check[cite: 2, 3]:
        $priorDeductionsInWindow = $account->getDeductionCountInWindow($timestampMs, self::VELOCITY_WINDOW_MS);
        if (($priorDeductionsInWindow + 1) > self::VELOCITY_MAX_DEDUCTIONS) {
            $account->revokeOverdraft();
        }

        if ($account->isOverdraftRevoked()) {
            if ($projectedBalance < 0.0) {
                throw new InsufficientFundsException(
                    "VIP Overdraft protection revoked for account {$account->getAccountId()} due to velocity limit breach."
                );
            }
            return;
        }

        // Active VIP Overdraft: Allowed up to -$500.00 USD converted to base currency[cite: 2]
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

    private function resetVendorFailure(string $vendorId): void
    {
        if(isset($this->vendorFailureCounts[$vendorId]) || isset($this->trippedVendors[$vendorId])) {
            $this->vendorFailureCounts[$vendorId] = 0;
            unset($this->trippedVendors[$vendorId]);

        }
        // if () {
        //     unset($this->trippedVendors[$vendorId]);
        // }
    }
}