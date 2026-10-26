# Repalogic Group — Senior PHP Developer Technical Assessment

This repository contains the pure, native PHP 8.1+ solutions for both Repalogic technical challenges:
1. **Core Logic & Policy Challenge (`TenantAccessGuard`)**
2. **Analytical Logic Challenge (`SettlementEngine`)**

Zero external frameworks, packages, or databases are used. State is strictly managed in-memory and verified via native PHP `assert()` statements in `run_tests.php`.

---

## 1. Architectural Choices & Design Explanation

### Part A: `TenantAccessGuard` (`src/Policy/TenantAccessGuard.php`)
- **Guard-Clause Prioritization**: The `evaluate()` method uses early returns ordered by rule severity:
  1. **Account Suspension (`Suspended`)**: Evaluated first across all tiers (`Standard`, `Premium`, `Enterprise`). A suspended account immediately returns `BLOCKED_STATUS` regardless of usage.
  2. **Standard Tier Strictness**: Standard accounts have no grace buffer. If `status === Overdue`, it immediately returns `BLOCKED_STATUS`. If `usage > hardCap`, it returns `BLOCKED_USAGE`.
  3. **Premium & Enterprise Grace Period**: When `status === Overdue`, the engine dynamically computes an effective ceiling (`hardCap * 1.10`). Usage within this 10% buffer returns `ALLOWED`; exceeding it returns `BLOCKED_USAGE`.
- **Test Case Matrix (6 Cases Implemented in `run_tests.php`)**:
  1. *Standard + Active + Under Cap* -> `ALLOWED` (Baseline happy path).
  2. *Standard + Overdue + Under Cap* -> `BLOCKED_STATUS` (Proves Standard accounts get no grace period).
  3. *Standard + Active + Over Cap* -> `BLOCKED_USAGE` (Proves hard cap enforcement).
  4. *Premium + Overdue + Within 10% Buffer (1080/1000)* -> `ALLOWED` (Proves Grace Period buffer works).
  5. *Enterprise + Overdue + Above 10% Buffer (1150/1000)* -> `BLOCKED_USAGE` (Proves buffer ceiling enforcement).
  6. *Enterprise + Suspended + Low Usage (100/1000)* -> `BLOCKED_STATUS` (Proves immediate suspension block across top tiers).

### Part B: `SettlementEngine` (`src/Settlement/`)
- **Separation of Concerns**:
  - `Account`: Encapsulates balance, tier (`Standard` vs `VIP`), base currency, and a rolling timestamp log of deductions.
  - `Transaction`: Immutable `readonly` value object representing an incoming API event.
  - `SettlementEngine`: Orchestrates currency conversion, velocity checks, vendor circuit breaking, audit logging, and atomic batch execution.
- **Multi-Currency Integrity**: Converts incoming transaction amounts to the account's base currency via a static exchange rate matrix and records `original_amount`, `rate_applied`, and `base_impact` in the internal audit ledger.
- **VIP Velocity Trap**: Before authorizing a deduction on a `VIP` account, the engine inspects the account's deduction timestamps within `[currentTimestamp - 60000ms, currentTimestamp]`. Once the 4th deduction occurs within a rolling 60-second window (`> 3 deductions`), overdraft capability is permanently revoked on the account, causing sub-zero deductions to fail with `InsufficientFundsException`.
- **Vendor Fraud Circuit Breaker**: Tracks consecutive failures per `vendor_id`. A valid transaction resets the vendor's failure count to `0`. Upon the 3rd consecutive failure, the vendor is marked tripped, and all subsequent requests immediately throw and log `CircuitBreakerException` before inspecting account balances.
- **Atomic Batch Rollback**: `processBatch()` takes a deep clone snapshot of all `Account` objects, the audit ledger, and vendor state counters prior to execution. If any transaction in the batch throws an exception, the engine restores the pre-batch snapshot so balances and ledgers remain 100% untouched.

---

## 2. How to Run the Tests

Ensure you have PHP 8.1 or higher installed, then run from the project root:

```bash
php run_tests.php