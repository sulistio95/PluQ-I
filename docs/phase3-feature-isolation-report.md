# Phase 3 — Feature Isolation and Individual Verification

Updated: 2026-10-27
Repository: https://github.com/sulistio95/PluQ-I
Official base: `main` at `52341bf38f1a3434dbe0f6a4ec817cd7a5c7f70f`

## Result

Phase 3 feature isolation and individual CI verification is complete. The feature lanes below are intentionally separate; they are not a combined release candidate. No changes were made to `main`. The current `main` ref still compares identical to the official base commit.

## Isolated feature lanes

| Lane | Result | Scope |
|---|---|---|
| `integration/phase2-main-baseline-2026-10` | Merged test PR #16; head `96ebc0f6536382f0f8066d19247e196072455495` | Supplier-price pagination, catalog assets, homepage catalog loading, associated tests |
| `integration/phase3-vipayment-stack-2026-10` | Merged test PR #19; head `e07439b426b3bf42d8919444ac7b934101008f31` | Dependent VIPayment nickname lookup stack, migration 013, provider lookup tests |
| `integration/phase3-admin-sidebar-stack-2026-10` | Merged test PR #20; head `48e426f04f9d3c7ec9ed5cd0692efb4046b90dab` | Admin sidebar and dependent admin pages, transaction search/detail, template settings |
| `integration/phase3-customer-rating-stack-2026-10` | Merged test PR #21; head `d2f1762addab20da908f8a53d6b0901a89f2b188` | Customer order ratings, supplier quality dashboard, migration 014, rating/CSRF tests |

All four test PRs targeted isolated integration branches, never `main`. PR #16's PHP quality run passed (run 40449012565); its source-tip PHP/JavaScript syntax and fulfillment safety runs were also successful. PR #19's PHP quality run passed (run 40449308038), including PHP lint, storefront JavaScript syntax, and VIPayment nickname-parameter tests. PR #20's PHP quality run passed (run 40449397706). PR #21's PHP quality run passed (run 40449474313), including rating rules and CSRF tests. These are automated CI results, not shared-hosting or live-provider verification.

## Branches deliberately not imported wholesale

- `feature/filter-supplier-prices-to-active-games`: PR #12 is already merged into `main`; do not re-add the full branch.
- `feature/automatic-game-account-form`: PR #3 is already merged into `main`; compare any remaining semantic differences before porting.
- `fix/provider-balance-reservations`: uses alternate migration 009 and `provider_reservations.php`, whereas `main` uses migrations 011/012 and `provider_budget.php`. Do not merge wholesale; Phase 4 must compare behavior and port only verified missing fixes.
- `audit/port-supplier-price-pagination` / PR #15: useful as a reference for adapting pagination to the admin-sidebar layout, but its base `integration/pre-main-2026-10` is not the official base.
- Diagnostic PRs #17 and #18 were closed unmerged after the nickname branch conflicted with a different integration lane and did not merge cleanly onto bare `main`. The dependent nickname stack was instead tested through PR #19.

## Dependencies for Phase 4

1. Reconcile the four isolated lanes without overwriting overlapping changes in `public/index.php`, `public/assets/catalog.js`, `app/services/catalog.php`, `public/admin/supplier-prices.php`, and template/admin navigation files.
2. Port supplier pagination into the sidebar layout using PR #15 only as a reference; do not import its old integration base.
3. Keep migration order valid: current `main` migrations 011/012, then migration 013 from the username-check protection stack, then migration 014 for ratings.
4. Reconcile provider balance reservation behavior against the current implementation and tests.
5. Run the full combined CI and database migration sequence on the resulting integration branch before considering a PR to `main`.

## Safety

No production checkout, real payment, supplier order, or Drive backup was triggered. No branch was deleted. This report does not certify live VIPayment behavior, browser/mobile layout, or shared-hosting compatibility.
