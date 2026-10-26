<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

class Transaction
{
    public const TYPE_TOP_UP    = 'Top-up';
    public const TYPE_DEDUCTION = 'Deduction';

    public function __construct(
        public readonly string $vendorId,
        public readonly string $accountId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly int $timestampMs,
        public readonly string $transactionType
    ) {
    }
}