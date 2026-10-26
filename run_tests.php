<?php

declare(strict_types=1);

// Enable strict assertions so any failed assert() throws an AssertionError
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

require_once __DIR__ . '/src/Policy/TenantAccessGuard.php';
require_once __DIR__ . '/src/Settlement/Exceptions/InsufficientFundsException.php';
require_once __DIR__ . '/src/Settlement/Exceptions/CircuitBreakerException.php';
require_once __DIR__ . '/src/Settlement/Account.php';
require_once __DIR__ . '/src/Settlement/Transaction.php';
require_once __DIR__ . '/src/Settlement/SettlementEngine.php';

use Repalogic\Policy\TenantAccessGuard;
use Repalogic\Settlement\Account;
use Repalogic\Settlement\Transaction;
use Repalogic\Settlement\SettlementEngine;
use Repalogic\Settlement\Exceptions\InsufficientFundsException;
use Repalogic\Settlement\Exceptions\CircuitBreakerException;

echo "=================================================================\n";
echo " REPALOGIC GROUP - SENIOR PHP DEVELOPER TECHNICAL ASSESSMENT\n";
echo "=================================================================\n\n";

/*
|--------------------------------------------------------------------------
| PART 1: CORE LOGIC & POLICY CHALLENGE (TenantAccessGuard)
|--------------------------------------------------------------------------
*/
echo "--- Running Part 1: Core Logic & Policy Challenge (TenantAccessGuard) ---\n";

$guard = new TenantAccessGuard();

$policyTestMatrix = [
    [
        'name'     => 'Test 1: Standard Tier - Active & Under Cap -> ALLOWED',
        'tier'     => TenantAccessGuard::TIER_STANDARD,
        'usage'    => 800,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_ACTIVE,
        'expected' => TenantAccessGuard::STATE_ALLOWED,
    ],
    [
        'name'     => 'Test 2: Standard Tier - Overdue & Under Cap -> BLOCKED_STATUS (No Grace Period)',
        'tier'     => TenantAccessGuard::TIER_STANDARD,
        'usage'    => 500,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_OVERDUE,
        'expected' => TenantAccessGuard::STATE_BLOCKED_STATUS,
    ],
    [
        'name'     => 'Test 3: Standard Tier - Active & Over Cap -> BLOCKED_USAGE',
        'tier'     => TenantAccessGuard::TIER_STANDARD,
        'usage'    => 1050,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_ACTIVE,
        'expected' => TenantAccessGuard::STATE_BLOCKED_USAGE,
    ],
    [
        'name'     => 'Test 4: Premium Tier - Overdue & Within 10% Grace Buffer (1080 <= 1100) -> ALLOWED',
        'tier'     => TenantAccessGuard::TIER_PREMIUM,
        'usage'    => 1080,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_OVERDUE,
        'expected' => TenantAccessGuard::STATE_ALLOWED,
    ],
    [
        'name'     => 'Test 5: Enterprise Tier - Overdue & Exceeds 10% Grace Buffer (1150 > 1100) -> BLOCKED_USAGE',
        'tier'     => TenantAccessGuard::TIER_ENTERPRISE,
        'usage'    => 1150,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_OVERDUE,
        'expected' => TenantAccessGuard::STATE_BLOCKED_USAGE,
    ],
    [
        'name'     => 'Test 6: Enterprise Tier - Suspended Status (Immediate Block Despite Low Usage) -> BLOCKED_STATUS',
        'tier'     => TenantAccessGuard::TIER_ENTERPRISE,
        'usage'    => 100,
        'cap'      => 1000,
        'status'   => TenantAccessGuard::STATUS_SUSPENDED,
        'expected' => TenantAccessGuard::STATE_BLOCKED_STATUS,
    ],
];

foreach ($policyTestMatrix as $test) {
    $actual = $guard->evaluate($test['tier'], $test['usage'], $test['cap'], $test['status']);
    assert($actual === $test['expected'], "Failed {$test['name']}: Expected {$test['expected']}, got {$actual}");
    echo "[PASS] {$test['name']}\n";
}

echo "\n--- Running Part 2: Analytical Logic Challenge (SettlementEngine) ---\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 1: Standard Currency Conversion & Ledger Tracking
|--------------------------------------------------------------------------
*/
$engine1 = new SettlementEngine(['USD' => 1.0, 'INR' => 90.0]);
$accountA = new Account('Account_A', 'USD', 100.00, Account::TIER_STANDARD);
$engine1->registerAccount($accountA);

$tx1 = new Transaction(
    vendorId: 'Vendor_1',
    accountId: 'Account_A',
    amount: 4500.00,
    currency: 'INR',
    timestampMs: 1000,
    transactionType: Transaction::TYPE_DEDUCTION
);

$engine1->processTransaction($tx1);

assert($accountA->getBalance() === 50.00, 'Scenario 1 Failed: Account A balance must be 50.00 USD.');
$ledger1 = $engine1->getAuditLedger();
assert(count($ledger1) === 1, 'Scenario 1 Failed: Audit ledger should have 1 entry.');
assert($ledger1[0]['original_amount'] === 4500.00, 'Scenario 1 Failed: Original amount should be 4500.00 INR.');
assert($ledger1[0]['rate_applied'] === 90.00, 'Scenario 1 Failed: Applied rate should be 90.00.');
assert($ledger1[0]['base_impact'] === -50.00, 'Scenario 1 Failed: Final base impact should be -50.00 USD.');
echo "[PASS] Scenario 1: Standard Currency Conversion & Ledger Tracking\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 2: The VIP Velocity Trap
|--------------------------------------------------------------------------
*/
$engine2 = new SettlementEngine(['USD' => 1.0]);
$accountB = new Account('Account_B', 'USD', 0.00, Account::TIER_VIP);
$engine2->registerAccount($accountB);

// 1. Deduct $100 USD at 1000ms -> Balance: -$100
$engine2->processTransaction(new Transaction('Vendor_VIP', 'Account_B', 100.00, 'USD', 1000, Transaction::TYPE_DEDUCTION));
assert($accountB->getBalance() === -100.00);

// 2. Deduct $100 USD at 5000ms -> Balance: -$200
$engine2->processTransaction(new Transaction('Vendor_VIP', 'Account_B', 100.00, 'USD', 5000, Transaction::TYPE_DEDUCTION));
assert($accountB->getBalance() === -200.00);

// 3. Deduct $100 USD at 12000ms -> Balance: -$300 (3rd deduction in 60s window)
$engine2->processTransaction(new Transaction('Vendor_VIP', 'Account_B', 100.00, 'USD', 12000, Transaction::TYPE_DEDUCTION));
assert($accountB->getBalance() === -300.00);

// 4. Deduct $50 USD at 20000ms -> 4th deduction within 60s! Revokes overdraft and throws InsufficientFundsException
$velocityExceptionThrown = false;
try {
    $engine2->processTransaction(new Transaction('Vendor_VIP', 'Account_B', 50.00, 'USD', 20000, Transaction::TYPE_DEDUCTION));
} catch (InsufficientFundsException $e) {
    $velocityExceptionThrown = true;
}

assert($velocityExceptionThrown === true, 'Scenario 2 Failed: 4th deduction should throw InsufficientFundsException.');
assert($accountB->isOverdraftRevoked() === true, 'Scenario 2 Failed: Overdraft protection should be flagged as revoked.');
assert($accountB->getBalance() === -300.00, 'Scenario 2 Failed: Balance must remain -300.00 USD after failed 4th tx.');
echo "[PASS] Scenario 2: The VIP Velocity Trap\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 3: The Cascading Vendor Circuit Breaker
|--------------------------------------------------------------------------
*/
$engine3 = new SettlementEngine(['USD' => 1.0]);
$engine3->registerAccount(new Account('Account_C', 'USD', 10.00));
$engine3->registerAccount(new Account('Account_D', 'USD', 10.00));
$engine3->registerAccount(new Account('Account_E', 'USD', 10.00));
$accountF = new Account('Account_F', 'USD', 10.00);
$engine3->registerAccount($accountF);

// 3 consecutive failed transactions from Vendor_X
$failedAttempts = [
    new Transaction('Vendor_X', 'Account_C', 50.00, 'USD', 1000, Transaction::TYPE_DEDUCTION),
    new Transaction('Vendor_X', 'Account_D', 20.00, 'USD', 2000, Transaction::TYPE_DEDUCTION),
    new Transaction('Vendor_X', 'Account_E', 15.00, 'USD', 3000, Transaction::TYPE_DEDUCTION),
];

$failCount = 0;
foreach ($failedAttempts as $tx) {
    try {
        $engine3->processTransaction($tx);
    } catch (InsufficientFundsException $e) {
        $failCount++;
    }
}

assert($failCount === 3, 'Scenario 3 Failed: First 3 invalid transactions should fail with InsufficientFundsException.');
assert($engine3->isVendorTripped('Vendor_X') === true, 'Scenario 3 Failed: Circuit breaker should be tripped for Vendor_X.');

// 4th transaction on Account F ($2.00 deduction on $10.00 balance) must fail with CircuitBreakerException
$circuitBreakerTriggered = false;
try {
    $engine3->processTransaction(new Transaction('Vendor_X', 'Account_F', 2.00, 'USD', 4000, Transaction::TYPE_DEDUCTION));
} catch (CircuitBreakerException $e) {
    $circuitBreakerTriggered = true;
}

assert($circuitBreakerTriggered === true, 'Scenario 3 Failed: 4th transaction from Vendor_X must throw CircuitBreakerException.');
assert($accountF->getBalance() === 10.00, 'Scenario 3 Failed: Account F balance must remain untouched at 10.00 USD.');
assert(count($engine3->getCircuitBreakerLogs()) === 1, 'Scenario 3 Failed: CircuitBreakerException must be logged.');
echo "[PASS] Scenario 3: The Cascading Vendor Circuit Breaker\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 4: Atomic Batch Rollback (Analytical Integrity)
|--------------------------------------------------------------------------
*/
$engine4 = new SettlementEngine(['USD' => 1.0]);
$accountG = new Account('Account_G', 'USD', 100.00);
$engine4->registerAccount($accountG);

$batch = [
    new Transaction('Vendor_Batch', 'Account_G', 40.00, 'USD', 1000, Transaction::TYPE_DEDUCTION),
    new Transaction('Vendor_Batch', 'Account_G', 50.00, 'USD', 2000, Transaction::TYPE_DEDUCTION),
    new Transaction('Vendor_Batch', 'Account_G', 30.00, 'USD', 3000, Transaction::TYPE_DEDUCTION), // 40+50+30 = 120 > 100 -> Fails!
];

$batchFailed = false;
try {
    $engine4->processBatch($batch);
} catch (InsufficientFundsException $e) {
    $batchFailed = true;
}

assert($batchFailed === true, 'Scenario 4 Failed: Batch exceeding balance must throw InsufficientFundsException.');
assert($accountG->getBalance() === 100.00, 'Scenario 4 Failed: Account G balance must cleanly roll back to 100.00 USD.');
assert(count($engine4->getAuditLedger()) === 0, 'Scenario 4 Failed: Audit ledger must remain empty after batch rollback.');
echo "[PASS] Scenario 4: Atomic Batch Rollback (Analytical Integrity)\n";

echo "\n=================================================================\n";
echo " ALL TEST SUITES PASSED SUCCESSFULLY (10/10 ASSERTIONS VERIFIED)\n";
echo "=================================================================\n";