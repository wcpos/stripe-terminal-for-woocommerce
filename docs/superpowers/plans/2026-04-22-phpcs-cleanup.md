# PHPCS Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer lint` pass by fixing the current PHPCS debt in the reported PHP files without weakening the ruleset.

**Architecture:** First clear the automatically-fixable PHPCS subset with PHPCBF, then address the remaining manual issues in focused batches by file and violation type. Validate each batch with lint and PHPUnit so style fixes do not mask behavior regressions.

**Tech Stack:** PHP, Composer, PHP_CodeSniffer, PHPCBF, PHPUnit, WordPress Coding Standards

---

### Task 1: Establish a clean runnable baseline in the worktree

**Files:**
- Modify: `composer.lock` (only if generated locally and intentionally tracked later)
- Test: `phpunit.xml.dist`

- [ ] **Step 1: Install PHP dependencies in the worktree**

Run:
```bash
composer install --no-interaction --prefer-dist
```

- [ ] **Step 2: Capture the baseline PHPCS report**

Run:
```bash
composer lint -- --report=summary
```
Expected: FAIL with the current PHPCS error summary.

- [ ] **Step 3: Capture the baseline PHPUnit result**

Run:
```bash
composer test
```
Expected: PASS so future regressions are attributable to cleanup changes.

### Task 2: Apply automatic PHPCS fixes

**Files:**
- Modify: `stripe-terminal-for-woocommerce.php`
- Modify: `includes/*.php`
- Modify: `includes/Abstracts/*.php`
- Modify: `includes/Utils/*.php`

- [ ] **Step 1: Run PHPCBF on the current lint target set**

Run:
```bash
composer format
```

- [ ] **Step 2: Inspect the remaining lint report**

Run:
```bash
composer lint -- --report=summary
```
Expected: FAIL with a smaller remaining set than the baseline.

- [ ] **Step 3: Review the actual diff before manual edits**

Run:
```bash
git diff --stat
git diff -- stripe-terminal-for-woocommerce.php includes/
```
Expected: Formatting-only changes from PHPCBF.

### Task 3: Fix manual PHPCS issues in focused batches

**Files:**
- Modify: `stripe-terminal-for-woocommerce.php`
- Modify: `includes/AjaxHandler.php`
- Modify: `includes/API.php`
- Modify: `includes/Frontend.php`
- Modify: `includes/Gateway.php`
- Modify: `includes/Logger.php`
- Modify: `includes/Settings.php`
- Modify: `includes/StripeTerminalService.php`
- Modify: `includes/Abstracts/APIController.php`
- Modify: `includes/Abstracts/StripeErrorHandler.php`
- Modify: `includes/Utils/CurrencyConverter.php`

- [ ] **Step 1: Add/fix missing file docblocks and package tags**

Run after edits:
```bash
composer lint -- --report=summary
```
Expected: File-comment-related issues reduced or gone.

- [ ] **Step 2: Fix translators comments and ordered placeholders**

Run after edits:
```bash
composer lint -- --report=summary
```
Expected: I18n placeholder issues reduced or gone.

- [ ] **Step 3: Fix sanitization and unslashing issues in request-handling code**

Run after edits:
```bash
composer lint -- --report=summary
```
Expected: Input-handling issues reduced or gone.

- [ ] **Step 4: Fix any remaining signature/doc/type issues**

Run after edits:
```bash
composer lint -- --report=summary
```
Expected: PASS.

### Task 4: Final verification and PR prep

**Files:**
- Modify: all touched PHP files only

- [ ] **Step 1: Run the full lint command**

Run:
```bash
composer lint
```
Expected: PASS.

- [ ] **Step 2: Run PHPUnit**

Run:
```bash
composer test
```
Expected: PASS.

- [ ] **Step 3: Review final diff**

Run:
```bash
git diff --stat origin/main...HEAD
git diff origin/main...HEAD
```
Expected: Only PHPCS cleanup changes.

- [ ] **Step 4: Commit**

Run:
```bash
git add stripe-terminal-for-woocommerce.php includes/ docs/superpowers/specs/2026-04-22-phpcs-cleanup-design.md docs/superpowers/plans/2026-04-22-phpcs-cleanup.md
git commit -m "chore: clean up PHPCS violations"
```
