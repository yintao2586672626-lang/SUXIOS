# App Entry Minification Implementation Plan

> **Execution rule:** keep `public/app-main.js` as the readable contract source and generate a deterministic browser artifact. Do not change templates, API calls, page routing, data semantics, or OTA evidence boundaries.

**Goal:** Reduce first-load JavaScript transfer and parse cost after entry externalization, while retaining a byte-verifiable build path and the existing source-level business guards.

**Measured reason:** `public/app-main.js` is 1,713,144 bytes and contributed about 359 KB of transferred JavaScript in the post-split browser sample. The prior split improved DOM interactive time but did not materially reduce first-load transfer or the remaining long task.

**Architecture:**

- Keep `public/app-main.js` unchanged as the canonical source checked by all existing contracts.
- Generate `public/app-main.min.js` with a pinned Terser dependency and conservative options: no property mangling, no top-level mangling, no source reordering outside Terser's standard safe compression.
- Point the ordered deferred startup chain at the generated artifact only.
- Add a verifier that regenerates the output in memory and requires exact equality, correct content-hash cache versioning, a material size reduction, and successful parsing.
- Keep page HTML and Vue template bytes unchanged except for the single script URL.

## Acceptance checks

- [ ] A failing test first proves the minified artifact/build contract is absent.
- [ ] The generated artifact is deterministic and exactly matches the verifier's in-memory output.
- [ ] `index.html` loads `app-main.min.js` last with `defer`, and does not load `app-main.js` at runtime.
- [ ] Existing source-level P0/business contracts still inspect `app-main.js` and remain green.
- [ ] The runtime artifact is materially smaller in raw and gzip size than the source.
- [ ] Anonymous real-browser startup mounts successfully with no console error, page error, failed request, or visible asset error.
- [ ] Before/after performance evidence is captured with truthful authentication status.
- [ ] Full PHP results are reported separately; unrelated existing failures are not relabeled as passing.

## TDD execution steps

1. Add `tests/automation/frontend_entry_build.test.mjs` expecting the source/artifact split, deterministic minifier output, ordered deferred reference, and cache hash.
2. Run it RED before adding the artifact or dependency.
3. Add pinned Terser development dependency, `scripts/lib/frontend_entry_build.mjs`, build/verify CLIs, package scripts, and generated artifact.
4. Update the public-entry guard and performance budget to validate/measure the runtime artifact while retaining source contract checks.
5. Run the focused build tests, public-entry guard, performance budget, P0 guards, browser startup check, and measurements.
6. Record exact evidence and stop this slice; choose another bottleneck only if measurements justify it.
