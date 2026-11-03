# 2026-11-03 GitHub source savepoint

This branch preserves the current local SUXIOS source, tests, migrations and generated frontend assets. It is a source savepoint, not a mainline merge or a production release. Its parent is `9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b`; the remote main observed before saving is `5c3c64bc9bc82c0600ce224eb3f3366016ecb679`.

The original working directory and its Git index are preserved. The savepoint was assembled and checked using a separate index and an isolated filesystem snapshot.

## Public repository boundary

User-supplied procurement quotations, investment workbooks and derived numeric extracts, original operating reports, private source manifests, third-party page screenshots, and generated CSV readback results are retained locally. They are not part of this public savepoint. General operating-diagnosis guidance is included without the report's actual figures.

Source ingestion/replay tools remain available, but their private source-material replays require the original local materials. In particular, the procurement reference service explicitly reports unavailable reference material when its private source pack is absent. This snapshot must not be described as a complete backup of business data or the local database.

## Savepoint verification and known limitations

The savepoint adds the investment-payback page to the existing investment style group and regenerates its stylesheet artifacts and cache versions. Two source-size ratchets are satisfied by removing tokenizer-verified blank lines; their budgets and business logic are unchanged.

The exact index must pass the normal pre-commit verifiers before the commit is created. Passing that hook is distinct from passing the full integration profile.

The broader checks found these existing failures that are not resolved by this source-save task:

1. `guest_operations_panel.test.mjs`: the installed ZXing scanner factory is not recognized by the test helper.
2. `operation_frontend_closure.test.mjs`: two assertions expect an older action/task heading.
3. `revenue_ai_static.test.mjs`: an assertion expects an older saved-snapshot condition.
4. `verify_revenue_ai_closure_contract.mjs`: an assertion expects an older review-draft expression.

The business-page contract run reported 350 passes and 4 failures out of 354 tests. The separate revenue-AI closure verifier reported the additional failure. These remain unresolved checks, regardless of whether they ultimately require test updates or implementation changes.

Do not merge or deploy this savepoint based solely on its successful GitHub push. Resolve and rerun the failed integration checks, integrate with current main, and perform the relevant application acceptance before release. Production, authenticated OTA/PMS, and business-effect acceptance are not established here.
