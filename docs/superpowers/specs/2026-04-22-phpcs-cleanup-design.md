# PHPCS Cleanup Design

**Date:** 2026-04-22

## Goal
Make the repository's root `composer lint` command pass without weakening the PHPCS ruleset and without introducing unrelated behavior changes.

## Scope
This cleanup is limited to the PHP files currently reported by PHPCS:
- `stripe-terminal-for-woocommerce.php`
- `includes/AjaxHandler.php`
- `includes/API.php`
- `includes/Frontend.php`
- `includes/Gateway.php`
- `includes/Logger.php`
- `includes/Settings.php`
- `includes/StripeTerminalService.php`
- `includes/Abstracts/APIController.php`
- `includes/Abstracts/StripeErrorHandler.php`
- `includes/Utils/CurrencyConverter.php`

## Approach
1. Run PHPCBF first to clear the automatically-fixable subset of violations.
2. Re-run PHPCS and group the remaining violations by category.
3. Fix remaining issues in small batches:
   - docblocks and package/file comments
   - translators comments and ordered placeholders
   - sanitization/unslashing and nonce-related findings
   - any remaining signature/whitespace/style issues not covered by PHPCBF
4. Re-run `composer lint` and `composer test` after each meaningful batch.

## Constraints
- Do not change `.phpcs.xml.dist` unless a rule is provably broken rather than the code.
- Do not refactor application behavior unless required to satisfy a legitimate lint finding.
- Keep runtime behavior stable; this is a lint debt cleanup, not a feature project.
- Prefer the smallest code edits that satisfy the existing standards.

## Risks
- Some PHPCS warnings may reveal real hygiene issues, especially around input sanitization.
- Fixes in large files like `AjaxHandler.php`, `Gateway.php`, and `StripeTerminalService.php` can accidentally alter runtime behavior if edits are too broad.
- The repo may rely on formatting or patterns that are inconsistent with the current PHPCS baseline.

## Verification
- `composer lint`
- `composer test`
- If PHP source behavior is touched materially, re-run the existing frontend/E2E checks only if needed by the affected code path.
