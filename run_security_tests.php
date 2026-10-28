<?php

declare(strict_types=1);

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
use Repalogic\Settlement\Exceptions\CircuitBreakerException;

echo "=================================================================\n";
echo " ADVERSARIAL SECURITY & EDGE-CASE UNIT TEST SUITE\n";
echo "=================================================================\n\n";

$guard = new TenantAccessGuard();

/*
 * TEST 1: IEEE-754 Floating-Point Boundary Attack on Grace Buffer
 * In raw float math, 1000.0 * 1.10 === 1100.0000000000002.
 * Verify exact 1100.00 is ALLOWED, and 1100.0001 is BLOCKED_USAGE.
 */
$resExact = $guard->evaluate(TenantAccessGuard::TIER_PREMIUM, 1100.00, 1000.00, TenantAccessGuard::STATUS_OVERDUE);
assert($resExact === TenantAccessGuard::STATE_ALLOWED, 'Security Test 1a Failed: Exact 1100.00 should be ALLOWED.');

$resOver = $guard->evaluate(TenantAccessGuard::TIER_PREMIUM, 1100.0001, 1000.00, TenantAccessGuard::STATUS_OVERDUE);
assert($resOver === TenantAccessGuard::STATE_BLOCKED_USAGE, 'Security Test 1b Failed: 1100.0001 should be BLOCKED_USAGE.');
echo "[PASS] Security Test 1: IEEE-754 Floating-Point Boundary Precision on 10% Grace Buffer\n";

/*
 * TEST 2: NaN and INF Injection Attack on TenantAccessGuard
 */
$nanBlocked = false;
try {
    $guard->evaluate(TenantAccessGuard::TIER_STANDARD, NAN, 1000.0, TenantAccessGuard::STATUS_ACTIVE);
} catch (InvalidArgumentException $e) {
    $nanBlocked = true;
}
assert($nanBlocked === true, 'Security Test 2 Failed: NaN usage must throw InvalidArgumentException.');

$infBlocked = false;
try {
    $guard->evaluate(TenantAccessGuard::TIER_STANDARD, 500.0, INF, TenantAccessGuard::STATUS_ACTIVE);
} catch (InvalidArgumentException $e) {
    $infBlocked = true;
}
assert($infBlocked === true, 'Security Test 2 Failed: INF hardCap must throw InvalidArgumentException.');
echo "[PASS] Security Test 2: NaN & Infinite (INF) Payload Injection Blocked\n";

/*
 * TEST 3: Backdated Timestamp Replay Attack (VIP Velocity Bypass Attempt)
 * Attacker executes 3 deductions at t=10000, 20000, 30000ms, then attempts to send a
 * backdated timestamp t=500ms to evade the 60-second window.
 */
$engineReplay = new SettlementEngine(['USD' => 1.0]);
$vipAcc = new Account('VIP_1', 'USD', 0.00, Account::TIER_VIP);
$engineReplay->registerAccount($vipAcc);

$engineReplay->processTransaction(new Transaction('Vendor_A', 'VIP_1', 100.0, 'USD', 10000, Transaction::TYPE_DEDUCTION));
$engineReplay->processTransaction(new Transaction('Vendor_A', 'VIP_1', 100.0, 'USD', 20000, Transaction::TYPE_DEDUCTION));
$engineReplay->processTransaction(new Transaction('Vendor_A', 'VIP_1', 100.0, 'USD', 30000, Transaction::TYPE_DEDUCTION));

$replayBlocked = false;
try {
    // Attacker sends backdated timestamp 500ms (< 30000ms)
    $engineReplay->processTransaction(new Transaction('Vendor_A', 'VIP_1', 100.0, 'USD', 500, Transaction::TYPE_DEDUCTION));
} catch (InvalidArgumentException $e) {
    $replayBlocked = true;
}
assert($replayBlocked === true, 'Security Test 3 Failed: Backdated timestamp replay must be rejected.');
assert($vipAcc->getBalance() === -300.00, 'Security Test 3 Failed: VIP balance must remain -300.00.');
echo "[PASS] Security Test 3: Backdated Timestamp Replay Attack (Velocity Bypass) Blocked\n";

/*
 * TEST 4: Micro-Transaction "Salami Slicing" Attack (Rounding to 0.0000 Base Currency)
 * Attacker sends 0.001 INR (which is 0.000011 USD -> rounds to 0.0000 USD).
 */
$engineSalami = new SettlementEngine(['USD' => 1.0, 'INR' => 90.0]);
$stdAcc = new Account('Std_1', 'USD', 100.00);
$engineSalami->registerAccount($stdAcc);

$salamiBlocked = false;
try {
    $engineSalami->processTransaction(new Transaction('Vendor_Salami', 'Std_1', 0.001, 'INR', 1000, Transaction::TYPE_DEDUCTION));
} catch (InvalidArgumentException $e) {
    $salamiBlocked = true;
}
assert($salamiBlocked === true, 'Security Test 4 Failed: Sub-precision micro-transaction rounding to 0 must be rejected.');
echo "[PASS] Security Test 4: Micro-Transaction 'Salami Slicing' (Zero-Impact Rounding) Blocked\n";

/*
 * TEST 5: Failed Batch Circuit-Breaker Evasion Attack
 * Attacker wraps failing transactions inside processBatch() 3 times, hoping batch rollback
 * also rolls back their vendor failure counter so the Circuit Breaker never trips.
 */
$engineBatchAttack = new SettlementEngine(['USD' => 1.0]);
$victimAcc = new Account('Victim_Acc', 'USD', 50.00);
$engineBatchAttack->registerAccount($victimAcc);

for ($i = 1; $i <= 3; $i++) {
    try {
        $engineBatchAttack->processBatch([
            new Transaction('Malicious_Batch_Vendor', 'Victim_Acc', 30.00, 'USD', $i * 1000, Transaction::TYPE_DEDUCTION),
            new Transaction('Malicious_Batch_Vendor', 'Victim_Acc', 40.00, 'USD', ($i * 1000) + 100, Transaction::TYPE_DEDUCTION), // 30+40=70 > 50 (Fails!)
        ]);
    } catch (Throwable $e) {
        // Expected batch failure
    }
}

assert($victimAcc->getBalance() === 50.00, 'Security Test 5a Failed: Victim account balance must remain 50.00 USD.');
assert($engineBatchAttack->isVendorTripped('Malicious_Batch_Vendor') === true, 'Security Test 5b Failed: Vendor circuit breaker MUST trip after 3 failed batches.');

$fourthAttemptBlockedByCB = false;
try {
    $engineBatchAttack->processTransaction(new Transaction('Malicious_Batch_Vendor', 'Victim_Acc', 1.00, 'USD', 9000, Transaction::TYPE_DEDUCTION));
} catch (CircuitBreakerException $e) {
    $fourthAttemptBlockedByCB = true;
}
assert($fourthAttemptBlockedByCB === true, 'Security Test 5c Failed: Subsequent valid transaction must throw CircuitBreakerException.');
echo "[PASS] Security Test 5: Batch Rollback Circuit-Breaker Evasion Prevented\n";

/*
 * TEST 6: Negative / Zero / NaN Exchange Rate Poisoning
 */
$poisonBlocked = false;
try {
    new SettlementEngine(['USD' => 1.0, 'INR' => -90.0]);
} catch (InvalidArgumentException $e) {
    $poisonBlocked = true;
}
assert($poisonBlocked === true, 'Security Test 6 Failed: Negative exchange rate must be rejected.');
echo "[PASS] Security Test 6: Exchange Rate Matrix Poisoning Blocked\n";

/*
 * TEST 7: Negative Initial Balance Injection on Standard Account
 */
$negInitBlocked = false;
try {
    new Account('Bad_Acc', 'USD', -100.00, Account::TIER_STANDARD);
} catch (InvalidArgumentException $e) {
    $negInitBlocked = true;
}
assert($negInitBlocked === true, 'Security Test 7 Failed: Negative initial balance on Standard account must be rejected.');
echo "[PASS] Security Test 7: Negative Initial Balance on Standard Account Blocked\n";

echo "\n=================================================================\n";
echo " ALL 7 ADVERSARIAL SECURITY TEST SUITES PASSED!\n";
echo "=================================================================\n";